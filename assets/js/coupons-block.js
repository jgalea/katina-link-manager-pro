(function (wp) {
    'use strict';

    var el = wp.element.createElement;
    var __ = wp.i18n.__;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var useBlockProps = wp.blockEditor.useBlockProps;
    var PanelBody = wp.components.PanelBody;
    var TextControl = wp.components.TextControl;
    var SelectControl = wp.components.SelectControl;
    var RangeControl = wp.components.RangeControl;
    var Disabled = wp.components.Disabled;
    var ServerSideRender = wp.serverSideRender;

    wp.blocks.registerBlockType('katina-link-manager-pro/coupons', {
        edit: function (props) {
            var a = props.attributes;
            var set = props.setAttributes;

            return el('div', useBlockProps(),
                el(InspectorControls, null,
                    el(PanelBody, { title: __('Coupons', 'katina-link-manager-pro') },
                        el(TextControl, {
                            label: __('Category slugs', 'katina-link-manager-pro'),
                            help: __('Comma-separated.', 'katina-link-manager-pro'),
                            value: a.category,
                            onChange: function (v) { set({ category: v }); }
                        }),
                        el(TextControl, {
                            label: __('Tag slugs', 'katina-link-manager-pro'),
                            help: __('Comma-separated.', 'katina-link-manager-pro'),
                            value: a.tag,
                            onChange: function (v) { set({ tag: v }); }
                        }),
                        el(TextControl, {
                            label: __('Coupon IDs', 'katina-link-manager-pro'),
                            help: __('Comma-separated. Overrides category, tag and order.', 'katina-link-manager-pro'),
                            value: a.ids,
                            onChange: function (v) { set({ ids: v }); }
                        }),
                        el(RangeControl, {
                            label: __('Maximum coupons', 'katina-link-manager-pro'),
                            min: 1, max: 100,
                            value: a.limit,
                            onChange: function (v) { set({ limit: v || 12 }); }
                        }),
                        el(SelectControl, {
                            label: __('Layout', 'katina-link-manager-pro'),
                            value: a.layout,
                            options: [
                                { label: __('Grid', 'katina-link-manager-pro'), value: 'grid' },
                                { label: __('List', 'katina-link-manager-pro'), value: 'list' }
                            ],
                            onChange: function (v) { set({ layout: v }); }
                        }),
                        a.layout === 'grid' && el(RangeControl, {
                            label: __('Columns', 'katina-link-manager-pro'),
                            min: 1, max: 4,
                            value: a.columns,
                            onChange: function (v) { set({ columns: v || 3 }); }
                        }),
                        el(SelectControl, {
                            label: __('Expired coupons', 'katina-link-manager-pro'),
                            value: a.expired,
                            options: [
                                { label: __('Hide', 'katina-link-manager-pro'), value: 'hide' },
                                { label: __('Show greyed out', 'katina-link-manager-pro'), value: 'show' }
                            ],
                            onChange: function (v) { set({ expired: v }); }
                        }),
                        el(SelectControl, {
                            label: __('Order by', 'katina-link-manager-pro'),
                            value: a.orderby,
                            options: [
                                { label: __('Newest first', 'katina-link-manager-pro'), value: 'date' },
                                { label: __('Title', 'katina-link-manager-pro'), value: 'title' },
                                { label: __('Expiry date', 'katina-link-manager-pro'), value: 'expires' },
                                { label: __('Menu order', 'katina-link-manager-pro'), value: 'menu_order' },
                                { label: __('Featured first', 'katina-link-manager-pro'), value: 'featured' }
                            ],
                            onChange: function (v) { set({ orderby: v }); }
                        }),
                        el(SelectControl, {
                            label: __('Click to reveal', 'katina-link-manager-pro'),
                            help: __('Hide codes behind a "Show code" button that also opens the coupon link.', 'katina-link-manager-pro'),
                            value: a.reveal,
                            options: [
                                { label: __('Use each coupon\'s setting', 'katina-link-manager-pro'), value: '' },
                                { label: __('Reveal on click', 'katina-link-manager-pro'), value: 'yes' },
                                { label: __('Always show codes', 'katina-link-manager-pro'), value: 'no' }
                            ],
                            onChange: function (v) { set({ reveal: v }); }
                        })
                    )
                ),
                el(Disabled, null,
                    el(ServerSideRender, {
                        block: 'katina-link-manager-pro/coupons',
                        attributes: a,
                        EmptyResponsePlaceholder: function () {
                            return el('p', null, __('No coupons match these settings.', 'katina-link-manager-pro'));
                        }
                    })
                )
            );
        },
        save: function () { return null; }
    });
})(window.wp);
