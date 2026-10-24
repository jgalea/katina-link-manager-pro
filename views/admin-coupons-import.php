<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Vars provided by KATINA_PRO_Coupons_Importer::render_page():
 *  $source_count (int)
 *  $report       (array|false)
 */
$labels = array(
    'created'      => __( 'Created', 'katina-link-manager-pro' ),
    'would_create' => __( 'Would create', 'katina-link-manager-pro' ),
    'skipped'      => __( 'Skipped', 'katina-link-manager-pro' ),
    'failed'       => __( 'Failed', 'katina-link-manager-pro' ),
    'matched'      => __( 'Matched existing', 'katina-link-manager-pro' ),
    'none'         => __( 'No link', 'katina-link-manager-pro' ),
);
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Import Coupons', 'katina-link-manager-pro' ); ?></h1>
    <p class="description">
        <?php esc_html_e( 'Copies coupons from the Affiliate Coupons plugin into Katina Coupons. Each coupon URL is matched to an existing Katina link by destination; if none matches, a link is created. Coupons already imported are skipped, so running this again is safe.', 'katina-link-manager-pro' ); ?>
    </p>

    <?php if ( 0 === $source_count ) : ?>
        <div class="notice notice-info inline"><p><?php esc_html_e( 'No Affiliate Coupons posts were found on this site.', 'katina-link-manager-pro' ); ?></p></div>
    <?php else : ?>
        <p>
            <?php
            /* translators: %d: number of source coupons */
            printf( esc_html( _n( '%d Affiliate Coupons coupon found.', '%d Affiliate Coupons coupons found.', $source_count, 'katina-link-manager-pro' ) ), (int) $source_count );
            ?>
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr( KATINA_PRO_Coupons_Importer::ACTION ); ?>" />
            <?php wp_nonce_field( KATINA_PRO_Coupons_Importer::ACTION ); ?>
            <p>
                <label>
                    <input type="checkbox" name="dry_run" value="1" checked="checked" />
                    <?php esc_html_e( 'Dry run (report only, write nothing)', 'katina-link-manager-pro' ); ?>
                </label>
            </p>
            <?php submit_button( __( 'Run import', 'katina-link-manager-pro' ) ); ?>
        </form>
    <?php endif; ?>

    <?php if ( is_array( $report ) && ! empty( $report['rows'] ) ) : ?>
        <?php $c = $report['counts']; ?>
        <h2><?php echo esc_html( $report['dry_run'] ? __( 'Dry run result', 'katina-link-manager-pro' ) : __( 'Import result', 'katina-link-manager-pro' ) ); ?></h2>
        <p>
            <?php
            printf(
                $report['dry_run']
                    /* translators: 1: coupons to create, 2: skipped, 3: failed, 4: links matched, 5: links to create, 6: coupons without a link */
                    ? esc_html__( 'Coupons: %1$d would be created, %2$d skipped, %3$d failed. Links: %4$d matched, %5$d would be created, %6$d without a link.', 'katina-link-manager-pro' )
                    /* translators: 1: coupons created, 2: skipped, 3: failed, 4: links matched, 5: links created, 6: coupons without a link */
                    : esc_html__( 'Coupons: %1$d created, %2$d skipped, %3$d failed. Links: %4$d matched, %5$d new, %6$d without a link.', 'katina-link-manager-pro' ),
                (int) $c['coupons_created'],
                (int) $c['coupons_skipped'],
                (int) $c['coupons_failed'],
                (int) $c['links_matched'],
                (int) $c['links_created'],
                (int) $c['links_missing']
            );
            ?>
        </p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Source ID', 'katina-link-manager-pro' ); ?></th>
                    <th><?php esc_html_e( 'Title', 'katina-link-manager-pro' ); ?></th>
                    <th><?php esc_html_e( 'Coupon', 'katina-link-manager-pro' ); ?></th>
                    <th><?php esc_html_e( 'Link', 'katina-link-manager-pro' ); ?></th>
                    <th><?php esc_html_e( 'Note', 'katina-link-manager-pro' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $report['rows'] as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $row['source_id'] ); ?></td>
                        <td><?php echo esc_html( $row['title'] ); ?></td>
                        <td><?php echo esc_html( isset( $labels[ $row['coupon'] ] ) ? $labels[ $row['coupon'] ] : $row['coupon'] ); ?></td>
                        <td><?php echo esc_html( isset( $labels[ $row['link'] ] ) ? $labels[ $row['link'] ] : $row['link'] ); ?></td>
                        <td><?php echo esc_html( $row['note'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
