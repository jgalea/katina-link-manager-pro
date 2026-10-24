( function( wp ) {
    var el = wp.element.createElement;
    var PluginSidebar = wp.editPost.PluginSidebar;
    var PanelBody = wp.components.PanelBody;
    var Button = wp.components.Button;
    var Spinner = wp.components.Spinner;
    var registerPlugin = wp.plugins.registerPlugin;
    var useSelect = wp.data.useSelect;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;

    function KatinaLinkManagerSidebar() {
        var postId = useSelect( function( select ) {
            return select( 'core/editor' ).getCurrentPostId();
        } );

        var _state = useState( [] );
        var suggestions = _state[0];
        var setSuggestions = _state[1];

        var _loading = useState( false );
        var loading = _loading[0];
        var setLoading = _loading[1];

        var _error = useState( '' );
        var error = _error[0];
        var setError = _error[1];

        function fetchSuggestions( refresh ) {
            if ( ! postId ) return;
            setLoading( true );
            setError( '' );

            var url = wpApiSettings.root + 'katina-link-manager-pro/v1/suggestions/' + postId;
            var opts = {
                headers: { 'X-WP-Nonce': wpApiSettings.nonce },
            };

            if ( refresh ) {
                opts.method = 'POST';
                url += '/refresh';
            }

            fetch( url, opts )
                .then( function( r ) { return r.json(); } )
                .then( function( data ) {
                    setSuggestions( Array.isArray( data ) ? data : [] );
                    setLoading( false );
                } )
                .catch( function( e ) {
                    setError( e.message || 'Failed to load suggestions' );
                    setLoading( false );
                } );
        }

        useEffect( function() {
            if ( postId ) fetchSuggestions( false );
        }, [ postId ] );

        function insertLink( suggestion ) {
            var linkHtml = '<a href="' + suggestion.url + '">' + suggestion.title + '</a>';
            wp.data.dispatch( 'core/editor' ).insertBlocks(
                wp.blocks.createBlock( 'core/paragraph', {
                    content: linkHtml,
                } )
            );
        }

        return el( PluginSidebar, {
            name: 'katina-link-manager-pro-suggestions',
            title: 'Katina Link Manager Suggestions',
            icon: 'admin-links',
        },
            el( PanelBody, { title: 'Suggested Links', initialOpen: true },
                el( 'div', { style: { marginBottom: '10px' } },
                    el( Button, {
                        variant: 'secondary',
                        onClick: function() { fetchSuggestions( true ); },
                        disabled: loading,
                        size: 'small',
                    }, loading ? el( Spinner, null ) : 'Refresh Suggestions' )
                ),
                error && el( 'p', { style: { color: '#d63638' } }, error ),
                ! loading && suggestions.length === 0 && el( 'p', {
                    style: { color: '#757575', fontStyle: 'italic' },
                }, 'No suggestions for this content yet. Click Refresh to analyze.' ),
                suggestions.map( function( s ) {
                    return el( 'div', {
                        key: s.id,
                        style: {
                            padding: '10px',
                            marginBottom: '8px',
                            background: '#f6f7f7',
                            border: '1px solid #ddd',
                            borderRadius: '4px',
                        },
                    },
                        el( 'strong', null, s.title ),
                        el( 'p', { style: { margin: '4px 0 8px', fontSize: '12px', color: '#555' } }, s.reason ),
                        el( 'div', { style: { display: 'flex', gap: '8px' } },
                            el( Button, {
                                variant: 'primary',
                                size: 'small',
                                onClick: function() { insertLink( s ); },
                            }, 'Insert' ),
                            el( Button, {
                                variant: 'link',
                                size: 'small',
                                href: s.url,
                                target: '_blank',
                            }, 'Preview' )
                        )
                    );
                } )
            )
        );
    }

    registerPlugin( 'katina-link-manager-pro', {
        render: KatinaLinkManagerSidebar,
        icon: 'admin-links',
    } );
} )( window.wp );
