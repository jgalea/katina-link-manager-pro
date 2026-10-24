<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_PRO_REST_API {

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    const REFRESH_RATE_WINDOW = 3600;  // 1 hour
    const REFRESH_RATE_MAX    = 10;    // refreshes per user per hour

    public static function register_routes() {
        $args = array(
            'post_id' => array(
                'validate_callback' => function ( $param ) {
                    return is_numeric( $param );
                },
                'sanitize_callback' => 'absint',
            ),
        );

        register_rest_route( 'katina-link-manager-pro/v1', '/suggestions/(?P<post_id>\d+)', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'get_suggestions' ),
            'permission_callback' => array( __CLASS__, 'check_post_permission' ),
            'args'                => $args,
        ) );

        register_rest_route( 'katina-link-manager-pro/v1', '/suggestions/(?P<post_id>\d+)/refresh', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'refresh_suggestions' ),
            'permission_callback' => array( __CLASS__, 'check_post_permission' ),
            'args'                => $args,
        ) );
    }

    /**
     * Require edit_post on the SPECIFIC post — not just generic edit_posts.
     * Without this, any contributor could query/refresh suggestions for posts
     * they don't own (and ship that content to the AI provider).
     */
    public static function check_post_permission( WP_REST_Request $request ) {
        $post_id = absint( $request->get_param( 'post_id' ) );
        if ( ! $post_id ) {
            return false;
        }
        $post = get_post( $post_id );
        if ( ! $post ) {
            return false;
        }
        return current_user_can( 'edit_post', $post_id );
    }

    /**
     * A GET only calls the AI provider when the post changed since the last
     * call, and that call counts against the same per-user limit as a refresh.
     * Over the limit it serves the last cached suggestions instead.
     */
    public static function get_suggestions( WP_REST_Request $request ) {
        $post_id = (int) $request->get_param( 'post_id' );

        if ( KATINA_PRO_Suggestions::has_fresh_cache( $post_id ) || ! self::consume_ai_call() ) {
            return rest_ensure_response( KATINA_PRO_Suggestions::get_cached( $post_id ) );
        }

        return rest_ensure_response( KATINA_PRO_Suggestions::get_suggestions( $post_id ) );
    }

    /**
     * Per-user rate limit on AI calls. Returns false when the limit is used up.
     */
    private static function consume_ai_call() {
        $key  = 'katinapro_refresh_rl_' . get_current_user_id();
        $hits = (int) get_transient( $key );
        if ( $hits >= self::REFRESH_RATE_MAX ) {
            return false;
        }
        set_transient( $key, $hits + 1, self::REFRESH_RATE_WINDOW );
        return true;
    }

    public static function refresh_suggestions( WP_REST_Request $request ) {
        $post_id = (int) $request->get_param( 'post_id' );

        if ( ! self::consume_ai_call() ) {
            return new WP_Error(
                'katinapro_rate_limited',
                __( 'Refresh rate limit reached. Try again later.', 'katina-link-manager-pro' ),
                array( 'status' => 429 )
            );
        }

        delete_post_meta( $post_id, KATINA_PRO_Suggestions::CACHE_HASH_KEY );

        $suggestions = KATINA_PRO_Suggestions::get_suggestions( $post_id );

        return rest_ensure_response( $suggestions );
    }
}
