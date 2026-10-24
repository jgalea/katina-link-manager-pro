<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_PRO_AI_Client {

    /**
     * Resolve the API key.
     * Prefer KATINA_PRO_AI_API_KEY constant in wp-config.php (Akismet/WC pattern) so
     * the key isn't sitting in wp_options where every admin can see it and
     * every backup includes it. Fall back to the option only if the constant
     * isn't defined.
     */
    public static function get_api_key() {
        if ( defined( 'KATINA_PRO_AI_API_KEY' ) && KATINA_PRO_AI_API_KEY ) {
            return (string) KATINA_PRO_AI_API_KEY;
        }
        return (string) get_option( 'katinapro_ai_api_key', '' );
    }

    public static function api_key_is_constant() {
        return defined( 'KATINA_PRO_AI_API_KEY' ) && KATINA_PRO_AI_API_KEY;
    }

    public static function query( $system_prompt, $user_message ) {
        $provider = get_option( 'katinapro_ai_provider', 'openai' );
        $api_key  = self::get_api_key();

        if ( empty( $api_key ) ) {
            return new WP_Error( 'katinapro_no_api_key', __( 'No AI API key configured.', 'katina-link-manager-pro' ) );
        }

        if ( 'anthropic' === $provider ) {
            return self::query_anthropic( $api_key, $system_prompt, $user_message );
        }

        return self::query_openai( $api_key, $system_prompt, $user_message );
    }

    private static function query_openai( $api_key, $system, $user_msg ) {
        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model'      => 'gpt-4o-mini',
                'temperature' => 0.3,
                'max_tokens' => 1024,
                'messages'   => array(
                    array( 'role' => 'system', 'content' => $system ),
                    array( 'role' => 'user',   'content' => $user_msg ),
                ),
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['choices'][0]['message']['content'] ) ) {
            return new WP_Error( 'katinapro_openai_error', __( 'Unexpected response from OpenAI.', 'katina-link-manager-pro' ) );
        }

        return $body['choices'][0]['message']['content'];
    }

    private static function query_anthropic( $api_key, $system, $user_msg ) {
        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
            'timeout' => 30,
            'headers' => array(
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => 1024,
                'system'     => $system,
                'messages'   => array(
                    array( 'role' => 'user', 'content' => $user_msg ),
                ),
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['content'][0]['text'] ) ) {
            return new WP_Error( 'katinapro_anthropic_error', __( 'Unexpected response from Anthropic.', 'katina-link-manager-pro' ) );
        }

        return $body['content'][0]['text'];
    }
}
