<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Delete every Pro-namespaced option (prefix-anchored).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'katinapro\_%'" );

// Delete every Pro-namespaced post meta (prefix-anchored).
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_katinapro\_%'" );

// Delete coupons, their meta and their terms (taxonomies aren't registered during uninstall, so go through SQL).
$coupon_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'katina_coupon'" );
foreach ( $coupon_ids as $coupon_id ) {
    wp_delete_post( (int) $coupon_id, true );
}
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_katina\_coupon\_%'" );
$coupon_term_ids = $wpdb->get_col( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ('katina_coupon_category', 'katina_coupon_tag')" );
foreach ( $coupon_term_ids as $term_id ) {
    $wpdb->delete( $wpdb->termmeta, array( 'term_id' => (int) $term_id ) );
}
$wpdb->query( "DELETE tr FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy IN ('katina_coupon_category', 'katina_coupon_tag')" );
$wpdb->query( "DELETE t FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy IN ('katina_coupon_category', 'katina_coupon_tag')" );
$wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ('katina_coupon_category', 'katina_coupon_tag')" );

// Drop Pro tables. The link index belongs to the free plugin, so it stays.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}katina_revenue" );

// Clear scheduled cron events.
$hooks = array( 'katinapro_content_gaps_scan', 'katinapro_autolink_refresh' );
foreach ( $hooks as $hook ) {
    wp_unschedule_hook( $hook );
}

// Drop transient keys (rate limits, caches).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_katinapro\_%' OR option_name LIKE '\_transient\_timeout\_katinapro\_%'" );
