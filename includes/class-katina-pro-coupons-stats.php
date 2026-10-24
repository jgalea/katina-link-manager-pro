<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Counts coupon views, copies and clicks from a public REST endpoint. Pages
 * are served from full-page caches, so requests carry no nonce; abuse is
 * limited by a per-coupon cap per minute and, when IP collection is on, a
 * short per-visitor throttle (an HMAC of the IP, never the IP itself).
 */
class KATINA_PRO_Coupons_Stats {

    const ROUTE_NAMESPACE = 'katina-link-manager-pro/v1';
    const ROUTE           = '/coupon-event';

    const EVENTS = array(
        'view'  => '_katina_coupon_views',
        'copy'  => '_katina_coupon_copies',
        'click' => '_katina_coupon_clicks',
    );

    const VISITOR_WINDOW    = 60;
    const COUPON_MINUTE_CAP = 120;

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );

        if ( is_admin() ) {
            add_action( 'add_meta_boxes_' . KATINA_PRO_Coupons::POST_TYPE, array( __CLASS__, 'add_meta_box' ) );
            add_filter( 'manage_' . KATINA_PRO_Coupons::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_columns' ), 20 );
            add_action( 'manage_' . KATINA_PRO_Coupons::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
        }
    }

    public static function register_route() {
        register_rest_route( self::ROUTE_NAMESPACE, self::ROUTE, array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'record' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'coupon_id' => array(
                    'type'     => 'integer',
                    'required' => true,
                    'minimum'  => 1,
                ),
                'event'     => array(
                    'type'     => 'string',
                    'required' => true,
                    'enum'     => array_keys( self::EVENTS ),
                ),
            ),
        ) );
    }

    // Some hosts and firewalls refuse POSTs to /wp-json/, so beacons use the ?rest_route= form WordPress always serves.
    public static function endpoint_url() {
        return add_query_arg( 'rest_route', '/' . self::ROUTE_NAMESPACE . self::ROUTE, home_url( '/' ) );
    }

    public static function record( WP_REST_Request $request ) {
        $coupon_id = (int) $request['coupon_id'];
        $event     = (string) $request['event'];
        $post      = get_post( $coupon_id );

        if ( ! $post || KATINA_PRO_Coupons::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status || ! KATINA_PRO_Coupons::is_in_schedule( $coupon_id ) ) {
            return new WP_Error( 'katina_pro_invalid_coupon', __( 'Invalid coupon.', 'katina-link-manager-pro' ), array( 'status' => 404 ) );
        }

        if ( self::is_editor() || KATINA_Bot_Detector::is_bot() ) {
            return rest_ensure_response( array( 'counted' => false ) );
        }

        if ( ! self::claim( $coupon_id, $event ) ) {
            return new WP_Error( 'katina_pro_throttled', __( 'Too many events.', 'katina-link-manager-pro' ), array( 'status' => 429 ) );
        }

        self::increment( $coupon_id, self::EVENTS[ $event ] );
        return rest_ensure_response( array( 'counted' => true ) );
    }

    /**
     * Beacons carry cookies but no REST nonce, so WordPress treats them as
     * logged out. Read the logged-in cookie directly to leave editors out.
     */
    private static function is_editor() {
        $user_id = wp_validate_auth_cookie( '', 'logged_in' );
        if ( ! $user_id ) {
            return false;
        }
        $type = get_post_type_object( KATINA_PRO_Coupons::POST_TYPE );
        return $type && user_can( $user_id, $type->cap->edit_posts );
    }

    private static function claim( $coupon_id, $event ) {
        $ip = self::client_ip();

        if ( '' !== $ip ) {
            $visitor_key = 'katinapro_cev_' . wp_hash( $ip . '|' . $coupon_id . '|' . $event, 'nonce' );
            if ( false !== get_transient( $visitor_key ) ) {
                return false;
            }
        }

        $cap_key = 'katinapro_cev_cap_' . $coupon_id . '_' . $event;
        $minute  = (int) floor( time() / MINUTE_IN_SECONDS );
        $state   = get_transient( $cap_key );
        $count   = is_array( $state ) && isset( $state[0], $state[1] ) && (int) $state[0] === $minute ? (int) $state[1] : 0;
        if ( $count >= self::COUPON_MINUTE_CAP ) {
            return false;
        }
        set_transient( $cap_key, array( $minute, $count + 1 ), 2 * MINUTE_IN_SECONDS );

        if ( '' !== $ip ) {
            set_transient( $visitor_key, 1, self::VISITOR_WINDOW );
        }
        return true;
    }

    // Forwarded headers such as CF-Connecting-IP can be sent by anyone who reaches the origin directly.
    private static function client_ip() {
        if ( 'yes' === get_option( 'katina_disable_ip_collection', 'yes' ) || empty( $_SERVER['REMOTE_ADDR'] ) ) {
            return '';
        }
        $ip = filter_var( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ), FILTER_VALIDATE_IP );
        return $ip ? $ip : '';
    }

    private static function increment( $coupon_id, $meta_key ) {
        global $wpdb;

        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s",
            $coupon_id,
            $meta_key
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        if ( ! $wpdb->query( $sql ) && ! add_post_meta( $coupon_id, $meta_key, 1, true ) ) {
            $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        }

        wp_cache_delete( $coupon_id, 'post_meta' );
    }

    public static function get_counts( $post_id ) {
        $counts = array();
        foreach ( self::EVENTS as $event => $meta_key ) {
            $counts[ $event ] = (int) get_post_meta( $post_id, $meta_key, true );
        }
        return $counts;
    }

    private static function labels() {
        return array(
            'view'  => __( 'Views', 'katina-link-manager-pro' ),
            'copy'  => __( 'Copies', 'katina-link-manager-pro' ),
            'click' => __( 'Clicks', 'katina-link-manager-pro' ),
        );
    }

    public static function add_meta_box() {
        add_meta_box(
            'katina-coupon-stats',
            __( 'Coupon stats', 'katina-link-manager-pro' ),
            array( __CLASS__, 'render_meta_box' ),
            KATINA_PRO_Coupons::POST_TYPE,
            'side'
        );
    }

    public static function render_meta_box( $post ) {
        $counts = self::get_counts( $post->ID );
        ?>
        <table class="widefat striped" role="presentation">
            <tbody>
                <?php foreach ( self::labels() as $event => $label ) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html( $label ); ?></th>
                        <td><?php echo esc_html( number_format_i18n( $counts[ $event ] ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description"><?php esc_html_e( 'Copies include code reveals. Logged-in editors are not counted.', 'katina-link-manager-pro' ); ?></p>
        <?php
    }

    public static function add_columns( $columns ) {
        $stats = array();
        foreach ( self::labels() as $event => $label ) {
            $stats[ 'katina_coupon_stat_' . $event ] = $label;
        }

        if ( ! isset( $columns['date'] ) ) {
            return $columns + $stats;
        }

        $new = array();
        foreach ( $columns as $key => $label ) {
            if ( 'date' === $key ) {
                $new += $stats;
            }
            $new[ $key ] = $label;
        }
        return $new;
    }

    public static function render_column( $column, $post_id ) {
        foreach ( self::EVENTS as $event => $meta_key ) {
            if ( 'katina_coupon_stat_' . $event === $column ) {
                echo esc_html( number_format_i18n( (int) get_post_meta( $post_id, $meta_key, true ) ) );
                return;
            }
        }
    }
}
