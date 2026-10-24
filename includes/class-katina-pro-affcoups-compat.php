<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders [affcoups] shortcodes from imported Katina coupons once the
 * Affiliate Coupons plugin is gone, so migrated posts need no edits.
 * Filters that have no Katina equivalent (category, type, vendor) are
 * resolved against the original affcoups data, which stays in the database
 * after that plugin is deactivated or deleted.
 */
class KATINA_PRO_Affcoups_Compat {

    const SHORTCODES = array( 'affcoups', 'affcoups_coupons' );

    private static $map = null;

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register' ), 99 );
    }

    public static function register() {
        if ( shortcode_exists( 'affcoups' ) || ! apply_filters( 'katina_pro_affcoups_compat', true ) ) {
            return;
        }

        foreach ( self::SHORTCODES as $tag ) {
            if ( ! shortcode_exists( $tag ) ) {
                add_shortcode( $tag, array( __CLASS__, 'render_shortcode' ) );
            }
        }
    }

    public static function render_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'id'       => '',
            'category' => '',
            'type'     => '',
            'vendor'   => '',
            'max'      => '',
            'orderby'  => '',
            'order'    => '',
            'template' => '',
            'grid'     => '',
            'expired'  => '',
        ), (array) $atts, 'affcoups' );

        $settings = get_option( 'affcoups_settings', array() );
        $settings = is_array( $settings ) ? $settings : array();
        $expired  = in_array( strtolower( trim( (string) $atts['expired'] ) ), array( 'true', '1', 'yes' ), true ) ? 'show' : 'hide';

        $ids = self::coupon_ids( $atts );
        if ( array() === $ids ) {
            return '';
        }

        $ids = self::sort_and_limit( $ids, $atts, $settings, 'hide' === $expired );
        if ( empty( $ids ) ) {
            return '';
        }

        return KATINA_PRO_Coupons_Render::render( array_merge( self::layout( $atts, $settings ), array(
            'ids'     => implode( ',', $ids ),
            'limit'   => count( $ids ),
            'expired' => $expired,
        ) ) );
    }

    /**
     * Katina coupon IDs the attributes select, or null when nothing narrows
     * the selection (a bare [affcoups] shows every coupon).
     */
    private static function coupon_ids( array $atts ) {
        $sources = null;

        if ( '' !== trim( (string) $atts['id'] ) ) {
            $sources = array_slice( array_values( array_unique( array_filter( array_map( 'absint', explode( ',', (string) $atts['id'] ) ) ) ) ), 0, 100 );
        }

        $filters = array(
            'category' => 'affcoups_coupon_category',
            'type'     => 'affcoups_coupon_type',
        );
        foreach ( $filters as $attr => $taxonomy ) {
            if ( '' !== trim( (string) $atts[ $attr ] ) ) {
                $sources = self::narrow( $sources, self::source_ids_in_terms( $taxonomy, (string) $atts[ $attr ] ) );
            }
        }

        if ( '' !== trim( (string) $atts['vendor'] ) ) {
            $sources = self::narrow( $sources, self::source_ids_for_vendors( (string) $atts['vendor'] ) );
        }

        if ( null === $sources ) {
            return null;
        }

        $map = self::get_map();
        $ids = array();
        foreach ( $sources as $source_id ) {
            if ( isset( $map[ $source_id ] ) ) {
                $ids[] = $map[ $source_id ];
            }
        }
        return array_values( array_unique( $ids ) );
    }

    private static function narrow( $current, array $found ) {
        return null === $current ? $found : array_values( array_intersect( $current, $found ) );
    }

    private static function sort_and_limit( $ids, array $atts, array $settings, $hide_expired ) {
        $orderby = strtolower( '' !== $atts['orderby'] ? $atts['orderby'] : ( isset( $settings['orderby'] ) ? (string) $settings['orderby'] : 'date' ) );
        $order   = strtoupper( '' !== $atts['order'] ? $atts['order'] : ( isset( $settings['order'] ) ? (string) $settings['order'] : 'desc' ) );
        $order   = 'ASC' === $order ? 'ASC' : 'DESC';

        $args = array(
            'post_type'           => KATINA_PRO_Coupons::POST_TYPE,
            'post_status'         => 'publish',
            'has_password'        => false,
            'posts_per_page'      => KATINA_PRO_Coupons_Render::MAX_LIMIT,
            'fields'              => 'ids',
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
            'meta_query'          => KATINA_PRO_Coupons::schedule_meta_query( $hide_expired ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            'orderby'             => 'date',
            'order'               => $order,
        );
        if ( null !== $ids ) {
            $args['post__in'] = $ids;
        }

        $date_meta = array(
            'valid_from'  => KATINA_PRO_Coupons::META['starts'],
            'valid_until' => KATINA_PRO_Coupons::META['expires'],
            'valid_to'    => KATINA_PRO_Coupons::META['expires'],
        );

        if ( 'name' === $orderby || 'title' === $orderby ) {
            $args['orderby'] = 'title';
        } elseif ( 'random' === $orderby ) {
            $args['orderby'] = 'rand';
        } elseif ( 'none' === $orderby && null !== $ids ) {
            $args['orderby'] = 'post__in';
        }

        $found = array_map( 'intval', get_posts( $args ) );

        if ( isset( $date_meta[ $orderby ] ) && $found ) {
            update_meta_cache( 'post', $found );
            $key = $date_meta[ $orderby ];
            usort( $found, function ( $a, $b ) use ( $key, $order ) {
                $da = (string) get_post_meta( $a, $key, true );
                $db = (string) get_post_meta( $b, $key, true );
                if ( $da === $db ) {
                    return 0;
                }
                // Coupons without the date go last either way.
                if ( '' === $da ) {
                    return 1;
                }
                if ( '' === $db ) {
                    return -1;
                }
                return 'ASC' === $order ? strcmp( $da, $db ) : strcmp( $db, $da );
            } );
        }

        $max = absint( $atts['max'] );
        return $max ? array_slice( $found, 0, $max ) : $found;
    }

    private static function layout( array $atts, array $settings ) {
        $template = '' !== $atts['template'] ? $atts['template'] : ( isset( $settings['template'] ) ? (string) $settings['template'] : 'standard' );
        $columns  = (int) $atts['grid'];

        if ( $columns > 0 ) {
            $template = 'grid';
        } elseif ( 'grid' === $template ) {
            $columns = isset( $settings['grid_size'] ) && (int) $settings['grid_size'] > 0 ? (int) $settings['grid_size'] : 2;
        }

        if ( 'list' === $template ) {
            return array( 'layout' => 'list', 'columns' => 1 );
        }
        if ( 'grid' === $template ) {
            return array( 'layout' => 'grid', 'columns' => $columns );
        }
        // standard, widget, amp and unknown templates: one card per row.
        return array( 'layout' => 'grid', 'columns' => 1 );
    }

    private static function source_ids_in_terms( $taxonomy, $value ) {
        global $wpdb;

        $values = array_slice( array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) ), 0, 100 );
        if ( ! $values ) {
            return array();
        }

        // Affiliate Coupons reads the whole list as term IDs when the first value is numeric.
        if ( is_numeric( $values[0] ) ) {
            $values = array_values( array_filter( array_map( 'absint', $values ) ) );
            $field  = 't.term_id';
            $format = '%d';
        } else {
            $values = array_values( array_filter( array_map( 'sanitize_title', $values ) ) );
            $field  = 't.slug';
            $format = '%s';
        }
        if ( ! $values ) {
            return array();
        }

        $placeholders = implode( ',', array_fill( 0, count( $values ), $format ) );
        // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT tr.object_id
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
             WHERE tt.taxonomy = %s AND {$field} IN ({$placeholders})",
            array_merge( array( $taxonomy ), $values )
        ) );
        // phpcs:enable

        return array_map( 'intval', (array) $ids );
    }

    private static function source_ids_for_vendors( $value ) {
        global $wpdb;

        $vendors = array_slice( array_values( array_filter( array_map( 'absint', explode( ',', $value ) ) ) ), 0, 100 );
        if ( ! $vendors ) {
            return array();
        }

        $placeholders = implode( ',', array_fill( 0, count( $vendors ), '%s' ) );
        // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'affcoups_coupon_vendor' AND meta_value IN ({$placeholders})",
            array_map( 'strval', $vendors )
        ) );
        // phpcs:enable

        return array_map( 'intval', (array) $ids );
    }

    /**
     * Original affcoups_coupon ID => published Katina coupon ID, from the
     * source ID the importer stores on each coupon.
     */
    private static function get_map() {
        if ( null !== self::$map ) {
            return self::$map;
        }

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = %s AND p.post_status = 'publish'
             WHERE pm.meta_key = %s
             ORDER BY pm.post_id ASC",
            KATINA_PRO_Coupons::POST_TYPE,
            KATINA_PRO_Coupons::META['source_id']
        ) );

        self::$map = array();
        foreach ( (array) $rows as $row ) {
            $source = (int) $row->meta_value;
            if ( $source && ! isset( self::$map[ $source ] ) ) {
                self::$map[ $source ] = (int) $row->post_id;
            }
        }

        return self::$map;
    }
}
