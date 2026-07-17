/**
 * A11y Scanner — Gutenberg Inspector Controls Panel
 *
 * Adds an A11y Scanner panel to the block editor sidebar so editors can
 * trigger a scan of the current post content without leaving the editor.
 * Includes inline a11y suggestions and severity badges per issue.
 */
( function ( wp ) {
	const { registerPlugin }    = wp.plugins;
	const { PluginSidebar }     = wp.editPost;
	const { PanelBody, Button, Notice, Spinner, Badge } = wp.components;
	const { useState }          = wp.element;
	const { useSelect }         = wp.data;
	const { __, sprintf }       = wp.i18n;

	/** Severity colour map. */
	const SEVERITY_COLORS = {
		critical: '#d63638',
		major:    '#d67c38',
		minor:    '#007cba',
	};

	/** Inline fix suggestion per issue type. */
	function getSuggestion( type ) {
		const map = {
			missing_alt:  __( 'Add a descriptive alt attribute to the image.', 'wp-simple-a11y-scanner' ),
			empty_link:   __( 'Add visible text or an aria-label to the link.', 'wp-simple-a11y-scanner' ),
			vague_link:   __( 'Replace vague link text with a descriptive phrase.', 'wp-simple-a11y-scanner' ),
			low_contrast: __( 'Increase the colour contrast ratio to at least 4.5:1 (WCAG AA).', 'wp-simple-a11y-scanner' ),
			target_size:  __( 'Increase the clickable target size to at least 24×24 CSS pixels.', 'wp-simple-a11y-scanner' ),
			keyboard_nav: __( 'Remove positive tabindex values and rely on DOM order for tab flow.', 'wp-simple-a11y-scanner' ),
			social_meta:  __( 'Update social meta fields in your SEO plugin settings.', 'wp-simple-a11y-scanner' ),
		};
		return map[ type ] || __( 'Review the element for accessibility compliance.', 'wp-simple-a11y-scanner' );
	}

	function SeverityBadge( { severity } ) {
		const color = SEVERITY_COLORS[ severity ] || '#999';
		return wp.element.createElement(
			'span',
			{
				style: {
					background:   color,
					color:        '#fff',
					borderRadius: '3px',
					padding:      '1px 6px',
					fontSize:     '10px',
					fontWeight:   'bold',
					marginRight:  '6px',
					textTransform: 'uppercase',
				},
			},
			severity || 'minor'
		);
	}

	function IssueRow( { issue } ) {
		const [ open, setOpen ] = useState( false );
		const severity = issue.severity || 'minor';
		return wp.element.createElement(
			'div',
			{
				style: {
					borderLeft:    '3px solid ' + ( SEVERITY_COLORS[ severity ] || '#999' ),
					paddingLeft:   '8px',
					marginBottom:  '8px',
					background:    '#fafafa',
					padding:       '6px 8px',
					cursor:        'pointer',
				},
				onClick: function() { setOpen( ! open ); },
			},
			wp.element.createElement( SeverityBadge, { severity: severity } ),
			wp.element.createElement( 'strong', null, issue.type ),
			wp.element.createElement( 'br' ),
			wp.element.createElement( 'span', { style: { fontSize: '12px' } }, issue.message ),
			open && wp.element.createElement(
				'div',
				{ style: { marginTop: '4px', fontSize: '11px', color: '#555' } },
				wp.element.createElement( 'em', null, '💡 ' + getSuggestion( issue.type ) )
			)
		);
	}

	function A11yScannerPanel() {
		const [ issues, setIssues ]   = useState( null );
		const [ loading, setLoading ] = useState( false );
		const [ error, setError ]     = useState( null );
		const [ version, setVersion ] = useState( 'v2' );

		const content = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'content' );
		}, [] );

		const postId = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		}, [] );

		function runScan() {
			setLoading( true );
			setError( null );
			setIssues( null );

			const path = '/simple-a11y/' + version + '/scan';

			wp.apiFetch( {
				path:   path,
				method: 'POST',
				data:   { content: content || '' },
			} )
				.then( function ( res ) {
					setIssues( res.issues || [] );
				} )
				.catch( function ( err ) {
					// v2 may require auth; fall back to v1.
					if ( version === 'v2' ) {
						setVersion( 'v1' );
					}
					setError( err.message || __( 'Scan failed.', 'wp-simple-a11y-scanner' ) );
				} )
				.finally( function () {
					setLoading( false );
				} );
		}

		function runSocialScan() {
			if ( ! postId ) return;
			setLoading( true );
			setError( null );
			wp.apiFetch( {
				path:   '/simple-a11y/v2/scan/social/' + postId,
				method: 'GET',
			} )
				.then( function ( res ) {
					setIssues( res.issues || [] );
				} )
				.catch( function ( err ) {
					setError( err.message || __( 'Social scan failed.', 'wp-simple-a11y-scanner' ) );
				} )
				.finally( function () {
					setLoading( false );
				} );
		}

		const criticalCount = issues ? issues.filter( i => i.severity === 'critical' ).length : 0;
		const majorCount    = issues ? issues.filter( i => i.severity === 'major' ).length : 0;

		return wp.element.createElement(
			PluginSidebar,
			{
				name:  'simple-a11y-scanner',
				title: __( 'A11y Scanner', 'wp-simple-a11y-scanner' ),
				icon:  'universal-access',
			},
			wp.element.createElement(
				PanelBody,
				{ title: __( 'Accessibility Check', 'wp-simple-a11y-scanner' ), initialOpen: true },
				wp.element.createElement(
					'div',
					{ style: { display: 'flex', gap: '8px', marginBottom: '8px' } },
					wp.element.createElement(
						Button,
						{ variant: 'primary', onClick: runScan, disabled: loading, style: { flex: 1 } },
						loading
							? wp.element.createElement( Spinner, null )
							: __( 'Scan Content', 'wp-simple-a11y-scanner' )
					),
					postId && wp.element.createElement(
						Button,
						{ variant: 'secondary', onClick: runSocialScan, disabled: loading, style: { flex: 1 } },
						__( 'Social Meta', 'wp-simple-a11y-scanner' )
					)
				),
				error && wp.element.createElement(
					Notice,
					{ status: 'error', isDismissible: true, onRemove: function() { setError( null ); } },
					error
				),
				issues !== null && wp.element.createElement(
					'div',
					null,
					issues.length === 0
						? wp.element.createElement(
							Notice,
							{ status: 'success', isDismissible: false },
							__( '✓ No accessibility issues found.', 'wp-simple-a11y-scanner' )
						  )
						: wp.element.createElement(
							'div',
							null,
							wp.element.createElement(
								'p',
								{ style: { fontWeight: 'bold', marginBottom: '8px' } },
								sprintf(
									__( '%d issue(s) found.', 'wp-simple-a11y-scanner' ),
									issues.length
								),
								criticalCount > 0 && wp.element.createElement( SeverityBadge, { severity: 'critical' } ),
								majorCount > 0    && wp.element.createElement( SeverityBadge, { severity: 'major' } )
							),
							issues.map( function ( issue, idx ) {
								return wp.element.createElement( IssueRow, { key: idx, issue: issue } );
							} )
						  )
				)
			)
		);
	}

	registerPlugin( 'simple-a11y-scanner', { render: A11yScannerPanel } );
} )( window.wp );
