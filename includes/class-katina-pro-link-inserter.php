<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_PRO_Link_Inserter {

    // Text inside these elements is never linked: a link there would nest anchors,
    // break scripts or styles, or alter code samples and form controls.
    const SKIP_TAGS = array( 'a', 'script', 'style', 'code', 'pre', 'kbd', 'samp', 'textarea', 'button', 'select', 'option', 'svg', 'math', 'noscript', 'template', 'iframe', 'object', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );

    // Elements carrying one of these classes are skipped with everything inside
    // them: coupon cards have their own CTA, and an expired card must stay unlinked.
    const SKIP_CLASSES = array( 'katina-coupons', 'katina-coupon' );

    const VOID_TAGS = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' );

    /**
     * Wrap the first plain-text mention of $keyword in $open_tag ... </a>, keeping
     * the matched text as written. Returns null when there was nothing to link.
     */
    public static function link_first( $content, $keyword, $open_tag ) {
        if ( '' === $keyword || '' === $content ) {
            return null;
        }

        $pattern = '/(?<!\w)' . preg_quote( $keyword, '/' ) . '(?!\w)/i';
        $parts   = wp_html_split( $content );
        $depth   = 0;
        $open    = array();
        $skip    = '';
        $nest    = 0;

        foreach ( $parts as $i => $part ) {
            if ( $i % 2 ) {
                if ( preg_match( '#^<(/?)([a-z][a-z0-9-]*)#i', $part, $tag ) ) {
                    $name    = strtolower( $tag[2] );
                    $closing = '/' === $tag[1];
                    $is_void = in_array( $name, self::VOID_TAGS, true ) || '/>' === substr( rtrim( $part ), -2 );

                    if ( '' !== $skip ) {
                        if ( $name === $skip && ! $is_void ) {
                            $nest += $closing ? -1 : 1;
                            if ( 0 === $nest ) {
                                $skip = '';
                            }
                        }
                        continue;
                    }

                    if ( ! $closing && ! $is_void && self::has_skip_class( $part ) ) {
                        $skip = $name;
                        $nest = 1;
                        continue;
                    }

                    if ( in_array( $name, self::SKIP_TAGS, true ) ) {
                        if ( '/' === $tag[1] ) {
                            if ( ! empty( $open[ $name ] ) ) {
                                $open[ $name ]--;
                                $depth--;
                            }
                        } elseif ( '/>' !== substr( rtrim( $part ), -2 ) ) {
                            $open[ $name ] = isset( $open[ $name ] ) ? $open[ $name ] + 1 : 1;
                            $depth++;
                        }
                    }
                }
                continue;
            }

            if ( $depth > 0 || '' !== $skip || '' === $part ) {
                continue;
            }

            $new = preg_replace_callback(
                $pattern,
                function ( $m ) use ( $open_tag ) {
                    return $open_tag . $m[0] . '</a>';
                },
                $part,
                1,
                $count
            );

            if ( $new !== null && $count ) {
                $parts[ $i ] = $new;
                return implode( '', $parts );
            }
        }

        return null;
    }

    private static function has_skip_class( $tag ) {
        if ( ! preg_match( '/\sclass\s*=\s*(["\'])(.*?)\1/is', $tag, $m ) ) {
            return false;
        }
        $classes = preg_split( '/\s+/', trim( $m[2] ) );
        return (bool) array_intersect( $classes, self::SKIP_CLASSES );
    }
}
