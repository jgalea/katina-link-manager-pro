<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_PRO_Coupons_CLI {

    public static function register() {
        WP_CLI::add_command( 'katina-link-manager import-coupons', array( __CLASS__, 'import' ) );
    }

    /**
     * Import coupons from the Affiliate Coupons plugin.
     *
     * Each coupon URL is matched to an existing Katina link by destination; if none matches, a link is created.
     * Coupons that were already imported are skipped, so the command is safe to re-run.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report what would happen without writing anything.
     *
     * [--limit=<n>]
     * : Only process the first <n> source coupons.
     *
     * ## EXAMPLES
     *
     *     wp katina-link-manager import-coupons --dry-run
     *     wp katina-link-manager import-coupons
     */
    public static function import( $args, $assoc_args ) {
        $dry_run = isset( $assoc_args['dry-run'] );
        $report  = KATINA_PRO_Coupons_Importer::run( array(
            'dry_run' => $dry_run,
            'limit'   => isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 0,
        ) );

        if ( empty( $report['rows'] ) ) {
            WP_CLI::warning( 'No Affiliate Coupons posts found.' );
            return;
        }

        WP_CLI\Utils\format_items( 'table', $report['rows'], array( 'source_id', 'title', 'coupon', 'coupon_id', 'link', 'link_id', 'note' ) );

        $c = $report['counts'];
        WP_CLI::success( sprintf(
            '%s: coupons %d, skipped %d, failed %d. Links: %d matched, %d new, %d without a link.',
            $dry_run ? 'Dry run (nothing written)' : 'Done',
            $c['coupons_created'],
            $c['coupons_skipped'],
            $c['coupons_failed'],
            $c['links_matched'],
            $c['links_created'],
            $c['links_missing']
        ) );
    }
}
