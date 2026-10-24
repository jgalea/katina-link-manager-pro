<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Vars provided by KATINA_PRO_Link_Opportunities::render_page():
 *  $orphans       (array of report rows with incoming = 0)
 *  $opportunities (array orphan_id => array of candidate source posts, or null when not scanned yet)
 *  $pending       (int orphans left unscanned on this load)
 *  $post_types    (array of post type names)
 *  $post_type     (string current filter)
 *  $has_index     (bool)
 */
$inserted = isset( $_GET['inserted'] ) ? absint( $_GET['inserted'] ) : null;
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Link Opportunities', 'katina-link-manager-pro' ); ?></h1>
    <p class="description">
        <?php esc_html_e( 'Pages with no incoming internal links, paired with posts that already mention them. Inserting a link edits the source post content at the first unlinked mention.', 'katina-link-manager-pro' ); ?>
    </p>

    <?php if ( $inserted === 1 ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Link inserted.', 'katina-link-manager-pro' ); ?></p></div>
    <?php elseif ( $inserted === 0 ) : ?>
        <div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Nothing was inserted. The mention may already be inside a link or a tag.', 'katina-link-manager-pro' ); ?></p></div>
    <?php endif; ?>

    <?php if ( ! $has_index ) : ?>
        <div class="notice notice-info">
            <p>
                <?php
                printf(
                    /* translators: %s: link to the Link Index screen */
                    wp_kses_post( __( 'Run a scan on the %s screen first. Opportunities are built from the link index.', 'katina-link-manager-pro' ) ),
                    '<a href="' . esc_url( KATINA_Link_Report::report_url() ) . '">' . esc_html__( 'Link Index', 'katina-link-manager-pro' ) . '</a>'
                );
                ?>
            </p>
        </div>
        </div>
        <?php return; ?>
    <?php endif; ?>

    <form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" style="margin:16px 0;">
        <input type="hidden" name="post_type" value="katina_link" />
        <input type="hidden" name="page" value="<?php echo esc_attr( KATINA_PRO_Link_Opportunities::PAGE_SLUG ); ?>" />
        <select name="post_type_filter" onchange="this.form.submit()">
            <option value=""><?php esc_html_e( 'All post types', 'katina-link-manager-pro' ); ?></option>
            <?php foreach ( $post_types as $type ) : ?>
                <?php $obj = get_post_type_object( $type ); ?>
                <option value="<?php echo esc_attr( $type ); ?>" <?php selected( $type, $post_type ); ?>>
                    <?php echo esc_html( $obj ? $obj->labels->name : $type ); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php if ( $pending > 0 ) : ?>
        <div class="notice notice-info inline">
            <p>
                <?php
                printf(
                    /* translators: %d: number of pages not checked yet */
                    esc_html( _n( '%d page has not been checked for mentions yet. Reload the page to check more.', '%d pages have not been checked for mentions yet. Reload the page to check more.', $pending, 'katina-link-manager-pro' ) ),
                    (int) $pending
                );
                ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ( empty( $orphans ) ) : ?>
        <p><?php esc_html_e( 'No orphans found. Every indexed page has at least one incoming internal link.', 'katina-link-manager-pro' ); ?></p>
    <?php else : ?>
        <?php foreach ( $orphans as $orphan ) : ?>
            <?php
            $orphan_id  = (int) $orphan['ID'];
            $scanned    = ! array_key_exists( $orphan_id, $opportunities ) || null !== $opportunities[ $orphan_id ];
            $candidates = $scanned && isset( $opportunities[ $orphan_id ] ) ? $opportunities[ $orphan_id ] : array();
            ?>
            <div style="background:#fff;border:1px solid #c3c4c7;padding:14px 16px;margin-bottom:14px;">
                <h2 style="margin:0 0 4px;font-size:15px;">
                    <a href="<?php echo esc_url( get_edit_post_link( $orphan_id ) ); ?>"><?php echo esc_html( $orphan['post_title'] !== '' ? $orphan['post_title'] : __( '(no title)', 'katina-link-manager-pro' ) ); ?></a>
                    <span style="font-weight:normal;color:#666;font-size:12px;">
                        <?php echo esc_html( $orphan['post_type'] . ' · ' . $orphan['post_status'] ); ?>
                    </span>
                </h2>

                <?php if ( ! $scanned ) : ?>
                    <p style="color:#666;margin:6px 0 0;">
                        <?php esc_html_e( 'Not checked yet.', 'katina-link-manager-pro' ); ?>
                    </p>
                <?php elseif ( empty( $candidates ) ) : ?>
                    <p style="color:#666;margin:6px 0 0;">
                        <?php esc_html_e( 'No post mentions this page by title. Set a custom keyword on the post to widen the match.', 'katina-link-manager-pro' ); ?>
                    </p>
                <?php else : ?>
                    <p style="margin:6px 0;">
                        <?php
                        printf(
                            /* translators: %s: the phrase being matched */
                            esc_html__( 'Matching on “%s”:', 'katina-link-manager-pro' ),
                            esc_html( $candidates[0]['keyword'] )
                        );
                        ?>
                    </p>
                    <table class="wp-list-table widefat striped" style="margin-top:6px;">
                        <tbody>
                            <?php foreach ( $candidates as $candidate ) : ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo esc_url( get_edit_post_link( (int) $candidate['ID'] ) ); ?>"><?php echo esc_html( $candidate['post_title'] ); ?></a>
                                        <span style="color:#666;font-size:12px;">
                                            <?php echo esc_html( $candidate['post_type'] . ' · ' . $candidate['post_status'] ); ?>
                                        </span>
                                    </td>
                                    <td style="width:140px;text-align:right;">
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                            <?php wp_nonce_field( 'katinapro_insert_internal_link' ); ?>
                                            <input type="hidden" name="action" value="katinapro_insert_internal_link" />
                                            <input type="hidden" name="source_id" value="<?php echo esc_attr( (string) $candidate['ID'] ); ?>" />
                                            <input type="hidden" name="target_id" value="<?php echo esc_attr( (string) $orphan_id ); ?>" />
                                            <input type="hidden" name="keyword" value="<?php echo esc_attr( $candidate['keyword'] ); ?>" />
                                            <button type="submit" class="button button-small"><?php esc_html_e( 'Insert link', 'katina-link-manager-pro' ); ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
