<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Turns the free plugin's orphan list into something actionable: for each page
 * with no incoming links, find posts that already mention it and offer to
 * insert the link.
 */
class KATINA_PRO_Link_Opportunities {

    const PAGE_SLUG = 'katina-link-opportunities';

    // Each uncached orphan costs a LIKE scan over post_content, so results are
    // cached per orphan and only a few new scans run per page load.
    const CACHE_PREFIX   = 'katinapro_opps_';
    const CACHE_TTL      = 12 * HOUR_IN_SECONDS;
    const GEN_OPTION     = 'katinapro_opportunities_gen';
    const SCANS_PER_LOAD = 5;
    const KEYWORD_META   = '_katinapro_link_keyword';

    public static function init() {
        // The index lives in the free plugin. Without it there is nothing to read.
        if ( ! class_exists( 'KATINA_Link_Index' ) ) {
            add_action( 'admin_notices', array( __CLASS__, 'render_version_notice' ) );
            return;
        }

        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_keyword_meta_box' ) );
        add_action( 'save_post', array( __CLASS__, 'save_keyword' ), 10, 2 );
        add_action( 'admin_post_katinapro_insert_internal_link', array( __CLASS__, 'handle_insert_link' ) );
    }

    public static function init_cache_invalidation() {
        if ( ! class_exists( 'KATINA_Link_Index' ) ) {
            return;
        }
        add_action( 'save_post', array( __CLASS__, 'flush_on_save' ), 10, 2 );
    }

    public static function add_keyword_meta_box() {
        add_meta_box(
            'katinapro-link-keyword',
            __( 'Link opportunity keyword', 'katina-link-manager-pro' ),
            array( __CLASS__, 'render_keyword_meta_box' ),
            KATINA_Link_Index::scannable_post_types(),
            'side',
            'low'
        );
    }

    public static function render_keyword_meta_box( $post ) {
        wp_nonce_field( 'katinapro_save_keyword', 'katinapro_keyword_nonce' );
        ?>
        <p>
            <input type="text" class="widefat" name="katinapro_link_keyword" value="<?php echo esc_attr( get_post_meta( $post->ID, self::KEYWORD_META, true ) ); ?>" />
        </p>
        <p class="description"><?php esc_html_e( 'Phrase other posts use to mention this one. Leave empty to match on the title.', 'katina-link-manager-pro' ); ?></p>
        <?php
    }

    public static function save_keyword( $post_id, $post ) {
        if ( ! isset( $_POST['katinapro_keyword_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['katinapro_keyword_nonce'] ) ), 'katinapro_save_keyword' ) ) {
            return;
        }
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $keyword = isset( $_POST['katinapro_link_keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['katinapro_link_keyword'] ) ) : '';
        if ( '' === $keyword ) {
            delete_post_meta( $post_id, self::KEYWORD_META );
        } else {
            update_post_meta( $post_id, self::KEYWORD_META, $keyword );
        }
    }

    public static function flush_on_save( $post_id, $post ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( ! in_array( $post->post_type, KATINA_Link_Index::scannable_post_types(), true ) ) {
            return;
        }
        update_option( self::GEN_OPTION, (string) microtime( true ), false );
    }

    private static function cache_key( $target_id, $limit ) {
        return self::CACHE_PREFIX . md5( get_option( self::GEN_OPTION, '0' ) . '|' . $target_id . '|' . $limit );
    }

    public static function render_version_notice() {
        echo '<div class="notice notice-warning"><p>' . esc_html__( 'Link Opportunities needs Katina Link Manager 1.3.0 or later. Update the free plugin to enable it.', 'katina-link-manager-pro' ) . '</p></div>';
    }

    public static function register_menu() {
        add_submenu_page(
            'edit.php?post_type=katina_link',
            __( 'Link Opportunities', 'katina-link-manager-pro' ),
            __( 'Link Opportunities', 'katina-link-manager-pro' ),
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'katina-link-manager-pro' ), 403 );
        }

        $post_type = isset( $_GET['post_type_filter'] ) ? sanitize_key( wp_unslash( $_GET['post_type_filter'] ) ) : '';
        if ( $post_type !== '' && ! in_array( $post_type, KATINA_Link_Index::scannable_post_types(), true ) ) {
            $post_type = '';
        }

        $orphans    = KATINA_Link_Index::get_orphans( 25, $post_type );
        $post_types = KATINA_Link_Index::scannable_post_types();
        $has_index  = KATINA_Link_Index::has_index();

        // Pull candidate sources for each orphan up front so the view stays dumb.
        // A null entry means the orphan hasn't been scanned yet.
        $opportunities = array();
        $scans         = 0;
        $pending       = 0;
        foreach ( $orphans as $orphan ) {
            $key  = self::cache_key( (int) $orphan['ID'], 5 );
            $rows = get_transient( $key );
            if ( ! is_array( $rows ) ) {
                if ( $scans < self::SCANS_PER_LOAD ) {
                    $rows = self::get_opportunities( (int) $orphan['ID'], 5 );
                    set_transient( $key, $rows, self::CACHE_TTL );
                    $scans++;
                } else {
                    $rows = null;
                    $pending++;
                }
            }
            $opportunities[ $orphan['ID'] ] = $rows;
        }

        include KATINA_PRO_PLUGIN_DIR . 'views/admin-link-opportunities.php';
    }

    /**
     * Find posts that already mention a target's title but don't link to it.
     */
    public static function get_opportunities( $target_id, $limit = 10 ) {
        global $wpdb;

        $target = get_post( $target_id );
        if ( ! $target ) {
            return array();
        }

        $keyword = self::opportunity_keyword( $target );
        if ( mb_strlen( $keyword ) < 4 ) {
            return array();
        }

        $table    = KATINA_Link_Index::table_name();
        $types    = KATINA_Link_Index::scannable_post_types();
        $statuses = KATINA_Link_Index::scannable_post_statuses();
        if ( empty( $types ) || empty( $statuses ) ) {
            return array();
        }

        $type_ph   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $status_ph = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

        $params = array_merge(
            array( $target_id ),
            $types,
            $statuses,
            array( $target_id, '%' . $wpdb->esc_like( $keyword ) . '%', (int) $limit )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title, p.post_type, p.post_status
                 FROM {$wpdb->posts} p
                 WHERE p.ID NOT IN ( SELECT source_id FROM {$table} WHERE target_id = %d AND link_type = 'internal' )
                   AND p.post_type IN ({$type_ph})
                   AND p.post_status IN ({$status_ph})
                   AND p.ID != %d
                   AND p.post_content LIKE %s
                 ORDER BY p.post_date DESC
                 LIMIT %d",
                $params
            ),
            ARRAY_A
        );

        if ( ! $rows ) {
            return array();
        }

        // Iterate the array itself — taking a reference to the result of `?:`
        // would write to a temporary and silently drop the keyword.
        foreach ( $rows as $index => $row ) {
            $rows[ $index ]['keyword'] = $keyword;
        }

        return $rows;
    }

    /**
     * The phrase we look for in other posts. Falls back to the title, minus any
     * trailing parenthetical or subtitle that would never appear verbatim.
     */
    public static function opportunity_keyword( $target ) {
        $custom = get_post_meta( $target->ID, self::KEYWORD_META, true );
        if ( ! empty( $custom ) ) {
            return trim( $custom );
        }

        $title = $target->post_title;
        $title = preg_split( '/\s+[–—:|]\s+/u', $title )[0];
        $title = preg_replace( '/\s*\([^)]*\)\s*$/', '', $title );

        return trim( $title );
    }

    /**
     * Insert a link to $target_id into $source_id at the first unlinked
     * occurrence of the keyword. Returns true if the content changed.
     */
    public static function insert_link( $source_id, $target_id, $keyword = '' ) {
        $source = get_post( $source_id );
        $target = get_post( $target_id );
        if ( ! $source || ! $target ) {
            return false;
        }

        $keyword = $keyword !== '' ? $keyword : self::opportunity_keyword( $target );
        if ( mb_strlen( $keyword ) < 4 ) {
            return false;
        }

        $url = get_permalink( $target_id );
        $new = KATINA_PRO_Link_Inserter::link_first( $source->post_content, $keyword, '<a href="' . esc_url( $url ) . '">' );

        if ( $new === null ) {
            return false;
        }

        wp_update_post( array(
            'ID'           => $source_id,
            'post_content' => wp_slash( $new ),
        ) );

        // save_post reindexes the source, but do it explicitly so callers that
        // suppress hooks still get a correct index.
        KATINA_Link_Index::index_post( $source_id );

        return true;
    }

    public static function handle_insert_link() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'katina-link-manager-pro' ), 403 );
        }
        check_admin_referer( 'katinapro_insert_internal_link' );

        $source_id = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;
        $target_id = isset( $_POST['target_id'] ) ? absint( $_POST['target_id'] ) : 0;
        $keyword   = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';

        // Editing the source post is the real capability being exercised here.
        if ( ! $source_id || ! $target_id || ! current_user_can( 'edit_post', $source_id ) ) {
            wp_die( esc_html__( 'You do not have permission to edit that post.', 'katina-link-manager-pro' ), 403 );
        }

        $inserted = self::insert_link( $source_id, $target_id, $keyword );

        $redirect = add_query_arg(
            array(
                'post_type' => 'katina_link',
                'page'      => self::PAGE_SLUG,
                'inserted'  => $inserted ? 1 : 0,
            ),
            admin_url( 'edit.php' )
        );
        wp_safe_redirect( $redirect );
        exit;
    }
}
