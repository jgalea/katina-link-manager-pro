<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Vars provided by KATINA_PRO_Revenue::render_page():
 *  $selected_ym (string YYYY-MM)
 *  $links       (array of WP_Post)
 *  $existing    (array link_id => row from wp_katina_revenue)
 *  $click_index (array link_id => int human clicks for $selected_ym)
 */
$months = KATINA_PRO_Revenue::recent_months( 12 );
$saved  = isset( $_GET['saved'] ) ? absint( $_GET['saved'] ) : 0;
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Revenue Tracking', 'katina-link-manager-pro' ); ?></h1>
    <p class="description">
        <?php esc_html_e( 'Enter monthly affiliate revenue per link. Clicks shown are human (non-bot) clicks for the selected month from your Katina click log. EPC is revenue ÷ clicks.', 'katina-link-manager-pro' ); ?>
    </p>

    <?php if ( $saved > 0 ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php
                /* translators: %d: number of revenue rows saved */
                printf( esc_html__( 'Saved %d revenue entries.', 'katina-link-manager-pro' ), (int) $saved );
            ?></p>
        </div>
    <?php endif; ?>

    <form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" style="margin: 16px 0;">
        <input type="hidden" name="post_type" value="katina_link" />
        <input type="hidden" name="page" value="<?php echo esc_attr( KATINA_PRO_Revenue::PAGE_SLUG ); ?>" />
        <label for="katinapro-ym"><strong><?php esc_html_e( 'Month:', 'katina-link-manager-pro' ); ?></strong></label>
        <select name="ym" id="katinapro-ym" onchange="this.form.submit()">
            <?php foreach ( $months as $ym ) : ?>
                <option value="<?php echo esc_attr( $ym ); ?>" <?php selected( $ym, $selected_ym ); ?>>
                    <?php echo esc_html( gmdate( 'F Y', strtotime( $ym . '-01' ) ) ); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'katinapro_save_revenue' ); ?>
        <input type="hidden" name="action" value="katinapro_save_revenue" />
        <input type="hidden" name="ym" value="<?php echo esc_attr( $selected_ym ); ?>" />

        <p>
            <label>
                <?php esc_html_e( 'Default currency for blank rows:', 'katina-link-manager-pro' ); ?>
                <select name="default_currency">
                    <?php foreach ( array( 'EUR', 'USD', 'GBP' ) as $cur ) : ?>
                        <option value="<?php echo esc_attr( $cur ); ?>" <?php selected( $cur, KATINA_PRO_Revenue::DEFAULT_CURRENCY ); ?>>
                            <?php echo esc_html( $cur ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </p>

        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e( 'Link', 'katina-link-manager-pro' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Destination', 'katina-link-manager-pro' ); ?></th>
                    <th scope="col" style="text-align:right;"><?php esc_html_e( 'Clicks', 'katina-link-manager-pro' ); ?></th>
                    <th scope="col" style="width:140px;"><?php esc_html_e( 'Revenue', 'katina-link-manager-pro' ); ?></th>
                    <th scope="col" style="width:90px;"><?php esc_html_e( 'Currency', 'katina-link-manager-pro' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Program', 'katina-link-manager-pro' ); ?></th>
                    <th scope="col" style="text-align:right;"><?php esc_html_e( 'EPC', 'katina-link-manager-pro' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $links ) ) : ?>
                    <tr>
                        <td colspan="7"><?php esc_html_e( 'No links found.', 'katina-link-manager-pro' ); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $links as $link ) :
                        $link_id  = (int) $link->ID;
                        $existing_row = $existing[ $link_id ] ?? null;
                        $clicks   = (int) ( $click_index[ $link_id ] ?? 0 );
                        $amount   = $existing_row ? (float) $existing_row['amount'] : 0.0;
                        $currency = $existing_row ? $existing_row['currency'] : KATINA_PRO_Revenue::DEFAULT_CURRENCY;
                        $program  = $existing_row ? $existing_row['program'] : '';
                        $dest     = get_post_meta( $link_id, '_katina_destination_url', true );
                        $dest_host = $dest ? wp_parse_url( $dest, PHP_URL_HOST ) : '';
                        $epc = ( $existing_row && $clicks > 0 ) ? ( $amount / $clicks ) : null;
                    ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url( get_edit_post_link( $link_id ) ); ?>">
                                    <?php echo esc_html( $link->post_title ?: $link->post_name ); ?>
                                </a>
                                <div class="row-actions">
                                    <span style="color:#999;">/go/<?php echo esc_html( $link->post_name ); ?>/</span>
                                </div>
                            </td>
                            <td><?php echo esc_html( $dest_host ); ?></td>
                            <td style="text-align:right;"><?php echo esc_html( number_format_i18n( $clicks ) ); ?></td>
                            <td>
                                <input type="text"
                                       name="rows[<?php echo esc_attr( (string) $link_id ); ?>][amount]"
                                       value="<?php echo $existing_row ? esc_attr( number_format( $amount, 2, '.', '' ) ) : ''; ?>"
                                       placeholder="0.00"
                                       style="width:120px; text-align:right;" />
                            </td>
                            <td>
                                <select name="rows[<?php echo esc_attr( (string) $link_id ); ?>][currency]">
                                    <?php foreach ( array( 'EUR', 'USD', 'GBP' ) as $cur ) : ?>
                                        <option value="<?php echo esc_attr( $cur ); ?>" <?php selected( $cur, $currency ); ?>>
                                            <?php echo esc_html( $cur ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="text"
                                       name="rows[<?php echo esc_attr( (string) $link_id ); ?>][program]"
                                       value="<?php echo esc_attr( $program ); ?>"
                                       placeholder="<?php esc_attr_e( 'e.g. trackmytarget, financeads', 'katina-link-manager-pro' ); ?>"
                                       style="width:100%;" />
                            </td>
                            <td style="text-align:right;">
                                <?php
                                if ( $epc === null ) {
                                    echo '<span style="color:#999;">—</span>';
                                } else {
                                    echo esc_html( KATINA_PRO_Revenue::format_money( $epc, $currency, 3 ) );
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <p class="submit">
            <button type="submit" class="button button-primary">
                <?php esc_html_e( 'Save revenue for this month', 'katina-link-manager-pro' ); ?>
            </button>
        </p>
    </form>
</div>
