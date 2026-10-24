<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_PRO_Revenue {

    const SCHEMA_VERSION   = 1;
    const SCHEMA_OPTION    = 'katinapro_revenue_schema_version';
    const PAGE_SLUG        = 'katina-revenue';
    const DEFAULT_CURRENCY = 'EUR';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade_schema' ) );
        add_action( 'admin_post_katinapro_save_revenue', array( __CLASS__, 'handle_save' ) );

        // Add columns to katina_link admin list — no free-plugin code change required.
        add_filter( 'manage_katina_link_posts_columns', array( __CLASS__, 'add_columns' ) );
        add_action( 'manage_katina_link_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'katina_revenue';
    }

    public static function create_table() {
        global $wpdb;
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            link_id BIGINT UNSIGNED NOT NULL,
            period_ym CHAR(7) NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            currency CHAR(3) NOT NULL DEFAULT 'EUR',
            program VARCHAR(191) NOT NULL DEFAULT '',
            source VARCHAR(32) NOT NULL DEFAULT 'manual',
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_link_month (link_id, period_ym),
            KEY idx_period_ym (period_ym)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
    }

    public static function maybe_upgrade_schema() {
        $current = (int) get_option( self::SCHEMA_OPTION, 0 );
        if ( $current < self::SCHEMA_VERSION ) {
            self::create_table();
        }
    }

    public static function register_menu() {
        add_submenu_page(
            'edit.php?post_type=katina_link',
            __( 'Revenue', 'katina-link-manager-pro' ),
            __( 'Revenue', 'katina-link-manager-pro' ),
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function add_columns( $columns ) {
        $new = array();
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'katina_clicks_30d' || $key === 'katina_clicks' ) {
                $new['katinapro_revenue'] = __( 'Revenue (last month)', 'katina-link-manager-pro' );
                $new['katinapro_epc']     = __( 'EPC (last month)', 'katina-link-manager-pro' );
            }
        }
        // Fallback if the click column key wasn't found — append at end.
        if ( ! isset( $new['katinapro_revenue'] ) ) {
            $new['katinapro_revenue'] = __( 'Revenue (last month)', 'katina-link-manager-pro' );
            $new['katinapro_epc']     = __( 'EPC (last month)', 'katina-link-manager-pro' );
        }
        return $new;
    }

    public static function render_column( $column, $post_id ) {
        if ( $column !== 'katinapro_revenue' && $column !== 'katinapro_epc' ) {
            return;
        }
        $ym  = self::last_month_ym();
        $row = self::get_monthly_revenue( $post_id, $ym );

        if ( $column === 'katinapro_revenue' ) {
            if ( ! $row || (float) $row['amount'] === 0.0 ) {
                echo '<span style="color:#999;">—</span>';
                return;
            }
            echo esc_html( self::format_money( (float) $row['amount'], $row['currency'] ) );
            return;
        }

        if ( $column === 'katinapro_epc' ) {
            $clicks = self::get_human_clicks_for_month( $post_id, $ym );
            if ( ! $row || $clicks === 0 ) {
                echo '<span style="color:#999;">—</span>';
                return;
            }
            $epc = (float) $row['amount'] / $clicks;
            echo esc_html( self::format_money( $epc, $row['currency'], 3 ) );
            echo ' <span style="color:#999;">(' . esc_html( (string) $clicks ) . ')</span>';
        }
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'katina-link-manager-pro' ), 403 );
        }

        $selected_ym = isset( $_GET['ym'] ) ? self::sanitize_ym( wp_unslash( $_GET['ym'] ) ) : self::last_month_ym();
        $links       = self::get_active_links();
        $existing    = self::get_revenue_for_month( $selected_ym );
        $click_index = self::get_human_clicks_index_for_month( $selected_ym );

        include KATINA_PRO_PLUGIN_DIR . 'views/admin-revenue.php';
    }

    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'katina-link-manager-pro' ), 403 );
        }
        check_admin_referer( 'katinapro_save_revenue' );

        $ym = isset( $_POST['ym'] ) ? self::sanitize_ym( wp_unslash( $_POST['ym'] ) ) : self::last_month_ym();
        $default_currency = isset( $_POST['default_currency'] )
            ? self::sanitize_currency( wp_unslash( $_POST['default_currency'] ) )
            : self::DEFAULT_CURRENCY;

        $rows = isset( $_POST['rows'] ) && is_array( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : array();
        $saved = 0;
        foreach ( $rows as $link_id => $row ) {
            $link_id = absint( $link_id );
            if ( ! $link_id ) {
                continue;
            }
            $amount   = isset( $row['amount'] ) ? self::parse_amount( $row['amount'] ) : null;
            $currency = isset( $row['currency'] ) && $row['currency'] !== ''
                ? self::sanitize_currency( $row['currency'] )
                : $default_currency;
            $program  = isset( $row['program'] ) ? sanitize_text_field( $row['program'] ) : '';

            // Skip empty rows unless there is an existing record we want to update to zero.
            if ( $amount === null ) {
                continue;
            }

            self::set_monthly_revenue( $link_id, $ym, $amount, $currency, $program );
            $saved++;
        }

        $redirect = add_query_arg(
            array(
                'post_type' => 'katina_link',
                'page'      => self::PAGE_SLUG,
                'ym'        => $ym,
                'saved'     => $saved,
            ),
            admin_url( 'edit.php' )
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    // ── Data access ───────────────────────────────────────────────────────────

    public static function get_monthly_revenue( $link_id, $ym ) {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT amount, currency, program FROM {$table} WHERE link_id = %d AND period_ym = %s", $link_id, $ym ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function set_monthly_revenue( $link_id, $ym, $amount, $currency, $program = '' ) {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (link_id, period_ym, amount, currency, program, source, created_at, updated_at)
                 VALUES (%d, %s, %f, %s, %s, 'manual', NOW(), NOW())
                 ON DUPLICATE KEY UPDATE amount = VALUES(amount), currency = VALUES(currency), program = VALUES(program), updated_at = NOW()",
                $link_id,
                $ym,
                (float) $amount,
                $currency,
                $program
            )
        );
    }

    public static function get_revenue_for_month( $ym ) {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT link_id, amount, currency, program FROM {$table} WHERE period_ym = %s", $ym ),
            ARRAY_A
        );
        $by_link = array();
        foreach ( $rows ?: array() as $row ) {
            $by_link[ (int) $row['link_id'] ] = $row;
        }
        return $by_link;
    }

    public static function get_human_clicks_for_month( $link_id, $ym ) {
        global $wpdb;
        $clicks_table = $wpdb->prefix . 'katina_clicks';
        list( $start, $end ) = self::ym_range( $ym );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$clicks_table}
                 WHERE link_id = %d AND is_bot = 0
                 AND clicked_at >= %s AND clicked_at < %s",
                $link_id,
                $start,
                $end
            )
        );
        return (int) $count;
    }

    public static function get_human_clicks_index_for_month( $ym ) {
        global $wpdb;
        $clicks_table = $wpdb->prefix . 'katina_clicks';
        list( $start, $end ) = self::ym_range( $ym );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT link_id, COUNT(*) AS clicks FROM {$clicks_table}
                 WHERE is_bot = 0 AND clicked_at >= %s AND clicked_at < %s
                 GROUP BY link_id",
                $start,
                $end
            ),
            ARRAY_A
        );
        $index = array();
        foreach ( $rows ?: array() as $row ) {
            $index[ (int) $row['link_id'] ] = (int) $row['clicks'];
        }
        return $index;
    }

    public static function get_active_links() {
        $links = get_posts( array(
            'post_type'      => 'katina_link',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ) );
        return $links;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function last_month_ym() {
        return gmdate( 'Y-m', strtotime( 'first day of last month' ) );
    }

    public static function sanitize_ym( $value ) {
        $value = is_string( $value ) ? trim( $value ) : '';
        if ( ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $value ) ) {
            return self::last_month_ym();
        }
        return $value;
    }

    public static function sanitize_currency( $value ) {
        $value = is_string( $value ) ? strtoupper( trim( $value ) ) : '';
        return preg_match( '/^[A-Z]{3}$/', $value ) ? $value : self::DEFAULT_CURRENCY;
    }

    public static function parse_amount( $value ) {
        if ( is_array( $value ) ) {
            return null;
        }
        $value = trim( (string) $value );
        if ( $value === '' ) {
            return null;
        }
        // Accept "1,234.56", "1.234,56", "1234.56", "1234,56" — strip thousands separators.
        $normalized = preg_replace( '/[^0-9.,\-]/', '', $value );
        // If both . and , present, treat the rightmost as decimal separator.
        if ( strpos( $normalized, ',' ) !== false && strpos( $normalized, '.' ) !== false ) {
            if ( strrpos( $normalized, ',' ) > strrpos( $normalized, '.' ) ) {
                $normalized = str_replace( '.', '', $normalized );
                $normalized = str_replace( ',', '.', $normalized );
            } else {
                $normalized = str_replace( ',', '', $normalized );
            }
        } elseif ( strpos( $normalized, ',' ) !== false ) {
            // Only comma — treat as decimal if it looks like a decimal separator (2 digits after).
            if ( preg_match( '/,\d{1,2}$/', $normalized ) ) {
                $normalized = str_replace( ',', '.', $normalized );
            } else {
                $normalized = str_replace( ',', '', $normalized );
            }
        }
        if ( ! is_numeric( $normalized ) ) {
            return null;
        }
        return round( (float) $normalized, 2 );
    }

    public static function format_money( $amount, $currency, $decimals = 2 ) {
        $symbol = self::currency_symbol( $currency );
        return $symbol . number_format( (float) $amount, $decimals, '.', ',' );
    }

    public static function currency_symbol( $currency ) {
        $map = array(
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
        );
        return $map[ $currency ] ?? ( $currency . ' ' );
    }

    public static function ym_range( $ym ) {
        $start = $ym . '-01 00:00:00';
        $next  = gmdate( 'Y-m-d 00:00:00', strtotime( $start . ' +1 month' ) );
        return array( $start, $next );
    }

    public static function recent_months( $count = 12 ) {
        $months = array();
        for ( $i = 0; $i < $count; $i++ ) {
            $months[] = gmdate( 'Y-m', strtotime( "first day of -{$i} month" ) );
        }
        return $months;
    }
}
