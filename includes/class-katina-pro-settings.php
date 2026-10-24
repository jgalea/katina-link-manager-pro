<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_PRO_Settings {

    const OPTION_GROUP = 'katinapro_settings_group';
    const PAGE_SLUG    = 'katina-settings-pro';

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
    }

    public static function register_settings() {
        register_setting( self::OPTION_GROUP, 'katinapro_ai_provider', array(
            'type'              => 'string',
            'sanitize_callback' => array( __CLASS__, 'sanitize_ai_provider' ),
            'default'           => 'openai',
            'show_in_rest'      => false,
        ) );
        register_setting( self::OPTION_GROUP, 'katinapro_ai_api_key', array(
            'type'              => 'string',
            'sanitize_callback' => array( __CLASS__, 'sanitize_api_key' ),
            'default'           => '',
            'autoload'          => false,
            'show_in_rest'      => false,
        ) );
        register_setting( self::OPTION_GROUP, 'katinapro_enable_auto_linking', array(
            'type'              => 'string',
            'sanitize_callback' => array( __CLASS__, 'sanitize_yes_no' ),
            'default'           => 'no',
            'show_in_rest'      => false,
        ) );
        register_setting( self::OPTION_GROUP, 'katinapro_enable_suggestions', array(
            'type'              => 'string',
            'sanitize_callback' => array( __CLASS__, 'sanitize_yes_no' ),
            'default'           => 'no', // explicit opt-in: ships post content to AI provider
            'show_in_rest'      => false,
        ) );
        register_setting( self::OPTION_GROUP, 'katinapro_auto_link_orphans', array(
            'type'              => 'string',
            'sanitize_callback' => array( __CLASS__, 'sanitize_orphan_linking_toggle' ),
            'default'           => 'no',
            'show_in_rest'      => false,
        ) );

        add_settings_section( 'katinapro_ai', __( 'AI Provider', 'katina-link-manager-pro' ), array( __CLASS__, 'section_intro' ), self::PAGE_SLUG );

        add_settings_field( 'katinapro_ai_provider', __( 'AI Provider', 'katina-link-manager-pro' ), array( __CLASS__, 'render_provider' ), self::PAGE_SLUG, 'katinapro_ai' );
        add_settings_field( 'katinapro_ai_api_key', __( 'API Key', 'katina-link-manager-pro' ), array( __CLASS__, 'render_api_key' ), self::PAGE_SLUG, 'katinapro_ai' );

        add_settings_section( 'katinapro_features', __( 'Pro Features', 'katina-link-manager-pro' ), null, self::PAGE_SLUG );

        add_settings_field( 'katinapro_enable_suggestions', __( 'Link Suggestions', 'katina-link-manager-pro' ), array( __CLASS__, 'render_toggle' ), self::PAGE_SLUG, 'katinapro_features', array(
            'option' => 'katinapro_enable_suggestions',
            'default' => 'no',
            'description' => __( 'Show AI-powered link suggestions in the editor sidebar while writing content.', 'katina-link-manager-pro' ),
        ) );
        add_settings_field( 'katinapro_enable_auto_linking', __( 'Smart Auto-Linking', 'katina-link-manager-pro' ), array( __CLASS__, 'render_toggle' ), self::PAGE_SLUG, 'katinapro_features', array(
            'option' => 'katinapro_enable_auto_linking',
            'default' => 'no',
            'description' => __( 'Automatically insert links into post content based on your link library.', 'katina-link-manager-pro' ),
        ) );

        add_settings_field( 'katinapro_auto_link_orphans', __( 'Auto-Link Orphan Pages', 'katina-link-manager-pro' ), array( __CLASS__, 'render_toggle' ), self::PAGE_SLUG, 'katinapro_features', array(
            'option' => 'katinapro_auto_link_orphans',
            'default' => 'no',
            'description' => __( 'Let Smart Auto-Linking also point at orphan pages from the Link Index — content that already mentions a stranded page starts linking to it. Requires Smart Auto-Linking and at least one completed scan.', 'katina-link-manager-pro' ),
        ) );
    }

    public static function sanitize_yes_no( $value ) {
        return $value === 'yes' ? 'yes' : 'no';
    }

    /**
     * The settings form never echoes the stored key, so an empty submission
     * means "unchanged". Clearing it takes the explicit checkbox.
     */
    public static function sanitize_api_key( $value ) {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- options.php has already checked the nonce.
        $from_form = isset( $_POST['option_page'] ) && self::OPTION_GROUP === $_POST['option_page'];
        if ( $from_form && ! empty( $_POST['katinapro_ai_api_key_clear'] ) ) {
            return '';
        }
        // phpcs:enable
        $value = trim( sanitize_text_field( (string) $value ) );
        if ( $from_form && '' === $value ) {
            return (string) get_option( 'katinapro_ai_api_key', '' );
        }
        return $value;
    }

    public static function sanitize_ai_provider( $value ) {
        return in_array( $value, array( 'openai', 'anthropic' ), true ) ? $value : 'openai';
    }

    /**
     * Drop the cached orphan list when the toggle changes, so the new setting
     * takes effect on the next page load rather than after the transient expires.
     */
    public static function sanitize_orphan_linking_toggle( $value ) {
        $clean = self::sanitize_yes_no( $value );
        if ( class_exists( 'KATINA_PRO_Auto_Linker' ) ) {
            KATINA_PRO_Auto_Linker::flush_orphan_cache();
        }
        return $clean;
    }

    public static function section_intro() {
        echo '<p>' . esc_html__( 'Configure your AI provider. The API key is stored on your site and only used to call the provider directly — never sent to Katina Link Manager servers.', 'katina-link-manager-pro' ) . '</p>';
        echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Privacy notice:', 'katina-link-manager-pro' ) . '</strong> ' . esc_html__( 'Enabling Link Suggestions or Smart Auto-Linking with an API key will transmit excerpts of your post content to your selected provider (OpenAI or Anthropic). Add an appropriate disclosure to your site privacy policy before enabling these features in the EU/UK.', 'katina-link-manager-pro' ) . '</p></div>';
    }

    public static function render_provider() {
        $value = get_option( 'katinapro_ai_provider', 'openai' );
        ?>
        <select name="katinapro_ai_provider" id="katinapro_ai_provider">
            <option value="openai" <?php selected( $value, 'openai' ); ?>><?php esc_html_e( 'OpenAI', 'katina-link-manager-pro' ); ?></option>
            <option value="anthropic" <?php selected( $value, 'anthropic' ); ?>><?php esc_html_e( 'Anthropic', 'katina-link-manager-pro' ); ?></option>
        </select>
        <?php
    }

    public static function render_api_key() {
        if ( class_exists( 'KATINA_PRO_AI_Client' ) && KATINA_PRO_AI_Client::api_key_is_constant() ) {
            ?>
            <input type="text" value="<?php esc_attr_e( 'Set via KATINA_PRO_AI_API_KEY constant in wp-config.php', 'katina-link-manager-pro' ); ?>" class="regular-text" disabled />
            <p class="description"><?php esc_html_e( 'Detected the KATINA_PRO_AI_API_KEY constant. The DB-stored value is ignored.', 'katina-link-manager-pro' ); ?></p>
            <?php
            return;
        }
        $has_key = '' !== (string) get_option( 'katinapro_ai_api_key', '' );
        ?>
        <span class="katina-key-input">
            <input type="password" name="katinapro_ai_api_key" id="katinapro_ai_api_key" value="" placeholder="<?php echo $has_key ? esc_attr__( 'Saved. Leave empty to keep it.', 'katina-link-manager-pro' ) : ''; ?>" class="regular-text" autocomplete="new-password" />
            <button type="button" class="button button-secondary katina-toggle-visibility" data-target="katinapro_ai_api_key" aria-label="<?php esc_attr_e( 'Show or hide API key', 'katina-link-manager-pro' ); ?>">
                <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
            </button>
        </span>
        <?php if ( $has_key ) : ?>
            <p>
                <label>
                    <input type="checkbox" name="katinapro_ai_api_key_clear" value="1" />
                    <?php esc_html_e( 'Remove the saved key', 'katina-link-manager-pro' ); ?>
                </label>
            </p>
        <?php endif; ?>
        <p class="description">
            <?php esc_html_e( 'Stored locally. Used only for direct calls to your chosen provider.', 'katina-link-manager-pro' ); ?>
            <br />
            <?php
            printf(
                /* translators: %s: PHP define snippet */
                esc_html__( 'For a more secure setup, define %s in wp-config.php and the field above will be hidden.', 'katina-link-manager-pro' ),
                '<code>define( \'KATINA_PRO_AI_API_KEY\', \'sk-...\' );</code>'
            );
            ?>
        </p>
        <?php
    }

    public static function render_toggle( $args ) {
        $option  = $args['option'];
        $default = isset( $args['default'] ) ? $args['default'] : 'no';
        $value   = get_option( $option, $default );
        ?>
        <select name="<?php echo esc_attr( $option ); ?>" id="<?php echo esc_attr( $option ); ?>">
            <option value="yes" <?php selected( $value, 'yes' ); ?>><?php esc_html_e( 'Enabled', 'katina-link-manager-pro' ); ?></option>
            <option value="no" <?php selected( $value, 'no' ); ?>><?php esc_html_e( 'Disabled', 'katina-link-manager-pro' ); ?></option>
        </select>
        <?php if ( ! empty( $args['description'] ) ) : ?>
            <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
        <?php endif; ?>
        <?php
    }

    public static function render_form() {
        ?>
        <form method="post" action="options.php">
            <?php
            settings_fields( self::OPTION_GROUP );
            do_settings_sections( self::PAGE_SLUG );
            submit_button();
            ?>
        </form>
        <?php
    }
}
