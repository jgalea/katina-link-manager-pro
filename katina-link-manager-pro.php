<?php
/**
 * Plugin Name: Katina Link Manager Pro
 * Description: Pro add-on for Katina Link Manager. Adds auto-linking, content gap analysis, AI suggestions, coupons and deals, and a REST API.
 * Version: 1.3.2
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Author: RebelCode
 * Author URI: https://rebelcode.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: katina-link-manager-pro
 * Domain Path: /languages
 * Update URI: https://github.com/jgalea/katina-link-manager-pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'KATINA_PRO_VERSION', '1.3.2' );
define( 'KATINA_PRO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KATINA_PRO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'KATINA_PRO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

register_activation_hook( __FILE__, 'katinapro_activate' );
register_deactivation_hook( __FILE__, 'katinapro_deactivate' );

function katinapro_activate() {
    // Revenue tracking needs its own table — create it on activation.
    require_once KATINA_PRO_PLUGIN_DIR . 'includes/class-katina-pro-revenue.php';
    KATINA_PRO_Revenue::create_table();

    wp_unschedule_hook( 'katinapro_content_gaps_scan' );
}

function katinapro_deactivate() {
    // Clear any scheduled Pro crons so they don't keep firing after deactivation.
    $hooks = array( 'katinapro_content_gaps_scan', 'katinapro_autolink_refresh' );
    foreach ( $hooks as $hook ) {
        wp_unschedule_hook( $hook );
    }
}

require_once KATINA_PRO_PLUGIN_DIR . 'includes/updater.php';

add_action( 'plugins_loaded', 'katinapro_init' );

function katinapro_init() {
    if ( ! class_exists( 'Katina_Link_Manager' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Katina Link Manager Pro requires the Katina Link Manager plugin to be installed and active.', 'katina-link-manager-pro' ) . '</p></div>';
        } );
        return;
    }

    spl_autoload_register( function ( $class ) {
        $prefix = 'KATINA_PRO_';
        if ( strpos( $class, $prefix ) !== 0 ) {
            return;
        }
        $relative = substr( $class, strlen( $prefix ) );
        $filename = 'class-katina-pro-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
        $file     = KATINA_PRO_PLUGIN_DIR . 'includes/' . $filename;
        if ( file_exists( $file ) ) {
            require_once $file;
        }
    } );

    if ( is_admin() ) {
        KATINA_PRO_Settings::init();
        KATINA_PRO_Link_Opportunities::init();
        KATINA_PRO_Coupons_Importer::init();
    }

    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        KATINA_PRO_Coupons_CLI::register();
    }

    KATINA_PRO_Link_Opportunities::init_cache_invalidation();
    KATINA_PRO_Auto_Linker::init();
    KATINA_PRO_Content_Gaps::init();
    KATINA_PRO_Coupons::init();
    KATINA_PRO_Affcoups_Compat::init();
    KATINA_PRO_REST_API::init();
    KATINA_PRO_Revenue::init();
    KATINA_PRO_Suggestions::init();

    add_action( 'enqueue_block_editor_assets', 'katinapro_enqueue_sidebar' );
}

function katinapro_enqueue_sidebar() {
    if ( get_option( 'katinapro_enable_suggestions', 'no' ) !== 'yes' ) {
        return;
    }

    wp_enqueue_script(
        'katina-pro-sidebar',
        KATINA_PRO_PLUGIN_URL . 'assets/js/sidebar.js',
        array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-blocks', 'wp-api-fetch' ),
        KATINA_PRO_VERSION,
        true
    );
}
