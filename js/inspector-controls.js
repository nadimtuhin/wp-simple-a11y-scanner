/**
 * A11y Scanner — Gutenberg Inspector Controls Panel
 *
 * Adds an A11y Scanner panel to the block editor sidebar so editors can
 * trigger a scan of the current post content without leaving the editor.
 */
( function ( wp ) {
	const { registerPlugin }    = wp.plugins;
	const { PluginSidebar }     = wp.editPost;
	const { PanelBody, Button, Notice, Spinner } = wp.components;
	const { useState }          = wp.element;
	const { useSelect }         = wp.data;
	const { __ }                = wp.i18n;

	function A11yScannerPanel() {
		const [ issues, setIssues ]   = useState( null );
		const [ loading, setLoading ] = useState( false );
		const [ error, setError ]     = useState( null );

		const content = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'content' );
		}, [] );

		function runScan() {
			setLoading( true );
			setError( null );
			setIssues( null );

			wp.apiFetch( {
				path: '/simple-a11y/v1/scan',
				method: 'POST',
				data: { content: content || '' },
			} )
				.then( function ( res ) {
					setIssues( res.issues || [] );
				} )
				.catch( function ( err ) {
					setError( err.message || __( 'Scan failed.', 'wp-simple-a11y-scanner' ) );
				} )
				.finally( function () {
					setLoading( false );
				} );
		}

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
					Button,
					{ variant: 'primary', onClick: runScan, disabled: loading },
					loading
						? wp.element.createElement( Spinner, null )
						: __( 'Scan This Post', 'wp-simple-a11y-scanner' )
				),
				error && wp.element.createElement(
					Notice,
					{ status: 'error', isDismissible: false },
					error
				),
				issues !== null && wp.element.createElement(
					'div',
					null,
					wp.element.createElement(
						'p',
						null,
						issues.length === 0
							? __( '✓ No accessibility issues found.', 'wp-simple-a11y-scanner' )
							: /* translators: %d = number of issues */
							  sprintf( __( '%d issue(s) found.', 'wp-simple-a11y-scanner' ), issues.length )
					),
					issues.map( function ( issue, idx ) {
						return wp.element.createElement(
							Notice,
							{ key: idx, status: 'warning', isDismissible: false },
							wp.element.createElement( 'strong', null, issue.type ),
							' — ',
							issue.message
						);
					} )
				)
			)
		);
	}

	registerPlugin( 'simple-a11y-scanner', { render: A11yScannerPanel } );
} )( window.wp );
