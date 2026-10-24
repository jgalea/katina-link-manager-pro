<?php
defined( 'ABSPATH' ) || exit;

const KATINAPRO_REPO = 'jgalea/katina-link-manager-pro';

function katinapro_latest_release() {
	$release = get_site_transient( 'katinapro_release' );
	if ( false !== $release ) {
		return $release ? $release : null;
	}

	$release  = array();
	$response = wp_remote_get(
		'https://api.github.com/repos/' . KATINAPRO_REPO . '/releases/latest',
		array(
			'timeout' => 10,
			'headers' => array( 'Accept' => 'application/vnd.github+json' ),
		)
	);

	if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			if ( 'katina-link-manager-pro.zip' === ( $asset['name'] ?? '' ) ) {
				$release = array(
					'version' => ltrim( (string) $data['tag_name'], 'v' ),
					'package' => $asset['browser_download_url'],
					'url'     => $data['html_url'],
					'notes'   => (string) ( $data['body'] ?? '' ),
				);
				break;
			}
		}
	}

	set_site_transient( 'katinapro_release', $release, $release ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );

	return $release ? $release : null;
}

// Hooked through the plugin's Update URI header, so WordPress.org never answers for this slug.
function katinapro_check_update( $update, $plugin_data, $plugin_file ) {
	if ( KATINA_PRO_PLUGIN_BASENAME !== $plugin_file ) {
		return $update;
	}

	$release = katinapro_latest_release();
	if ( ! $release ) {
		return $update;
	}

	return array(
		'slug'    => dirname( KATINA_PRO_PLUGIN_BASENAME ),
		'version' => $release['version'],
		'url'     => $release['url'],
		'package' => $release['package'],
	);
}
add_filter( 'update_plugins_github.com', 'katinapro_check_update', 10, 3 );

function katinapro_plugin_info( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || dirname( KATINA_PRO_PLUGIN_BASENAME ) !== ( $args->slug ?? '' ) ) {
		return $result;
	}

	$release = katinapro_latest_release();
	if ( ! $release ) {
		return $result;
	}

	return (object) array(
		'name'          => 'Katina Link Manager Pro',
		'slug'          => dirname( KATINA_PRO_PLUGIN_BASENAME ),
		'version'       => $release['version'],
		'author'        => '<a href="https://rebelcode.com/">RebelCode</a>',
		'homepage'      => 'https://github.com/' . KATINAPRO_REPO,
		'download_link' => $release['package'],
		'requires'      => '6.2',
		'requires_php'  => '8.0',
		'sections'      => array( 'changelog' => wp_kses_post( wpautop( $release['notes'] ) ) ),
	);
}
add_filter( 'plugins_api', 'katinapro_plugin_info', 10, 3 );

function katinapro_clear_release_cache( $upgrader, $options ) {
	if ( 'update' === ( $options['action'] ?? '' ) && 'plugin' === ( $options['type'] ?? '' ) ) {
		delete_site_transient( 'katinapro_release' );
	}
}
add_action( 'upgrader_process_complete', 'katinapro_clear_release_cache', 10, 2 );
