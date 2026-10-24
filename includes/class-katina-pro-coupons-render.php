<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_PRO_Coupons_Render {

    const SHORTCODE = 'katina_coupons';
    const MAX_LIMIT = 100;

    public static function init() {
        add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
        add_action( 'init', array( __CLASS__, 'register_assets' ), 6 );
        add_action( 'init', array( __CLASS__, 'register_block' ), 7 );
    }

    public static function register_assets() {
        wp_register_style( 'katina-pro-coupons', KATINA_PRO_PLUGIN_URL . 'assets/css/coupons.css', array(), KATINA_PRO_VERSION );
        wp_register_script( 'katina-pro-coupons', KATINA_PRO_PLUGIN_URL . 'assets/js/coupons.js', array(), KATINA_PRO_VERSION, array( 'strategy' => 'defer', 'in_footer' => true ) );
        wp_register_script(
            'katina-pro-coupons-block',
            KATINA_PRO_PLUGIN_URL . 'assets/js/coupons-block.js',
            array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
            KATINA_PRO_VERSION,
            true
        );
        wp_set_script_translations( 'katina-pro-coupons-block', 'katina-link-manager-pro' );
    }

    public static function register_block() {
        register_block_type( KATINA_PRO_PLUGIN_DIR . 'blocks/coupons', array(
            'render_callback' => array( __CLASS__, 'render_block' ),
        ) );
    }

    public static function defaults() {
        return array(
            'ids'      => '',
            'category' => '',
            'tag'      => '',
            'limit'    => 12,
            'layout'   => 'grid',
            'columns'  => 3,
            'expired'  => 'hide',
            'orderby'  => 'date',
            'reveal'   => '',
        );
    }

    public static function shortcode( $atts ) {
        $atts = shortcode_atts( self::defaults(), (array) $atts, self::SHORTCODE );
        return self::render( $atts );
    }

    public static function render_block( $attributes ) {
        $attrs = array();
        foreach ( self::defaults() as $key => $default ) {
            $attrs[ $key ] = isset( $attributes[ $key ] ) ? $attributes[ $key ] : $default;
        }
        return self::render( $attrs );
    }

    private static function normalize( array $atts ) {
        $atts['ids']      = array_values( array_filter( array_map( 'absint', explode( ',', (string) $atts['ids'] ) ) ) );
        $atts['category'] = array_values( array_filter( array_map( 'sanitize_title', explode( ',', (string) $atts['category'] ) ) ) );
        $atts['tag']      = array_values( array_filter( array_map( 'sanitize_title', explode( ',', (string) $atts['tag'] ) ) ) );
        // limit="0" (or below) means all coupons, still capped at MAX_LIMIT.
        $limit            = (int) $atts['limit'];
        $atts['limit']    = $limit > 0 ? min( self::MAX_LIMIT, $limit ) : self::MAX_LIMIT;
        $atts['layout']   = 'list' === $atts['layout'] ? 'list' : 'grid';
        $atts['columns']  = max( 1, min( 4, (int) $atts['columns'] ) );
        $atts['expired']  = 'show' === $atts['expired'] ? 'show' : 'hide';
        $atts['orderby']  = in_array( $atts['orderby'], array( 'date', 'title', 'expires', 'menu_order', 'featured' ), true ) ? $atts['orderby'] : 'date';
        $atts['reveal']   = in_array( $atts['reveal'], array( 'yes', 'no' ), true ) ? $atts['reveal'] : '';
        return $atts;
    }

    private static function query( array $atts ) {
        $today = KATINA_PRO_Coupons::today();
        $meta  = KATINA_PRO_Coupons::schedule_meta_query( 'hide' === $atts['expired'] );

        $args = array(
            'post_type'           => KATINA_PRO_Coupons::POST_TYPE,
            'post_status'         => 'publish',
            'has_password'        => false,
            'posts_per_page'      => $atts['limit'],
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
            'meta_query'          => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        );

        if ( ! empty( $atts['ids'] ) ) {
            $args['post__in'] = $atts['ids'];
            $args['orderby']  = 'post__in';
        } elseif ( 'title' === $atts['orderby'] ) {
            $args['orderby'] = 'title';
            $args['order']   = 'ASC';
        } elseif ( 'menu_order' === $atts['orderby'] ) {
            $args['orderby'] = array( 'menu_order' => 'ASC', 'date' => 'DESC' );
        } else {
            $args['orderby'] = 'date';
            $args['order']   = 'DESC';
        }

        $tax = array();
        if ( ! empty( $atts['category'] ) ) {
            $tax[] = array( 'taxonomy' => KATINA_PRO_Coupons::TAX_CATEGORY, 'field' => 'slug', 'terms' => $atts['category'] );
        }
        if ( ! empty( $atts['tag'] ) ) {
            $tax[] = array( 'taxonomy' => KATINA_PRO_Coupons::TAX_TAG, 'field' => 'slug', 'terms' => $atts['tag'] );
        }
        if ( $tax ) {
            $args['tax_query'] = array_merge( array( 'relation' => 'AND' ), $tax ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
        }

        if ( 'featured' === $atts['orderby'] && empty( $atts['ids'] ) && 'show' === $atts['expired'] ) {
            $posts = self::featured_first_active_then_expired( $args, $today );
        } elseif ( 'featured' === $atts['orderby'] && empty( $atts['ids'] ) ) {
            $posts = self::featured_first( $args );
        } else {
            $posts = get_posts( $args );
        }

        if ( 'expires' === $atts['orderby'] && empty( $atts['ids'] ) ) {
            usort( $posts, function ( $a, $b ) {
                $ea = (string) get_post_meta( $a->ID, KATINA_PRO_Coupons::META['expires'], true );
                $eb = (string) get_post_meta( $b->ID, KATINA_PRO_Coupons::META['expires'], true );
                if ( $ea === $eb ) {
                    return 0;
                }
                if ( '' === $ea ) {
                    return 1;
                }
                if ( '' === $eb ) {
                    return -1;
                }
                return strcmp( $ea, $eb );
            } );
        }

        if ( 'show' === $atts['expired'] ) {
            $active  = array();
            $expired = array();
            foreach ( $posts as $post ) {
                if ( KATINA_PRO_Coupons::is_expired( $post->ID, $today ) ) {
                    $expired[] = $post;
                } else {
                    $active[] = $post;
                }
            }
            $posts = array_merge( $active, $expired );
        }

        return $posts;
    }

    private static function featured_first_active_then_expired( array $args, $today ) {
        $active_args               = $args;
        $active_args['meta_query'] = KATINA_PRO_Coupons::schedule_meta_query( true ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        $posts                     = self::featured_first( $active_args );

        $remaining = $args['posts_per_page'] - count( $posts );
        if ( $remaining <= 0 ) {
            return $posts;
        }

        $expired_args                   = $args;
        $expired_args['posts_per_page'] = $remaining;
        $expired_args['meta_query']     = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            'relation' => 'AND',
            $args['meta_query'],
            array( 'key' => KATINA_PRO_Coupons::META['expires'], 'value' => '', 'compare' => '!=' ),
            array( 'key' => KATINA_PRO_Coupons::META['expires'], 'value' => $today, 'compare' => '<', 'type' => 'CHAR' ),
        );
        return array_merge( $posts, self::featured_first( $expired_args ) );
    }

    private static function featured_first( array $args ) {
        $featured_args               = $args;
        $featured_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            'relation' => 'AND',
            $args['meta_query'],
            array( 'key' => KATINA_PRO_Coupons::META['featured'], 'value' => 'yes' ),
        );
        $featured = get_posts( $featured_args );

        $remaining = $args['posts_per_page'] - count( $featured );
        if ( $remaining <= 0 ) {
            return $featured;
        }

        $args['posts_per_page'] = $remaining;
        $args['post__not_in']   = wp_list_pluck( $featured, 'ID' );
        return array_merge( $featured, get_posts( $args ) );
    }

    public static function render( array $atts ) {
        $atts  = self::normalize( array_merge( self::defaults(), $atts ) );
        $posts = self::query( $atts );

        wp_enqueue_style( 'katina-pro-coupons' );
        wp_enqueue_script( 'katina-pro-coupons' );

        if ( empty( $posts ) ) {
            return '';
        }

        $link_ids = array();
        foreach ( $posts as $post ) {
            $link_id = (int) get_post_meta( $post->ID, KATINA_PRO_Coupons::META['link_id'], true );
            if ( $link_id ) {
                $link_ids[] = $link_id;
            }
        }
        if ( $link_ids ) {
            _prime_post_caches( array_unique( $link_ids ), false, true );
        }

        $today = KATINA_PRO_Coupons::today();

        ob_start();
        printf(
            '<div class="katina-coupons katina-coupons--%1$s" style="--katina-coupons-cols:%2$d" data-copy-label="%3$s" data-copied-label="%4$s" data-failed-label="%5$s" data-revealed-label="%6$s" data-stats-url="%7$s">',
            esc_attr( $atts['layout'] ),
            (int) $atts['columns'],
            esc_attr__( 'Copy', 'katina-link-manager-pro' ),
            esc_attr__( 'Copied', 'katina-link-manager-pro' ),
            esc_attr__( 'Press Ctrl+C to copy', 'katina-link-manager-pro' ),
            esc_attr__( 'Code revealed:', 'katina-link-manager-pro' ),
            esc_url( KATINA_PRO_Coupons_Stats::endpoint_url() )
        );
        echo '<ul class="katina-coupons__list">';
        foreach ( $posts as $post ) {
            self::render_item( $post, $today, $atts['reveal'] );
        }
        echo '</ul>';
        echo '<p class="katina-coupons__status screen-reader-text" role="status" aria-live="polite"></p>';
        echo '</div>';
        return ob_get_clean();
    }

    private static function render_item( WP_Post $post, $today, $reveal_attr ) {
        $values   = KATINA_PRO_Coupons::get_values( $post->ID );
        $expired  = '' !== $values['expires'] && $values['expires'] < $today;
        $code     = $values['code'];
        $title    = get_the_title( $post );
        $link     = null;
        $title_id = 'katina-coupon-title-' . $post->ID;

        if ( ! $expired && $values['link_id'] ) {
            $candidate = get_post( (int) $values['link_id'] );
            if ( $candidate && 'katina_link' === $candidate->post_type && 'publish' === $candidate->post_status ) {
                $link = new KATINA_Link( $candidate->ID );
            }
        }

        $featured = ! $expired && 'yes' === $values['featured'];
        $reveal   = ! $expired && '' !== $code && ( '' !== $reveal_attr ? 'yes' === $reveal_attr : 'yes' === $values['reveal'] );
        $classes  = array( 'katina-coupon' );
        if ( $expired ) {
            $classes[] = 'is-expired';
        }
        if ( $reveal ) {
            $classes[] = 'has-reveal';
        }
        if ( $featured ) {
            $classes[] = 'is-featured';
        }
        ?>
        <li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-coupon-id="<?php echo (int) $post->ID; ?>">
            <article aria-labelledby="<?php echo esc_attr( $title_id ); ?>">
                <?php if ( has_post_thumbnail( $post ) ) : ?>
                    <div class="katina-coupon__image">
                        <?php echo get_the_post_thumbnail( $post, 'medium', array( 'alt' => '', 'loading' => 'lazy', 'decoding' => 'async' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                <?php endif; ?>
                <div class="katina-coupon__body">
                    <?php if ( $featured ) : ?>
                        <p class="katina-coupon__featured"><span aria-hidden="true">&#9733;</span> <?php esc_html_e( 'Featured', 'katina-link-manager-pro' ); ?></p>
                    <?php endif; ?>
                    <?php if ( '' !== $values['discount'] ) : ?>
                        <p class="katina-coupon__discount"><?php echo esc_html( $values['discount'] ); ?></p>
                    <?php endif; ?>
                    <h3 class="katina-coupon__title" id="<?php echo esc_attr( $title_id ); ?>"><?php echo esc_html( $title ); ?></h3>
                    <?php if ( '' !== $values['description'] ) : ?>
                        <div class="katina-coupon__description"><?php echo wp_kses_post( wpautop( $values['description'] ) ); ?></div>
                    <?php endif; ?>
                    <?php if ( $expired ) : ?>
                        <p class="katina-coupon__expired">
                            <?php
                            /* translators: %s: expiry date */
                            printf( esc_html__( 'Expired on %s', 'katina-link-manager-pro' ), esc_html( mysql2date( get_option( 'date_format' ), $values['expires'] ) ) );
                            ?>
                        </p>
                    <?php else : ?>
                        <?php if ( $reveal ) : ?>
                            <button type="button" class="katina-coupon__reveal"<?php echo $link ? ' data-href="' . esc_url( $link->get_cloaked_url() ) . '" data-new-window="' . ( $link->opens_new_window() ? 'yes' : 'no' ) . '"' : ''; ?>>
                                <?php esc_html_e( 'Show code', 'katina-link-manager-pro' ); ?>
                                <span class="screen-reader-text"><?php /* translators: %s: coupon title */ echo esc_html( sprintf( __( 'for %s', 'katina-link-manager-pro' ), $title ) ); ?></span>
                            </button>
                        <?php endif; ?>
                        <?php if ( '' !== $code ) : ?>
                            <div class="katina-coupon__code-row">
                                <code class="katina-coupon__code"><?php echo esc_html( $code ); ?></code>
                                <button type="button" class="katina-coupon__copy" data-code="<?php echo esc_attr( $code ); ?>" aria-label="<?php /* translators: %s: coupon code */ echo esc_attr( sprintf( __( 'Copy code %s', 'katina-link-manager-pro' ), $code ) ); ?>"><?php esc_html_e( 'Copy', 'katina-link-manager-pro' ); ?></button>
                            </div>
                        <?php endif; ?>
                        <?php if ( $link ) : ?>
                            <?php
                            $label = '' !== $code ? __( 'Get discount', 'katina-link-manager-pro' ) : __( 'Get deal', 'katina-link-manager-pro' );
                            $label = apply_filters( 'katina_pro_coupon_cta_label', $label, $post, $link );
                            $rel   = trim( 'noopener ' . $link->get_rel_tags() );
                            ?>
                            <a class="katina-coupon__cta" href="<?php echo esc_url( $link->get_cloaked_url() ); ?>" rel="<?php echo esc_attr( $rel ); ?>"<?php echo $link->opens_new_window() ? ' target="_blank"' : ''; ?>>
                                <?php echo esc_html( $label ); ?>
                                <span class="screen-reader-text"><?php /* translators: %s: coupon title */ echo esc_html( sprintf( __( 'for %s', 'katina-link-manager-pro' ), $title ) ); ?></span>
                            </a>
                        <?php endif; ?>
                        <?php if ( '' !== $values['expires'] ) : ?>
                            <p class="katina-coupon__valid">
                                <?php
                                /* translators: %s: expiry date */
                                printf( esc_html__( 'Valid until %s', 'katina-link-manager-pro' ), esc_html( mysql2date( get_option( 'date_format' ), $values['expires'] ) ) );
                                ?>
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </article>
        </li>
        <?php
    }
}
