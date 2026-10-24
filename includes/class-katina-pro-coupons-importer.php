<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_PRO_Coupons_Importer {

    const SOURCE_TYPE   = 'affcoups_coupon';
    const PAGE_SLUG     = 'katina-coupons-import';
    const ACTION        = 'katinapro_import_coupons';
    const REPORT_PREFIX = 'katinapro_coupon_import_';
    const DRAFT_LINK    = '_katinapro_imported_draft_link';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_request' ) );
    }

    public static function register_menu() {
        add_submenu_page(
            'edit.php?post_type=katina_link',
            __( 'Import Coupons', 'katina-link-manager-pro' ),
            __( 'Import Coupons', 'katina-link-manager-pro' ),
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'katina-link-manager-pro' ), 403 );
        }

        $source_count = self::source_count();
        $report       = get_transient( self::REPORT_PREFIX . get_current_user_id() );

        include KATINA_PRO_PLUGIN_DIR . 'views/admin-coupons-import.php';
    }

    public static function handle_request() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'katina-link-manager-pro' ), 403 );
        }
        check_admin_referer( self::ACTION );

        $dry_run = ! empty( $_POST['dry_run'] );
        $report  = self::run( array( 'dry_run' => $dry_run ) );

        set_transient( self::REPORT_PREFIX . get_current_user_id(), $report, HOUR_IN_SECONDS );

        wp_safe_redirect( add_query_arg(
            array(
                'post_type' => 'katina_link',
                'page'      => self::PAGE_SLUG,
            ),
            admin_url( 'edit.php' )
        ) );
        exit;
    }

    public static function source_count() {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash', 'auto-draft')",
            self::SOURCE_TYPE
        ) );
    }

    public static function run( array $args = array() ) {
        $args = wp_parse_args( $args, array(
            'dry_run' => false,
            'limit'   => 0,
        ) );

        $dry_run = (bool) $args['dry_run'];
        $query   = array(
            'post_type'      => self::SOURCE_TYPE,
            'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
            'posts_per_page' => $args['limit'] ? (int) $args['limit'] : -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        );

        $sources  = get_posts( $query );
        $imported = self::imported_index();
        $links    = self::link_index();

        $report = array(
            'dry_run' => $dry_run,
            'rows'    => array(),
            'counts'  => array(
                'coupons_created' => 0,
                'coupons_skipped' => 0,
                'coupons_failed'  => 0,
                'links_matched'   => 0,
                'links_created'   => 0,
                'links_missing'   => 0,
            ),
        );

        foreach ( $sources as $source ) {
            $row = array(
                'source_id' => $source->ID,
                'title'     => '',
                'coupon'    => '',
                'coupon_id' => 0,
                'link'      => '',
                'link_id'   => 0,
                'note'      => '',
            );

            $mapped       = self::map( $source );
            $row['title'] = $mapped['title'];

            if ( isset( $imported[ $source->ID ] ) ) {
                $row['coupon']    = 'skipped';
                $row['coupon_id'] = $imported[ $source->ID ];
                $row['note']      = __( 'already imported', 'katina-link-manager-pro' );
                $report['counts']['coupons_skipped']++;
                $report['rows'][] = $row;
                continue;
            }

            $publish        = 'publish' === $source->post_status;
            $resolved       = self::resolve_link( $mapped, $links, $dry_run, $publish );
            $row['link']    = $resolved['action'];
            $row['link_id'] = $resolved['id'];
            if ( $resolved['note'] ) {
                $row['note'] = $resolved['note'];
            }
            $report['counts'][ 'links_' . $resolved['bucket'] ]++;

            if ( $dry_run ) {
                $row['coupon'] = 'would_create';
                $report['counts']['coupons_created']++;
                $report['rows'][] = $row;
                continue;
            }

            $coupon_id = self::create_coupon( $source, $mapped, $resolved['id'], $publish );
            if ( is_wp_error( $coupon_id ) ) {
                $row['coupon'] = 'failed';
                $row['note']   = $coupon_id->get_error_message();
                $report['counts']['coupons_failed']++;
            } else {
                if ( ! $publish && 'created' === $resolved['action'] ) {
                    update_post_meta( $resolved['id'], self::DRAFT_LINK, (string) $coupon_id );
                }
                $row['coupon']        = 'created';
                $row['coupon_id']     = $coupon_id;
                $imported[ $source->ID ] = $coupon_id;
                $report['counts']['coupons_created']++;
            }
            $report['rows'][] = $row;
        }

        return $report;
    }

    private static function map( WP_Post $source ) {
        $id     = $source->ID;
        $meta   = function ( $key ) use ( $id ) {
            return get_post_meta( $id, 'affcoups_' . $key, true );
        };
        $vendor = (int) $meta( 'coupon_vendor' );

        $title = trim( (string) $meta( 'coupon_title' ) );
        if ( '' === $title ) {
            $title = $source->post_title;
        }

        $url = trim( (string) $meta( 'coupon_url' ) );
        if ( '' === $url && $vendor ) {
            $url = trim( (string) get_post_meta( $vendor, 'affcoups_vendor_url', true ) );
        }

        return array(
            'title'       => sanitize_text_field( $title ),
            'description' => wp_kses_post( (string) $meta( 'coupon_description' ) ),
            'code'        => sanitize_text_field( (string) $meta( 'coupon_code' ) ),
            'discount'    => sanitize_text_field( (string) $meta( 'coupon_discount' ) ),
            'url'         => $url,
            'starts'      => self::to_date( $meta( 'coupon_valid_from' ) ),
            'expires'     => self::to_date( $meta( 'coupon_valid_until' ) ),
            'image_id'    => self::find_image( $id, $vendor ),
            'terms'       => self::source_terms( $id ),
        );
    }

    private static function to_date( $value ) {
        if ( is_numeric( $value ) && (int) $value > 0 ) {
            return wp_date( 'Y-m-d', (int) $value );
        }
        return '';
    }

    private static function find_image( $source_id, $vendor_id ) {
        $candidates = array( (int) get_post_thumbnail_id( $source_id ) );
        foreach ( (array) get_post_meta( $source_id, 'affcoups_coupon_image', false ) as $value ) {
            $candidates[] = (int) $value;
        }
        if ( $vendor_id ) {
            $candidates[] = (int) get_post_meta( $vendor_id, 'affcoups_vendor_image', true );
        }
        foreach ( $candidates as $candidate ) {
            if ( $candidate && 'attachment' === get_post_type( $candidate ) ) {
                return $candidate;
            }
        }
        return 0;
    }

    private static function source_terms( $source_id ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT tt.taxonomy, t.name
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
             WHERE tr.object_id = %d AND tt.taxonomy IN ('affcoups_coupon_category', 'affcoups_coupon_type')",
            $source_id
        ) );

        $terms = array(
            KATINA_PRO_Coupons::TAX_CATEGORY => array(),
            KATINA_PRO_Coupons::TAX_TAG      => array(),
        );
        foreach ( (array) $rows as $row ) {
            $target             = 'affcoups_coupon_category' === $row->taxonomy ? KATINA_PRO_Coupons::TAX_CATEGORY : KATINA_PRO_Coupons::TAX_TAG;
            $terms[ $target ][] = $row->name;
        }
        return $terms;
    }

    private static function imported_index() {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_value, post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
            KATINA_PRO_Coupons::META['source_id']
        ) );

        $index = array();
        foreach ( (array) $rows as $row ) {
            $index[ (int) $row->meta_value ] = (int) $row->post_id;
        }
        return $index;
    }

    private static function link_index() {
        global $wpdb;
        $prefix = trim( (string) get_option( 'katina_link_prefix', 'go' ), '/' );
        $rows   = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_name, p.post_status, pm.meta_value AS destination
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
             WHERE p.post_type = 'katina_link' AND p.post_status IN ('publish', 'draft')
             ORDER BY p.ID ASC",
            '_katina_destination_url'
        ) );

        // 'draft' marks destination keys whose link is a draft, which only a
        // draft coupon may reuse. A published link wins over a draft one.
        $index = array( 'destination' => array(), 'draft' => array(), 'cloaked' => array() );
        foreach ( (array) $rows as $row ) {
            $is_draft = 'draft' === $row->post_status;
            if ( $row->destination ) {
                $key = self::normalize_url( $row->destination );
                if ( ! isset( $index['destination'][ $key ] ) || ( isset( $index['draft'][ $key ] ) && ! $is_draft ) ) {
                    $index['destination'][ $key ] = (int) $row->ID;
                    if ( $is_draft ) {
                        $index['draft'][ $key ] = true;
                    } else {
                        unset( $index['draft'][ $key ] );
                    }
                }
            }
            if ( ! $is_draft ) {
                $cloaked = self::normalize_url( home_url( $prefix . '/' . $row->post_name . '/' ) );
                $index['cloaked'][ $cloaked ] = (int) $row->ID;
            }
        }
        return $index;
    }

    private static function normalize_url( $url ) {
        $parts = wp_parse_url( trim( (string) $url ) );
        if ( empty( $parts['host'] ) ) {
            return strtolower( trim( (string) $url ) );
        }
        $path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
        $key  = strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . $path;
        if ( isset( $parts['query'] ) ) {
            $key .= '?' . $parts['query'];
        }
        return $key;
    }

    private static function resolve_link( array $mapped, array &$links, $dry_run, $publish ) {
        $none = array( 'action' => 'none', 'bucket' => 'missing', 'id' => 0, 'note' => '' );

        if ( '' === $mapped['url'] ) {
            $none['note'] = __( 'no URL on coupon or vendor', 'katina-link-manager-pro' );
            return $none;
        }
        if ( ! KATINA_Link::is_safe_destination( $mapped['url'] ) ) {
            $none['note'] = __( 'URL is not http(s), no link created', 'katina-link-manager-pro' );
            return $none;
        }

        $key = self::normalize_url( $mapped['url'] );
        if ( isset( $links['destination'][ $key ] ) && ( ! $publish || ! isset( $links['draft'][ $key ] ) ) ) {
            return array( 'action' => 'matched', 'bucket' => 'matched', 'id' => max( 0, $links['destination'][ $key ] ), 'note' => '' );
        }
        if ( isset( $links['cloaked'][ $key ] ) ) {
            return array( 'action' => 'matched', 'bucket' => 'matched', 'id' => $links['cloaked'][ $key ], 'note' => __( 'URL is already a Katina link', 'katina-link-manager-pro' ) );
        }

        if ( $dry_run ) {
            $links['destination'][ $key ] = -1;
            self::mark_draft( $links, $key, ! $publish );
            return array( 'action' => 'would_create', 'bucket' => 'created', 'id' => 0, 'note' => '' );
        }

        $link_id = wp_insert_post( array(
            'post_type'   => 'katina_link',
            'post_status' => $publish ? 'publish' : 'draft',
            'post_title'  => $mapped['title'],
        ), true );

        if ( is_wp_error( $link_id ) ) {
            $none['note'] = $link_id->get_error_message();
            return $none;
        }

        $link = new KATINA_Link( $link_id );
        $link->save_meta( array(
            'destination_url' => esc_url_raw( $mapped['url'] ),
            'redirect_type'   => 'default',
            'nofollow'        => 'default',
            'sponsored'       => 'default',
            'new_window'      => 'default',
            'pass_query_str'  => 'default',
        ) );

        $links['destination'][ $key ] = $link_id;
        self::mark_draft( $links, $key, ! $publish );
        return array( 'action' => 'created', 'bucket' => 'created', 'id' => $link_id, 'note' => '' );
    }

    private static function mark_draft( array &$links, $key, $is_draft ) {
        if ( $is_draft ) {
            $links['draft'][ $key ] = true;
        } else {
            unset( $links['draft'][ $key ] );
        }
    }

    /**
     * A draft link the importer made for a draft coupon goes live when that
     * same coupon does. The marker holds the owning coupon's ID, so pointing
     * another coupon at the link doesn't publish it.
     */
    public static function publish_draft_link( $new_status, $old_status, $post ) {
        if ( 'publish' !== $new_status || 'publish' === $old_status || KATINA_PRO_Coupons::POST_TYPE !== $post->post_type ) {
            return;
        }
        $link_id = (int) get_post_meta( $post->ID, KATINA_PRO_Coupons::META['link_id'], true );
        if ( ! $link_id || 'draft' !== get_post_status( $link_id ) ) {
            return;
        }
        if ( (int) get_post_meta( $link_id, self::DRAFT_LINK, true ) !== (int) $post->ID ) {
            return;
        }
        if ( get_current_user_id() && ! current_user_can( 'publish_post', $link_id ) ) {
            return;
        }
        delete_post_meta( $link_id, self::DRAFT_LINK );
        wp_publish_post( $link_id );
    }

    private static function create_coupon( WP_Post $source, array $mapped, $link_id, $publish ) {
        $coupon_id = wp_insert_post( array(
            'post_type'     => KATINA_PRO_Coupons::POST_TYPE,
            'post_status'   => $publish ? 'publish' : 'draft',
            'post_title'    => $mapped['title'],
            'post_date'     => $source->post_date,
            'post_date_gmt' => $source->post_date_gmt,
            'menu_order'    => $source->menu_order,
        ), true );

        if ( is_wp_error( $coupon_id ) ) {
            return $coupon_id;
        }

        KATINA_PRO_Coupons::write_meta( $coupon_id, array(
            'description' => $mapped['description'],
            'code'        => $mapped['code'],
            'discount'    => $mapped['discount'],
            'link_id'     => $link_id ? (string) $link_id : '',
            'starts'      => $mapped['starts'],
            'expires'     => $mapped['expires'],
            'source_id'   => (string) $source->ID,
        ) );

        if ( $mapped['image_id'] ) {
            set_post_thumbnail( $coupon_id, $mapped['image_id'] );
        }
        foreach ( $mapped['terms'] as $taxonomy => $names ) {
            if ( $names ) {
                wp_set_object_terms( $coupon_id, $names, $taxonomy );
            }
        }

        return $coupon_id;
    }
}
