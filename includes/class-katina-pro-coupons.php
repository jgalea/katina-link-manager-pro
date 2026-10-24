<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_PRO_Coupons {

    const POST_TYPE    = 'katina_coupon';
    const TAX_CATEGORY = 'katina_coupon_category';
    const TAX_TAG      = 'katina_coupon_tag';
    const NONCE_ACTION = 'katina_coupon_save';

    const META = array(
        'code'        => '_katina_coupon_code',
        'discount'    => '_katina_coupon_discount',
        'description' => '_katina_coupon_description',
        'link_id'     => '_katina_coupon_link_id',
        'starts'      => '_katina_coupon_starts',
        'expires'     => '_katina_coupon_expires',
        'source_id'   => '_katina_coupon_source_id',
        'reveal'      => '_katina_coupon_reveal',
        'featured'    => '_katina_coupon_featured',
    );

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register' ), 5 );
        add_action( 'transition_post_status', array( 'KATINA_PRO_Coupons_Importer', 'publish_draft_link' ), 10, 3 );
        add_filter( 'rest_' . self::POST_TYPE . '_query', array( __CLASS__, 'limit_rest_query' ), 10, 2 );
        add_filter( 'rest_prepare_' . self::POST_TYPE, array( __CLASS__, 'guard_rest_item' ), 10, 2 );

        if ( is_admin() ) {
            add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_meta_boxes' ) );
            add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_meta' ) );
            add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
            add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_columns' ) );
            add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
        }

        KATINA_PRO_Coupons_Render::init();
        KATINA_PRO_Coupons_Stats::init();
    }

    public static function register() {
        register_post_type( self::POST_TYPE, array(
            'labels'              => array(
                'name'               => __( 'Coupons', 'katina-link-manager-pro' ),
                'singular_name'      => __( 'Coupon', 'katina-link-manager-pro' ),
                'add_new'            => __( 'Add New Coupon', 'katina-link-manager-pro' ),
                'add_new_item'       => __( 'Add New Coupon', 'katina-link-manager-pro' ),
                'edit_item'          => __( 'Edit Coupon', 'katina-link-manager-pro' ),
                'new_item'           => __( 'New Coupon', 'katina-link-manager-pro' ),
                'view_item'          => __( 'View Coupon', 'katina-link-manager-pro' ),
                'search_items'       => __( 'Search Coupons', 'katina-link-manager-pro' ),
                'not_found'          => __( 'No coupons found', 'katina-link-manager-pro' ),
                'not_found_in_trash' => __( 'No coupons found in trash', 'katina-link-manager-pro' ),
                'all_items'          => __( 'Coupons & Deals', 'katina-link-manager-pro' ),
                'featured_image'     => __( 'Logo / image', 'katina-link-manager-pro' ),
                'set_featured_image' => __( 'Set logo / image', 'katina-link-manager-pro' ),
            ),
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => 'edit.php?post_type=katina_link',
            'show_in_rest'        => true,
            'rest_base'           => 'katina-coupons',
            'supports'            => array( 'title', 'thumbnail', 'page-attributes' ),
            'has_archive'         => false,
            'rewrite'             => false,
            'exclude_from_search' => true,
            'capability_type'     => 'post',
        ) );

        register_taxonomy( self::TAX_CATEGORY, self::POST_TYPE, array(
            'labels'            => array(
                'name'          => __( 'Coupon Categories', 'katina-link-manager-pro' ),
                'singular_name' => __( 'Coupon Category', 'katina-link-manager-pro' ),
                'add_new_item'  => __( 'Add New Category', 'katina-link-manager-pro' ),
                'edit_item'     => __( 'Edit Category', 'katina-link-manager-pro' ),
                'search_items'  => __( 'Search Categories', 'katina-link-manager-pro' ),
                'all_items'     => __( 'Coupon Categories', 'katina-link-manager-pro' ),
            ),
            'hierarchical'      => true,
            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => false,
        ) );

        register_taxonomy( self::TAX_TAG, self::POST_TYPE, array(
            'labels'            => array(
                'name'          => __( 'Coupon Tags', 'katina-link-manager-pro' ),
                'singular_name' => __( 'Coupon Tag', 'katina-link-manager-pro' ),
                'add_new_item'  => __( 'Add New Tag', 'katina-link-manager-pro' ),
                'edit_item'     => __( 'Edit Tag', 'katina-link-manager-pro' ),
                'search_items'  => __( 'Search Tags', 'katina-link-manager-pro' ),
                'all_items'     => __( 'Coupon Tags', 'katina-link-manager-pro' ),
            ),
            'hierarchical'      => false,
            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => false,
        ) );
    }

    public static function add_meta_boxes() {
        add_meta_box(
            'katina-coupon-details',
            __( 'Coupon details', 'katina-link-manager-pro' ),
            array( __CLASS__, 'render_meta_box' ),
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    public static function enqueue_admin( $hook ) {
        $screen = get_current_screen();
        if ( ! $screen || self::POST_TYPE !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }

        wp_enqueue_script(
            'katina-pro-coupon-admin',
            KATINA_PRO_PLUGIN_URL . 'assets/js/coupon-admin.js',
            array( 'wp-api-fetch' ),
            KATINA_PRO_VERSION,
            true
        );
        wp_localize_script( 'katina-pro-coupon-admin', 'katinaProCoupon', array(
            'noResults' => __( 'No links found.', 'katina-link-manager-pro' ),
        ) );
    }

    public static function render_meta_box( $post ) {
        wp_nonce_field( self::NONCE_ACTION, 'katina_coupon_nonce' );

        $values  = self::get_values( $post->ID );
        $link_id = (int) $values['link_id'];
        $link    = $link_id ? get_post( $link_id ) : null;
        $link_ok = $link && 'katina_link' === $link->post_type;
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="katina-coupon-description"><?php esc_html_e( 'Description', 'katina-link-manager-pro' ); ?></label></th>
                <td><textarea name="katina_coupon_description" id="katina-coupon-description" class="large-text" rows="4"><?php echo esc_textarea( $values['description'] ); ?></textarea></td>
            </tr>
            <tr>
                <th scope="row"><label for="katina-coupon-code"><?php esc_html_e( 'Coupon code', 'katina-link-manager-pro' ); ?></label></th>
                <td>
                    <input type="text" name="katina_coupon_code" id="katina-coupon-code" class="regular-text code" value="<?php echo esc_attr( $values['code'] ); ?>" />
                    <p class="description"><?php esc_html_e( 'Leave empty for a deal that needs no code.', 'katina-link-manager-pro' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="katina-coupon-discount"><?php esc_html_e( 'Discount label', 'katina-link-manager-pro' ); ?></label></th>
                <td>
                    <input type="text" name="katina_coupon_discount" id="katina-coupon-discount" class="regular-text" value="<?php echo esc_attr( $values['discount'] ); ?>" placeholder="<?php esc_attr_e( '30% off', 'katina-link-manager-pro' ); ?>" />
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="katina-coupon-link-search"><?php esc_html_e( 'Katina link', 'katina-link-manager-pro' ); ?></label></th>
                <td>
                    <div class="katina-coupon-link-picker">
                        <input type="hidden" name="katina_coupon_link_id" id="katina-coupon-link-id" value="<?php echo esc_attr( $link_ok ? (string) $link_id : '' ); ?>" />
                        <p id="katina-coupon-link-current" <?php echo $link_ok ? '' : 'hidden'; ?>>
                            <strong id="katina-coupon-link-title"><?php echo $link_ok ? esc_html( $link->post_title ) : ''; ?></strong>
                            <button type="button" class="button-link" id="katina-coupon-link-clear"><?php esc_html_e( 'Remove', 'katina-link-manager-pro' ); ?></button>
                        </p>
                        <input type="search" id="katina-coupon-link-search" class="regular-text" autocomplete="off" placeholder="<?php esc_attr_e( 'Search links by title', 'katina-link-manager-pro' ); ?>" />
                        <ul id="katina-coupon-link-results" class="katina-coupon-link-results" style="margin:4px 0 0;max-width:25em;"></ul>
                    </div>
                    <p class="description"><?php esc_html_e( 'The button on the coupon goes through this link, so clicks are tracked.', 'katina-link-manager-pro' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="katina-coupon-starts"><?php esc_html_e( 'Start date', 'katina-link-manager-pro' ); ?></label></th>
                <td><input type="date" name="katina_coupon_starts" id="katina-coupon-starts" value="<?php echo esc_attr( $values['starts'] ); ?>" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="katina-coupon-expires"><?php esc_html_e( 'Expiry date', 'katina-link-manager-pro' ); ?></label></th>
                <td>
                    <input type="date" name="katina_coupon_expires" id="katina-coupon-expires" value="<?php echo esc_attr( $values['expires'] ); ?>" />
                    <p class="description"><?php esc_html_e( 'The coupon is shown through the end of this day (site time zone). Leave both dates empty for no schedule.', 'katina-link-manager-pro' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Display', 'katina-link-manager-pro' ); ?></th>
                <td>
                    <label for="katina-coupon-reveal">
                        <input type="checkbox" name="katina_coupon_reveal" id="katina-coupon-reveal" value="yes" <?php checked( 'yes', $values['reveal'] ); ?> />
                        <?php esc_html_e( 'Hide the code behind a "Show code" button', 'katina-link-manager-pro' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Clicking the button shows the code and opens the Katina link in a new tab. The reveal attribute on the shortcode or block overrides this.', 'katina-link-manager-pro' ); ?></p>
                    <p>
                        <label for="katina-coupon-featured">
                            <input type="checkbox" name="katina_coupon_featured" id="katina-coupon-featured" value="yes" <?php checked( 'yes', $values['featured'] ); ?> />
                            <?php esc_html_e( 'Featured', 'katina-link-manager-pro' ); ?>
                        </label>
                    </p>
                    <p class="description"><?php esc_html_e( 'Featured coupons get a label and a highlighted border. orderby="featured" lists them first.', 'katina-link-manager-pro' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    public static function save_meta( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! isset( $_POST['katina_coupon_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['katina_coupon_nonce'] ) ), self::NONCE_ACTION ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $link_id = isset( $_POST['katina_coupon_link_id'] ) ? absint( $_POST['katina_coupon_link_id'] ) : 0;
        if ( $link_id && 'katina_link' !== get_post_type( $link_id ) ) {
            $link_id = 0;
        }

        $data = array(
            'code'        => isset( $_POST['katina_coupon_code'] ) ? sanitize_text_field( wp_unslash( $_POST['katina_coupon_code'] ) ) : '',
            'discount'    => isset( $_POST['katina_coupon_discount'] ) ? sanitize_text_field( wp_unslash( $_POST['katina_coupon_discount'] ) ) : '',
            'description' => isset( $_POST['katina_coupon_description'] ) ? wp_kses_post( wp_unslash( $_POST['katina_coupon_description'] ) ) : '',
            'link_id'     => $link_id ? (string) $link_id : '',
            'starts'      => isset( $_POST['katina_coupon_starts'] ) ? self::sanitize_date( sanitize_text_field( wp_unslash( $_POST['katina_coupon_starts'] ) ) ) : '',
            'expires'     => isset( $_POST['katina_coupon_expires'] ) ? self::sanitize_date( sanitize_text_field( wp_unslash( $_POST['katina_coupon_expires'] ) ) ) : '',
            'reveal'      => isset( $_POST['katina_coupon_reveal'] ) ? 'yes' : '',
            'featured'    => isset( $_POST['katina_coupon_featured'] ) ? 'yes' : '',
        );

        self::write_meta( $post_id, $data );
    }

    public static function write_meta( $post_id, array $data ) {
        foreach ( $data as $key => $value ) {
            if ( ! isset( self::META[ $key ] ) ) {
                continue;
            }
            if ( '' === $value ) {
                delete_post_meta( $post_id, self::META[ $key ] );
            } else {
                update_post_meta( $post_id, self::META[ $key ], $value );
            }
        }
    }

    public static function sanitize_date( $value ) {
        $date = DateTime::createFromFormat( '!Y-m-d', (string) $value );
        if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
            return '';
        }
        return $value;
    }

    public static function get_values( $post_id ) {
        $values = array();
        foreach ( self::META as $key => $meta_key ) {
            $values[ $key ] = (string) get_post_meta( $post_id, $meta_key, true );
        }
        return $values;
    }

    /**
     * Meta query that keeps coupons inside their schedule: started, and not
     * expired unless $hide_expired is false.
     */
    public static function schedule_meta_query( $hide_expired = true ) {
        $today = self::today();
        $meta  = array( 'relation' => 'AND' );

        $meta[] = array(
            'relation' => 'OR',
            array( 'key' => self::META['starts'], 'compare' => 'NOT EXISTS' ),
            array( 'key' => self::META['starts'], 'value' => '', 'compare' => '=' ),
            array( 'key' => self::META['starts'], 'value' => $today, 'compare' => '<=', 'type' => 'CHAR' ),
        );

        if ( $hide_expired ) {
            $meta[] = array(
                'relation' => 'OR',
                array( 'key' => self::META['expires'], 'compare' => 'NOT EXISTS' ),
                array( 'key' => self::META['expires'], 'value' => '', 'compare' => '=' ),
                array( 'key' => self::META['expires'], 'value' => $today, 'compare' => '>=', 'type' => 'CHAR' ),
            );
        }

        return $meta;
    }

    public static function is_in_schedule( $post_id ) {
        $starts = (string) get_post_meta( $post_id, self::META['starts'], true );
        return ( '' === $starts || $starts <= self::today() ) && ! self::is_expired( $post_id );
    }

    private static function user_can_edit_coupons() {
        $type = get_post_type_object( self::POST_TYPE );
        return $type && current_user_can( $type->cap->edit_posts );
    }

    /**
     * Visitors who can't edit coupons only see what the front end shows: no
     * coupons that haven't started, none that have expired.
     */
    public static function limit_rest_query( $args, $request ) {
        if ( self::user_can_edit_coupons() ) {
            return $args;
        }
        $schedule = self::schedule_meta_query( true );
        if ( ! empty( $args['meta_query'] ) ) {
            $schedule[] = $args['meta_query'];
        }
        $args['meta_query'] = $schedule; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        return $args;
    }

    /**
     * Every REST representation of a coupon passes through here, whatever
     * route spelling or embed produced it.
     */
    public static function guard_rest_item( $response, $post ) {
        if ( self::user_can_edit_coupons() || self::is_in_schedule( $post->ID ) ) {
            return $response;
        }
        return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'katina-link-manager-pro' ), array( 'status' => 404 ) );
    }

    public static function today() {
        return wp_date( 'Y-m-d' );
    }

    public static function is_expired( $post_id, $today = '' ) {
        $expires = (string) get_post_meta( $post_id, self::META['expires'], true );
        $today   = $today ? $today : self::today();
        return '' !== $expires && $expires < $today;
    }

    public static function add_columns( $columns ) {
        $new = array();
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'title' === $key ) {
                $new['katina_coupon_discount'] = __( 'Discount', 'katina-link-manager-pro' );
                $new['katina_coupon_code']     = __( 'Code', 'katina-link-manager-pro' );
                $new['katina_coupon_link']     = __( 'Link', 'katina-link-manager-pro' );
                $new['katina_coupon_expires']  = __( 'Expires', 'katina-link-manager-pro' );
            }
        }
        return $new;
    }

    public static function render_column( $column, $post_id ) {
        $dash = '<span aria-hidden="true">&mdash;</span>';
        switch ( $column ) {
            case 'katina_coupon_discount':
                $value = (string) get_post_meta( $post_id, self::META['discount'], true );
                echo '' !== $value ? esc_html( $value ) : $dash; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                break;
            case 'katina_coupon_code':
                $value = (string) get_post_meta( $post_id, self::META['code'], true );
                echo '' !== $value ? '<code>' . esc_html( $value ) . '</code>' : esc_html__( 'Deal', 'katina-link-manager-pro' );
                break;
            case 'katina_coupon_link':
                $link_id = (int) get_post_meta( $post_id, self::META['link_id'], true );
                $link    = $link_id ? get_post( $link_id ) : null;
                if ( $link && 'katina_link' === $link->post_type ) {
                    echo '<a href="' . esc_url( get_edit_post_link( $link_id ) ) . '">' . esc_html( $link->post_title ) . '</a>';
                } else {
                    echo $dash; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }
                break;
            case 'katina_coupon_expires':
                $expires = (string) get_post_meta( $post_id, self::META['expires'], true );
                if ( '' === $expires ) {
                    echo $dash; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    break;
                }
                echo esc_html( $expires );
                if ( self::is_expired( $post_id ) ) {
                    echo ' <strong>' . esc_html__( '(expired)', 'katina-link-manager-pro' ) . '</strong>';
                }
                break;
        }
    }
}
