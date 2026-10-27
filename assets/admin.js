/* Migrate Plus admin screen. React from wp-element, written with createElement so there is no build step. */
( function () {
	'use strict';

	const D = window.MIGRATEPLUS_DATA;
	const mount = document.getElementById( 'migrateplus-app' );
	const wpElement = window.wp && window.wp.element;
	if ( ! D || ! mount || ! wpElement ) {
		return;
	}
	const { createElement, createPortal, Fragment, useLayoutEffect, useRef, useState } = wpElement;

	// Strings go through wp.i18n (text domain 'migrateplus'). Without it, as before WordPress 5.0, they stay in English.
	const i18n = window.wp.i18n;
	const __ = i18n ? i18n.__ : ( text ) => text;
	const _n = i18n ? i18n._n : ( single, plural, n ) => ( 1 === n ? single : plural );
	const sprintf = i18n ? i18n.sprintf : ( format, ...args ) => {
		let next = 0;
		return String( format ).replace( /%(?:(\d+)\$)?[sd]/g, ( m, pos ) => String( args[ pos ? pos - 1 : next++ ] ) );
	};

	// Handlers read the DOM straight after render(), so it has to commit before it returns. A
	// createRoot root only does that inside flushSync. WordPress before 6.3 is missing one of
	// them, and its legacy render() is synchronous anyway.
	const concurrent = !! ( wpElement.createRoot && wpElement.flushSync );
	const flushSync = concurrent ? wpElement.flushSync : ( fn ) => fn();
	const reactRoot = concurrent ? wpElement.createRoot( mount ) : null;

	// Handlers change this and call render(). State only one component uses stays in that component.
	const state = {
		tab: [ 'history', 'export', 'replace' ].includes( D.view ) ? D.view : 'new',
		step: 'source',
		sourceId: D.sources[ 0 ] ? D.sources[ 0 ].id : '',
		job: null,
		records: [],
		fields: [],
		mapping: { fields: {}, meta: [], taxonomies: [], acf: {} },
		options: Object.assign( {}, D.defaults ),
		suggested: {}, // Post types the mapping step has filled in suggestions for, in this job.
		running: false,
		paused: false,
		resumedAt: 0, // When Resume was last pressed, so a double click doesn't pause straight away.
		lockWait: false, // Another tab or request holds the batch lock; the run is waiting for it.
		error: '',
		sourceError: '', // A failed preview, shown above the source form as it was typed.
		busy: false,
		fileMode: {}, // 'source:fieldKey' => 'upload' | 'url'
		runClock: null, // { t, processed } when the current run started, for the ETA
		logFilter: 'all',
		logQuery: '',
		dups: null, // The duplicates screen: its job, the check's answer and the choices made there.
		historyQuery: '',
		historyJobs: null, // null while loading.
		historyError: '',
		historyExports: null,
		exportsError: '',
		picked: { job: new Set(), export: new Set() }, // History rows ticked for bulk delete.
		direct: false, // A MigratePlus export imported straight from the upload screen, without the mapping step.
		savedMappings: D.mappings || [], // Saved mappings for JSON that is not a MigratePlus export.
		savedPick: '', // The saved mapping chosen in the select, or offered because the file has its fields.
		savedName: '',
		savedNotice: null, // { type, text } shown on the saved mappings panel.
		exportArgs: Object.assign( {}, D.exportDefaults, {
			statuses: ( D.exportDefaults.statuses || [ 'publish' ] ).slice(),
			post_types: ( D.exportDefaults.post_types || [ 'post' ] ).slice(),
		} ),
		exportPicks: [], // Hand-picked posts for the export: { id, title, type, label, status, date }.
		exportPicksOpen: false, // The hand-picked card is open; it stays open while still empty.
		exportPickSearch: '',
		exportPickResults: null, // null until a search answers.
		exportPickBusy: false,
		exportPickError: '',
		exportCount: { text: __( 'Counting…', 'migrateplus' ), warn: false, empty: false },
		lastExport: null,
		replace: {
			search: '',
			replace: '',
			tables: ( D.tables || [] ).map( ( t ) => t.name ),
			case_insensitive: 0,
			whole_urls: 1,
			guids: 0,
			dry_run: 1,
			result: null,
			problem: '',
		},
		replaceRuns: null, // null while loading.
		replaceRunsError: '',
	};

	// Helpers

	/**
	 * A URL from a file or the server, or null unless it is http(s) or relative: a javascript: or
	 * data: link would run in the admin. Browsers drop tabs and newlines inside a URL and spaces
	 * and control characters before it, so they are dropped here too before reading the scheme.
	 */
	function safeUrl( url ) {
		const s = String( url ).replace( /[\t\n\r]/g, '' ).replace( /^[\u0000- ]+/, '' );
		const scheme = /^([a-z][a-z0-9+.-]*):/i.exec( s );
		return ! scheme || /^https?$/i.test( scheme[ 1 ] ) ? url : null;
	}

	/** Component state whose setter redraws before it returns, like render(). */
	function useImmediateState( initial ) {
		const [ value, setValue ] = useState( initial );
		return [ value, ( next ) => flushSync( () => setValue( next ) ) ];
	}

	function apiBody( action, data ) {
		const body = data instanceof FormData ? data : new FormData();
		if ( ! ( data instanceof FormData ) ) {
			Object.entries( data || {} ).forEach( ( [ k, v ] ) => body.append( k, typeof v === 'object' ? JSON.stringify( v ) : v ) );
		}
		body.append( 'action', 'migrateplus_' + action );
		body.append( 'nonce', D.nonce );
		return body;
	}

	function parseResponse( status, text ) {
		let json = null;
		try {
			json = JSON.parse( text );
		} catch ( e ) {}
		// admin-ajax answers a bare "0" when the request never reached MigratePlus, e.g. a post larger than post_max_size.
		if ( ! json || 'object' !== typeof json ) {
			/* translators: 1: HTTP status code, 2: the start of the server's answer */
			throw new Error( sprintf( __( 'Unexpected server response (HTTP %1$s). %2$s', 'migrateplus' ), status, String( text ).replace( /<[^>]+>/g, ' ' ).trim().slice( 0, 300 ) ) );
		}
		if ( ! json.success ) {
			const err = new Error( ( json.data && json.data.message ) || __( 'Request failed.', 'migrateplus' ) );
			// The message is translated; the WP_Error code is what to match on.
			err.code = ( json.data && json.data.code ) || '';
			throw err;
		}
		return json.data || {};
	}

	// An import batch may run for the 300 seconds the runner allows PHP, plus the time to send and answer.
	const API_TIMEOUT = 6 * 60 * 1000;

	/**
	 * POST to admin-ajax. A request that never reached the server, or got no answer in time,
	 * fails with err.network set, so a long run can retry it.
	 */
	function api( action, data ) {
		const controller = new AbortController();
		const timer = setTimeout( () => controller.abort(), API_TIMEOUT );
		return fetch( D.ajaxUrl, { method: 'POST', body: apiBody( action, data ), credentials: 'same-origin', signal: controller.signal } )
			.then( ( r ) => r.text().then( ( t ) => parseResponse( r.status, t ) ), ( e ) => {
				const err = new Error( e && 'AbortError' === e.name ? __( 'The server took too long to respond.', 'migrateplus' ) : __( 'Network error. Check your connection and try again.', 'migrateplus' ) );
				err.network = true;
				throw err;
			} )
			.finally( () => clearTimeout( timer ) );
	}

	/** api(), retried after 2, 4 and 8 seconds when the request never got an answer, as an import batch is. */
	function apiWithRetry( action, data ) {
		let retries = 0;
		const attempt = () => api( action, data ).catch( ( err ) => {
			if ( ! err.network || retries >= 3 ) {
				throw err;
			}
			return new Promise( ( resolve ) => setTimeout( resolve, 2000 * Math.pow( 2, retries++ ) ) ).then( attempt );
		} );
		return attempt();
	}

	/**
	 * Same as api(), but uses XHR so upload progress can be reported.
	 * onUpload( loaded, total ) fires while the request body is sent; onSent() once it is fully uploaded.
	 * The promise has abort(), which rejects it with err.aborted set.
	 */
	function apiWithProgress( action, data, onUpload, onSent ) {
		const xhr = new XMLHttpRequest();
		const request = new Promise( ( resolve, reject ) => {
			xhr.open( 'POST', D.ajaxUrl );
			xhr.withCredentials = true;
			xhr.timeout = API_TIMEOUT;
			xhr.upload.addEventListener( 'progress', ( e ) => {
				if ( e.lengthComputable && onUpload ) {
					onUpload( e.loaded, e.total );
				}
			} );
			xhr.upload.addEventListener( 'load', () => onSent && onSent() );
			xhr.addEventListener( 'load', () => {
				try {
					resolve( parseResponse( xhr.status, xhr.responseText ) );
				} catch ( err ) {
					reject( err );
				}
			} );
			xhr.addEventListener( 'error', () => reject( new Error( __( 'Network error. Check your connection and try again.', 'migrateplus' ) ) ) );
			xhr.addEventListener( 'timeout', () => reject( new Error( __( 'The server took too long to respond.', 'migrateplus' ) ) ) );
			xhr.addEventListener( 'abort', () => {
				const err = new Error( __( 'The upload was cancelled.', 'migrateplus' ) );
				err.aborted = true;
				reject( err );
			} );
			xhr.send( apiBody( action, data ) );
		} );
		request.abort = () => xhr.abort();
		return request;
	}

	function formatBytes( n ) {
		if ( ! n ) {
			return '0 B';
		}
		const units = [ 'B', 'KB', 'MB', 'GB' ];
		const i = Math.min( units.length - 1, Math.floor( Math.log( n ) / Math.log( 1024 ) ) );
		return ( n / Math.pow( 1024, i ) ).toFixed( i ? 1 : 0 ) + ' ' + units[ i ];
	}

	function formatDuration( seconds ) {
		seconds = Math.max( 0, Math.round( seconds ) );
		if ( seconds < 60 ) {
			/* translators: %s: seconds */
			return sprintf( __( '%ss', 'migrateplus' ), seconds );
		}
		const m = Math.floor( seconds / 60 );
		if ( m < 60 ) {
			/* translators: 1: minutes, 2: seconds */
			return sprintf( __( '%1$sm %2$ss', 'migrateplus' ), m, String( seconds % 60 ).padStart( 2, '0' ) );
		}
		/* translators: 1: hours, 2: minutes */
		return sprintf( __( '%1$sh %2$sm', 'migrateplus' ), Math.floor( m / 60 ), String( m % 60 ).padStart( 2, '0' ) );
	}

	function hostOf( url ) {
		try {
			return new URL( url ).host;
		} catch ( e ) {
			return url;
		}
	}

	// Progress modal

	/**
	 * A single reusable modal with a progress bar, drawn by <Modal> into document.body.
	 *
	 * modal.open( { title, message, percent, detail, stats, actions, tone, icon, dismissible, input, content } )
	 * modal.update( { ...same keys } )   percent: number 0-100, or null for indeterminate.
	 * modal.close()
	 *
	 * The header icon follows the tone (spinner while working, check on success, pause, error);
	 * icon: 'file' overrides it. The primary action is always placed last (rightmost).
	 * open( { kind: 'run' } ) shows the running import, worked out from the job on every render.
	 */
	const modal = ( function () {
		let opts = null;
		let opened = 0; // Bumped by open(), so the dialog moves focus to its default button again.
		let lastFocus = null;
		let lastFocusId = '';

		function open( next ) {
			if ( ! opts ) {
				lastFocus = document.activeElement;
				lastFocusId = lastFocus ? lastFocus.id : '';
				document.body.classList.add( 'migrateplus-modal-open' );
			}
			opts = Object.assign( {}, next );
			opened++;
			render();
		}

		function update( next ) {
			if ( ! opts ) {
				return;
			}
			opts = Object.assign( opts, next || {} );
			render();
		}

		function close() {
			if ( ! opts ) {
				return;
			}
			opts = null;
			document.body.classList.remove( 'migrateplus-modal-open' );
			render();
			// A control that is gone, or disabled now, can't take focus back: then one with its id, or the page heading.
			let back = lastFocus && document.body.contains( lastFocus ) && ! lastFocus.disabled ? lastFocus : null;
			back = back || ( lastFocusId && document.getElementById( lastFocusId ) ) || document.querySelector( '.migrateplus-wrap h1' );
			if ( back ) {
				if ( 'H1' === back.tagName ) {
					back.setAttribute( 'tabindex', '-1' );
				}
				back.focus( { preventScroll: true } );
			}
			lastFocus = null;
			lastFocusId = '';
		}

		function current() {
			return opts && 'run' === opts.kind ? runModalOptions() : opts;
		}

		return {
			open,
			update,
			close,
			current,
			opened: () => opened,
			isOpen: () => !! opts,
			kind: () => ( opts && opts.kind ) || '',
			dismissible: () => !! ( opts && current().dismissible ),
		};
	}() );

	// Disabled and hidden controls can't take focus; focusing one drops it to the page behind.
	const FOCUSABLE = 'input:not(:disabled):not([hidden]), select:not(:disabled):not([hidden]), button:not(:disabled):not([hidden]), a[href]:not([hidden])';

	const ICONS = { success: 'dashicons-yes', error: 'dashicons-warning', paused: 'dashicons-controls-pause', file: 'dashicons-database-import', danger: 'dashicons-trash', question: 'dashicons-editor-help', working: '' };

	function Modal( { o } ) {
		const dialog = useRef( null );
		const buttons = useRef( null );
		const status = o.icon || { success: 'success', error: 'error', paused: 'paused' }[ o.tone ] || 'working';
		const indeterminate = o.percent === null || o.percent === undefined;
		const pct = indeterminate ? 0 : Math.max( 0, Math.min( 100, o.percent ) );
		// A soft moving sheen on the bar while work is in progress.
		const running = 'working' === status && ! indeterminate && pct < 100;
		// Primary action last, so it sits on the right.
		const actions = ( o.actions || [] ).filter( ( a ) => ! a.primary ).concat( ( o.actions || [] ).filter( ( a ) => a.primary ) );
		const sig = JSON.stringify( actions.map( ( a ) => [ a.label, a.primary, a.danger, a.href, a.disabled ] ) );

		// Focus the first field, or the default button, when the dialog opens and when its buttons change, not on every tick.
		const shownOpen = useRef( 0 );
		useLayoutEffect( () => {
			const reopened = shownOpen.current !== modal.opened();
			shownOpen.current = modal.opened();
			const active = document.activeElement;
			if ( ! reopened && dialog.current.contains( active ) && active.matches( 'input, select' ) ) {
				return; // Choosing in a field enabled a button: focus stays where the user is.
			}
			const field = dialog.current.querySelector( '.migrateplus-modal-input' );
			if ( field && ! field.disabled ) {
				field.focus( { preventScroll: true } );
				if ( field.select ) {
					field.select();
				}
				return;
			}
			const target = buttons.current.querySelector( '.button-primary:not(:disabled)' ) || buttons.current.querySelector( FOCUSABLE ) || dialog.current;
			target.focus( { preventScroll: true } );
		}, [ sig, modal.opened() ] );

		/** Escape and the ✕ button do the same thing: the caller's onDismiss, or close. */
		const dismiss = () => {
			if ( typeof o.onDismiss === 'function' ) {
				o.onDismiss();
			} else {
				modal.close();
			}
		};

		const onKeyDown = ( e ) => {
			if ( e.key === 'Escape' && o.dismissible ) {
				e.preventDefault();
				dismiss();
			} else if ( e.key === 'Tab' ) {
				// Keep focus inside the dialog, and bring it back when a click on the overlay dropped it to the page.
				const focusables = dialog.current.querySelectorAll( FOCUSABLE );
				if ( ! focusables.length ) {
					e.preventDefault();
					dialog.current.focus();
					return;
				}
				const first = focusables[ 0 ];
				const last = focusables[ focusables.length - 1 ];
				const inside = dialog.current.contains( document.activeElement ) && document.activeElement !== dialog.current;
				if ( ! inside ) {
					e.preventDefault();
					( e.shiftKey ? last : first ).focus();
				} else if ( e.shiftKey && document.activeElement === first ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && document.activeElement === last ) {
					e.preventDefault();
					first.focus();
				}
			}
		};
		// On the document, not the overlay: focus can be outside the dialog, e.g. on the body after a click on the overlay.
		const keyHandler = useRef( null );
		keyHandler.current = onKeyDown;
		useLayoutEffect( () => {
			const listener = ( e ) => keyHandler.current( e );
			document.addEventListener( 'keydown', listener );
			return () => document.removeEventListener( 'keydown', listener );
		}, [] );

		const barClass = 'migrateplus-progress migrateplus-modal-bar' +
			( indeterminate ? ' is-indeterminate' : '' ) +
			( 'error' === o.tone ? ' is-error' : '' ) +
			( 'paused' === o.tone ? ' is-paused' : '' ) +
			( 'success' === o.tone ? ' is-success' : '' ) +
			( running ? ' is-running' : '' );

		return createPortal( createElement( 'div', { className: 'migrateplus-modal-overlay' },
			createElement( 'div', { ref: dialog, className: 'migrateplus-modal', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'migrateplus-modal-title', 'aria-describedby': 'migrateplus-modal-message', 'data-status': status, tabIndex: -1 },
				createElement( 'header', { className: 'migrateplus-modal-head' },
					createElement( 'span', { className: 'migrateplus-modal-icon', 'aria-hidden': 'true' }, createElement( 'span', { className: 'dashicons ' + ( ICONS[ status ] || '' ) } ) ),
					createElement( 'div', { className: 'migrateplus-modal-heading' },
						// Announced when it changes, e.g. to "Import complete"; the message changes every batch, so it is not.
						createElement( 'h2', { className: 'migrateplus-modal-title', id: 'migrateplus-modal-title', 'aria-live': 'polite' }, o.title || '' ),
						createElement( 'p', { className: 'migrateplus-modal-message', id: 'migrateplus-modal-message', hidden: ! o.message }, o.message || '' )
					),
					createElement( 'button', { type: 'button', className: 'migrateplus-modal-close', 'aria-label': __( 'Close', 'migrateplus' ), title: __( 'Close', 'migrateplus' ), hidden: ! o.dismissible, onClick: dismiss },
						createElement( 'span', { className: 'dashicons dashicons-no-alt', 'aria-hidden': 'true' } ) )
				),
				// bar: false hides the progress bar and its numbers (e.g. for a confirmation).
				createElement( 'div', { className: 'migrateplus-modal-meta', hidden: false === o.bar },
					createElement( 'span', { className: 'migrateplus-modal-percent' }, indeterminate || o.tone === 'error' ? '' : Math.floor( pct ) + '%' ),
					createElement( 'span', { className: 'migrateplus-modal-detail' }, o.detail || '' )
				),
				createElement( 'div', { className: barClass, role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': indeterminate ? null : Math.round( pct ), 'aria-labelledby': 'migrateplus-modal-title', hidden: !! o.outcome || false === o.bar },
					createElement( 'span', { className: 'migrateplus-modal-fill', style: indeterminate ? null : { width: pct + '%' } } ) ),
				// Outcome mode: the bar is split into created / updated / skipped / failed.
				createElement( OutcomeBar, o.outcome
					? { stats: o.outcome.stats, total: o.outcome.total, size: 'lg', indeterminate, tone: o.tone, label: o.title, running }
					: { stats: {}, total: 0, hidden: true } ),
				createElement( 'ul', { className: 'migrateplus-legend migrateplus-modal-stats', hidden: ! ( o.stats && o.stats.length ) }, ( o.stats || [] ).map( ( s, i ) =>
					createElement( 'li', { key: i, className: 'is-' + s.key + ( Number( s.value ) ? '' : ' is-zero' ) },
						createElement( 'strong', null, ( Number( s.value ) || 0 ).toLocaleString() ),
						createElement( 'span', null, s.label ) )
				) ),
				o.content || null,
				// A text field, as the rename dialog asks for a name. Keyed by the open, so each dialog starts from its own value.
				o.input ? createElement( 'input', {
					key: modal.opened(),
					type: 'text',
					id: 'migrateplus-modal-input',
					className: 'migrateplus-modal-input',
					defaultValue: o.input.value,
					'aria-label': o.input.label,
					maxLength: 100,
					onKeyDown: ( e ) => {
						const primary = buttons.current.querySelector( '.button-primary:not(:disabled)' );
						if ( 'Enter' === e.key && primary ) {
							e.preventDefault();
							primary.click();
						}
					},
				} ) : null,
				// The run's latest log line stays on one line; any other note is read in full.
				createElement( 'p', { className: 'migrateplus-modal-note' + ( 'error' === o.tone ? ' is-error' : '' ) + ( 'run' === o.kind && 'error' !== o.tone ? ' is-log' : '' ), 'aria-live': 'polite', hidden: ! o.note }, o.note || '' ),
				createElement( 'div', { ref: buttons, className: 'migrateplus-modal-actions', hidden: ! actions.length }, actions.map( ( a, i ) => {
					const className = 'button' + ( a.primary ? ' button-primary' : '' ) + ( a.danger ? ' migrateplus-danger' : '' );
					return a.href
						? createElement( 'a', { key: i, className, href: safeUrl( a.href ) }, a.label )
						: createElement( 'button', { key: i, type: 'button', className, disabled: a.disabled, onClick: a.onClick }, a.label );
				} ) )
			)
		), document.body );
	}

	function normalizeMapping( m ) {
		m = m || {};
		return {
			fields: m.fields && ! Array.isArray( m.fields ) ? Object.assign( {}, m.fields ) : {},
			meta: Array.isArray( m.meta ) ? m.meta.slice() : [],
			taxonomies: Array.isArray( m.taxonomies ) ? m.taxonomies.slice() : [],
			acf: m.acf && ! Array.isArray( m.acf ) ? Object.assign( {}, m.acf ) : {},
		};
	}

	/** ACF fields registered for a post type (empty when ACF is not active). */
	function acfFields( postTypeName ) {
		return D.acf && D.acf.active && D.acf.fields[ postTypeName ] ? D.acf.fields[ postTypeName ] : [];
	}

	/**
	 * Source fields plus their parents ("acf.items" for "acf.items.0.title"),
	 * so nested data can feed repeaters, groups, galleries and relationships.
	 */
	function nestedFields() {
		const set = new Set( state.fields );
		const parents = [];
		state.fields.forEach( ( f ) => {
			const parts = f.split( '.' );
			for ( let i = 1; i < parts.length; i++ ) {
				if ( /^\d+$/.test( parts[ i - 1 ] ) ) {
					break;
				}
				const prefix = parts.slice( 0, i ).join( '.' );
				if ( ! set.has( prefix ) ) {
					set.add( prefix );
					parents.push( prefix );
				}
			}
		} );
		return parents;
	}

	// Custom field rows the user added or edited on the mapping step. Suggestions never remove them.
	const userMetaRows = new WeakSet();

	/**
	 * Suggest ACF and taxonomy mappings for the current post type, once per post type, and
	 * custom fields once per job: a row the user deleted is not added back when "Import as" changes.
	 * Matches acf.<name>, meta.<name>, <name> and the field label (case-insensitive).
	 */
	function suggestExtras() {
		const pt = state.options.post_type;
		if ( state.suggested[ pt ] ) {
			return;
		}
		const firstForJob = ! Object.keys( state.suggested ).length;
		state.suggested[ pt ] = true;
		const m = state.mapping;
		const lower = {};
		state.fields.concat( nestedFields() ).forEach( ( f ) => {
			lower[ f.toLowerCase() ] = f;
		} );
		const used = new Set( Object.values( m.acf ) );
		acfFields( pt ).forEach( ( f ) => {
			if ( m.acf[ f.key ] || m.acf[ f.name ] ) {
				return;
			}
			const slug = String( f.label ).toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_|_$/g, '' );
			const names = [ 'acf.' + f.name, f.name, 'meta.' + f.name, 'acf_' + f.name, 'acf.' + slug, slug ].map( ( n ) => n.toLowerCase() );
			const hit = names.map( ( n ) => lower[ n ] ).find( ( v ) => v && ! used.has( v ) );
			if ( hit ) {
				m.acf[ f.key ] = hit;
				used.add( hit );
				// Don't also write it as a plain custom field, unless the user put that row there.
				m.meta = m.meta.filter( ( row ) => row.source !== hit || userMetaRows.has( row ) );
			}
		} );
		// Custom fields from MigratePlus / WordPress exports ("meta.<key>"), skipping private keys.
		const acfNames = new Set( acfFields( pt ).map( ( f ) => f.name ) );
		( firstForJob ? state.fields : [] ).forEach( ( f ) => {
			const key = f.startsWith( 'meta.' ) ? f.slice( 5 ) : '';
			if ( ! key || key.startsWith( '_' ) || key.includes( '.' ) || acfNames.has( key ) || used.has( f ) || m.meta.some( ( row ) => row.key === key ) ) {
				return;
			}
			m.meta.push( { key, source: f } );
		} );
		const taxes = postType( pt ).taxonomies;
		taxes.forEach( ( t ) => {
			if ( t.name === 'category' || t.name === 'post_tag' || m.taxonomies.some( ( row ) => row.taxonomy === t.name ) ) {
				return;
			}
			const hit = lower[ ( 'terms.' + t.name ).toLowerCase() ] || lower[ t.name.toLowerCase() ];
			if ( hit ) {
				m.taxonomies.push( { taxonomy: t.name, source: hit } );
			}
		} );
	}

	function postType( name ) {
		return D.postTypes.find( ( p ) => p.name === name ) || D.postTypes[ 0 ];
	}

	function sample( field ) {
		if ( ! field ) {
			return '';
		}
		for ( const r of state.records ) {
			if ( r[ field ] !== undefined && r[ field ] !== '' ) {
				return String( r[ field ] );
			}
		}
		// Nested data: summarise the child values.
		for ( const r of state.records ) {
			const keys = Object.keys( r ).filter( ( k ) => k.startsWith( field + '.' ) && r[ k ] !== '' );
			if ( keys.length ) {
				const rows = new Set( keys.map( ( k ) => k.slice( field.length + 1 ).split( '.' )[ 0 ] ) );
				const isList = [ ...rows ].every( ( x ) => /^\d+$/.test( x ) );
				return isList
					/* translators: 1: number of items in a list, 2: the first value */
					? sprintf( _n( '%1$s item: %2$s', '%1$s items: %2$s', rows.size, 'migrateplus' ), rows.size, r[ keys[ 0 ] ] )
					/* translators: 1: number of values in an object, 2: the first value */
					: sprintf( _n( '%1$s value: %2$s', '%1$s values: %2$s', keys.length, 'migrateplus' ), keys.length, r[ keys[ 0 ] ] );
			}
		}
		return '';
	}

	function truncate( s, n ) {
		s = String( s === null || s === undefined ? '' : s );
		return s.length > n ? s.slice( 0, n ) + '…' : s;
	}

	/**
	 * Above this many source fields, a select gets its options when it is focused. Every mapping
	 * row has a select listing every field, and each render goes over them all: a few hundred
	 * fields is a few thousand options, but a very wide file makes hundreds of thousands and
	 * freezes the tab.
	 */
	const LAZY_FIELDS = 300;

	function FieldSelect( { value, onChange, nested, label } ) {
		const [ filled, setFilled ] = useImmediateState( state.fields.length <= LAZY_FIELDS );
		const parents = nested ? nestedFields() : [];
		const isParent = !! value && ! state.fields.includes( value ) && parents.includes( value );
		const known = !! value && ( isParent || state.fields.includes( value ) );
		return createElement( 'select', {
			className: 'migrateplus-field-select',
			'aria-label': label || __( 'Source field', 'migrateplus' ),
			value: value || '',
			onChange: ( e ) => onChange( e.target.value ),
			onFocus: filled ? null : () => setFilled( true ),
		},
			createElement( 'option', { value: '' }, __( '(not mapped)', 'migrateplus' ) ),
			filled ? state.fields.map( ( f ) => createElement( 'option', { key: f, value: f }, f ) ) : null,
			/* translators: %s: a source field that holds a list or object */
			filled && parents.length ? createElement( 'optgroup', { label: __( 'Nested data (lists and objects)', 'migrateplus' ) }, parents.map( ( f ) => createElement( 'option', { key: f, value: f }, sprintf( __( '%s (all)', 'migrateplus' ), f ) ) ) ) : null,
			// Until then only the mapped field, so the select still shows it.
			! filled && known ? createElement( 'option', { value }, isParent ? sprintf( __( '%s (all)', 'migrateplus' ), value ) : value ) : null,
			/* translators: %s: a source field name */
			value && ! known ? createElement( 'option', { value }, sprintf( __( '%s (not in preview)', 'migrateplus' ), value ) ) : null
		);
	}

	function Notice( { type, message } ) {
		return createElement( 'div', { className: 'notice notice-' + type + ' inline migrateplus-notice' }, createElement( 'p', null, message ) );
	}

	/**
	 * A confirmation in the plugin's modal, in place of window.confirm (and, with input, of
	 * window.prompt). The modal does not block, so whatever follows the answer goes in onConfirm,
	 * which gets the text field's value. Its button is disabled once clicked so the request can't
	 * be sent twice; onConfirm then closes the modal or updates it.
	 */
	function ask( { title, message, label, danger, input, onConfirm } ) {
		modal.open( {
			kind: 'confirm',
			// The spinner is for work in progress; until the user answers, show what is being asked.
			icon: danger ? 'danger' : 'question',
			bar: false,
			dismissible: true,
			title,
			message,
			input,
			actions: [
				{ label, primary: true, danger, onClick: () => {
					const field = document.getElementById( 'migrateplus-modal-input' );
					const value = field ? field.value : '';
					// No icon of its own: the tone's shows, the spinner now and the result's once onConfirm updates the modal.
					/* translators: %s: the label of the button that was pressed, e.g. Delete */
					modal.update( { icon: null, dismissible: false, actions: [ { label: sprintf( __( '%s…', 'migrateplus' ), label ), primary: true, danger, disabled: true } ] } );
					onConfirm( value );
				} },
				{ label: __( 'Cancel', 'migrateplus' ), onClick: () => modal.close() },
			],
		} );
	}

	/** A failed request's message in the modal, in place of window.alert. */
	function modalError( title, err ) {
		modal.open( {
			kind: 'error',
			bar: false,
			tone: 'error',
			dismissible: true,
			title,
			note: err.message,
			actions: [ { label: __( 'Close', 'migrateplus' ), primary: true, onClick: () => modal.close() } ],
		} );
	}

	function formatDate( ts ) {
		return new Date( ts * 1000 ).toLocaleString();
	}

	function relativeTime( ts ) {
		const diff = Date.now() / 1000 - ts;
		if ( diff < 60 ) {
			return __( 'just now', 'migrateplus' );
		}
		if ( diff < 3600 ) {
			/* translators: %s: minutes */
			return sprintf( __( '%s min ago', 'migrateplus' ), Math.floor( diff / 60 ) );
		}
		if ( diff < 86400 ) {
			const hours = Math.floor( diff / 3600 );
			/* translators: %s: hours */
			return sprintf( _n( '%s hour ago', '%s hours ago', hours, 'migrateplus' ), hours );
		}
		if ( diff < 86400 * 7 ) {
			const d = Math.floor( diff / 86400 );
			/* translators: %s: days */
			return d === 1 ? __( 'yesterday', 'migrateplus' ) : sprintf( _n( '%s day ago', '%s days ago', d, 'migrateplus' ), d );
		}
		return new Date( ts * 1000 ).toLocaleDateString();
	}

	const STATUS_LABELS = {
		draft: __( 'Not started', 'migrateplus' ),
		ready: __( 'Ready', 'migrateplus' ),
		running: __( 'Running', 'migrateplus' ),
		complete: __( 'Complete', 'migrateplus' ),
		failed: __( 'Failed', 'migrateplus' ),
		rolled_back: __( 'Rolled back', 'migrateplus' ),
	};

	const OUTCOMES = [
		[ 'created', __( 'Created', 'migrateplus' ), __( 'Would create', 'migrateplus' ) ],
		[ 'updated', __( 'Updated', 'migrateplus' ), __( 'Would update', 'migrateplus' ) ],
		[ 'skipped', __( 'Skipped', 'migrateplus' ), __( 'Skipped', 'migrateplus' ) ],
		[ 'failed', __( 'Failed', 'migrateplus' ), __( 'Failed', 'migrateplus' ) ],
	];

	/**
	 * Progress bar split by outcome: created / updated / skipped / failed.
	 * Segments are sized against the total (or the processed count when the total is unknown);
	 * the same nodes are kept between renders, so widths animate.
	 */
	function OutcomeBar( { stats, total, size, indeterminate, tone, label, running, hidden } ) {
		const processed = Number( stats.processed ) || 0;
		const base = Math.max( Number( total ) || 0, processed, 1 );
		return createElement( 'div', {
			className: 'migrateplus-obar' + ( size ? ' is-' + size : '' ) + ( indeterminate ? ' is-indeterminate' : '' ) + ( tone ? ' is-' + tone : '' ) + ( running ? ' is-running' : '' ),
			role: 'progressbar',
			'aria-valuemin': 0,
			'aria-valuemax': 100,
			'aria-valuenow': indeterminate ? null : Math.round( ( processed / base ) * 100 ),
			'aria-label': label || __( 'Progress', 'migrateplus' ),
			hidden,
		},
			OUTCOMES.map( ( [ key ] ) => createElement( 'span', { key, className: 'migrateplus-obar-seg is-' + key, style: { width: ( ( ( Number( stats[ key ] ) || 0 ) / base ) * 100 ) + '%' } } ) ),
			createElement( 'span', { className: 'migrateplus-obar-runner' } )
		);
	}

	function OutcomeLegend( { stats, dry } ) {
		return createElement( 'ul', { className: 'migrateplus-legend' }, OUTCOMES.map( ( [ key, label, dryLabel ] ) =>
			createElement( 'li', { key, className: 'is-' + key + ( Number( stats[ key ] ) ? '' : ' is-zero' ) },
				createElement( 'strong', null, ( Number( stats[ key ] ) || 0 ).toLocaleString() ),
				createElement( 'span', null, dry ? dryLabel : label ) )
		) );
	}

	/** A switch with its label and help text, as the options, Export and Find & Replace screens use. */
	function Toggle( { id, label, help, checked, disabled, onChange } ) {
		return createElement( 'div', { className: 'migrateplus-toggle' + ( disabled ? ' is-disabled' : '' ) },
			createElement( 'input', { type: 'checkbox', role: 'switch', id, className: 'migrateplus-switch', disabled, checked, onChange } ),
			createElement( 'label', { htmlFor: id }, createElement( 'span', null, label ), help ? createElement( 'span', { className: 'migrateplus-help' }, help ) : null )
		);
	}

	// The date fields show m/d/Y; state and the queries keep YYYY-MM-DD.
	const displayDate = ( ymd ) => {
		const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec( ymd || '' );
		return m ? m[ 2 ] + '/' + m[ 3 ] + '/' + m[ 1 ] : ( ymd || '' );
	};
	// YYYY-MM-DD from typed m/d/Y, or null while the text is not a whole, real date.
	const parseDisplayDate = ( text ) => {
		const m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec( text.trim() );
		if ( ! m ) {
			return null;
		}
		const date = new Date( Number( m[ 3 ] ), Number( m[ 1 ] ) - 1, Number( m[ 2 ] ) );
		if ( date.getMonth() !== Number( m[ 1 ] ) - 1 || date.getDate() !== Number( m[ 2 ] ) ) {
			return null;
		}
		return m[ 3 ] + '-' + m[ 1 ].padStart( 2, '0' ) + '-' + m[ 2 ].padStart( 2, '0' );
	};

	// A date field backed by Vanilla Calendar Pro, the calendar Bootstrap's datepicker wraps. Focusing
	// the input opens the popup; picking a day lands in onChange as YYYY-MM-DD. Without the library
	// (an enqueue failure, or jsdom in tests) it stays a plain text input taking typed m/d/Y dates.
	function DateInput( { label, value, onChange } ) {
		const input = useRef( null );
		const picker = useRef( null );
		const change = useRef( onChange );
		change.current = onChange;
		const [ text, setText ] = useState( displayDate( value ) );
		useLayoutEffect( () => {
			const lib = window.VanillaCalendarPro;
			if ( ! lib || ! input.current ) {
				return undefined;
			}
			const calendar = new lib.Calendar( input.current, {
				inputMode: true,
				positionToInput: 'auto',
				selectedTheme: 'light',
				onChangeToInput: ( self ) => {
					change.current( ( self.context.selectedDates && self.context.selectedDates[ 0 ] ) || '' );
					self.hide();
				},
			} );
			calendar.init();
			picker.current = calendar;
			return () => {
				calendar.destroy();
				picker.current = null;
			};
		}, [] );
		useLayoutEffect( () => {
			// The picker, a reset, anything that changes the value from outside: the shown text follows.
			if ( value !== ( '' === text.trim() ? '' : parseDisplayDate( text ) ) ) {
				setText( displayDate( value ) );
			}
			// A half-typed date must not reach the picker: it only gets whole days, or an empty clear.
			if ( picker.current && ( '' === value || /^\d{4}-\d{2}-\d{2}$/.test( value ) ) ) {
				picker.current.set( { selectedDates: value ? [ value ] : [] } );
			}
		}, [ value ] );
		return createElement( 'input', {
			type: 'text',
			ref: input,
			'aria-label': label,
			placeholder: __( 'MM/DD/YYYY', 'migrateplus' ),
			autoComplete: 'off',
			value: text,
			onChange: ( e ) => {
				const raw = e.target.value;
				setText( raw );
				const ymd = '' === raw.trim() ? '' : parseDisplayDate( raw );
				if ( null !== ymd && ymd !== value ) {
					onChange( ymd );
				}
			},
		} );
	}

	const SOURCE_BLURBS = {
		json: __( 'A Migrate Plus export, or any JSON file or API', 'migrateplus' ),
	};

	// Render

	function render() {
		// The mapping step fills in suggestions for a post type the first time it shows it.
		if ( 'new' === state.tab && 'map' === state.step ) {
			suggestExtras();
		}
		if ( concurrent ) {
			flushSync( () => reactRoot.render( createElement( App ) ) );
		} else {
			wpElement.render( createElement( App ), mount );
		}
	}

	function App() {
		const Screen = { history: History, export: Export, replace: Replace }[ state.tab ] || Import;
		const o = modal.current();
		return createElement( Fragment, null, createElement( Screen ), o ? createElement( Modal, { o } ) : null );
	}

	function Import() {
		// A file dropped outside the drop zone would make the browser leave the page to open it.
		useLayoutEffect( () => {
			const keepFileDrops = ( e ) => {
				if ( e.dataTransfer && Array.from( e.dataTransfer.types || [] ).includes( 'Files' ) ) {
					e.preventDefault();
				}
			};
			window.addEventListener( 'dragover', keepFileDrops );
			window.addEventListener( 'drop', keepFileDrops );
			return () => {
				window.removeEventListener( 'dragover', keepFileDrops );
				window.removeEventListener( 'drop', keepFileDrops );
			};
		}, [] );
		const dropScreen = state.step === 'source' && isDropSource();
		let body;
		if ( 'duplicates' === state.step ) {
			body = createElement( Duplicates );
		} else if ( dropScreen ) {
			body = createElement( Fragment, null, createElement( Drop ), createElement( Pull ) );
		} else if ( state.step === 'source' ) {
			body = createElement( Source );
		} else if ( state.step === 'map' ) {
			body = createElement( MapStep );
		} else {
			body = createElement( Run );
		}
		return createElement( Fragment, null,
			! dropScreen && ! state.direct && 'duplicates' !== state.step ? createElement( Stepper ) : null,
			state.error ? createElement( Notice, { type: 'error', message: state.error } ) : null,
			body
		);
	}

	function Stepper() {
		const steps = [
			[ 'source', isDropSource() ? __( 'Upload a file', 'migrateplus' ) : __( 'Choose a source', 'migrateplus' ) ],
			[ 'map', __( 'Map fields', 'migrateplus' ) ],
			[ 'run', __( 'Import', 'migrateplus' ) ],
		];
		const idx = steps.findIndex( ( s ) => s[ 0 ] === state.step );
		return createElement( 'ol', { className: 'migrateplus-route', 'aria-label': __( 'Import steps', 'migrateplus' ) }, steps.map( ( s, i ) =>
			createElement( 'li', {
				key: s[ 0 ],
				className: i === idx ? 'is-current' : ( i < idx ? 'is-done' : '' ),
				'aria-current': i === idx ? 'step' : null,
			},
				createElement( 'span', { className: 'migrateplus-route-dot', 'aria-hidden': 'true' }, i < idx ? '✓' : String( i + 1 ) ),
				createElement( 'span', { className: 'migrateplus-route-label' }, s[ 1 ] ) )
		) );
	}

	// Step 1

	function Source() {
		const source = D.sources.find( ( s ) => s.id === state.sourceId );
		return createElement( Fragment, null,
			D.sources.length > 1 ? createElement( 'div', { className: 'migrateplus-tiles', role: 'radiogroup', 'aria-label': __( 'Source', 'migrateplus' ) }, D.sources.map( ( s ) =>
				createElement( 'label', { key: s.id, className: 'migrateplus-tile' + ( s.id === state.sourceId ? ' is-selected' : '' ) },
					createElement( 'input', { type: 'radio', name: 'migrateplus_source', value: s.id, checked: s.id === state.sourceId, onChange: () => {
						state.sourceId = s.id;
						state.error = '';
						state.sourceError = '';
						render();
					} } ),
					createElement( 'span', { className: 'migrateplus-source-icon migrateplus-icon-' + s.id, 'aria-hidden': 'true' } ),
					createElement( 'span', { className: 'migrateplus-tile-text' },
						createElement( 'strong', null, s.label ),
						SOURCE_BLURBS[ s.id ] ? createElement( 'span', null, SOURCE_BLURBS[ s.id ] ) : null )
				)
			) ) : null,
			// Keep the form as typed; just surface the error.
			state.sourceError ? createElement( Notice, { type: 'error', message: state.sourceError } ) : null,
			// Keyed by source, so switching sources starts from that source's empty form.
			createElement( 'form', { key: source.id, className: 'migrateplus-panel migrateplus-source-form', onSubmit: onPreview },
				createElement( 'header', { className: 'migrateplus-panel-head' },
					createElement( 'span', { className: 'migrateplus-source-icon migrateplus-icon-' + source.id, 'aria-hidden': 'true' } ),
					createElement( 'div', null,
						createElement( 'h2', null, source.label ),
						SOURCE_BLURBS[ source.id ] ? createElement( 'p', null, SOURCE_BLURBS[ source.id ] ) : null )
				),
				createElement( 'div', { className: 'migrateplus-fields' }, source.schema.map( ( f ) => createElement( SourceField, { key: f.key, f } ) ) ),
				createElement( 'footer', { className: 'migrateplus-panel-foot' },
					createElement( 'p', { className: 'migrateplus-foot-note' }, __( 'Nothing is imported yet. You will see a preview and map the fields first.', 'migrateplus' ) ),
					createElement( 'button', { type: 'submit', className: 'button button-primary migrateplus-btn-lg', disabled: state.busy }, __( 'Connect and preview', 'migrateplus' ) )
				)
			)
		);
	}

	/** One setting from a source's schema. The inputs are left uncontrolled: the form is read with FormData on submit. */
	function SourceField( { f } ) {
		const id = 'migrateplus-set-' + f.key;
		const name = f.type === 'file' ? 'file_' + f.key : 'settings[' + f.key + ']';
		let input;
		if ( f.type === 'select' ) {
			input = createElement( 'select', { id, name, defaultValue: f.default }, Object.entries( f.options ).map( ( [ v, l ] ) =>
				createElement( 'option', { key: v, value: v }, l )
			) );
		} else if ( f.type === 'textarea' ) {
			input = createElement( 'textarea', { id, name, rows: 3, className: 'code', spellCheck: false } );
		} else if ( f.type === 'file' ) {
			input = createElement( FileField, { f, id, name } );
		} else {
			input = createElement( 'input', {
				id,
				name,
				type: f.type === 'password' ? 'password' : ( f.type === 'url' ? 'url' : ( f.type === 'number' ? 'number' : 'text' ) ),
				defaultValue: f.default || '',
				placeholder: f.type === 'url' ? 'https://' : null,
				autoComplete: f.type === 'password' ? 'new-password' : 'off',
				required: f.required && f.type !== 'file' ? true : null,
			} );
		}
		return createElement( 'div', { className: 'migrateplus-field migrateplus-field-' + f.type },
			createElement( 'label', { className: 'migrateplus-field-label', htmlFor: f.type === 'file' ? null : id },
				f.label,
				f.required ? createElement( 'span', { className: 'migrateplus-req' }, __( 'Required', 'migrateplus' ) ) : null ),
			input,
			f.help ? createElement( 'p', { className: 'migrateplus-field-help' }, f.help ) : null,
			/* translators: %s: the largest upload this server accepts, e.g. 8 MB */
			f.type === 'file' && f.remote === false ? createElement( 'p', { className: 'migrateplus-field-help' }, sprintf( __( 'Max upload size: %s', 'migrateplus' ), D.maxUpload ) ) : null
		);
	}

	/** A source whose only setting is a file upload gets the drop-and-import screen. */
	function isDropSource() {
		const source = D.sources.find( ( s ) => s.id === state.sourceId );
		return !! source && 1 === source.schema.length && 'file' === source.schema[ 0 ].type;
	}

	/**
	 * Upload screen: one large drop area and a button to choose the file, with the
	 * upload limit underneath. Dropping a MigratePlus export imports it straight away.
	 */
	function Drop() {
		const field = D.sources.find( ( s ) => s.id === state.sourceId ).schema[ 0 ];
		const input = useRef( null );
		const [ over, setOver ] = useImmediateState( false );
		const markDragOver = ( e ) => {
			e.preventDefault();
			if ( ! over ) {
				setOver( true );
			}
		};
		return createElement( 'section', { className: 'migrateplus-panel migrateplus-import' },
			createElement( 'div', {
				className: 'migrateplus-import-drop' + ( over ? ' is-dragover' : '' ),
				onDragEnter: markDragOver,
				onDragOver: markDragOver,
				onDragLeave: ( e ) => {
					if ( ! e.currentTarget.contains( e.relatedTarget ) ) {
						setOver( false );
					}
				},
				onDragEnd: () => setOver( false ),
				onDrop: ( e ) => {
					e.preventDefault();
					setOver( false );
					const file = e.dataTransfer && e.dataTransfer.files[ 0 ];
					if ( file && ! state.busy ) {
						uploadFile( field, file );
					}
				},
			},
				createElement( 'span', { className: 'dashicons dashicons-cloud-upload', 'aria-hidden': 'true' } ),
				createElement( 'strong', null, __( 'Drop a JSON file (.json or .json.gz) here to import it', 'migrateplus' ) ),
				createElement( 'span', { className: 'migrateplus-muted' }, __( 'A Migrate Plus export imports straight away. Other JSON files go to field mapping first.', 'migrateplus' ) ),
				createElement( 'button', { type: 'button', className: 'button button-primary migrateplus-btn-lg', disabled: state.busy, onClick: () => input.current.click() }, __( 'Choose a JSON file', 'migrateplus' ) )
			),
			createElement( 'input', { ref: input, type: 'file', id: 'migrateplus-import-file', accept: field.accept || '', className: 'migrateplus-drop-input', 'aria-label': __( 'File to import', 'migrateplus' ), onChange: ( e ) => {
				const file = e.target.files && e.target.files[ 0 ];
				e.target.value = ''; // Choosing the same file again still fires change.
				if ( file ) {
					uploadFile( field, file );
				}
			} } ),
			/* translators: %s: the largest upload this server accepts, e.g. 8 MB */
			createElement( 'p', { className: 'migrateplus-import-limit' }, sprintf( __( 'Maximum upload size: %s', 'migrateplus' ), D.maxUpload ) )
		);
	}

	/** Whether a file can't be imported, because of its extension or its size. When so, the modal says why. */
	function refuseUnfitFile( field, file ) {
		const exts = ( field.accept || '' ).split( ',' ).map( ( a ) => a.trim().toLowerCase() ).filter( ( a ) => a.startsWith( '.' ) );
		const refuse = ( title, note ) => {
			modal.open( {
				kind: 'preview',
				bar: false,
				title,
				tone: 'error',
				note,
				dismissible: true,
				actions: [ { label: __( 'Close', 'migrateplus' ), primary: true, onClick: () => modal.close() } ],
			} );
			return true;
		};
		if ( exts.length && ! exts.some( ( x ) => file.name.toLowerCase().endsWith( x ) ) ) {
			/* translators: 1: file name, 2: the extensions accepted, e.g. ".json or .gz" */
			return refuse( __( 'This file can’t be imported', 'migrateplus' ), sprintf( __( '“%1$s” is not a %2$s file. Export one from Migrate Plus → Export on the other site.', 'migrateplus' ), file.name, exts.join( ' ' + __( 'or', 'migrateplus' ) + ' ' ) ) );
		}
		if ( Number( D.maxUploadBytes ) && file.size > Number( D.maxUploadBytes ) ) {
			/* translators: 1: file name, 2: its size, 3: the largest upload this server accepts */
			const note = sprintf( __( '“%1$s” is %2$s. This server accepts uploads up to %3$s.', 'migrateplus' ), file.name, formatBytes( file.size ), D.maxUpload );
			const gzip = D.zlib && ! /\.gz$/i.test( file.name ) ? ' ' + __( 'Export it again with “Compress the file” on: a .json.gz is much smaller.', 'migrateplus' ) : '';
			return refuse( __( 'This file is too large', 'migrateplus' ), note + gzip );
		}
		return false;
	}

	/** Check the file type and size, then upload it. */
	function uploadFile( field, file ) {
		if ( refuseUnfitFile( field, file ) ) {
			return;
		}
		const fd = new FormData();
		fd.append( 'source', state.sourceId );
		fd.append( 'file_' + field.key, file );
		requestPreview( fd, { upload: file } );
	}

	/**
	 * Pull straight from another site running Migrate Plus: connect with an
	 * application password or the old site's pull key, pick the content types, and
	 * the old site exports and hands the file over, which then imports like a
	 * dropped one. The credentials live in this component only, go along with each
	 * request, and are stored nowhere on either site.
	 */
	function Pull() {
		const [ conn, setConn ] = useState( null ); // { creds: { url, user, pass } or { url, key }, site: the pull_check response }
		const [ method, setMethod ] = useState( 'password' ); // 'password' or 'key'
		const [ picked, setPicked ] = useState( [] );
		const [ busy, setBusy ] = useState( false );
		const [ error, setError ] = useState( '' );

		const connect = ( e ) => {
			e.preventDefault();
			const fd = new FormData( e.target );
			const creds = { url: String( fd.get( 'url' ) || '' ).trim() };
			if ( 'key' === method ) {
				creds.key = String( fd.get( 'key' ) || '' ).trim();
			} else {
				creds.user = String( fd.get( 'user' ) || '' ).trim();
				creds.pass = String( fd.get( 'pass' ) || '' ).trim();
			}
			setBusy( true );
			setError( '' );
			api( 'pull_check', creds ).then( ( site ) => {
				setBusy( false );
				setConn( { creds, site } );
				// Every type with something to pull starts ticked.
				setPicked( ( site.types || [] ).filter( ( t ) => t.count > 0 ).map( ( t ) => t.type ) );
			} ).catch( ( err ) => {
				setBusy( false );
				setError( err.message );
			} );
		};

		const credField = ( key, label, type, extra, help ) => {
			const id = 'migrateplus-pull-' + key;
			return createElement( 'div', { className: 'migrateplus-field migrateplus-field-' + type },
				createElement( 'label', { className: 'migrateplus-field-label', htmlFor: id },
					label,
					createElement( 'span', { className: 'migrateplus-req' }, __( 'Required', 'migrateplus' ) ) ),
				createElement( 'input', Object.assign( { id, name: key, type, required: true, autoComplete: 'off' }, extra ) ),
				help ? createElement( 'p', { className: 'migrateplus-field-help' }, help ) : null );
		};

		const methodChip = ( value, label ) => {
			const id = 'migrateplus-pull-method-' + value;
			return createElement( 'label', { key: value, className: 'migrateplus-check-chip', htmlFor: id },
				createElement( 'input', { type: 'radio', id, name: 'method', value, checked: method === value, onChange: () => setMethod( value ) } ),
				createElement( 'span', null, label ) );
		};

		let body;
		if ( ! conn ) {
			body = createElement( 'form', { className: 'migrateplus-fields', onSubmit: connect },
				credField( 'url', __( 'Site address', 'migrateplus' ), 'url', { placeholder: 'https://' } ),
				createElement( 'div', { className: 'migrateplus-field' },
					createElement( 'span', { className: 'migrateplus-field-label' }, __( 'Connect with', 'migrateplus' ) ),
					createElement( 'div', { className: 'migrateplus-chips' },
						methodChip( 'password', __( 'Application password', 'migrateplus' ) ),
						methodChip( 'key', __( 'Pull key', 'migrateplus' ) ) ) ),
				'key' === method
					? credField( 'key', __( 'Pull key', 'migrateplus' ), 'password', {},
						__( 'Made on the old site under Migrate Plus → Settings → Access. It goes along with each request and is stored nowhere on this site.', 'migrateplus' ) )
					: createElement( Fragment, null,
						credField( 'user', __( 'Username', 'migrateplus' ), 'text', {} ),
						credField( 'pass', __( 'Application password', 'migrateplus' ), 'password', { autoComplete: 'new-password' },
							__( 'Made on the old site under Users → Profile → Application Passwords, for a user allowed to run Migrate Plus there. It goes along with each request and is stored nowhere on this site.', 'migrateplus' ) ) ),
				createElement( 'div', { className: 'migrateplus-field' },
					createElement( 'button', { type: 'submit', className: 'button button-secondary', disabled: busy }, busy ? __( 'Connecting…', 'migrateplus' ) : __( 'Connect', 'migrateplus' ) ) )
			);
		} else {
			const types = conn.site.types || [];
			const total = types.filter( ( t ) => picked.includes( t.type ) ).reduce( ( n, t ) => n + Number( t.count ), 0 );
			body = createElement( Fragment, null,
				createElement( 'p', { className: 'migrateplus-pull-site' },
					/* translators: 1: the old site's name, 2: its address */
					sprintf( __( 'Connected to %1$s (%2$s). Choose what to pull:', 'migrateplus' ), conn.site.name || hostOf( conn.site.url || conn.creds.url ), hostOf( conn.site.url || conn.creds.url ) ),
					' ',
					createElement( 'button', { type: 'button', className: 'button-link', onClick: () => { setConn( null ); setPicked( [] ); setError( '' ); } }, __( 'Disconnect', 'migrateplus' ) ) ),
				types.length ? createElement( 'div', { className: 'migrateplus-chips' }, types.map( ( t ) => {
					const id = 'migrateplus-pull-type-' + t.type;
					return createElement( 'label', { key: t.type, className: 'migrateplus-check-chip', htmlFor: id, title: t.type },
						createElement( 'input', { type: 'checkbox', id, checked: picked.includes( t.type ), disabled: ! Number( t.count ), onChange: ( e ) => setPicked( e.target.checked ? picked.concat( t.type ) : picked.filter( ( k ) => k !== t.type ) ) } ),
						/* translators: 1: a content type, 2: how many of it the old site has */
						createElement( 'span', null, sprintf( __( '%1$s (%2$s)', 'migrateplus' ), t.label, Number( t.count ).toLocaleString() ) ) );
				} ) ) : createElement( 'p', { className: 'migrateplus-help' }, __( 'The old site has nothing this user may export.', 'migrateplus' ) ),
				createElement( 'p', { className: 'migrateplus-pull-go' },
					createElement( 'button', { type: 'button', className: 'button button-primary migrateplus-btn-lg', disabled: state.busy || ! picked.length, onClick: () => runPull( conn.creds, picked, conn.site ) },
						/* translators: %s: how many items will be pulled */
						total ? sprintf( _n( 'Pull %s item', 'Pull %s items', total, 'migrateplus' ), total.toLocaleString() ) : __( 'Pull', 'migrateplus' ) ) )
			);
		}
		return createElement( 'section', { className: 'migrateplus-panel migrateplus-pull' },
			createElement( 'header', { className: 'migrateplus-panel-head' },
				createElement( 'div', null,
					createElement( 'h2', null, __( 'Or pull from another site', 'migrateplus' ) ),
					createElement( 'p', null, __( 'No file to carry over: the old site, running Migrate Plus too, exports and hands the file straight to this one.', 'migrateplus' ) ) ) ),
			error ? createElement( Notice, { type: 'error', message: error } ) : null,
			body
		);
	}

	/**
	 * The pull itself: start an export on the old site, poll it to completion, then
	 * have this server download the file. The download's response is a preview,
	 * like a dropped file's, so the import carries on down the usual path.
	 */
	function runPull( creds, types, site ) {
		discardDraftJob(); // The file this one replaces was never imported.
		state.busy = true;
		state.error = '';
		render();
		let cancelled = false;
		let exportId = '';
		const cancel = () => {
			cancelled = true;
			state.busy = false;
			modal.close();
			render();
		};
		modal.open( {
			kind: 'preview',
			/* translators: %s: the old site's host name */
			title: sprintf( __( 'Pulling from %s', 'migrateplus' ), hostOf( site.url || creds.url ) ),
			message: __( 'Starting the export on the old site…', 'migrateplus' ),
			actions: [ { label: __( 'Cancel', 'migrateplus' ), onClick: cancel } ],
		} );
		const withCreds = ( data ) => Object.assign( {}, creds, data );
		const poll = () => apiWithRetry( 'pull_run', withCreds( { export: exportId } ) ).then( ( p ) => {
			if ( cancelled ) {
				return;
			}
			if ( ! p.complete ) {
				/* translators: 1: items exported so far, 2: items in all */
				modal.update( { message: sprintf( __( 'Exporting on the old site… %1$s of %2$s', 'migrateplus' ), Number( p.done ).toLocaleString(), Number( p.total ).toLocaleString() ), percent: p.total ? ( p.done / p.total ) * 100 : null } );
				return poll();
			}
			modal.update( { message: __( 'Export done. Downloading the file and reading the first records…', 'migrateplus' ), percent: null } );
			// Not retried: an answer lost after the download already made a job, and a retry would make a second.
			return api( 'pull_fetch', withCreds( { export: exportId, filename: p.filename || '' } ) ).then( ( data ) => {
				if ( cancelled ) {
					// The file arrived before the cancel reached it: don't leave its job in History.
					api( 'delete', { job: data.job.id } ).catch( () => {} );
					return;
				}
				adoptPreview( data );
			} );
		} );
		// Retried like an import batch: one dropped request shouldn't end a long pull.
		apiWithRetry( 'pull_start', withCreds( { post_types: types.join( ',' ) } ) ).then( ( start ) => {
			if ( cancelled ) {
				return;
			}
			exportId = start.export;
			return poll();
		} ).catch( ( err ) => {
			if ( cancelled ) {
				return;
			}
			state.busy = false;
			modal.update( {
				title: __( 'Could not pull from the old site', 'migrateplus' ),
				message: '',
				percent: 100,
				tone: 'error',
				detail: '',
				note: err.message,
				dismissible: true,
				onDismiss: null,
				actions: [ { label: __( 'Close', 'migrateplus' ), primary: true, onClick: () => modal.close() } ],
			} );
		} );
	}

	/** Whether a previewed job came from a MigratePlus export (it carries its own mapping). */
	function isExport( job ) {
		return !! ( job.settings && ( job.settings.migrateplus_site || job.settings.migrateplus_post_type ) );
	}

	/** Whether a MigratePlus export holds several post types (each record then names its own). */
	function isMultiType( job ) {
		return !! ( job && job.settings && String( job.settings.migrateplus_post_types || '' ).includes( ',' ) );
	}

	/**
	 * The sections an export carries next to its items, in import order: the job stage,
	 * the section type and how to count them ("3 terms", "the site settings").
	 */
	const SIDES = {
		/* translators: %s: number of ACF definitions (field groups, post types, taxonomies, options pages) */
		acf_definitions: { type: 'acf_definitions', count: ( n ) => sprintf( _n( '%s ACF definition', '%s ACF definitions', n, 'migrateplus' ), n.toLocaleString() ), when: 'before' },
		/* translators: %s: number of terms */
		terms: { type: 'terms', count: ( n ) => sprintf( _n( '%s term', '%s terms', n, 'migrateplus' ), n.toLocaleString() ), when: 'before' },
		/* translators: %s: number of ACF options pages */
		acf_options: { type: 'acf_options', count: ( n ) => sprintf( _n( '%s ACF options page', '%s ACF options pages', n, 'migrateplus' ), n.toLocaleString() ), when: 'after' },
		site_settings: { type: 'site_settings', count: () => __( 'the site settings', 'migrateplus' ), when: 'after' },
		/* translators: %s: number of menus */
		menus: { type: 'nav_menu', count: ( n ) => sprintf( _n( '%s menu', '%s menus', n, 'migrateplus' ), n.toLocaleString() ), when: 'after' },
		/* translators: %s: number of orders */
		orders: { type: 'orders', count: ( n ) => sprintf( _n( '%s order', '%s orders', n, 'migrateplus' ), n.toLocaleString() ), when: 'after' },
	};
	const SIDE_TYPES = Object.values( SIDES ).map( ( s ) => s.type );

	/** "3 terms", "1 menu", "the site settings". */
	function sideText( key, n ) {
		return SIDES[ key ].count( n );
	}

	/** The side sections in `counts` ({ terms: 3, menus: 1 }) as "3 terms and 1 menu", in import order. */
	function sidesList( counts, only ) {
		return Object.keys( SIDES ).filter( ( k ) => Number( counts && counts[ k ] ) > 0 && ( ! only || only( k ) ) ).map( ( k ) => sideText( k, Number( counts[ k ] ) ) );
	}

	/** "A, B and C". */
	function joinAnd( parts ) {
		/* translators: the end of a list, "A, B and C". 1: the items before the last, 2: the last item */
		return parts.length > 1 ? sprintf( __( '%1$s and %2$s', 'migrateplus' ), parts.slice( 0, -1 ).join( ', ' ), parts[ parts.length - 1 ] ) : parts.join( '' );
	}

	/** The side sections a job's file holds, from its settings: { terms: 3, menus: 1 }. */
	function jobSides( job ) {
		const out = {};
		Object.keys( SIDES ).forEach( ( k ) => {
			const n = Number( job.settings[ 'migrateplus_' + k ] ) || 0;
			if ( n ) {
				out[ k ] = n;
			}
		} );
		return out;
	}

	/** Progress per part of a MigratePlus export ("Media 12 of 12, Pages 3 of 10, Menus 0 of 2"), when it has more than one. */
	function sectionsText( job ) {
		const list = job.sections || [];
		/* translators: progress of one part of an import. 1: the part, e.g. Pages, 2: items done, 3: items in it */
		return list.length > 1 ? list.map( ( x ) => sprintf( __( '%1$s %2$s of %3$s', 'migrateplus' ), x.label, Number( x.done ).toLocaleString(), Number( x.total ).toLocaleString() ) ).join( ', ' ) : '';
	}

	/** The part being imported now: the job's side stage (terms, menus...), or the first one not done. */
	function currentSection( job ) {
		const list = job.sections || [];
		if ( list.length < 2 || [ 'complete', 'rolled_back' ].includes( job.status ) ) {
			return null;
		}
		const type = SIDES[ job.stage ] ? SIDES[ job.stage ].type : '';
		return ( type && list.find( ( x ) => x.type === type ) ) || list.find( ( x ) => Number( x.done ) < Number( x.total ) ) || null;
	}

	/**
	 * Ask before importing a MigratePlus export straight away. With duplicates found
	 * (d from checkDuplicates), the next step is the duplicates screen instead of the import.
	 */
	function confirmImport( d ) {
		const job = state.job;
		const total = Number( job.total ) || 0;
		const from = job.settings.migrateplus_site ? hostOf( job.settings.migrateplus_site ) : '';
		const sides = jobSides( job );
		const menus = sides.menus || 0;
		const parts = ( job.sections || [] ).filter( ( x ) => ! SIDE_TYPES.includes( x.type ) ).map( ( x ) => x.label );
		// Which sections the options import; their import happens before or after the items.
		const option = { terms: 'import_term_data', acf_definitions: 'import_acf_definitions', acf_options: 'import_acf_options', site_settings: 'import_site_settings', menus: 'import_menus', orders: 'import_orders' };
		const on = ( k ) => !! Number( state.options[ option[ k ] ] );
		const beforeKeys = Object.keys( SIDES ).filter( ( k ) => 'before' === SIDES[ k ].when && on( k ) && Number( sides[ k ] ) > 0 );
		const afterKeys = Object.keys( SIDES ).filter( ( k ) => 'after' === SIDES[ k ].when && on( k ) && Number( sides[ k ] ) > 0 );
		const before = joinAnd( beforeKeys.map( ( k ) => sideText( k, Number( sides[ k ] ) ) ) );
		const after = joinAnd( afterKeys.map( ( k ) => sideText( k, Number( sides[ k ] ) ) ) );
		/* translators: %s: what is imported before the items, e.g. "2 ACF definitions and 3 terms" */
		const beforeNote = beforeKeys.length ? ' ' + sprintf( 1 === beforeKeys.length && 1 === Number( sides[ beforeKeys[ 0 ] ] ) ? __( 'First %s is created or updated.', 'migrateplus' ) : __( 'First %s are created or updated.', 'migrateplus' ), before ) : '';
		/* translators: %s: what is imported after the items, e.g. "2 menus and the site settings" */
		const afterNote = afterKeys.length ? ' ' + sprintf( 1 === afterKeys.length && 'site_settings' === afterKeys[ 0 ] ? __( 'Then %s are applied.', 'migrateplus' ) : __( 'Then %s follow, linked to the imported content.', 'migrateplus' ), after ) : '';
		/* translators: %s: the site the file was exported from */
		const fromNote = from ? sprintf( __( 'Exported from %s.', 'migrateplus' ), from ) + ' ' : '';
		// An export of menus, terms or settings alone: no records.
		const sidesOnly = ! needsAuthor( job );
		let intro = fromNote + ( isMultiType( job )
			/* translators: %s: the content types in the file, e.g. "Media, Pages and Posts" */
			? sprintf( __( '%s are imported in that order, each as its own type, with the field mapping saved in the file.', 'migrateplus' ), joinAnd( parts ) )
			/* translators: %s: a post type, e.g. Post */
			: sprintf( __( 'Everything is imported as %s, with the field mapping saved in the file.', 'migrateplus' ), postType( state.options.post_type ).label ) ) + beforeNote + afterNote;
		if ( sidesOnly ) {
			const off = Object.keys( sides ).filter( ( k ) => ! on( k ) ).map( ( k ) => sideText( k, sides[ k ] ) );
			/* translators: %s: what the file holds, e.g. "2 menus and 5 terms" */
			intro = fromNote + sprintf( __( 'This file holds %s only.', 'migrateplus' ), joinAnd( sidesList( sides ) ) ) +
				( menus ? ' ' + __( 'Menu items link to the posts, pages and terms already on this site; an item whose target is not here is skipped.', 'migrateplus' ) : '' ) +
				/* translators: %s: the parts of the file whose import is off, e.g. "5 terms" */
				( off.length ? ' ' + sprintf( _n( 'The import of %s is off in Settings, so that part would not be imported.', 'The import of %s is off in Settings, so those parts would not be imported.', off.length, 'migrateplus' ), joinAnd( off ) ) : '' );
		}
		const cancel = { label: __( 'Cancel', 'migrateplus' ), onClick: () => modal.close() };
		const base = {
			kind: 'confirm',
			icon: 'file',
			bar: false,
			/* translators: %s: what the file holds, e.g. "2 menus" */
			title: sidesOnly ? sprintf( __( 'Import %s?', 'migrateplus' ), joinAnd( sidesList( sides ) ) ) : sprintf( _n( 'Import %s item?', 'Import %s items?', total, 'migrateplus' ), total.toLocaleString() ),
			dismissible: true,
		};
		const actions = () => ( d && d.count
			? [ { label: __( 'Choose what to do with them', 'migrateplus' ), primary: true, onClick: () => openDuplicates( d, 'confirm' ) } ]
			: [ { label: __( 'Import', 'migrateplus' ), primary: true, onClick: () => start( false, true ) } ] ).concat( [ cancel ] );
		if ( d && d.count ) {
			modal.open( Object.assign( base, {
				message: intro + ' ' + duplicateMessage( d ),
				actions: actions(),
			} ) );
			return;
		}
		modal.open( Object.assign( base, {
			message: intro + ' ' + __( 'You can roll the import back from History.', 'migrateplus' ),
			actions: actions(),
		} ) );
	}

	/** The mapping as it will be saved: ACF fields only when they belong to the chosen type. */
	function mappingToSave() {
		if ( isMultiType( state.job ) ) {
			return state.mapping; // Its ACF fields belong to several types.
		}
		const allowed = new Set( acfFields( state.options.post_type ).flatMap( ( f ) => [ f.key, f.name ] ) );
		const mapping = Object.assign( {}, state.mapping, { acf: {} } );
		Object.entries( state.mapping.acf ).forEach( ( [ k, v ] ) => {
			if ( v && ( allowed.has( k ) || ! ( D.acf && D.acf.active ) ) ) {
				mapping.acf[ k ] = v;
			}
		} );
		return mapping;
	}

	/** Count the records already on this site, with the mapping and options as they stand. */
	function checkDuplicates() {
		return api( 'duplicates', { job: state.job.id, mapping: mappingToSave(), options: state.options } );
	}

	/** "12 of them are already on this site.", for the export confirmation. */
	function duplicateMessage( d ) {
		const n = Number( d.count );
		/* translators: %s: items in the file already on this site */
		return sprintf( _n( '%s of them is already on this site.', '%s of them are already on this site.', n, 'migrateplus' ), n.toLocaleString() ) + ' ' +
			__( 'Next, choose which to update, skip or import as copies.', 'migrateplus' );
	}

	/** How a duplicate was matched to the post on this site: "imported before" or "same slug". */
	function matchedBy( it ) {
		/* translators: %s: what matched, e.g. slug or SKU */
		return 'before' === it.kind ? __( 'imported before', 'migrateplus' ) : sprintf( __( 'same %s', 'migrateplus' ), it.label );
	}

	// Rows per page on the duplicates screen.
	const DUPLICATES_PAGE = 50;

	/**
	 * Open the duplicates screen for d (from checkDuplicates()). from is 'confirm' for the
	 * confirmation of a dropped export, which Cancel goes back to; otherwise the step it came from.
	 * Choices made for this job before, on a screen left with Back, are kept.
	 */
	function openDuplicates( d, from ) {
		const prev = state.dups && state.dups.job === state.job.id ? state.dups : null;
		modal.close();
		state.dups = {
			job: state.job.id,
			d,
			from,
			back: state.step,
			// Post id here => choice; unset means the default. By post, as the server reads them: two records
			// matching the same post share one choice, which the screen shows on both.
			choices: prev ? prev.choices : new Map(),
			rest: prev ? prev.rest : 'skip', // What happens to duplicates past the list, when it was cut short.
			filter: 'all',
			query: '',
			page: 0,
			open: new Set(), // Rows whose changes are shown.
		};
		state.step = 'duplicates';
		render();
		window.scrollTo( 0, 0 );
	}

	/** What a duplicate gets unless the user picks: an update for one that differs when the options say "Update them", otherwise a skip. */
	function defaultChoice( it ) {
		return ! it.media && it.changed && 'update' === state.options.duplicate ? 'update' : 'skip';
	}

	function choiceOf( it ) {
		const v = state.dups.choices.get( it.post_id );
		return v && choicesFor( it ).some( ( c ) => c[ 0 ] === v ) ? v : defaultChoice( it );
	}

	const POST_CHOICES = [ [ 'update', __( 'Update', 'migrateplus' ) ], [ 'skip', __( 'Skip', 'migrateplus' ) ], [ 'copy', __( 'Import as copy', 'migrateplus' ) ] ];
	const MEDIA_CHOICES = [ [ 'update', __( 'Update details', 'migrateplus' ) ], [ 'replace', __( 'Replace file', 'migrateplus' ) ], [ 'skip', __( 'Skip', 'migrateplus' ) ] ];

	/** What can be done with a duplicate: a post or page the same as the one here has nothing to update. A product is never compared, so it keeps every choice. */
	function choicesFor( it ) {
		if ( it.media ) {
			return MEDIA_CHOICES;
		}
		return it.changed || false === it.compared ? POST_CHOICES : POST_CHOICES.filter( ( c ) => 'update' !== c[ 0 ] );
	}

	/** How many records each choice covers, the duplicates past the list included. */
	function duplicateCounts() {
		const { d, rest } = state.dups;
		const items = d.items || [];
		const c = { created: Number( d.total ) - Number( d.count ), update: 0, skip: 0, copy: 0, details: 0, replace: 0 };
		items.forEach( ( it ) => {
			const v = choiceOf( it );
			if ( it.media && 'update' === v ) {
				c.details++;
			} else {
				c[ v ]++;
			}
		} );
		c[ rest ] += Math.max( 0, Number( d.count ) - items.length );
		return c;
	}

	/** "Import 12 new items, update 5, skip 3." */
	function duplicatePlan( c ) {
		/* translators: %s: number of new items */
		const parts = [ sprintf( _n( 'Import %s new item', 'Import %s new items', c.created, 'migrateplus' ), c.created.toLocaleString() ) ];
		/* translators: %s: number of items already on this site that are updated */
		[ [ c.update, _n( 'update %s', 'update %s', c.update, 'migrateplus' ) ],
			/* translators: %s: number of items imported again as copies */
			[ c.copy, _n( 'import %s as a copy', 'import %s as copies', c.copy, 'migrateplus' ) ],
			/* translators: %s: number of media files whose details are updated */
			[ c.details, _n( 'update the details of %s media file', 'update the details of %s media files', c.details, 'migrateplus' ) ],
			/* translators: %s: number of media files replaced */
			[ c.replace, _n( 'replace %s file', 'replace %s files', c.replace, 'migrateplus' ) ],
			/* translators: %s: number of items skipped */
			[ c.skip, _n( 'skip %s', 'skip %s', c.skip, 'migrateplus' ) ],
		].forEach( ( [ n, text ] ) => n && parts.push( sprintf( text, n.toLocaleString() ) ) );
		return parts.join( ', ' ) + '.';
	}

	/**
	 * The choices as the options the server reads. Each listed duplicate is named in update_ids,
	 * replace_ids or copy_ids, or skipped: by skip_existing for a match by slug, SKU or file, and
	 * for one imported before by the duplicate option, which stays as chosen when every such row
	 * agrees with it. A list cut short also names the skipped ones, and duplicate_rest says what
	 * becomes of the duplicates past it.
	 */
	function duplicateOptions() {
		const { d, rest } = state.dups;
		const items = d.items || [];
		const lists = { update: [], replace: [], copy: [], skip: [] };
		items.forEach( ( it ) => lists[ choiceOf( it ) ].includes( it.post_id ) || lists[ choiceOf( it ) ].push( it.post_id ) );
		const natural = { update: 'update', create: 'copy' }[ state.options.duplicate ] || 'skip';
		const agree = items.every( ( it ) => 'before' !== it.kind || choiceOf( it ) === natural );
		const out = {
			skip_existing: 1,
			duplicate: agree ? state.options.duplicate : 'skip',
			update_ids: lists.update,
			replace_ids: lists.replace,
			copy_ids: lists.copy,
			skip_ids: [],
			duplicate_rest: '',
		};
		if ( d.items_truncated ) {
			out.duplicate = state.options.duplicate;
			out.skip_ids = lists.skip;
			out.duplicate_rest = rest;
		}
		return out;
	}

	/** The summary card a duplicate counts under, as the choices stand. */
	function outcomeOf( it ) {
		const v = choiceOf( it );
		return it.media && 'update' === v ? 'details' : v;
	}

	/**
	 * Every record in the import under the card it counts toward, as the choices stand, in a scroll
	 * box under the cards. A group whose list was cut short says how many more it holds.
	 */
	function ImportRoll( { d, counts } ) {
		const rows = { created: [], update: [], skip: [], copy: [], details: [], replace: [] };
		( d.new_items || [] ).forEach( ( it ) => rows.created.push( { index: it.index, title: it.title, detail: it.type } ) );
		( d.items || [] ).forEach( ( it ) => rows[ outcomeOf( it ) ].push( {
			index: it.index,
			title: it.title,
			/* translators: 1: post type, 2: post ID on this site, 3: how it matched, e.g. imported before */
			detail: sprintf( __( '%1$s #%2$s, %3$s', 'migrateplus' ), it.type, it.post_id, matchedBy( it ) ),
		} ) );
		const groups = [
			[ 'created', 'created', __( 'New', 'migrateplus' ) ],
			[ 'update', 'updated', __( 'Will update', 'migrateplus' ) ],
			[ 'skip', 'skipped', __( 'Skipped', 'migrateplus' ) ],
			[ 'copy', 'copied', __( 'Imported as copies', 'migrateplus' ) ],
			[ 'details', 'updated', __( 'Update details', 'migrateplus' ) ],
			[ 'replace', 'replaced', __( 'Replace file', 'migrateplus' ) ],
		].filter( ( [ key ] ) => counts[ key ] );
		if ( ! groups.length ) {
			return null;
		}
		return createElement( 'div', { className: 'migrateplus-dups-roll', role: 'region', tabIndex: 0, 'aria-label': __( 'Each record in the import and what happens to it', 'migrateplus' ) },
			groups.map( ( [ key, tone, label ] ) => {
				const more = counts[ key ] - rows[ key ].length;
				return createElement( 'section', { key },
					createElement( 'h3', { className: 'migrateplus-dups-roll-head is-' + tone },
						createElement( 'span', null, label ),
						createElement( 'span', { className: 'migrateplus-dups-roll-column' }, __( 'Type', 'migrateplus' ) ) ),
					createElement( 'ul', null,
						rows[ key ].map( ( row ) => createElement( 'li', { key: row.index },
							createElement( 'span', { className: 'migrateplus-dups-roll-title' }, row.title || __( '(untitled)', 'migrateplus' ) ),
							createElement( 'span', { className: 'migrateplus-help' }, row.detail ) ) ),
						/* translators: %s: records in this group that are not listed */
						more > 0 ? createElement( 'li', { className: 'migrateplus-dups-roll-more' }, sprintf( _n( 'and %s more not listed', 'and %s more not listed', more, 'migrateplus' ), more.toLocaleString() ) ) : null ) );
			} ) );
	}

	/** Whether a duplicate passes the screen's filter and title search. */
	function duplicateShown( it, filter, query ) {
		const pass = {
			all: true,
			differs: it.changed,
			unchanged: ! it.changed,
			before: 'before' === it.kind,
			existing: 'existing' === it.kind,
			media: !! it.media,
		}[ filter ];
		const q = query.trim().toLowerCase();
		return pass && ( ! q || ( it.title + '\n' + it.existing_title ).toLowerCase().includes( q ) );
	}

	/** Both copies of a media duplicate side by side. */
	function MediaCompare( { it } ) {
		const m = it.media;
		const mediaFigure = ( src, href, caption ) => createElement( 'figure', { className: 'migrateplus-media-pick' },
			src && m.is_image
				/* translators: 1: where the copy is, e.g. On this site, 2: the image title */
				? createElement( 'a', { href: safeUrl( href ), target: '_blank', rel: 'noopener' }, createElement( 'img', { src: safeUrl( src ), alt: sprintf( __( '%1$s: %2$s', 'migrateplus' ), caption, it.title || __( 'image', 'migrateplus' ) ), loading: 'lazy' } ) )
				: createElement( 'span', { className: 'migrateplus-media-file' }, ( href || '' ).split( '/' ).pop() || __( '(no preview)', 'migrateplus' ) ),
			createElement( 'figcaption', null, caption ) );
		return createElement( 'div', { className: 'migrateplus-media-compare' },
			mediaFigure( m.current, m.current_full, __( 'On this site', 'migrateplus' ) ),
			mediaFigure( m.imported_preview || m.imported, m.imported, __( 'In the import', 'migrateplus' ) ) );
	}

	/**
	 * Every record already on this site, with what to do with each: update it, skip it or
	 * import it as a copy (media: update its details, replace its file or skip it), and
	 * what the import will do with the choices as they stand.
	 */
	function Duplicates() {
		const s = state.dups;
		const d = s.d;
		const items = d.items || [];
		const shown = items.filter( ( it ) => duplicateShown( it, s.filter, s.query ) );
		const pages = Math.max( 1, Math.ceil( shown.length / DUPLICATES_PAGE ) );
		const page = Math.min( s.page, pages - 1 );
		const rows = shown.slice( page * DUPLICATES_PAGE, ( page + 1 ) * DUPLICATES_PAGE );
		const c = duplicateCounts();
		const set = ( list, v ) => {
			list.forEach( ( it ) => s.choices.set( it.post_id, v ) );
			render();
		};
		const posts = shown.filter( ( it ) => ! it.media );
		const media = shown.filter( ( it ) => it.media );
		const rest = Number( d.count ) - items.length;
		const leave = () => {
			state.step = s.back;
			render();
		};
		const chips = [
			[ 'created', c.created, __( 'New', 'migrateplus' ) ],
			[ 'updated', c.update, __( 'Will update', 'migrateplus' ) ],
			[ 'skipped', c.skip, __( 'Skipped', 'migrateplus' ) ],
			[ 'copied', c.copy, __( 'Imported as copies', 'migrateplus' ) ],
		].concat( items.some( ( it ) => it.media ) ? [ [ 'updated', c.details, __( 'Update details', 'migrateplus' ) ], [ 'replaced', c.replace, __( 'Replace file', 'migrateplus' ) ] ] : [] );
		const filters = [
			[ 'all', __( 'All', 'migrateplus' ) ],
			[ 'differs', __( 'Differs', 'migrateplus' ) ],
			[ 'unchanged', __( 'Unchanged', 'migrateplus' ) ],
			[ 'before', __( 'Imported before', 'migrateplus' ) ],
			[ 'existing', __( 'Same slug, SKU or file', 'migrateplus' ) ],
			[ 'media', __( 'Media', 'migrateplus' ) ],
		];
		const bulk = ( label, list, v ) => createElement( 'button', { type: 'button', className: 'button button-small', disabled: ! list.length, onClick: () => set( list, v ) }, label );

		return createElement( Fragment, null,
			createElement( 'section', { className: 'migrateplus-panel migrateplus-dups-head' },
				createElement( 'h2', null, __( 'Items already on this site', 'migrateplus' ) ),
				/* translators: 1: records already on this site, 2: records in the import */
				createElement( 'p', null, sprintf( _n( '%1$s of %2$s records is already on this site. Choose what to do with it.', '%1$s of %2$s records are already on this site. Choose what to do with each.', Number( d.count ), 'migrateplus' ), Number( d.count ).toLocaleString(), Number( d.total ).toLocaleString() ) ),
				createElement( 'ul', { className: 'migrateplus-legend migrateplus-dups-chips' }, chips.map( ( [ key, value, label ], i ) =>
					createElement( 'li', { key: i, className: 'is-' + key + ( value ? '' : ' is-zero' ) },
						createElement( 'strong', null, value.toLocaleString() ),
						createElement( 'span', null, label ) ) ) ),
				createElement( ImportRoll, { d, counts: c } ),
				createElement( 'p', { className: 'migrateplus-dups-plan', 'aria-live': 'polite' }, duplicatePlan( c ) ),
				createElement( 'p', { className: 'migrateplus-muted' }, __( 'An update also writes the custom fields, terms and images. WordPress keeps a revision of each post or page it updates, so the old version can be restored from the editor. A replaced file cannot be rolled back.', 'migrateplus' ) )
			),
			createElement( 'section', { className: 'migrateplus-panel migrateplus-dups' },
				createElement( 'div', { className: 'migrateplus-dups-tools' },
					createElement( 'label', { htmlFor: 'migrateplus-dups-filter' }, __( 'Show', 'migrateplus' ) ),
					createElement( 'select', { id: 'migrateplus-dups-filter', value: s.filter, onChange: ( e ) => {
						s.filter = e.target.value;
						s.page = 0;
						render();
					} }, filters.map( ( [ v, l ] ) => createElement( 'option', { key: v, value: v }, l ) ) ),
					createElement( 'input', { type: 'search', id: 'migrateplus-dups-search', value: s.query, placeholder: __( 'Search titles', 'migrateplus' ), 'aria-label': __( 'Search duplicates by title', 'migrateplus' ), onChange: ( e ) => {
						s.query = e.target.value;
						s.page = 0;
						render();
					} } ) ),
				createElement( 'div', { className: 'migrateplus-dups-bulk' },
					/* translators: %s: number of rows the filter shows */
					createElement( 'span', { className: 'migrateplus-muted' }, sprintf( _n( 'Applies to the %s row shown:', 'Applies to the %s rows shown:', shown.length, 'migrateplus' ), shown.length.toLocaleString() ) ),
					bulk( __( 'Update all that differ', 'migrateplus' ), posts.filter( ( it ) => it.changed ), 'update' ),
					bulk( __( 'Skip all', 'migrateplus' ), posts, 'skip' ),
					bulk( __( 'Import all as copies', 'migrateplus' ), posts, 'copy' ),
					media.length ? bulk( __( 'Update details for all', 'migrateplus' ), media, 'update' ) : null,
					media.length ? bulk( __( 'Replace all files', 'migrateplus' ), media, 'replace' ) : null,
					media.length ? bulk( __( 'Skip all media', 'migrateplus' ), media, 'skip' ) : null ),
				rows.length ? createElement( 'table', { className: 'migrateplus-map-table migrateplus-dups-table' },
					createElement( 'caption', { className: 'screen-reader-text' }, __( 'Items already on this site', 'migrateplus' ) ),
					createElement( 'thead', null, createElement( 'tr', null,
						createElement( 'th', { scope: 'col' }, __( 'In the file', 'migrateplus' ) ),
						createElement( 'th', { scope: 'col' }, __( 'On this site', 'migrateplus' ) ),
						createElement( 'th', { scope: 'col' }, __( 'Why it matched', 'migrateplus' ) ),
						createElement( 'th', { scope: 'col' }, __( 'Status', 'migrateplus' ) ),
						createElement( 'th', { scope: 'col' }, __( 'Author', 'migrateplus' ) ),
						createElement( 'th', { scope: 'col' }, __( 'What to do', 'migrateplus' ) ) ) ),
					createElement( 'tbody', null, rows.flatMap( ( it ) => {
						const open = s.open.has( it.index );
						const id = 'migrateplus-dup-' + it.index;
						const view = it.view_url ? safeUrl( it.view_url ) : null;
						const edit = it.edit_url ? safeUrl( it.edit_url ) : null;
						const fields = Object.keys( it.changes || {} ).map( ( k ) => it.changes[ k ] );
						const title = it.title || __( '(untitled)', 'migrateplus' );
						const row = createElement( 'tr', { key: it.index, className: open ? 'is-open' : '' },
							createElement( 'td', { className: 'migrateplus-dups-title' }, title ),
							createElement( 'td', null,
								view ? createElement( 'a', { href: view, target: '_blank', rel: 'noopener' }, it.existing_title || __( '(untitled)', 'migrateplus' ) ) : ( it.existing_title || __( '(untitled)', 'migrateplus' ) ),
								/* translators: 1: post type, 2: post ID */
								createElement( 'span', { className: 'migrateplus-help' }, sprintf( __( '%1$s #%2$s', 'migrateplus' ), it.type, it.post_id ),
									/* translators: %s: the title of the item on this site */
									edit ? [ ' · ', createElement( 'a', { key: 'edit', href: edit, target: '_blank', rel: 'noopener', 'aria-label': sprintf( __( 'Edit “%s”', 'migrateplus' ), it.existing_title ) }, __( 'Edit', 'migrateplus' ) ) ] : null ) ),
							createElement( 'td', null, matchedBy( it ) ),
							createElement( 'td', null,
								false === it.compared ? null : createElement( 'span', { className: 'migrateplus-badge ' + ( it.changed ? 'migrateplus-badge-differs' : 'migrateplus-badge-unchanged' ) }, it.changed ? __( 'Differs', 'migrateplus' ) : __( 'Unchanged', 'migrateplus' ) ),
								it.changed || it.media ? createElement( 'button', { type: 'button', className: 'button-link migrateplus-dups-toggle', 'aria-expanded': open ? 'true' : 'false', 'aria-controls': id + '-changes', onClick: () => {
									if ( open ) {
										s.open.delete( it.index );
									} else {
										s.open.add( it.index );
									}
									render();
								} }, it.changed ? __( 'Show changes', 'migrateplus' ) : __( 'Compare files', 'migrateplus' ) ) : null ),
							// Media keeps its author; an update or a copy of a post or page takes the one picked here.
							createElement( 'td', null, it.media ? null : createElement( AuthorPicker, {
								id: id + '-author',
								/* translators: %s: a post's title */
								label: sprintf( __( 'Author of “%s”', 'migrateplus' ), title ),
								hideLabel: true,
								// The author for all is picked only on the mapping step; without one the import gives the post to whoever runs it.
								emptyLabel: Number( state.options.author ) > 0 ? __( 'Author for all', 'migrateplus' ) : __( 'You (the person importing)', 'migrateplus' ),
								value: ( state.options.authors || {} )[ it.index ],
								onChange: ( user ) => setRecordAuthor( it.index, user ),
							} ) ),
							createElement( 'td', null,
								/* translators: %s: the title of a record in the file */
								createElement( 'label', { className: 'screen-reader-text', htmlFor: id }, sprintf( __( 'What to do with “%s”', 'migrateplus' ), title ) ),
								createElement( 'select', { id, value: choiceOf( it ), onChange: ( e ) => set( [ it ], e.target.value ) },
									choicesFor( it ).map( ( [ v, l ] ) => createElement( 'option', { key: v, value: v }, l ) ) ) ) );
						return open ? [ row, createElement( 'tr', { key: it.index + '-changes', className: 'migrateplus-dups-changes' },
							createElement( 'td', { colSpan: 6, id: id + '-changes' },
								it.media ? createElement( MediaCompare, { it } ) : null,
								fields.length ? createElement( FieldChanges, { fields } ) : null ) ) ] : [ row ];
					} ) ) ) : createElement( 'p', { className: 'migrateplus-muted' }, __( 'No duplicates match.', 'migrateplus' ) ),
				pages > 1 ? createElement( 'div', { className: 'migrateplus-authors-paging' },
					createElement( 'button', { type: 'button', className: 'button', disabled: ! page, onClick: () => {
						s.page = page - 1;
						render();
					} }, __( 'Previous', 'migrateplus' ) ),
					/* translators: 1: first row shown, 2: last row shown, 3: rows in all */
					createElement( 'span', { className: 'migrateplus-muted' }, sprintf( __( '%1$s–%2$s of %3$s', 'migrateplus' ), ( page * DUPLICATES_PAGE + 1 ).toLocaleString(), ( page * DUPLICATES_PAGE + rows.length ).toLocaleString(), shown.length.toLocaleString() ) ),
					createElement( 'button', { type: 'button', className: 'button', disabled: page >= pages - 1, onClick: () => {
						s.page = page + 1;
						render();
					} }, __( 'Next', 'migrateplus' ) ) ) : null,
				d.items_truncated ? createElement( 'div', { className: 'migrateplus-opt migrateplus-dups-rest' },
					/* translators: 1: rows listed, 2: duplicates in all */
					createElement( 'p', { className: 'migrateplus-help' }, sprintf( __( 'Showing the first %1$s of %2$s.', 'migrateplus' ), items.length.toLocaleString(), Number( d.count ).toLocaleString() ) ),
					/* translators: %s: number of duplicates not listed */
					createElement( 'label', { className: 'migrateplus-opt-label', htmlFor: 'migrateplus-dups-rest' }, sprintf( _n( 'For the other %s duplicate:', 'For the other %s duplicates:', rest, 'migrateplus' ), rest.toLocaleString() ) ),
					createElement( 'select', { id: 'migrateplus-dups-rest', value: s.rest, onChange: ( e ) => {
						s.rest = e.target.value;
						render();
					} },
						createElement( 'option', { value: 'skip' }, __( 'Skip', 'migrateplus' ) ),
						createElement( 'option', { value: 'update' }, __( 'Update', 'migrateplus' ) ),
						createElement( 'option', { value: 'copy' }, __( 'Import as copies', 'migrateplus' ) ) ) ) : null
			),
			createElement( 'div', { className: 'migrateplus-actionbar' },
				createElement( 'span', { className: 'migrateplus-actionbar-status' }, duplicatePlan( c ) ),
				createElement( 'div', { className: 'migrateplus-actionbar-buttons' },
					createElement( 'button', { type: 'button', className: 'button', onClick: leave }, 'confirm' === s.from ? __( 'Cancel', 'migrateplus' ) : ( 'map' === s.back ? __( 'Back to mapping', 'migrateplus' ) : __( 'Back', 'migrateplus' ) ) ),
					'map' === s.back ? createElement( 'button', { type: 'button', className: 'button', disabled: state.busy, title: __( 'Process everything without saving, to check the mapping', 'migrateplus' ), onClick: () => start( true ) }, __( 'Dry run', 'migrateplus' ) ) : null,
					createElement( 'button', { type: 'button', className: 'button button-primary', disabled: state.busy, onClick: () => start( false, true, duplicateOptions() ) }, __( 'Import', 'migrateplus' ) ) ) )
		);
	}

	/** Each changed field: a diff for longer text, "Now / Imported" for short values. */
	function FieldChanges( { fields } ) {
		return createElement( Fragment, null, fields.map( ( f, i ) => createElement( 'div', { key: i, className: 'migrateplus-review-field' },
			createElement( 'h3', null, f.label ),
			f.old.includes( '\n' ) || f.new.includes( '\n' ) || f.old.length + f.new.length > 160
				? createElement( DiffView, { oldText: f.old, newText: f.new } )
				: createElement( 'dl', { className: 'migrateplus-review-swap' },
					createElement( 'dt', null, __( 'Now', 'migrateplus' ) ), createElement( 'dd', { className: 'is-old' }, f.old || __( '(empty)', 'migrateplus' ) ),
					createElement( 'dt', null, __( 'Imported', 'migrateplus' ) ), createElement( 'dd', { className: 'is-new' }, f.new || __( '(empty)', 'migrateplus' ) ) )
		) ) );
	}

	/**
	 * Line diff of two texts: unchanged lines around each change, the rest folded.
	 * Uses a longest-common-subsequence table after trimming the shared start and end;
	 * a pair too large for that is shown as "all removed, all added".
	 */
	function DiffView( { oldText, newText } ) {
		const a = oldText.split( '\n' );
		const b = newText.split( '\n' );
		let start = 0;
		while ( start < a.length && start < b.length && a[ start ] === b[ start ] ) {
			start++;
		}
		let endA = a.length;
		let endB = b.length;
		while ( endA > start && endB > start && a[ endA - 1 ] === b[ endB - 1 ] ) {
			endA--;
			endB--;
		}
		const x = a.slice( start, endA );
		const y = b.slice( start, endB );
		let mid = [];
		if ( x.length * y.length > 4000000 ) {
			mid = x.map( ( s ) => [ '-', s ] ).concat( y.map( ( s ) => [ '+', s ] ) );
		} else {
			const w = y.length + 1;
			const t = new Uint32Array( ( x.length + 1 ) * w );
			for ( let i = x.length - 1; i >= 0; i-- ) {
				for ( let j = y.length - 1; j >= 0; j-- ) {
					t[ i * w + j ] = x[ i ] === y[ j ] ? t[ ( i + 1 ) * w + j + 1 ] + 1 : Math.max( t[ ( i + 1 ) * w + j ], t[ i * w + j + 1 ] );
				}
			}
			let i = 0;
			let j = 0;
			while ( i < x.length || j < y.length ) {
				if ( i < x.length && j < y.length && x[ i ] === y[ j ] ) {
					mid.push( [ ' ', x[ i++ ] ] );
					j++;
				} else if ( i < x.length && ( j >= y.length || t[ ( i + 1 ) * w + j ] >= t[ i * w + j + 1 ] ) ) {
					mid.push( [ '-', x[ i++ ] ] ); // On a tie, removed lines come before added ones.
				} else {
					mid.push( [ '+', y[ j++ ] ] );
				}
			}
		}
		const rows = a.slice( 0, start ).map( ( s ) => [ ' ', s ] ).concat( mid, a.slice( endA ).map( ( s ) => [ ' ', s ] ) );

		// Keep 2 lines of context around changes; fold longer unchanged runs.
		const near = rows.map( () => false );
		rows.forEach( ( row, k ) => {
			if ( ' ' !== row[ 0 ] ) {
				for ( let c = Math.max( 0, k - 2 ); c <= Math.min( rows.length - 1, k + 2 ); c++ ) {
					near[ c ] = true;
				}
			}
		} );
		const out = [];
		let folded = 0;
		const addFoldedLines = ( k ) => {
			if ( folded ) {
				/* translators: %s: number of unchanged lines folded away */
				out.push( createElement( 'li', { key: 'fold-' + k, className: 'is-fold' }, '⋯ ' + sprintf( _n( '%s unchanged line', '%s unchanged lines', folded, 'migrateplus' ), folded ) ) );
				folded = 0;
			}
		};
		rows.forEach( ( row, k ) => {
			if ( ! near[ k ] ) {
				folded++;
				return;
			}
			addFoldedLines( k );
			const kind = { ' ': 'is-same', '-': 'is-old', '+': 'is-new' }[ row[ 0 ] ];
			// The sign is drawn for the eye; screen readers ignore aria-label on a span, so they get the word as hidden text.
			out.push( createElement( 'li', { key: k, className: kind },
				createElement( 'span', { className: 'migrateplus-diff-sign', 'aria-hidden': 'true' }, ' ' === row[ 0 ] ? '' : ( '-' === row[ 0 ] ? '−' : '+' ) ),
				' ' === row[ 0 ] ? null : createElement( 'span', { className: 'screen-reader-text' }, '-' === row[ 0 ] ? __( 'Removed:', 'migrateplus' ) : __( 'Added:', 'migrateplus' ) ),
				createElement( 'span', null, row[ 1 ] || ' ' ) ) );
		} );
		addFoldedLines( rows.length );
		return createElement( 'ol', { className: 'migrateplus-diff' }, out );
	}

	/**
	 * File field with an "Upload file / From URL" switch (unless the schema sets remote: false).
	 * The upload side is a drop zone.
	 */
	function FileField( { f, id, name } ) {
		const upload = useRef( null );
		const urlInput = useRef( null );
		const [ dragging, setDragging ] = useImmediateState( false );
		const [ chosen, setChosen ] = useImmediateState( '' );
		const modeKey = state.sourceId + ':' + f.key;
		const isUrl = f.remote !== false && 'url' === state.fileMode[ modeKey ];
		const showFile = () => {
			const file = upload.current.files && upload.current.files[ 0 ];
			/* translators: 1: file name, 2: file size */
			setChosen( file ? sprintf( __( '%1$s (%2$s)', 'migrateplus' ), file.name, formatBytes( file.size ) ) : '' );
		};
		const markDragging = ( e ) => {
			e.preventDefault();
			if ( ! dragging ) {
				setDragging( true );
			}
		};
		const zone = createElement( 'label', {
			className: 'migrateplus-drop' + ( chosen ? ' has-file' : '' ) + ( dragging ? ' is-dragging' : '' ),
			htmlFor: id,
			onDragEnter: markDragging,
			onDragOver: markDragging,
			onDragLeave: ( e ) => {
				if ( ! e.currentTarget.contains( e.relatedTarget ) ) {
					setDragging( false );
				}
			},
			onDragEnd: () => setDragging( false ),
			onDrop: ( e ) => {
				e.preventDefault();
				setDragging( false );
				if ( e.dataTransfer && e.dataTransfer.files.length ) {
					upload.current.files = e.dataTransfer.files;
					showFile();
				}
			},
		},
			// Disabled inputs are left out of the submitted form.
			createElement( 'input', { ref: upload, id, name, type: 'file', accept: f.accept || '', className: 'migrateplus-drop-input', disabled: isUrl, onChange: showFile } ),
			createElement( 'span', { className: 'migrateplus-drop-icon', 'aria-hidden': 'true' } ),
			createElement( 'span', { className: 'migrateplus-drop-text' },
				createElement( 'strong', null, __( 'Drop a file here or choose one', 'migrateplus' ) ),
				/* translators: 1: the file extensions accepted, 2: the largest upload this server accepts */
				createElement( 'span', null, sprintf( __( '%1$s, up to %2$s', 'migrateplus' ), ( f.accept || '' ).split( ',' ).filter( ( a ) => a.trim().startsWith( '.' ) ).join( ', ' ), D.maxUpload ) ) ),
			createElement( 'span', { className: 'migrateplus-drop-file' }, chosen )
		);

		if ( f.remote === false ) {
			return zone;
		}
		const setMode = ( m ) => {
			state.fileMode[ modeKey ] = m;
			render();
			( 'url' === m ? urlInput : upload ).current.focus();
		};
		const modeButton = ( m, label ) => createElement( 'button', {
			type: 'button',
			className: 'migrateplus-seg' + ( ( 'url' === m ) === isUrl ? ' is-active' : '' ),
			'aria-pressed': ( 'url' === m ) === isUrl ? 'true' : 'false',
			onClick: () => setMode( m ),
		}, label );
		return createElement( 'div', { className: 'migrateplus-file-field' },
			/* translators: %s: the name of a file setting, e.g. JSON file */
			createElement( 'div', { className: 'migrateplus-segmented', role: 'group', 'aria-label': sprintf( __( '%s: how to provide it', 'migrateplus' ), f.label ) }, modeButton( 'upload', __( 'Upload a file', 'migrateplus' ) ), modeButton( 'url', __( 'From a URL', 'migrateplus' ) ) ),
			createElement( 'div', { className: 'migrateplus-file-pane', hidden: isUrl }, zone ),
			createElement( 'div', { className: 'migrateplus-file-pane', hidden: ! isUrl },
				createElement( 'input', {
					ref: urlInput,
					id: id + '-url',
					name: 'settings[' + f.key + '_url]',
					type: 'url',
					placeholder: 'https://',
					autoComplete: 'off',
					/* translators: %s: the name of a file setting, e.g. JSON file */
					'aria-label': sprintf( __( '%s URL', 'migrateplus' ), f.label ),
					disabled: ! isUrl,
					required: isUrl && !! f.required,
				} ),
				/* translators: %s: the largest file the server downloads, e.g. 64 MB */
				createElement( 'p', { className: 'migrateplus-field-help' }, sprintf( __( 'Your server downloads the file (up to %s). Google Sheets, Google Drive, Dropbox and GitHub share links work too, as long as they are shared publicly.', 'migrateplus' ), D.maxRemote ) ) )
		);
	}

	function onPreview( e ) {
		e.preventDefault();
		const fd = new FormData( e.currentTarget );
		fd.append( 'source', state.sourceId );
		const source = D.sources.find( ( s ) => s.id === state.sourceId );

		// Work out what the server is about to do, for the modal copy. A file is checked as one dropped on the upload screen.
		let upload = null;
		let remote = '';
		let refused = false;
		source.schema.filter( ( f ) => f.type === 'file' ).forEach( ( f ) => {
			const file = fd.get( 'file_' + f.key );
			if ( file && file.name && file.size ) {
				upload = file;
				refused = refused || refuseUnfitFile( f, file );
			}
			const url = fd.get( 'settings[' + f.key + '_url]' );
			if ( url ) {
				remote = String( url );
			}
		} );
		if ( refused ) {
			return;
		}
		const urlField = source.schema.find( ( f ) => f.type === 'url' && fd.get( 'settings[' + f.key + ']' ) );
		const connectTo = urlField ? hostOf( String( fd.get( 'settings[' + urlField.key + ']' ) ) ) : '';

		state.sourceError = '';
		requestPreview( fd, { upload, remote, connectTo, label: source.label, onError: ( err ) => {
			state.sourceError = err.message;
			render();
		} } );
	}

	/**
	 * Send a source to the server and read its first records. A MigratePlus export goes
	 * straight to the import confirmation; anything else opens the mapping step.
	 *
	 * ctx: { upload: File, remote: url, connectTo: host, label, onError( err ) }
	 */
	function requestPreview( fd, ctx ) {
		const upload = ctx.upload || null;
		let readingMsg = __( 'Reading the first records…', 'migrateplus' );
		if ( ctx.remote ) {
			/* translators: %s: the host the file is downloaded from */
			readingMsg = sprintf( __( 'Downloading the file from %s and reading the first records…', 'migrateplus' ), hostOf( ctx.remote ) );
		} else if ( ctx.connectTo ) {
			/* translators: %s: the host of the source */
			readingMsg = sprintf( __( 'Connecting to %s and reading the first records…', 'migrateplus' ), ctx.connectTo );
		}
		let title = __( 'Fetching remote file', 'migrateplus' );
		if ( upload ) {
			/* translators: %s: file name */
			title = sprintf( __( 'Uploading %s', 'migrateplus' ), upload.name );
		} else if ( ! ctx.remote ) {
			/* translators: %s: the source's name */
			title = sprintf( __( 'Connecting to %s', 'migrateplus' ), ctx.label );
		}

		discardDraftJob(); // The file this one replaces was never imported.
		state.busy = true;
		state.error = '';
		let req = null;
		let cancelled = false;
		const cancel = () => {
			cancelled = true;
			if ( req.abort ) {
				req.abort();
			}
			state.busy = false;
			modal.close();
			render();
		};
		modal.open( {
			kind: 'preview',
			title,
			message: upload ? __( 'Sending the file to your server.', 'migrateplus' ) : readingMsg,
			percent: upload ? 0 : null,
			/* translators: 1: bytes sent so far, 2: the file size */
			detail: upload ? sprintf( __( '%1$s of %2$s', 'migrateplus' ), formatBytes( 0 ), formatBytes( upload.size ) ) : '',
			actions: [ { label: __( 'Cancel', 'migrateplus' ), onClick: cancel } ],
		} );

		req = upload
			? apiWithProgress( 'preview', fd, ( loaded, total ) => {
				modal.update( {
					percent: ( loaded / total ) * 100,
					detail: sprintf( __( '%1$s of %2$s', 'migrateplus' ), formatBytes( loaded ), formatBytes( total ) ),
				} );
			}, () => {
				/* translators: %s: file name */
				modal.update( { title: sprintf( __( 'Reading %s', 'migrateplus' ), upload.name ), message: __( 'Upload complete. Checking the file and reading the first records…', 'migrateplus' ), percent: null, detail: '' } );
			} )
			: api( 'preview', fd );

		req.then( ( data ) => {
			if ( cancelled ) {
				// The server read the file before the cancel reached it: don't leave its job in History.
				api( 'delete', { job: data.job.id } ).catch( () => {} );
				return;
			}
			adoptPreview( data );
		} ).catch( ( err ) => {
			if ( cancelled ) {
				return;
			}
			state.busy = false;
			modal.update( {
				title: upload ? __( 'Could not read the file', 'migrateplus' ) : __( 'Could not connect', 'migrateplus' ),
				message: '',
				percent: 100,
				tone: 'error',
				detail: '',
				note: err.message,
				dismissible: true,
				onDismiss: null,
				actions: [ { label: __( 'Close', 'migrateplus' ), primary: true, onClick: () => modal.close() } ],
			} );
			if ( ctx.onError ) {
				ctx.onError( err );
			} else {
				state.error = err.message;
				render();
			}
		} );
	}

	/**
	 * A preview response becomes the open job, however the file arrived (dropped,
	 * fetched from a URL, or pulled from another site). A MigratePlus export goes
	 * straight to the duplicate check in the modal already open; anything else
	 * closes it and moves to field mapping.
	 */
	function adoptPreview( data ) {
		state.job = data.job;
		state.records = data.records;
		state.fields = ( data.fields || [] ).map( String ); // Numeric JSON keys may arrive as numbers.
		state.mapping = normalizeMapping( data.job.mapping );
		state.options = Object.assign( {}, D.defaults, data.job.options );
		// Picked by the person importing, never preselected.
		state.options.author = 0;
		state.options.authors = {};
		state.options.author_map = {};
		state.suggested = {};
		state.busy = false;
		state.savedPick = data.savedMapping || '';
		/* translators: %s: the name of a saved mapping */
		state.savedNotice = data.savedMapping ? { type: 'info', text: sprintf( __( 'This file has the same fields as the saved mapping “%s”, so it is selected below. Choose Apply to use it, or map the fields yourself.', 'migrateplus' ), data.savedMapping ) } : null;
		// Set either way: an export dropped and cancelled before would otherwise hide the steps of the next file.
		state.direct = isExport( data.job );
		syncJobUrl();
		if ( state.direct ) {
			render();
			modal.update( { title: __( 'Checking for duplicates', 'migrateplus' ), message: __( 'Looking for items in this file that are already on this site…', 'migrateplus' ), percent: null, detail: '', actions: [] } );
			checkDuplicates().then( confirmImport ).catch( duplicateCheckFailed );
			return;
		}
		modal.close();
		state.step = 'map';
		render();
	}

	/** Delete the current job when it is a draft, never imported, so it doesn't stay in History as "Not started"; then forget it. */
	function discardDraftJob() {
		if ( state.job && 'draft' === state.job.status ) {
			api( 'delete', { job: state.job.id } ).catch( () => {} );
		}
		state.job = null;
		syncJobUrl();
	}

	// Step 2

	function MapStep() {
		const job = state.job;
		const source = D.sources.find( ( s ) => s.id === job.source ) || D.sources.find( ( s ) => s.id === state.sourceId ) || { id: job.source, label: job.source };
		const total = job.total === null || job.total === undefined ? null : Number( job.total );
		const cols = state.fields.slice( 0, 40 );
		const mapped = D.targets.filter( ( t ) => state.mapping.fields[ t.key ] ).length;
		const ready = !! ( state.mapping.fields.post_title || state.mapping.fields.post_content );
		let status = __( 'Map the title or the content to continue', 'migrateplus' );
		if ( ready ) {
			/* translators: 1: fields mapped, 2: WordPress fields that can be mapped */
			status = sprintf( __( '%1$s of %2$s fields mapped', 'migrateplus' ), mapped, D.targets.length );
		}
		const where = job.settings.file_url || job.settings.url || job.settings.site_url;

		return createElement( Fragment, null,
			createElement( 'section', { className: 'migrateplus-summary' },
				createElement( 'span', { className: 'migrateplus-source-icon migrateplus-icon-' + source.id, 'aria-hidden': 'true' } ),
				createElement( 'div', { className: 'migrateplus-summary-text' },
					/* translators: %s: the source's name */
					createElement( 'strong', null, sprintf( __( 'Connected to %s', 'migrateplus' ), source.label ) ),
					createElement( 'span', null, mapSummary( total, where ) ) ),
				createElement( 'button', { type: 'button', className: 'button', onClick: () => {
					discardDraftJob();
					state.step = 'source';
					state.error = '';
					render();
				} }, isDropSource() ? __( 'Choose another file', 'migrateplus' ) : __( 'Change source', 'migrateplus' ) )
			),
			createElement( SavedMappings ),
			// No records when a job's cached copy is gone: an empty table would only say "First 0 records".
			state.records.length ? createElement( 'details', { className: 'migrateplus-panel migrateplus-preview-panel', open: true },
				createElement( 'summary', null,
					createElement( 'h2', null, __( 'Preview', 'migrateplus' ) ),
					/* translators: %s: number of records shown */
					createElement( 'span', { className: 'migrateplus-muted' }, sprintf( _n( 'First %s record', 'First %s records', state.records.length, 'migrateplus' ), state.records.length ) +
						/* translators: 1: fields shown, 2: fields in the file */
						( state.fields.length > cols.length ? ', ' + sprintf( __( '%1$s of %2$s fields shown', 'migrateplus' ), cols.length, state.fields.length ) : '' ) ) ),
				createElement( 'div', { className: 'migrateplus-preview-scroll', tabIndex: 0, 'aria-label': __( 'Preview records', 'migrateplus' ) }, createElement( 'table', { className: 'migrateplus-preview' },
					createElement( 'thead', null, createElement( 'tr', null, cols.map( ( c ) => createElement( 'th', { key: c, scope: 'col' }, c ) ) ) ),
					createElement( 'tbody', null, state.records.map( ( r, i ) => createElement( 'tr', { key: i }, cols.map( ( c ) => createElement( 'td', { key: c, title: r[ c ] || '' }, truncate( r[ c ], 80 ) ) ) ) ) )
				) )
			) : null,
			createElement( 'div', { className: 'migrateplus-grid' }, createElement( MappingCard ), createElement( OptionsCard ) ),
			createElement( 'div', { className: 'migrateplus-actionbar' },
				createElement( 'span', { className: 'migrateplus-actionbar-status' + ( ready ? '' : ' is-warn' ), 'aria-live': 'polite' }, status ),
				createElement( 'div', { className: 'migrateplus-actionbar-buttons' },
					createElement( 'button', { type: 'button', className: 'button', disabled: state.busy, title: __( 'Process everything without saving, to check the mapping', 'migrateplus' ), onClick: () => start( true ) }, __( 'Dry run', 'migrateplus' ) ),
					createElement( 'button', { type: 'button', className: 'button button-primary', disabled: state.busy, onClick: () => start( false ) }, __( 'Start import', 'migrateplus' ) ) )
			)
		);
	}

	/** "15 records, 3 fields from example.com", for the mapping step's summary. */
	function mapSummary( total, where ) {
		const records = null === total
			/* translators: %s: number of fields */
			? sprintf( __( 'Unknown number of records, %s fields', 'migrateplus' ), state.fields.length )
			/* translators: 1: number of records, 2: number of fields */
			: sprintf( __( '%1$s records, %2$s fields', 'migrateplus' ), total.toLocaleString(), state.fields.length );
		/* translators: 1: records and fields, 2: the host they come from */
		return where ? sprintf( __( '%1$s from %2$s', 'migrateplus' ), records, hostOf( where ) ) : records;
	}

	/**
	 * Save the mapping and options under a name, or load, rename or delete a saved one.
	 * Saved mappings are only for JSON that is not a MigratePlus export.
	 */
	function SavedMappings() {
		const list = state.savedMappings;
		const pick = list.find( ( m ) => m.name === state.savedPick ) || null;
		const showNotice = ( type, text ) => {
			state.savedNotice = { type, text };
			render();
		};
		const refresh = ( d ) => {
			state.savedMappings = d.mappings || [];
			state.savedPick = d.name || '';
		};
		const save = () => {
			const name = ( state.savedName || '' ).trim() || ( pick ? pick.name : '' );
			if ( ! name ) {
				showNotice( 'error', __( 'Give the mapping a name first.', 'migrateplus' ) );
				return;
			}
			const send = () => api( 'mapping_save', { job: state.job.id, name, mapping: mappingToSave(), options: optionsWithoutAuthors( state.options ) } ).then( ( d ) => {
				refresh( d );
				state.savedName = '';
				/* translators: %s: the name of a saved mapping */
				showNotice( 'success', sprintf( __( 'Saved as “%s”. A file with the same fields will offer it.', 'migrateplus' ), d.name ) );
			} ).catch( ( err ) => showNotice( 'error', err.message ) );
			if ( ! list.some( ( m ) => m.name === name ) ) {
				send();
				return;
			}
			ask( {
				title: __( 'Replace saved mapping?', 'migrateplus' ),
				/* translators: %s: the name of a saved mapping */
				message: sprintf( __( 'Replace the saved mapping “%s” with this one?', 'migrateplus' ), name ),
				label: __( 'Replace', 'migrateplus' ),
				onConfirm: () => {
					modal.close();
					send();
				},
			} );
		};
		const rename = () => ask( {
			title: __( 'Rename saved mapping', 'migrateplus' ),
			/* translators: %s: the name of a saved mapping */
			message: sprintf( __( 'New name for “%s”:', 'migrateplus' ), pick.name ),
			input: { label: __( 'New name', 'migrateplus' ), value: pick.name },
			label: __( 'Rename', 'migrateplus' ),
			onConfirm: ( value ) => {
				modal.close();
				const name = value.trim();
				if ( ! name || name === pick.name ) {
					return;
				}
				api( 'mapping_rename', { name: pick.name, new_name: name } ).then( ( d ) => {
					refresh( d );
					/* translators: %s: the new name of a saved mapping */
					showNotice( 'success', sprintf( __( 'Renamed to “%s”.', 'migrateplus' ), d.name ) );
				} ).catch( ( err ) => showNotice( 'error', err.message ) );
			},
		} );
		const remove = () => ask( {
			title: __( 'Delete saved mapping?', 'migrateplus' ),
			/* translators: %s: the name of a saved mapping */
			message: sprintf( __( 'Delete the saved mapping “%s”? It is deleted for everyone who imports on this site.', 'migrateplus' ), pick.name ),
			label: __( 'Delete', 'migrateplus' ),
			danger: true,
			onConfirm: () => {
				modal.close();
				api( 'mapping_delete', { name: pick.name } ).then( ( d ) => {
					refresh( d );
					/* translators: %s: the name of a saved mapping */
					showNotice( 'success', sprintf( __( 'Deleted “%s”.', 'migrateplus' ), pick.name ) );
				} ).catch( ( err ) => showNotice( 'error', err.message ) );
			},
		} );
		return createElement( 'section', { className: 'migrateplus-panel migrateplus-saved' },
			createElement( 'h2', null, __( 'Saved mappings', 'migrateplus' ) ),
			createElement( 'p', { className: 'migrateplus-muted' }, __( 'Save this mapping and the import options to use them again for the next file from the same place. Saved mappings are shared by everyone who imports on this site.', 'migrateplus' ) ),
			state.savedNotice ? createElement( Notice, { type: state.savedNotice.type, message: state.savedNotice.text } ) : null,
			createElement( 'div', { className: 'migrateplus-saved-row' },
				createElement( 'select', { id: 'migrateplus-saved-select', 'aria-label': __( 'Load saved mapping', 'migrateplus' ), disabled: ! list.length, value: pick ? pick.name : '', onChange: ( e ) => {
					state.savedPick = e.target.value;
					state.savedNotice = null;
					render();
				} },
					createElement( 'option', { value: '' }, list.length ? __( 'Choose a saved mapping', 'migrateplus' ) : __( 'No saved mappings yet', 'migrateplus' ) ),
					list.map( ( m ) => createElement( 'option', { key: m.name, value: m.name }, m.name ) ) ),
				createElement( 'button', { type: 'button', className: 'button', disabled: ! pick, onClick: () => applySavedMapping( pick ) }, __( 'Apply', 'migrateplus' ) ),
				createElement( 'button', { type: 'button', className: 'button-link', disabled: ! pick, onClick: rename }, __( 'Rename', 'migrateplus' ) ),
				createElement( 'button', { type: 'button', className: 'migrateplus-link-danger', disabled: ! pick, onClick: remove }, __( 'Delete', 'migrateplus' ) )
			),
			createElement( 'div', { className: 'migrateplus-saved-row' },
				createElement( 'input', { type: 'text', id: 'migrateplus-saved-name', 'aria-label': __( 'Name for this mapping', 'migrateplus' ), placeholder: pick ? pick.name : __( 'Name, for example Books feed', 'migrateplus' ), value: state.savedName, maxLength: 100, onChange: ( e ) => {
					state.savedName = e.target.value;
					render();
				} } ),
				createElement( 'button', { type: 'button', className: 'button', onClick: save }, __( 'Save mapping', 'migrateplus' ) )
			)
		);
	}

	/**
	 * Use a saved mapping and its import options. Source fields this file does not
	 * have are left unmapped, and the notice lists them.
	 */
	function applySavedMapping( entry ) {
		const have = new Set( state.fields.concat( nestedFields() ) );
		const dropped = new Set();
		const keep = ( source ) => {
			if ( have.has( source ) ) {
				return true;
			}
			dropped.add( source );
			return false;
		};
		const m = normalizeMapping( entry.mapping );
		Object.keys( m.fields ).forEach( ( k ) => {
			if ( ! keep( m.fields[ k ] ) ) {
				delete m.fields[ k ];
			}
		} );
		m.meta = m.meta.filter( ( row ) => keep( row.source ) );
		m.taxonomies = m.taxonomies.filter( ( row ) => keep( row.source ) );
		Object.keys( m.acf ).forEach( ( k ) => {
			if ( ! keep( m.acf[ k ] ) ) {
				delete m.acf[ k ];
			}
		} );
		const options = optionsWithoutAuthors( entry.options );
		const missingType = options.post_type && ! D.postTypes.some( ( p ) => p.name === options.post_type ) ? options.post_type : '';
		if ( missingType ) {
			delete options.post_type;
		}
		state.mapping = m;
		state.options = Object.assign( {}, state.options, options );
		// Suggestions would add fields to a mapping chosen on purpose.
		state.suggested = { [ state.options.post_type ]: true };
		/* translators: %s: the name of a saved mapping */
		const notes = [ sprintf( __( 'Applied “%s”.', 'migrateplus' ), entry.name ) ];
		if ( dropped.size ) {
			/* translators: %s: source fields, comma-separated */
			notes.push( sprintf( __( 'Not in this file, so left unmapped: %s.', 'migrateplus' ), Array.from( dropped ).join( ', ' ) ) );
		}
		if ( missingType ) {
			/* translators: %s: a post type name */
			notes.push( sprintf( __( 'Its post type “%s” does not exist on this site; “Import as” was left as it was.', 'migrateplus' ), missingType ) );
		}
		state.savedNotice = { type: dropped.size || missingType ? 'warning' : 'success', text: notes.join( ' ' ) };
		render();
	}

	function MappingCard() {
		const m = state.mapping;
		const taxes = postType( state.options.post_type ).taxonomies.filter( ( t ) => t.name !== 'category' && t.name !== 'post_tag' );
		const remove = ( list, i ) => () => {
			list.splice( i, 1 );
			render();
		};

		return createElement( 'section', { className: 'migrateplus-panel migrateplus-mapping' },
			createElement( 'header', { className: 'migrateplus-section-head' },
				createElement( 'h2', null, __( 'Field mapping', 'migrateplus' ) ),
				createElement( 'p', { className: 'migrateplus-muted' }, __( 'Pick which source field fills each WordPress field. Suggestions are already filled in.', 'migrateplus' ) ) ),
			createElement( 'table', { className: 'migrateplus-map-table' },
				createElement( 'thead', null, createElement( 'tr', null,
					createElement( 'th', { scope: 'col' }, __( 'WordPress field', 'migrateplus' ) ),
					createElement( 'th', { 'aria-hidden': 'true' } ),
					createElement( 'th', { scope: 'col' }, __( 'Source field', 'migrateplus' ) ),
					createElement( 'th', { scope: 'col' }, __( 'Sample value', 'migrateplus' ) ) ) ),
				createElement( 'tbody', null, D.targets.map( ( t ) => createElement( 'tr', { key: t.key, className: m.fields[ t.key ] ? 'is-mapped' : '' },
					createElement( 'th', { scope: 'row' }, createElement( 'span', null, t.label ), t.help ? createElement( 'span', { className: 'migrateplus-help' }, t.help ) : null ),
					createElement( 'td', { className: 'migrateplus-arrow', 'aria-hidden': 'true' }, '←' ),
					createElement( 'td', null, createElement( FieldSelect, { value: m.fields[ t.key ] || '', nested: t.key === 'comments', onChange: ( v ) => {
						if ( v ) {
							m.fields[ t.key ] = v;
						} else {
							delete m.fields[ t.key ];
						}
						render();
					} } ) ),
					createElement( 'td', { className: 'migrateplus-sample' }, truncate( sample( m.fields[ t.key ] ), 120 ) )
				) ) )
			),
			createElement( AcfSection ),
			createElement( 'div', { className: 'migrateplus-subsection' },
				createElement( 'h3', null, __( 'Custom fields', 'migrateplus' ) ),
				m.meta.length ? createElement( 'div', { className: 'migrateplus-pairs' }, m.meta.map( ( row, i ) => createElement( 'div', { key: i, className: 'migrateplus-pair' },
					createElement( 'input', { type: 'text', className: 'code', placeholder: 'meta_key', 'aria-label': __( 'Custom field name', 'migrateplus' ), value: row.key || '', onChange: ( e ) => {
						row.key = e.target.value;
						userMetaRows.add( row );
						render();
					} } ),
					createElement( 'span', { className: 'migrateplus-arrow', 'aria-hidden': 'true' }, '←' ),
					createElement( FieldSelect, { value: row.source, onChange: ( v ) => {
						row.source = v;
						userMetaRows.add( row );
						render();
					} } ),
					createElement( 'button', { type: 'button', className: 'migrateplus-icon-btn', 'aria-label': __( 'Remove custom field', 'migrateplus' ), title: __( 'Remove', 'migrateplus' ), onClick: remove( m.meta, i ) }, '×' )
				) ) ) : createElement( 'p', { className: 'migrateplus-muted' }, __( 'Save any source field as post meta. ACF field names work too.', 'migrateplus' ) ),
				createElement( 'button', { type: 'button', className: 'button migrateplus-add', onClick: () => {
					const row = { key: '', source: '' };
					userMetaRows.add( row );
					m.meta.push( row );
					render();
				} }, __( 'Add custom field', 'migrateplus' ) )
			),
			createElement( 'div', { className: 'migrateplus-subsection' },
				createElement( 'h3', null, __( 'Other taxonomies', 'migrateplus' ) ),
				taxes.length
					? ( m.taxonomies.length ? createElement( 'div', { className: 'migrateplus-pairs' }, m.taxonomies.map( ( row, i ) => createElement( 'div', { key: i, className: 'migrateplus-pair' },
						createElement( 'select', { 'aria-label': __( 'Taxonomy', 'migrateplus' ), value: row.taxonomy || '', onChange: ( e ) => {
							row.taxonomy = e.target.value;
							render();
						} },
							createElement( 'option', { value: '' }, __( 'Choose taxonomy', 'migrateplus' ) ),
							taxes.map( ( t ) => createElement( 'option', { key: t.name, value: t.name }, t.label + ' (' + t.name + ')' ) ) ),
						createElement( 'span', { className: 'migrateplus-arrow', 'aria-hidden': 'true' }, '←' ),
						createElement( FieldSelect, { value: row.source, onChange: ( v ) => {
							row.source = v;
							render();
						} } ),
						createElement( 'button', { type: 'button', className: 'migrateplus-icon-btn', 'aria-label': __( 'Remove taxonomy', 'migrateplus' ), title: __( 'Remove', 'migrateplus' ), onClick: remove( m.taxonomies, i ) }, '×' )
					) ) ) : createElement( 'p', { className: 'migrateplus-muted' }, __( 'Assign terms in custom taxonomies registered for this post type.', 'migrateplus' ) ) )
					: createElement( 'p', { className: 'migrateplus-muted' }, __( 'This post type has no other taxonomies.', 'migrateplus' ) ),
				taxes.length ? createElement( 'button', { type: 'button', className: 'button migrateplus-add', onClick: () => {
					m.taxonomies.push( { taxonomy: '', source: '' } );
					render();
				} }, __( 'Add taxonomy', 'migrateplus' ) ) : null
			)
		);
	}

	const ACF_TYPE_LABELS = {
		text: __( 'Text', 'migrateplus' ), textarea: __( 'Text area', 'migrateplus' ), wysiwyg: __( 'Editor', 'migrateplus' ), number: __( 'Number', 'migrateplus' ), range: __( 'Range', 'migrateplus' ), email: __( 'Email', 'migrateplus' ), url: __( 'URL', 'migrateplus' ), password: __( 'Password', 'migrateplus' ),
		image: __( 'Image', 'migrateplus' ), file: __( 'File', 'migrateplus' ), gallery: __( 'Gallery', 'migrateplus' ), oembed: __( 'oEmbed', 'migrateplus' ), select: __( 'Select', 'migrateplus' ), checkbox: __( 'Checkbox', 'migrateplus' ), radio: __( 'Radio', 'migrateplus' ), button_group: __( 'Buttons', 'migrateplus' ),
		true_false: __( 'True / false', 'migrateplus' ), link: __( 'Link', 'migrateplus' ), post_object: __( 'Post', 'migrateplus' ), page_link: __( 'Page link', 'migrateplus' ), relationship: __( 'Relationship', 'migrateplus' ), taxonomy: __( 'Taxonomy', 'migrateplus' ), user: __( 'User', 'migrateplus' ),
		google_map: __( 'Map', 'migrateplus' ), date_picker: __( 'Date', 'migrateplus' ), date_time_picker: __( 'Date and time', 'migrateplus' ), time_picker: __( 'Time', 'migrateplus' ), color_picker: __( 'Color', 'migrateplus' ),
		group: __( 'Group', 'migrateplus' ), repeater: __( 'Repeater', 'migrateplus' ), flexible_content: __( 'Flexible content', 'migrateplus' ),
	};

	const ACF_HINTS = {
		image: __( 'Image URL. Downloaded into the Media Library.', 'migrateplus' ),
		file: __( 'File URL. Downloaded into the Media Library.', 'migrateplus' ),
		gallery: __( 'List of image URLs.', 'migrateplus' ),
		relationship: __( 'IDs, slugs or titles. Source IDs from earlier imports work.', 'migrateplus' ),
		post_object: __( 'ID, slug or title. Source IDs from earlier imports work.', 'migrateplus' ),
		page_link: __( 'ID, slug, title or URL.', 'migrateplus' ),
		taxonomy: __( 'Term names or IDs.', 'migrateplus' ),
		user: __( 'Email, login or ID.', 'migrateplus' ),
		select: __( 'Choice values or labels.', 'migrateplus' ),
		checkbox: __( 'Choice values or labels.', 'migrateplus' ),
		repeater: __( 'A list of rows, nested data or JSON.', 'migrateplus' ),
		group: __( 'An object, nested data or JSON.', 'migrateplus' ),
		flexible_content: __( 'Rows with acf_fc_layout, as nested data or JSON.', 'migrateplus' ),
		google_map: __( '"lat, lng" or an object.', 'migrateplus' ),
		date_picker: __( 'Any date format.', 'migrateplus' ),
		date_time_picker: __( 'Any date format.', 'migrateplus' ),
	};

	function AcfSection() {
		if ( ! D.acf || ! D.acf.active ) {
			return null;
		}
		const fields = acfFields( state.options.post_type );
		const m = state.mapping;
		const head = createElement( 'div', { className: 'migrateplus-acf-head' },
			createElement( 'h3', null, __( 'ACF fields', 'migrateplus' ) ),
			/* translators: %s: number of ACF fields */
			createElement( 'span', { className: 'migrateplus-badge' }, sprintf( _n( '%s field', '%s fields', fields.length, 'migrateplus' ), fields.length ) ) );
		if ( ! fields.length ) {
			return createElement( 'div', { className: 'migrateplus-subsection migrateplus-acf' },
				head,
				createElement( 'p', { className: 'migrateplus-muted' }, __( 'No ACF field groups are assigned to this post type. Assign one in ACF, or pick another post type under "Import as".', 'migrateplus' ) ) );
		}
		const groups = {};
		fields.forEach( ( f ) => {
			( groups[ f.group ] = groups[ f.group ] || [] ).push( f );
		} );
		const body = [];
		Object.entries( groups ).forEach( ( [ group, list ] ) => {
			body.push( createElement( 'tr', { key: 'group-' + group, className: 'migrateplus-acf-group' }, createElement( 'th', { colSpan: 4, scope: 'colgroup' }, group ) ) );
			list.forEach( ( f ) => {
				const current = m.acf[ f.key ] || m.acf[ f.name ] || '';
				body.push( createElement( 'tr', { key: f.key, className: current ? 'is-mapped' : '' },
					createElement( 'th', { scope: 'row' },
						createElement( 'span', null, f.label + ( f.required ? ' *' : '' ) ),
						createElement( 'span', { className: 'migrateplus-help' },
							createElement( 'code', null, f.name ),
							' ' + ( ACF_TYPE_LABELS[ f.type ] || f.type ),
							f.sub && f.sub.length ? ' (' + f.sub.join( ', ' ) + ')' : '' ) ),
					createElement( 'td', { className: 'migrateplus-arrow', 'aria-hidden': 'true' }, '←' ),
					createElement( 'td', null,
						createElement( FieldSelect, { value: current, nested: true, label: f.label, onChange: ( v ) => {
							delete m.acf[ f.name ];
							if ( v ) {
								m.acf[ f.key ] = v;
							} else {
								delete m.acf[ f.key ];
							}
							render();
						} } ),
						ACF_HINTS[ f.type ] ? createElement( 'span', { className: 'migrateplus-help' }, ACF_HINTS[ f.type ] ) : null ),
					createElement( 'td', { className: 'migrateplus-sample' }, truncate( sample( current ), 120 ) )
				) );
			} );
		} );
		return createElement( 'div', { className: 'migrateplus-subsection migrateplus-acf' },
			head,
			createElement( 'p', { className: 'migrateplus-muted' }, __( 'Values are converted for each field type: image URLs are downloaded, related posts and terms are looked up, dates and choices are normalised.', 'migrateplus' ) ),
			createElement( 'table', { className: 'migrateplus-map-table' }, createElement( 'tbody', null, body ) ) );
	}

	function OptionsCard() {
		const o = state.options;
		const setOption = ( k ) => ( e ) => {
			o[ k ] = e.target.type === 'checkbox' ? ( e.target.checked ? 1 : 0 ) : e.target.value;
			render();
		};
		const toggle = ( k, label, help ) => createElement( Toggle, { id: 'migrateplus-opt-' + k, label, help, checked: !! Number( o[ k ] ), onChange: setOption( k ) } );
		const optionRow = ( id, label, control, help ) => createElement( 'div', { className: 'migrateplus-opt' },
			createElement( 'label', { className: 'migrateplus-opt-label', htmlFor: id }, label ),
			control,
			help ? createElement( 'span', { className: 'migrateplus-help' }, help ) : null );
		const choices = ( list ) => list.map( ( [ v, l ] ) => createElement( 'option', { key: v, value: v }, l ) );
		const statuses = [ [ 'publish', __( 'Published', 'migrateplus' ) ], [ 'draft', __( 'Draft', 'migrateplus' ) ], [ 'pending', __( 'Pending review', 'migrateplus' ) ], [ 'private', __( 'Private', 'migrateplus' ) ] ];
		/* translators: %s: a link to the Settings screen, labelled Settings */
		const defaultsNote = __( 'Defaults come from %s.', 'migrateplus' ).split( '%s' );

		return createElement( 'aside', { className: 'migrateplus-panel migrateplus-options' },
			createElement( 'header', { className: 'migrateplus-section-head' },
				createElement( 'h2', null, __( 'Import options', 'migrateplus' ) ),
				D.settingsUrl ? createElement( 'p', { className: 'migrateplus-muted' }, defaultsNote[ 0 ], createElement( 'a', { href: D.settingsUrl }, __( 'Settings', 'migrateplus' ) ), defaultsNote[ 1 ] || '' ) : null ),
			needsAuthor( state.job ) ? createElement( MainAuthorPicker, { id: 'migrateplus-opt-author', onPick: render } ) : null,
			needsAuthor( state.job ) ? createElement( AuthorsPerRecord ) : null,
			optionRow( 'migrateplus-opt-row-1', __( 'Import as', 'migrateplus' ), createElement( 'select', { id: 'migrateplus-opt-row-1', value: o.post_type, onChange: setOption( 'post_type' ) },
				D.postTypes.map( ( p ) => createElement( 'option', { key: p.name, value: p.name }, p.label + ' (' + p.name + ')' ) ) ), __( 'Used for every record, unless "Post type" is mapped and names a type this site has.', 'migrateplus' ) ),
			optionRow( 'migrateplus-opt-row-2', __( 'Default status', 'migrateplus' ), createElement( 'select', { id: 'migrateplus-opt-row-2', value: o.default_status, onChange: setOption( 'default_status' ) }, choices( statuses ) ), __( 'Used when a record has no status, or one Migrate Plus does not recognise.', 'migrateplus' ) ),
			optionRow( 'migrateplus-opt-row-3', __( 'Status for every item', 'migrateplus' ), createElement( 'select', { id: 'migrateplus-opt-row-3', value: o.force_status || '', onChange: setOption( 'force_status' ) }, choices( [ [ '', __( 'Keep each record’s status', 'migrateplus' ) ] ].concat( statuses ) ) ), __( 'Overrides the status in the file, also for posts that are updated. Published posts with a future date are scheduled.', 'migrateplus' ) ),
			optionRow( 'migrateplus-opt-row-5', __( 'Records imported before', 'migrateplus' ), createElement( 'select', { id: 'migrateplus-opt-row-5', value: o.duplicate, onChange: setOption( 'duplicate' ) }, choices( [
				[ 'skip', __( 'Skip them', 'migrateplus' ) ], [ 'update', __( 'Update them', 'migrateplus' ) ], [ 'create', __( 'Create duplicates', 'migrateplus' ) ],
			] ) ), __( 'Matched by the mapped Source ID.', 'migrateplus' ) ),
			optionRow( 'migrateplus-opt-row-6', __( 'Batch size', 'migrateplus' ), createElement( 'input', { id: 'migrateplus-opt-row-6', type: 'number', min: 1, max: 100, value: o.batch_size, onChange: setOption( 'batch_size' ) } ), __( 'Records per request. Lower it if image downloads time out.', 'migrateplus' ) ),
			createElement( 'div', { className: 'migrateplus-toggles' },
				toggle( 'import_featured_image', __( 'Download featured images', 'migrateplus' ) ),
				toggle( 'import_content_images', __( 'Download images in content', 'migrateplus' ), __( 'Copies <img> files into the Media Library and rewrites their URLs.', 'migrateplus' ) ),
				toggle( 'create_terms', __( 'Create missing categories and terms', 'migrateplus' ) ),
				toggle( 'rewrite_links', __( 'Point links to the old site here', 'migrateplus' ), __( 'Migrate Plus exports only. Files in wp-content keep their old URLs unless images in content are downloaded.', 'migrateplus' ) ),
				toggle( 'import_comments', __( 'Import comments', 'migrateplus' ), __( 'Migrate Plus exports that include comments. Importing the file again updates them instead of adding them twice.', 'migrateplus' ) ),
				toggle( 'import_menus', __( 'Import menus', 'migrateplus' ), __( 'Migrate Plus exports that include menus, after all posts. A menu with the same slug is filled rather than copied.', 'migrateplus' ) ),
				toggle( 'import_term_data', __( 'Import term data', 'migrateplus' ), __( 'Migrate Plus exports that include terms, before the posts: descriptions, custom fields, ACF fields, category images and empty terms. Terms already here are updated, never deleted.', 'migrateplus' ) ),
				toggle( 'import_acf_definitions', __( 'Import ACF definitions', 'migrateplus' ), __( 'Field groups, post types, taxonomies and options pages, before the posts. Definitions this site has in code or acf-json are left alone.', 'migrateplus' ) ),
				toggle( 'import_acf_options', __( 'Import ACF options pages', 'migrateplus' ), __( 'The values of ACF options pages, after the posts. Rollback puts the previous values back.', 'migrateplus' ) ),
				toggle( 'import_site_settings', __( 'Import site settings', 'migrateplus' ), __( 'General, Reading, Discussion, Media and Permalinks settings, widgets, Customizer settings of the same theme, Additional CSS, global styles and Yoast SEO settings, after the posts. Rollback puts the previous values back.', 'migrateplus' ) ),
				toggle( 'import_orders', __( 'Import orders', 'migrateplus' ), __( 'WooCommerce orders, after the products and customers they name. No emails are sent and no stock is moved.', 'migrateplus' ) ),
				toggle( 'import_redirects', __( 'Redirect old addresses', 'migrateplus' ), __( 'When a post lands at another address than it had on the old site, the old one sends visitors to the new one once the domain points here.', 'migrateplus' ) ) )
		);
	}

	// bootstrap_data() lists at most this many users who can edit posts. A list that long may be cut short, so a search is offered.
	const AUTHORS_CAP = 200;
	// Every user the picker has listed, by id, so the one picked still shows after a search that leaves it out.
	const seenAuthors = new Map( ( D.authors || [] ).map( ( u ) => [ String( u.id ), u ] ) );

	/** Whether a job imports posts or pages, which get an author. A file of menus, terms or settings alone does not. */
	function needsAuthor( job ) {
		return !! Number( job.total ) || ! Object.keys( jobSides( job ) ).length;
	}

	/** The options without the authors: user ids differ per site, so a saved mapping neither keeps nor applies them. */
	function optionsWithoutAuthors( options ) {
		const rest = Object.assign( {}, options );
		delete rest.author;
		delete rest.authors;
		delete rest.author_map;
		return rest;
	}

	/** How many records have an author of their own. */
	function ownAuthorCount() {
		return Object.values( state.options.authors || {} ).filter( ( id ) => Number( id ) > 0 ).length;
	}

	/**
	 * A select of the people who may write posts, searchable on a site with more than D.authors
	 * lists. Authors are not matched from the file. value is a user id, 0 for emptyLabel;
	 * onChange gets the id picked, or 0.
	 */
	function AuthorPicker( { id, label, hideLabel, emptyLabel, value, onChange, help } ) {
		const [ found, setFound ] = useImmediateState( null ); // Search results, or null to list D.authors.
		const [ problem, setProblem ] = useImmediateState( '' );
		const timer = useRef( null );
		const asked = useRef( 0 );
		const picked = Number( value ) > 0 ? String( value ) : '';
		const list = ( found || D.authors || [] ).slice();
		if ( picked && seenAuthors.has( picked ) && ! list.some( ( u ) => String( u.id ) === picked ) ) {
			list.unshift( seenAuthors.get( picked ) );
		}
		const search = ( e ) => {
			const q = e.target.value.trim();
			const ticket = ++asked.current; // On input, not in the timer: an answer to what was typed before is stale.
			clearTimeout( timer.current );
			timer.current = setTimeout( () => {
				if ( ! q ) {
					setProblem( '' );
					setFound( null );
					return;
				}
				api( 'users', { q } ).then( ( d ) => {
					if ( ticket !== asked.current ) {
						return;
					}
					const users = Array.isArray( d ) ? d : ( d.authors || [] );
					users.forEach( ( u ) => seenAuthors.set( String( u.id ), u ) );
					setProblem( '' );
					setFound( users );
				} ).catch( ( err ) => ticket === asked.current && setProblem( err.message ) );
			}, 300 );
		};
		return createElement( 'div', { className: 'migrateplus-author' + ( hideLabel ? '' : ' migrateplus-opt' ) },
			createElement( 'label', { className: hideLabel ? 'screen-reader-text' : 'migrateplus-opt-label', htmlFor: id }, label ),
			( D.authors || [] ).length >= AUTHORS_CAP ? createElement( 'input', { type: 'search', className: 'migrateplus-author-search', placeholder: __( 'Search users by name or login', 'migrateplus' ), 'aria-label': __( 'Search users', 'migrateplus' ), onChange: search } ) : null,
			createElement( 'select', { id, value: picked, onChange: ( e ) => onChange( Number( e.target.value ) || 0 ) },
				createElement( 'option', { value: '' }, emptyLabel ),
				/* translators: 1: a user's display name, 2: their login */
				list.map( ( u ) => createElement( 'option', { key: u.id, value: String( u.id ) }, sprintf( __( '%1$s (%2$s)', 'migrateplus' ), u.name, u.login ) ) ) ),
			problem ? createElement( 'div', { className: 'notice notice-error inline' }, createElement( 'p', null, problem ) ) : null,
			help ? createElement( 'span', { className: 'migrateplus-help' }, help ) : null
		);
	}

	/** The author of every imported post and page without one of its own, as state.options.author. onPick redraws what depends on it. */
	function MainAuthorPicker( { id, onPick } ) {
		return createElement( AuthorPicker, {
			id,
			label: __( 'Author for all posts and pages', 'migrateplus' ),
			emptyLabel: __( 'You (the person importing)', 'migrateplus' ),
			value: state.options.author,
			help: __( 'User accounts differ between sites, so authors are not taken from the file.', 'migrateplus' ),
			onChange: ( user ) => {
				state.options.author = user;
				onPick();
			},
		} );
	}

	/** Give the record at index (its position in the file) its own author, or with 0 the author for all again, and redraw. */
	function setRecordAuthor( index, user ) {
		state.options.authors = Object.assign( {}, state.options.authors );
		if ( user ) {
			state.options.authors[ index ] = user;
		} else {
			delete state.options.authors[ index ];
		}
		render();
	}

	// Records per page in the author for each post or page.
	const RECORDS_PAGE = 50;

	/**
	 * "Choose an author for each post or page": the job's records a page at a time, read from the
	 * server, each with a picker whose choice goes into state.options.authors by its position in the file.
	 */
	function AuthorsPerRecord() {
		const [ open, setOpen ] = useImmediateState( false );
		const [ page, setPage ] = useImmediateState( null ); // { records, total, matched, truncated, offset, q }, null until loaded.
		const [ problem, setProblem ] = useImmediateState( '' );
		const query = useRef( '' );
		const timer = useRef( null );
		const asked = useRef( 0 );
		const load = ( offset, q ) => {
			const ticket = ++asked.current;
			api( 'records', { job: state.job.id, offset, limit: RECORDS_PAGE, q, mapping: mappingToSave() } ).then( ( d ) => {
				if ( ticket === asked.current ) { // An answer to an earlier page or search arriving late is dropped.
					setProblem( '' );
					setPage( Object.assign( {}, d, { offset, q } ) );
				}
			} ).catch( ( err ) => ticket === asked.current && setProblem( err.message ) );
		};
		const toggle = () => {
			if ( ! open && ! page ) {
				load( 0, '' );
			}
			setOpen( ! open );
		};
		const search = ( e ) => {
			query.current = e.target.value.trim();
			clearTimeout( timer.current );
			timer.current = setTimeout( () => load( 0, query.current ), 300 );
		};
		const own = ownAuthorCount();
		const rows = page ? page.records || [] : [];
		const of = page ? Number( page.q ? page.matched : page.total ) || 0 : 0;
		return createElement( 'div', { className: 'migrateplus-opt migrateplus-authors-each' },
			createElement( 'button', { type: 'button', className: 'button-link migrateplus-authors-toggle', 'aria-expanded': open ? 'true' : 'false', 'aria-controls': 'migrateplus-authors-each', onClick: toggle },
				__( 'Choose an author for each post or page', 'migrateplus' ) ),
			/* translators: %s: number of posts and pages with an author of their own */
			own ? createElement( 'span', { className: 'migrateplus-help migrateplus-authors-count' }, sprintf( _n( '%s post has its own author.', '%s posts have their own author.', own, 'migrateplus' ), own.toLocaleString() ) ) : null,
			createElement( 'div', { id: 'migrateplus-authors-each', hidden: ! open },
				createElement( 'div', { className: 'migrateplus-authors-tools' },
					createElement( 'input', { type: 'search', id: 'migrateplus-authors-search', placeholder: __( 'Search titles', 'migrateplus' ), 'aria-label': __( 'Search posts and pages by title', 'migrateplus' ), onChange: search } ),
					createElement( 'button', { type: 'button', className: 'button', disabled: ! own, onClick: () => {
						state.options.authors = {};
						render();
					} }, __( 'Reset all to the author above', 'migrateplus' ) ) ),
				problem ? createElement( 'div', { className: 'notice notice-error inline' }, createElement( 'p', null, problem ) ) : null,
				page || problem ? null : createElement( 'p', { className: 'migrateplus-muted' }, __( 'Loading…', 'migrateplus' ) ),
				page && ! rows.length ? createElement( 'p', { className: 'migrateplus-muted' }, page.q ? __( 'No titles match.', 'migrateplus' ) : __( 'No records.', 'migrateplus' ) ) : null,
				rows.length ? createElement( 'table', { className: 'migrateplus-map-table migrateplus-authors-table' },
					createElement( 'caption', { className: 'screen-reader-text' }, __( 'Author for each post or page', 'migrateplus' ) ),
					createElement( 'thead', null, createElement( 'tr', null,
						createElement( 'th', { scope: 'col' }, __( 'Title', 'migrateplus' ) ),
						createElement( 'th', { scope: 'col' }, __( 'Type', 'migrateplus' ) ),
						createElement( 'th', { scope: 'col' }, __( 'Author', 'migrateplus' ) ) ) ),
					createElement( 'tbody', null, rows.map( ( r ) => createElement( 'tr', { key: r.index },
						createElement( 'td', { className: 'migrateplus-authors-title' }, r.title ),
						createElement( 'td', null, ( D.postTypes.find( ( p ) => p.name === r.type ) || { label: r.type } ).label ),
						createElement( 'td', null, createElement( AuthorPicker, {
							id: 'migrateplus-author-' + r.index,
							/* translators: %s: a post's title */
							label: sprintf( __( 'Author of “%s”', 'migrateplus' ), r.title ),
							hideLabel: true,
							emptyLabel: __( 'Same as above', 'migrateplus' ),
							value: ( state.options.authors || {} )[ r.index ],
							onChange: ( user ) => setRecordAuthor( r.index, user ),
						} ) ) ) ) ) ) : null,
				rows.length ? createElement( 'div', { className: 'migrateplus-authors-paging' },
					createElement( 'button', { type: 'button', className: 'button', disabled: ! page.offset, onClick: () => load( Math.max( 0, page.offset - RECORDS_PAGE ), page.q ) }, __( 'Previous', 'migrateplus' ) ),
					/* translators: 1: first record shown, 2: last record shown, 3: records in all */
					createElement( 'span', { className: 'migrateplus-muted', 'aria-live': 'polite' }, sprintf( __( '%1$s–%2$s of %3$s', 'migrateplus' ), ( page.offset + 1 ).toLocaleString(), ( page.offset + rows.length ).toLocaleString(), of.toLocaleString() ) ),
					createElement( 'button', { type: 'button', className: 'button', disabled: page.offset + rows.length >= of, onClick: () => load( page.offset + RECORDS_PAGE, page.q ) }, __( 'Next', 'migrateplus' ) ) ) : null,
				page && page.truncated ? createElement( 'p', { className: 'migrateplus-help' }, __( 'Only the first 5,000 records were searched.', 'migrateplus' ) ) : null )
		);
	}

	/**
	 * Save and run. A real import first looks for records already on this site and
	 * asks what to do with them; `checked` skips that once the user has answered.
	 */
	function start( dry, checked, overrides ) {
		state.options.dry_run = dry ? 1 : 0;
		if ( dry ) {
			// A real run asks what to do with records already on this site. A dry run
			// can't ask, so it takes the default answer (skip them); otherwise its
			// counts would call every duplicate "created".
			state.options.skip_existing = 1;
			state.options.update_ids = [];
			state.options.replace_ids = [];
			state.options.copy_ids = [];
		}
		if ( ! dry && ! checked ) {
			state.options.skip_existing = 0;
			modal.open( { kind: 'confirm', title: __( 'Checking for duplicates', 'migrateplus' ), message: __( 'Looking for items in this import that are already on this site…', 'migrateplus' ), percent: null, actions: [] } );
			checkDuplicates().then( ( d ) => {
				if ( ! d.count ) {
					start( false, true );
					return;
				}
				openDuplicates( d, state.step );
			} ).catch( duplicateCheckFailed );
			return;
		}
		if ( state.busy ) {
			return; // Already saving: a second click on Import must not start it twice.
		}
		state.busy = true;
		state.error = '';
		modal.update( { actions: [ { label: __( 'Starting…', 'migrateplus' ), primary: true, disabled: true } ] } );
		render();
		const mapping = mappingToSave();
		// The choices on the duplicates screen go with this save only: Back keeps the options as they were.
		api( 'save', { job: state.job.id, mapping, options: Object.assign( {}, state.options, overrides || {} ) } ).then( ( data ) => {
			state.job = data.job;
			state.step = 'run';
			state.busy = false;
			state.paused = false;
			modal.open( { kind: 'run' } );
			runLoop();
		} ).catch( ( err ) => {
			modal.close();
			state.busy = false;
			state.error = err.message;
			render();
		} );
	}

	/** The duplicate check failed: show why, and let the user import anyway or stop. */
	function duplicateCheckFailed( err ) {
		modal.open( {
			kind: 'confirm',
			bar: false,
			tone: 'error',
			dismissible: true,
			title: __( 'Could not check for duplicates', 'migrateplus' ),
			message: __( 'Items that are already on this site may be imported again.', 'migrateplus' ),
			note: err.message,
			actions: [
				{ label: __( 'Import anyway', 'migrateplus' ), primary: true, onClick: () => start( false, true ) },
				{ label: __( 'Cancel', 'migrateplus' ), onClick: () => modal.close() },
			],
		} );
	}

	// Step 3

	function Run() {
		const job = state.job;
		const s = job.stats;
		const total = job.total ? Number( job.total ) : 0;
		const processed = Number( s.processed ) || 0;
		const dry = Number( job.options.dry_run ) === 1;
		const done = [ 'complete', 'rolled_back' ].includes( job.status );
		const idle = done || 'draft' === job.status;
		const pct = done ? 100 : ( total ? Math.min( 100, Math.floor( ( processed / total ) * 100 ) ) : null );
		// A paused or abandoned import can be rolled back too, once this tab isn't running it; the server refuses while a batch holds the lock.
		const canRollBack = ! dry && ! state.running && ! [ 'rolled_back', 'draft' ].includes( job.status ) && importedCount( job ) > 0;

		let statusText;
		let tone = '';
		if ( job.status === 'rolled_back' ) {
			statusText = __( 'Rolled back', 'migrateplus' );
		} else if ( done ) {
			statusText = dry ? __( 'Dry run complete. Nothing was saved.', 'migrateplus' ) : __( 'Import complete', 'migrateplus' );
			tone = 'success';
		} else if ( job.status === 'draft' ) {
			statusText = __( 'Not started. Nothing was imported.', 'migrateplus' );
		} else if ( state.error ) {
			statusText = __( 'Stopped. Progress is saved.', 'migrateplus' );
			tone = 'error';
		} else if ( state.paused ) {
			statusText = state.running ? __( 'Pausing after this batch…', 'migrateplus' ) : __( 'Paused. Progress is saved.', 'migrateplus' );
			tone = 'paused';
		} else {
			statusText = dry ? __( 'Dry run in progress. Keep this tab open.', 'migrateplus' ) : __( 'Importing. Keep this tab open.', 'migrateplus' );
		}
		let processedText = total
			/* translators: 1: records processed, 2: records in the import */
			? sprintf( __( '%1$s of %2$s records processed', 'migrateplus' ), processed.toLocaleString(), total.toLocaleString() )
			/* translators: %s: records processed */
			: sprintf( __( '%s records processed', 'migrateplus' ), processed.toLocaleString() );
		if ( dry ) {
			/* translators: %s: how many records were processed */
			processedText = sprintf( __( '%s (dry run)', 'migrateplus' ), processedText );
		}

		return createElement( Fragment, null,
			createElement( 'section', { className: 'migrateplus-panel migrateplus-run' + ( tone ? ' is-' + tone : '' ) },
				createElement( 'div', { className: 'migrateplus-run-head' },
					createElement( 'div', { className: 'migrateplus-run-figure', 'aria-hidden': 'true' }, pct === null
						? createElement( 'span', { className: 'migrateplus-run-count' }, processed.toLocaleString() )
						: createElement( Fragment, null, createElement( 'span', { className: 'migrateplus-run-count' }, String( pct ) ), createElement( 'span', { className: 'migrateplus-run-unit' }, '%' ) ) ),
					createElement( 'div', { className: 'migrateplus-run-text' },
						createElement( 'h2', { 'aria-live': 'polite' }, statusText ),
						createElement( 'p', { className: 'migrateplus-muted' }, processedText ),
						sectionsText( job ) ? createElement( 'p', { className: 'migrateplus-muted' }, sectionsText( job ) ) : null )
				),
				createElement( OutcomeBar, { stats: s, total, size: 'lg', indeterminate: ! done && ! total && ! state.paused, tone, label: __( 'Import progress', 'migrateplus' ) } ),
				createElement( OutcomeLegend, { stats: s, dry } ),
				createElement( 'div', { className: 'migrateplus-actions' },
					// One button for Pause and Resume, so focus stays on it when it flips after each batch.
					idle ? null : createElement( 'button', state.paused
						? { type: 'button', id: 'migrateplus-run-toggle', className: 'button button-primary', disabled: state.running, onClick: resume }
						: { type: 'button', id: 'migrateplus-run-toggle', className: 'button', onClick: pauseUnlessJustResumed }, state.paused ? __( 'Resume', 'migrateplus' ) : __( 'Pause', 'migrateplus' ) ),
					! idle && ! modal.isOpen() ? createElement( 'button', { type: 'button', className: 'button', onClick: () => modal.open( { kind: 'run' } ) }, __( 'Show progress window', 'migrateplus' ) ) : null,
					idle && dry ? createElement( 'button', { type: 'button', className: 'button button-primary', onClick: () => start( false ) }, __( 'Run the real import', 'migrateplus' ) ) : null,
					idle && dry ? createElement( 'button', { type: 'button', className: 'button', onClick: () => {
						state.step = 'map';
						render();
					} }, __( 'Adjust mapping', 'migrateplus' ) ) : null,
					idle && ! dry && job.status === 'complete' ? createElement( 'a', { className: 'button button-primary', href: contentUrl( job ) }, __( 'View imported content', 'migrateplus' ) ) : null,
					idle ? createElement( 'button', { type: 'button', className: 'button', onClick: reset }, __( 'Import', 'migrateplus' ) ) : null,
					canRollBack ? createElement( 'button', { type: 'button', className: 'button migrateplus-danger migrateplus-push', onClick: () => rollback( job.id ) }, __( 'Roll back this import', 'migrateplus' ) ) : null
				)
			),
			createElement( RunLog, { job } )
		);
	}

	/** The run's log, newest first, with filters and search. */
	function RunLog( { job } ) {
		const counts = { all: 0, error: 0, warning: 0, success: 0, skipped: 0 };
		( job.log || [] ).forEach( ( e ) => {
			counts.all++;
			if ( counts[ e.l ] !== undefined ) {
				counts[ e.l ]++;
			}
		} );
		const q = state.logQuery.trim().toLowerCase();
		const entries = ( job.log || [] ).slice().reverse().filter( ( e ) =>
			( state.logFilter === 'all' || e.l === state.logFilter ) && ( ! q || String( e.m ).toLowerCase().includes( q ) )
		);
		const filterChip = ( key, label ) => createElement( 'button', {
			type: 'button',
			id: 'migrateplus-log-filter-' + key,
			className: 'migrateplus-chip migrateplus-chip-' + key + ( state.logFilter === key ? ' is-active' : '' ),
			'aria-pressed': state.logFilter === key ? 'true' : 'false',
			onClick: () => {
				state.logFilter = key;
				render();
			},
		}, label, createElement( 'span', { className: 'migrateplus-chip-count' }, String( counts[ key ] || 0 ) ) );

		return createElement( 'section', { className: 'migrateplus-panel migrateplus-log-panel' },
			createElement( 'header', { className: 'migrateplus-log-head' },
				createElement( 'h2', null, __( 'Log', 'migrateplus' ) ),
				createElement( 'div', { className: 'migrateplus-chips', role: 'group', 'aria-label': __( 'Filter log', 'migrateplus' ) },
					filterChip( 'all', __( 'All', 'migrateplus' ) ), filterChip( 'success', __( 'Saved', 'migrateplus' ) ), filterChip( 'skipped', __( 'Skipped', 'migrateplus' ) ), filterChip( 'warning', __( 'Warnings', 'migrateplus' ) ), filterChip( 'error', __( 'Errors', 'migrateplus' ) ) ),
				createElement( 'input', { type: 'search', id: 'migrateplus-log-search', className: 'migrateplus-search', placeholder: __( 'Search the log', 'migrateplus' ), 'aria-label': __( 'Search the log', 'migrateplus' ), value: state.logQuery, onChange: ( e ) => {
					state.logQuery = e.target.value;
					render();
				} } )
			),
			createElement( 'ol', { className: 'migrateplus-log' }, entries.length
				? entries.map( ( e, i ) => createElement( 'li', { key: i, className: 'migrateplus-log-' + e.l },
					createElement( 'time', { dateTime: new Date( e.t * 1000 ).toISOString() }, new Date( e.t * 1000 ).toLocaleTimeString() ),
					createElement( 'span', null, e.m ) ) )
				: createElement( 'li', { className: 'migrateplus-log-empty' }, counts.all ? __( 'No log lines match.', 'migrateplus' ) : __( 'Waiting for the first batch…', 'migrateplus' ) ) )
		);
	}

	/** Where the imported content is listed: the menus or settings screen for an export of those alone. */
	function contentUrl( job ) {
		const sides = jobSides( job );
		if ( ! Number( job.total ) ) {
			if ( sides.menus ) {
				return D.adminUrl + 'nav-menus.php';
			}
			if ( sides.site_settings ) {
				return D.adminUrl + 'options-general.php';
			}
		}
		return D.adminUrl + 'edit.php?post_type=' + encodeURIComponent( job.options.post_type );
	}

	function pause() {
		state.paused = true;
		render();
	}

	/** Pause from the page's Pause/Resume button, but not when it was Resume a moment ago: that's the second click of a double click. */
	function pauseUnlessJustResumed() {
		if ( Date.now() - state.resumedAt < 500 ) {
			return;
		}
		pause();
	}

	function resume() {
		state.paused = false;
		state.error = '';
		state.resumedAt = Date.now();
		render();
		runLoop();
	}

	/**
	 * Modal content for the current state of the running job.
	 */
	function runModalOptions() {
		const job = state.job;
		const s = job.stats;
		const total = job.total ? Number( job.total ) : 0;
		const processed = Number( s.processed ) || 0;
		const dry = Number( job.options.dry_run ) === 1;
		const pausing = state.paused && state.running;

		let percent = null;
		if ( total ) {
			percent = Math.min( 100, ( processed / total ) * 100 );
		}

		let detail = total
			? sprintf( __( '%1$s of %2$s records processed', 'migrateplus' ), processed.toLocaleString(), total.toLocaleString() )
			: sprintf( __( '%s records processed', 'migrateplus' ), processed.toLocaleString() );
		const clock = state.runClock;
		if ( ! state.paused && total && clock && processed > clock.processed ) {
			const rate = ( processed - clock.processed ) / ( ( Date.now() - clock.t ) / 1000 );
			if ( rate > 0 ) {
				/* translators: 1: records processed, 2: time left, e.g. 3m 05s */
				detail = sprintf( __( '%1$s, about %2$s left', 'migrateplus' ), detail, formatDuration( ( total - processed ) / rate ) );
			}
		}

		let title;
		let message;
		let tone = '';
		let note = '';
		let actions;
		const close = () => modal.close();
		if ( state.error ) {
			title = dry ? __( 'Dry run stopped', 'migrateplus' ) : __( 'Import stopped', 'migrateplus' );
			message = __( 'Progress is saved. Fix the problem, then resume to pick up where it stopped.', 'migrateplus' );
			tone = 'error';
			note = state.error.endsWith( ' ' + RESUME_ADVICE ) ? state.error.slice( 0, -RESUME_ADVICE.length - 1 ) : state.error;
			actions = [
				{ label: __( 'Resume', 'migrateplus' ), primary: true, onClick: resume },
				{ label: __( 'Close', 'migrateplus' ), onClick: close },
			];
		} else if ( pausing ) {
			title = __( 'Pausing…', 'migrateplus' );
			message = __( 'Finishing the current batch, then pausing.', 'migrateplus' );
			tone = 'paused';
			actions = [ { label: __( 'Pausing…', 'migrateplus' ), disabled: true }, { label: __( 'Hide', 'migrateplus' ), onClick: close } ];
		} else if ( state.paused ) {
			title = dry ? __( 'Dry run paused', 'migrateplus' ) : __( 'Import paused', 'migrateplus' );
			message = __( 'Progress is saved. Resume any time, or come back later from History.', 'migrateplus' );
			tone = 'paused';
			actions = [
				{ label: __( 'Resume', 'migrateplus' ), primary: true, onClick: resume },
				{ label: __( 'Close', 'migrateplus' ), onClick: close },
			];
		} else {
			title = dry ? __( 'Dry run in progress…', 'migrateplus' ) : __( 'Importing content…', 'migrateplus' );
			const part = currentSection( job );
			/* translators: %s: records per batch */
			message = sprintf( __( 'Keep this tab open. Records are processed in batches of %s.', 'migrateplus' ), job.options.batch_size );
			if ( part ) {
				/* translators: 1: the part being imported, e.g. pages, 2: items done, 3: items in it, 4: progress of every part */
				message = sprintf( __( 'Now: %1$s, %2$s of %3$s. %4$s.', 'migrateplus' ), part.label.toLowerCase(), Number( part.done ).toLocaleString(), Number( part.total ).toLocaleString(), sectionsText( job ) ) + ' ' + message;
			}
			const last = ( job.log || [] )[ ( job.log || [] ).length - 1 ];
			note = last ? truncate( last.m, 140 ) : __( 'Waiting for the first batch…', 'migrateplus' );
			if ( state.lockWait ) {
				note = __( 'Waiting for another tab…', 'migrateplus' );
			}
			actions = [
				{ label: __( 'Pause', 'migrateplus' ), onClick: pause },
				{ label: __( 'Hide', 'migrateplus' ), onClick: close },
			];
		}

		return {
			kind: 'run',
			outcome: { stats: s, total },
			title,
			message,
			percent,
			detail,
			tone,
			note,
			stats: OUTCOMES.map( ( [ key, label, dryLabel ] ) => ( { key, label: dry ? dryLabel : label, value: s[ key ] } ) ),
			actions,
			dismissible: true,
		};
	}

	// Added to the message of an error that stopped a run.
	const RESUME_ADVICE = __( 'Fix the problem, then press Resume to pick up where it stopped.', 'migrateplus' );
	// How long a run waits for another tab or request to let go of the batch lock: a batch may take as long as API_TIMEOUT.
	const LOCK_RETRY_MS = 3000;
	const LOCK_RETRIES = Math.ceil( API_TIMEOUT / LOCK_RETRY_MS );

	function runLoop() {
		if ( state.running ) {
			return;
		}
		state.running = true;
		state.runClock = { t: Date.now(), processed: Number( state.job.stats.processed ) || 0 };
		let retries = 0;
		let lockRetries = 0;
		const tick = () => {
			if ( state.paused ) {
				state.running = false;
				state.lockWait = false;
				render();
				return;
			}
			api( 'run', { job: state.job.id } ).then( ( data ) => {
				retries = 0;
				lockRetries = 0;
				state.lockWait = false;
				state.job = data.job;
				if ( state.tab === 'new' && state.step === 'run' ) {
					render();
				}
				if ( data.done ) {
					state.running = false;
					// No completion modal: the page behind it shows the finished run in full.
					if ( modal.isOpen() && 'run' === modal.kind() ) {
						modal.close();
					}
					render();
					return;
				}
				tick();
			} ).catch( ( err ) => {
				// Another tab or request holds the batch lock: wait for it, and say so, for as long as one batch can take.
				if ( 'migrateplus_locked' === err.code && lockRetries < LOCK_RETRIES ) {
					lockRetries++;
					state.lockWait = true;
					render();
					setTimeout( tick, LOCK_RETRY_MS );
					return;
				}
				// A dropped connection shouldn't stop a long import: try again after 2, 4 and 8 seconds.
				if ( err.network && retries < 3 ) {
					setTimeout( tick, 2000 * Math.pow( 2, retries++ ) );
					return;
				}
				state.running = false;
				state.paused = true;
				state.lockWait = false;
				state.error = err.message.replace( /([^.!?])$/, '$1.' ) + ' ' + RESUME_ADVICE;
				render();
			} );
		};
		tick();
	}

	function reset() {
		state.step = 'source';
		state.job = null;
		state.records = [];
		state.fields = [];
		state.mapping = normalizeMapping();
		state.options = Object.assign( {}, D.defaults );
		state.options.authors = {};
		state.options.author_map = {};
		state.error = '';
		state.paused = false;
		state.direct = false;
		state.savedPick = '';
		state.savedNotice = null;
		state.logFilter = 'all';
		state.logQuery = '';
		if ( state.tab !== 'new' ) {
			window.location.href = D.importUrl;
			return;
		}
		syncJobUrl();
		render();
	}

	/**
	 * Keep the open job's id in the address, and no more: a refresh then comes back to this
	 * job's screen, while &resume=1 never survives a load, so a reload after Pause can't resume.
	 */
	function syncJobUrl() {
		const url = new URL( window.location.href );
		const id = state.job ? String( state.job.id ) : '';
		if ( ( url.searchParams.get( 'job' ) || '' ) === id && ! url.searchParams.has( 'resume' ) ) {
			return;
		}
		if ( id ) {
			url.searchParams.set( 'job', id );
		} else {
			url.searchParams.delete( 'job' );
		}
		url.searchParams.delete( 'resume' );
		window.history.replaceState( window.history.state, '', url.toString() );
	}

	/** Posts the job created over all its runs (stats only cover the last; jobs saved before created_total fall back to them). */
	function importedCount( job ) {
		return ( Number( job.created_total ) || Number( job.stats.created ) || 0 ) + ( Number( job.comments_total ) || 0 );
	}

	function rollback( jobId, confirmed ) {
		if ( ! confirmed ) {
			ask( {
				title: __( 'Roll back this import?', 'migrateplus' ),
				message: __( 'Permanently delete every post, media file, comment and menu created by this import? Posts that were only updated are kept, without the comments it added to them.', 'migrateplus' ),
				label: __( 'Roll back', 'migrateplus' ),
				danger: true,
				onConfirm: () => rollback( jobId, true ),
			} );
			return;
		}
		let total = 0;
		let removed = 0;
		const refresh = () => {
			if ( state.tab === 'history' ) {
				loadHistory();
			} else {
				render();
			}
		};
		/* translators: %s: number of items deleted */
		const deletedText = () => sprintf( _n( '%s item deleted', '%s items deleted', removed, 'migrateplus' ), removed.toLocaleString() );
		modal.open( {
			kind: 'rollback',
			title: __( 'Rolling back import', 'migrateplus' ),
			message: __( 'Deleting the posts and media files this import created. Keep this tab open.', 'migrateplus' ),
			percent: null,
			detail: __( 'Counting items…', 'migrateplus' ),
			tone: '',
			actions: [],
			dismissible: false,
		} );
		const nextBatch = () => api( 'rollback', { job: jobId } ).then( ( data ) => {
			removed += Number( data.deleted ) || 0;
			// The first response tells us how much there was to delete in total.
			total = Math.max( total, removed + ( Number( data.remaining ) || 0 ) );
			if ( state.job && state.job.id === jobId ) {
				state.job = data.job;
			}
			if ( data.error ) {
				throw new Error( data.error );
			}
			if ( data.done ) {
				modal.update( {
					title: __( 'Rollback complete', 'migrateplus' ),
					message: __( 'The imported content was deleted. Posts that were only updated were kept.', 'migrateplus' ),
					percent: 100,
					tone: 'success',
					detail: deletedText(),
					dismissible: true,
					onDismiss: null,
					actions: [ { label: __( 'Close', 'migrateplus' ), primary: true, onClick: () => modal.close() } ],
				} );
				refresh();
				return;
			}
			modal.update( {
				percent: total ? ( removed / total ) * 100 : null,
				/* translators: 1: items deleted so far, 2: items to delete */
				detail: sprintf( __( '%1$s of %2$s items deleted', 'migrateplus' ), removed.toLocaleString(), total.toLocaleString() ),
			} );
			return nextBatch();
		} ).catch( ( err ) => {
			modal.update( {
				title: __( 'Rollback stopped', 'migrateplus' ),
				message: __( 'Some items may already be deleted. You can run the rollback again to finish it.', 'migrateplus' ),
				tone: 'error',
				detail: removed ? deletedText() : '',
				note: err.message,
				dismissible: true,
				onDismiss: null,
				actions: [
					{ label: __( 'Try again', 'migrateplus' ), primary: true, onClick: () => {
						modal.update( { title: __( 'Rolling back import', 'migrateplus' ), tone: '', note: '', dismissible: false, actions: [] } );
						nextBatch();
					} },
					{ label: __( 'Close', 'migrateplus' ), onClick: () => {
						modal.close();
						refresh();
					} },
				],
			} );
		} );
		nextBatch();
	}

	// Export

	const EXPORT_STATUSES = [ [ 'publish', __( 'Published', 'migrateplus' ) ], [ 'draft', __( 'Draft', 'migrateplus' ) ], [ 'pending', __( 'Pending review', 'migrateplus' ) ], [ 'private', __( 'Private', 'migrateplus' ) ], [ 'future', __( 'Scheduled', 'migrateplus' ) ] ];
	// Shown as chips on the Export screen; every other type goes in the Custom Post Types list.
	const BUILTIN_TYPES = [ 'post', 'page', 'attachment' ];
	// WooCommerce's content, listed under its own label with orders when WooCommerce is active.
	const WOO_TYPES = [ 'product', 'shop_coupon' ];
	// The block editor's content (synced patterns, navigation, templates, template parts), in import order.
	const BLOCK_TYPES = [ 'wp_block', 'wp_template_part', 'wp_template' ];

	// One timer for the screen, so changes made close together still send one count.
	let exportCountTimer = null;
	// Counts can come back out of order; only the latest request's answer is shown.
	let exportCountSeq = 0;
	// The hand-picked search works the same way: one timer, only the latest answer shown.
	let pickSearchTimer = null;
	let pickSearchSeq = 0;

	/** The types the export holds: the picks' own types when hand-picking, the ticked cards otherwise. */
	function exportEffectiveTypes() {
		return state.exportPicks.length
			? state.exportPicks.map( ( p ) => p.type ).filter( ( t, i, all ) => all.indexOf( t ) === i )
			: state.exportArgs.post_types;
	}

	/** Ask the server how much the export would hold, 250 ms after the last change. */
	function recount() {
		clearTimeout( exportCountTimer );
		const a = state.exportArgs;
		if ( ! a.post_types.length && ! state.exportPicks.length && ! Number( a.include_orders ) && ! Number( a.include_site_settings ) && ! Number( a.include_menus ) ) {
			++exportCountSeq; // An answer still on its way is stale.
			state.exportCount = { text: __( 'Nothing to export yet. Select at least one card to export.', 'migrateplus' ), warn: true, empty: true, lines: [] };
			render();
			return;
		}
		// Not empty until the count says so: a count that fails must not leave Export disabled by an older answer.
		state.exportCount = Object.assign( {}, state.exportCount, { text: __( 'Counting…', 'migrateplus' ), empty: false } );
		render();
		const seq = ++exportCountSeq; // Now, not in the timer: an answer that lands while this one waits is stale.
		exportCountTimer = setTimeout( () => {
			api( 'export_count', { args: exportArgsToSend() } ).then( ( d ) => {
				if ( seq !== exportCountSeq ) {
					return;
				}
				const counts = Object.entries( d.counts || {} );
				const typeName = ( type, n ) => ( 1 === n ? postType( type ).label : postType( type ).plural || postType( type ).label ).toLowerCase();
				/* translators: a number of items of one content type. 1: the number, 2: the type, e.g. posts */
				const typeCount = ( [ type, n ] ) => sprintf( __( '%1$s %2$s', 'migrateplus' ), n.toLocaleString(), typeName( type, n ) );
				const each = counts.length > 1 ? ' (' + counts.map( typeCount ).join( ', ' ) + ')' : '';
				const sides = sidesList( d.sides || { terms: d.terms, menus: d.menus } );
				// One line per part of the file, for the rail: types with something in them, then the side sections.
				const lines = counts.filter( ( [ , n ] ) => n > 0 ).map( typeCount ).concat( sides );
				// No post type ticked (orders or settings alone) is a choice, not an empty match.
				const noTypes = ! state.exportArgs.post_types.length;
				let text;
				if ( ! d.count && sides.length ) {
					/* translators: %s: what will be exported, e.g. "2 orders" */
					text = noTypes ? sprintf( __( '%s will be exported', 'migrateplus' ), joinAnd( sides ) ) : sprintf( __( 'No content matches: only %s will be exported', 'migrateplus' ), joinAnd( sides ) );
				} else {
					/* translators: %s: number of items */
					let what = 1 === d.count && 1 === counts.length ? typeCount( counts[ 0 ] ) : sprintf( _n( '%s item', '%s items', d.count, 'migrateplus' ), d.count.toLocaleString() );
					what += each;
					if ( sides.length ) {
						/* translators: 1: the items, 2: what else is exported, e.g. "5 terms and 2 orders" */
						what = sprintf( __( '%1$s, with %2$s', 'migrateplus' ), what, joinAnd( sides ) );
					}
					text = sprintf( __( '%s will be exported', 'migrateplus' ), what );
				}
				state.exportCount = {
					text,
					warn: ! d.count && ! ( noTypes && sides.length ),
					empty: ! d.count && ! sides.length, // Menus, terms, orders or settings alone are worth a file.
					lines,
				};
				render();
			} ).catch( ( e ) => {
				if ( seq !== exportCountSeq ) {
					return;
				}
				state.exportCount = Object.assign( {}, state.exportCount, { text: e.message, warn: true } );
				render();
			} );
		}, 250 );
	}

	/** The ACF fields on the types the export holds. */
	function exportAcfCount() {
		return exportEffectiveTypes().reduce( ( n, t ) => n + acfFields( t ).length, 0 );
	}

	// The toggles the export cards can show, with their label and help.
	const EXPORT_CARD_TOGGLES = {
		include_terms: [ __( 'Categories, tags and terms', 'migrateplus' ), __( 'Hierarchy is kept as "Parent > Child".', 'migrateplus' ) ],
		include_term_data: [ __( 'Term data', 'migrateplus' ), __( 'The terms of these content types as their own section: descriptions, custom fields, ACF fields, category images and terms nothing uses yet.', 'migrateplus' ) ],
		include_image: [ __( 'Featured images', 'migrateplus' ), __( 'Exported as URLs; the importing site downloads them.', 'migrateplus' ) ],
		include_meta: [ __( 'Custom fields', 'migrateplus' ), __( 'Public post meta. Private keys starting with "_" and keys listed in Settings are left out.', 'migrateplus' ) ],
		/* translators: %s: number of ACF fields */
		include_acf: [ __( 'ACF fields', 'migrateplus' ), () => sprintf( _n( '%s field on these content types. Images become URLs, related posts become references.', '%s fields on these content types. Images become URLs, related posts become references.', exportAcfCount(), 'migrateplus' ), exportAcfCount() ) ],
		include_acf_definitions: [ __( 'ACF definitions', 'migrateplus' ), __( 'Field groups, post types, taxonomies and options pages, as ACF\'s own export writes them.', 'migrateplus' ) ],
		include_comments: [ __( 'Comments', 'migrateplus' ), __( 'Approved comments and replies, with their public comment fields. Pending, spam and trashed comments are left out.', 'migrateplus' ) ],
		include_yoast: [ __( 'Yoast SEO', 'migrateplus' ), __( 'Titles, descriptions, keyphrases, social images and primary categories of posts and terms.', 'migrateplus' ) ],
		include_woo: [ __( 'Product data', 'migrateplus' ), __( 'Prices, stock, attributes, downloads and variations. Images become URLs; related products link up by ID or SKU.', 'migrateplus' ) ],
		include_acf_options: [ __( 'ACF options pages', 'migrateplus' ), __( 'The values of every options page. Images become URLs, related posts become references.', 'migrateplus' ) ],
	};

	/**
	 * Which card toggles are on screen for what is ticked now: `post` for the card that carries the post field
	 * toggles, then by card key. The Export screen draws these, and exportArgsToSend() sends every other one as 0.
	 */
	function shownCardToggles() {
		const x = state.exportArgs;
		const acf = !! ( D.acf && D.acf.active );
		// Only what the ticked types can carry: no terms toggle for types without taxonomies, no featured
		// images or comments for types that don't support them, no ACF toggle without ACF fields.
		const typeNames = exportEffectiveTypes();
		const picked = typeNames.map( postType );
		const can = ( key ) => picked.some( ( p ) => ! p.supports || p.supports[ key ] );
		const hasTerms = picked.some( ( p ) => ( p.taxonomies || [] ).length > 0 );
		// Statuses, featured images and the like don't apply to media.
		const isMedia = picked.length > 0 && typeNames.every( ( t ) => t === 'attachment' );
		const post = ! picked.length ? [] : [
			hasTerms && 'include_terms',
			hasTerms && 'include_term_data',
			! isMedia && can( 'thumbnail' ) && 'include_image',
			'include_meta',
			acf && exportAcfCount() && 'include_acf',
			acf && 'include_acf_definitions',
			can( 'comments' ) && 'include_comments',
			D.sides && D.sides.yoast && 'include_yoast',
		].filter( Boolean );
		return {
			post,
			woo: D.woo && D.woo.active && typeNames.includes( 'product' ) ? [ 'include_woo' ] : [],
			site: acf && D.sides && D.sides.site && Number( x.include_site_settings ) ? [ 'include_acf_options' ] : [],
		};
	}

	/** The export args as sent: a card toggle only applies while it is on screen, so every hidden one goes as 0. */
	function exportArgsToSend() {
		const x = state.exportArgs;
		const shown = Object.values( shownCardToggles() ).flat();
		const args = Object.assign( {}, x, {
			// The PHP side drops an empty array from the request, so no sections goes as ''.
			site_sections: ( x.site_sections || [] ).length ? x.site_sections : '',
			post_ids: state.exportPicks.map( ( p ) => p.id ),
		} );
		Object.keys( EXPORT_CARD_TOGGLES ).forEach( ( k ) => {
			args[ k ] = shown.includes( k ) && Number( x[ k ] ) ? 1 : 0;
		} );
		return args;
	}

	function Export() {
		const x = state.exportArgs;
		const setArg = ( k, v ) => {
			x[ k ] = v;
			recount();
		};
		const toggle = ( k, label, help, disabled ) => createElement( Toggle, { key: k, id: 'migrateplus-export-' + k, label, help, disabled, checked: ! disabled && !! Number( x[ k ] ), onChange: ( e ) => setArg( k, e.target.checked ? 1 : 0 ) } );
		// Statuses, featured images and the like don't apply to media.
		const isMedia = x.post_types.length > 0 && x.post_types.every( ( t ) => t === 'attachment' );
		// WooCommerce's types go behind a button of their own, with orders, like the Custom Post Types.
		// What to export is a checklist of features. Ticking a feature takes all of it and shows its
		// parts under the row, so a feature can be narrowed; unticking it drops the lot.
		const wooActive = D.woo && D.woo.active;
		const byName = ( names ) => names.map( ( name ) => exportTypes().find( ( p ) => p.name === name ) ).filter( Boolean );
		const wooTypes = wooActive ? byName( WOO_TYPES ) : [];
		const blocks = byName( BLOCK_TYPES );
		const customs = exportTypes().filter( ( p ) => ! BUILTIN_TYPES.includes( p.name ) && ! BLOCK_TYPES.includes( p.name ) && 'wp_navigation' !== p.name && ! ( wooActive && WOO_TYPES.includes( p.name ) ) );
		// Classic menus (Appearance → Menus) and the Site Editor's navigation blocks share one card.
		const menus = D.menus ? { name: 'menus', label: __( 'Menus', 'migrateplus' ), arg: 'include_menus' } : null;
		const navigation = byName( [ 'wp_navigation' ] ).map( ( p ) => Object.assign( {}, p, { plural: __( 'Navigation blocks', 'migrateplus' ) } ) );
		const orders = D.sides && D.sides.orders ? { name: 'orders', label: __( 'Orders', 'migrateplus' ), arg: 'include_orders' } : null;
		const site = D.sides && D.sides.site ? { name: 'site_settings', label: __( 'Site settings', 'migrateplus' ), arg: 'include_site_settings' } : null;
		const siteSections = D.siteSections || [];
		const pickedSections = ( x.site_sections || [] ).filter( ( k ) => siteSections.some( ( s ) => s.key === k ) );
		// A feature's parts: post types (by name) and side sections (by arg). What is picked of each.
		const typeItem = ( p ) => ( { kind: 'type', name: p.name, label: p.plural || p.label, on: x.post_types.includes( p.name ) } );
		const sideItem = ( s ) => ( { kind: 'side', name: s.name, label: s.label, arg: s.arg, on: !! Number( x[ s.arg ] ) } );
		const features = [
			{ key: 'content', label: __( 'Posts, pages and media', 'migrateplus' ), help: __( 'Tick the types, then choose below what travels with them.', 'migrateplus' ), items: byName( BUILTIN_TYPES ).map( typeItem ) },
			{ key: 'blocks', label: __( 'Block editor', 'migrateplus' ), help: __( 'Synced patterns, templates and template parts of the Site Editor.', 'migrateplus' ), items: blocks.map( typeItem ) },
			{ key: 'navigation', label: __( 'Navigation', 'migrateplus' ), help: __( 'Menus from Appearance → Menus with their items and theme locations, and the Site Editor\'s navigation blocks. Export the content they link to in the same file.', 'migrateplus' ), items: ( menus ? [ sideItem( menus ) ] : [] ).concat( navigation.map( typeItem ) ) },
			{ key: 'woo', label: __( 'WooCommerce', 'migrateplus' ), help: __( 'Products with their data, coupons, and orders with their items, refunds and notes.', 'migrateplus' ), items: wooTypes.map( typeItem ).concat( orders ? [ sideItem( orders ) ] : [] ) },
			{ key: 'cpt', label: __( 'Custom post types', 'migrateplus' ), help: __( 'Content types added by the theme or plugins.', 'migrateplus' ), items: customs.map( typeItem ) },
			site ? { key: 'site', label: __( 'Site settings', 'migrateplus' ), help: __( 'Settings, widgets, theme and plugin options. Pick which parts below.', 'migrateplus' ), items: [ sideItem( site ) ], single: true } : null,
		].filter( ( f ) => f && f.items.length );
		const pickedCount = features.reduce( ( n, f ) => n + f.items.filter( ( i ) => i.on ).length, 0 );
		// Apply a set of items: post types in the order shown, side sections by their arg. Everything may be
		// unticked; the count line then says so and Export is disabled.
		const apply = ( items, on ) => {
			const types = items.filter( ( i ) => 'type' === i.kind ).map( ( i ) => i.name );
			const sides = items.filter( ( i ) => 'side' === i.kind );
			// Ticking a whole type ends hand-picking: the two would fight over what the file holds.
			if ( on && types.length ) {
				state.exportPicks = [];
				state.exportPicksOpen = false;
			}
			const nextTypes = on ? exportTypes().map( ( t ) => t.name ).filter( ( t ) => x.post_types.includes( t ) || types.includes( t ) ) : x.post_types.filter( ( t ) => ! types.includes( t ) );
			const nextSides = {};
			sides.forEach( ( s ) => { nextSides[ s.arg ] = on ? 1 : 0; } );
			x.post_types = nextTypes;
			Object.assign( x, nextSides );
			if ( site && on && nextSides[ site.arg ] && ! pickedSections.length ) {
				x.site_sections = siteSections.map( ( s ) => s.key ); // A fresh tick of Site settings takes every part.
			}
			recount();
		};
		const setSections = ( keys ) => setArg( 'site_sections', siteSections.map( ( s ) => s.key ).filter( ( k ) => keys.includes( k ) ) );
		// The status and date filters sit in the Posts, pages and media card: they apply to its content (dates to orders too).
		const contentFilters = () => createElement( 'div', { className: 'migrateplus-export-filters' },
				isMedia ? null : createElement( 'fieldset', { className: 'migrateplus-opt' },
					createElement( 'legend', { className: 'migrateplus-opt-label' }, __( 'Status', 'migrateplus' ) ),
					createElement( 'div', { className: 'migrateplus-chips' }, EXPORT_STATUSES.map( ( [ v, l ] ) => {
						const id = 'migrateplus-export-status-' + v;
						return createElement( 'label', { key: v, className: 'migrateplus-check-chip', htmlFor: id },
							createElement( 'input', { type: 'checkbox', id, checked: x.statuses.includes( v ), onChange: ( e ) => {
								const picked = e.target.checked ? x.statuses.concat( v ) : x.statuses.filter( ( st ) => st !== v );
								if ( ! picked.length ) {
									render(); // The last one stays ticked.
									return;
								}
								x.statuses = picked;
								recount();
							} } ),
							createElement( 'span', null, l ) );
					} ) )
				),
				createElement( 'fieldset', { className: 'migrateplus-opt' },
					createElement( 'legend', { className: 'migrateplus-opt-label' }, isMedia ? __( 'Uploaded between', 'migrateplus' ) : __( 'Published between', 'migrateplus' ) ),
					createElement( 'div', { className: 'migrateplus-date-range' },
						createElement( DateInput, { label: __( 'From', 'migrateplus' ), value: x.after, onChange: ( v ) => setArg( 'after', v ) } ),
						createElement( 'span', { className: 'migrateplus-muted' }, __( 'and', 'migrateplus' ) ),
						createElement( DateInput, { label: __( 'To', 'migrateplus' ), value: x.before, onChange: ( v ) => setArg( 'before', v ) } ) ),
					createElement( 'span', { className: 'migrateplus-help' }, __( 'Leave empty to export everything.', 'migrateplus' ) )
				)
		);
		// What travels with a card's content: the post fields with Posts, pages and media (and any other post
		// type), product data with products, the options pages with the site settings.
		// The first card with a post type ticked carries the post field toggles: normally Posts, pages and media.
		const postCard = features.find( ( o ) => o.items.some( ( i ) => 'type' === i.kind && i.on ) ) || null;
		const shown = shownCardToggles();
		const picksMode = state.exportPicks.length > 0;
		// When hand-picking, the post field toggles (and product data, for a picked product) sit in the picks card.
		const cardToggles = ( f ) => ( f === postCard ? shown.post : [] ).concat( picksMode && 'woo' === f.key ? [] : shown[ f.key ] || [] ).map( ( k ) => {
			const [ label, help ] = EXPORT_CARD_TOGGLES[ k ];
			return toggle( k, label, typeof help === 'function' ? help() : help );
		} );
		// The hand-picked card: a search over every exportable type, and the rows added from it.
		const setPickSearch = ( term ) => {
			state.exportPickSearch = term;
			clearTimeout( pickSearchTimer );
			if ( '' === term.trim() ) {
				++pickSearchSeq; // An answer still on its way is stale.
				state.exportPickResults = null;
				state.exportPickBusy = false;
				state.exportPickError = '';
				render();
				return;
			}
			state.exportPickBusy = true;
			state.exportPickError = '';
			render();
			const seq = ++pickSearchSeq; // Now, not in the timer: an answer that lands while this one waits is stale.
			pickSearchTimer = setTimeout( () => {
				api( 'export_posts', { search: term.trim() } ).then( ( d ) => {
					if ( seq !== pickSearchSeq ) {
						return;
					}
					state.exportPickResults = d.posts || [];
					state.exportPickBusy = false;
					render();
				} ).catch( ( e ) => {
					if ( seq !== pickSearchSeq ) {
						return;
					}
					state.exportPickResults = null;
					state.exportPickBusy = false;
					state.exportPickError = e.message;
					render();
				} );
			}, 300 );
		};
		const addPick = ( p ) => {
			if ( ! state.exportPicks.some( ( q ) => q.id === p.id ) ) {
				state.exportPicks = state.exportPicks.concat( p );
				recount();
			}
		};
		const removePick = ( id ) => {
			state.exportPicks = state.exportPicks.filter( ( p ) => p.id !== id );
			recount();
		};
		const pickMeta = ( p ) => [ p.label, p.status, p.date ].filter( Boolean ).join( ' · ' );
		const pickResults = () => {
			if ( state.exportPickBusy ) {
				return createElement( 'p', { className: 'migrateplus-help' }, __( 'Searching…', 'migrateplus' ) );
			}
			if ( state.exportPickError ) {
				return createElement( 'p', { className: 'migrateplus-help is-warn' }, state.exportPickError );
			}
			if ( ! state.exportPickResults ) {
				return null;
			}
			if ( ! state.exportPickResults.length ) {
				return createElement( 'p', { className: 'migrateplus-help' }, __( 'Nothing found.', 'migrateplus' ) );
			}
			return createElement( 'ul', { className: 'migrateplus-pick-results' }, state.exportPickResults.map( ( p ) => {
				const got = state.exportPicks.some( ( q ) => q.id === p.id );
				return createElement( 'li', { key: p.id },
					createElement( 'span', { className: 'migrateplus-pick-text' },
						createElement( 'span', { className: 'migrateplus-pick-title' }, p.title ),
						createElement( 'span', { className: 'migrateplus-pick-meta' }, pickMeta( p ) ) ),
					createElement( 'button', { type: 'button', className: 'button button-small', disabled: got, onClick: () => addPick( p ) }, got ? __( 'Added', 'migrateplus' ) : __( 'Add', 'migrateplus' ) ) );
			} ) );
		};
		const pickToggles = () => shown.post.concat( shown.woo || [] ).map( ( k ) => {
			const [ label, help ] = EXPORT_CARD_TOGGLES[ k ];
			return toggle( k, label, typeof help === 'function' ? help() : help );
		} );
		const picksCard = () => {
			const picks = state.exportPicks;
			const open = state.exportPicksOpen || picks.length > 0;
			const id = 'migrateplus-export-feature-picks';
			return createElement( 'div', { key: 'picks', className: 'migrateplus-export-card' + ( picks.length ? ' is-on' : '' ) },
				createElement( 'label', { className: 'migrateplus-export-card-head', htmlFor: id },
					createElement( 'input', { type: 'checkbox', id, checked: open, onChange: ( e ) => {
						if ( e.target.checked ) {
							state.exportPicksOpen = true;
							x.post_types = []; // Hand-picking and whole types don't mix: the picks name their own types.
						} else {
							state.exportPicksOpen = false;
							state.exportPicks = [];
						}
						recount();
					} } ),
					createElement( 'span', { className: 'migrateplus-export-card-title' }, __( 'Hand-picked content', 'migrateplus' ) ),
					/* translators: %s: number of items */
					picks.length ? createElement( 'span', { className: 'migrateplus-export-card-count' }, sprintf( _n( '%s item', '%s items', picks.length, 'migrateplus' ), picks.length ) ) : null,
					createElement( 'span', { className: 'migrateplus-help' }, __( 'Search for exactly the content to export, whatever its type. The status and date filters don\'t apply to hand-picked items.', 'migrateplus' ) ) ),
				open ? createElement( 'div', { className: 'migrateplus-export-parts' },
					createElement( 'label', { className: 'screen-reader-text', htmlFor: 'migrateplus-export-pick-search' }, __( 'Search content', 'migrateplus' ) ),
					createElement( 'input', { type: 'search', id: 'migrateplus-export-pick-search', className: 'regular-text migrateplus-pick-search', placeholder: __( 'Search by title, or paste an ID…', 'migrateplus' ), value: state.exportPickSearch, onChange: ( e ) => setPickSearch( e.target.value ) } ),
					pickResults(),
					picks.length ? createElement( 'ul', { className: 'migrateplus-pick-list' }, picks.map( ( p ) => createElement( 'li', { key: p.id },
						createElement( 'span', { className: 'migrateplus-pick-text' },
							createElement( 'span', { className: 'migrateplus-pick-title' }, p.title ),
							createElement( 'span', { className: 'migrateplus-pick-meta' }, pickMeta( p ) ) ),
						createElement( 'button', { type: 'button', className: 'button-link migrateplus-pick-remove', onClick: () => removePick( p.id ) }, __( 'Remove', 'migrateplus' ) ) ) ) ) : null,
					picks.length && pickToggles().filter( Boolean ).length ? createElement( 'div', { className: 'migrateplus-toggles migrateplus-card-toggles' }, pickToggles() ) : null ) : null );
		};
		const featureCard = ( f ) => {
			const picked = f.items.filter( ( i ) => i.on ).length;
			const id = 'migrateplus-export-feature-' + f.key;
			return createElement( 'div', { key: f.key, className: 'migrateplus-export-card' + ( picked ? ' is-on' : '' ) },
				createElement( 'label', { className: 'migrateplus-export-card-head', htmlFor: id },
					createElement( 'input', {
						type: 'checkbox',
						id,
						checked: picked > 0, // Ticked as soon as any part is: the count beside the name says how much.
						onChange: ( e ) => apply( f.items, e.target.checked ),
					} ),
					createElement( 'span', { className: 'migrateplus-export-card-title' }, f.label ),
					/* translators: 1: parts ticked, 2: parts of the card. Or: %s: number of content types */
					! f.single ? createElement( 'span', { className: 'migrateplus-export-card-count' }, picked ? sprintf( __( '%1$s of %2$s', 'migrateplus' ), picked, f.items.length ) : sprintf( _n( '%s type', '%s types', f.items.length, 'migrateplus' ), f.items.length ) ) : null,
					createElement( 'span', { className: 'migrateplus-help' }, f.help ) ),
				picked && ! f.single ? createElement( 'div', { className: 'migrateplus-export-parts' },
					createElement( 'div', { className: 'migrateplus-chips' }, f.items.map( ( i ) => {
						const cid = 'migrateplus-export-type-' + i.name;
						return createElement( 'label', { key: i.name, className: 'migrateplus-check-chip', htmlFor: cid, title: i.name },
							createElement( 'input', { type: 'checkbox', id: cid, checked: i.on, onChange: ( e ) => apply( [ i ], e.target.checked ) } ),
							createElement( 'span', null, i.label ) );
					} ) ),
					'content' === f.key ? contentFilters() : null,
					cardToggles( f ).filter( Boolean ).length ? createElement( 'div', { className: 'migrateplus-toggles migrateplus-card-toggles' }, cardToggles( f ) ) : null ) : null,
				picked && 'site' === f.key ? createElement( 'div', { className: 'migrateplus-export-parts' },
					createElement( 'div', { className: 'migrateplus-table-tools' },
						/* translators: 1: sections ticked, 2: sections there are */
						createElement( 'span', { className: 'migrateplus-help' }, sprintf( __( '%1$s of %2$s sections', 'migrateplus' ), pickedSections.length, siteSections.length ) ),
						createElement( 'button', { type: 'button', className: 'button-link', onClick: () => setSections( siteSections.map( ( s ) => s.key ) ) }, __( 'All', 'migrateplus' ) ),
						createElement( 'button', { type: 'button', className: 'button-link', onClick: () => setSections( [] ) }, __( 'None', 'migrateplus' ) ) ),
					createElement( 'div', { className: 'migrateplus-chips' }, siteSections.map( ( s ) => {
						const sid = 'migrateplus-export-site-' + s.key;
						return createElement( 'label', { key: s.key, className: 'migrateplus-check-chip', htmlFor: sid, title: s.help },
							createElement( 'input', { type: 'checkbox', id: sid, value: s.key, checked: pickedSections.includes( s.key ), onChange: ( e ) => setSections( e.target.checked ? pickedSections.concat( s.key ) : pickedSections.filter( ( k ) => k !== s.key ) ) } ),
							createElement( 'span', null, s.label ) );
					} ) ),
					! pickedSections.length ? createElement( 'p', { className: 'migrateplus-help is-warn' }, __( 'Pick at least one section, or untick Site settings.', 'migrateplus' ) ) : null,
					cardToggles( f ).length ? createElement( 'div', { className: 'migrateplus-toggles migrateplus-card-toggles' }, cardToggles( f ) ) : null ) : null );
		};
		const le = state.lastExport;

		const file = state.exportCount;
		// The server would read no sections as all of them.
		const noSections = !! site && !! Number( x.include_site_settings ) && ! pickedSections.length;
		return createElement( Fragment, null,
			createElement( 'form', { className: 'migrateplus-panel migrateplus-export', onSubmit: ( e ) => {
				e.preventDefault();
				runExport();
			} },
				createElement( 'div', { className: 'migrateplus-export-layout' },
					createElement( 'section', { className: 'migrateplus-export-manifest' },
						createElement( 'h2', null, __( 'What to export', 'migrateplus' ) ),
						createElement( 'div', { className: 'migrateplus-export-cards', role: 'group', 'aria-label': __( 'What to export', 'migrateplus' ) }, picksCard(), features.map( featureCard ) ) ),
					// The file being built: what is in it so far, and the button that writes it.
					createElement( 'aside', { className: 'migrateplus-export-file', 'aria-label': __( 'Your file', 'migrateplus' ) },
						createElement( 'h2', null, __( 'Your file', 'migrateplus' ) ),
						createElement( 'p', { className: 'migrateplus-export-count' + ( file.warn ? ' is-warn' : '' ), 'aria-live': 'polite' }, file.text ),
						file.lines && file.lines.length ? createElement( 'ul', { className: 'migrateplus-file-lines' }, file.lines.map( ( line ) => createElement( 'li', { key: line }, line ) ) ) : null,
						pickedCount > 1 ? createElement( 'p', { className: 'migrateplus-help' }, __( 'Imported in this order on the other site: media first, then the rest as listed, and orders last.', 'migrateplus' ) ) : null,
						D.zlib ? createElement( 'div', { className: 'migrateplus-toggles migrateplus-file-options' },
							toggle( 'gzip', __( 'Compress the file (.json.gz)', 'migrateplus' ), __( 'Much smaller, for a site whose upload limit is lower than the export. Migrate Plus imports it as it is.', 'migrateplus' ) ) ) : null,
						createElement( 'button', { type: 'submit', className: 'button button-primary migrateplus-btn-lg migrateplus-file-export', disabled: file.empty || noSections }, __( 'Export', 'migrateplus' ) ) )
				)
			),
			le ? createElement( 'section', { className: 'migrateplus-summary' },
				createElement( 'span', { className: 'migrateplus-source-icon migrateplus-icon-json', 'aria-hidden': 'true' } ),
				createElement( 'div', { className: 'migrateplus-summary-text' },
					createElement( 'strong', null, le.filename ),
					/* translators: 1: number of items, 2: file size */
					createElement( 'span', null, sprintf( _n( '%1$s item, %2$s. The download link works for 24 hours.', '%1$s items, %2$s. The download link works for 24 hours.', le.done, 'migrateplus' ), le.done.toLocaleString(), le.size ) ) ),
				createElement( 'a', { className: 'button button-primary', href: safeUrl( le.url ) }, __( 'Download', 'migrateplus' ) )
			) : null
		);
	}

	/** The post types the Export screen offers (the server leaves out ACF's settings, orders and the like). */
	function exportTypes() {
		return D.postTypes.filter( ( p ) => false !== p.export );
	}

	function runExport() {
		const x = state.exportArgs;
		const working = {
			/* translators: %s: a post type, e.g. post */
			title: 1 === x.post_types.length ? sprintf( __( 'Exporting %s content', 'migrateplus' ), postType( x.post_types[ 0 ] ).label.toLowerCase() ) : __( 'Exporting content', 'migrateplus' ),
			message: __( 'Writing a JSON file. Keep this tab open.', 'migrateplus' ),
			tone: '',
			note: '',
			actions: [],
			dismissible: false,
		};
		modal.open( Object.assign( { kind: 'export', percent: null, detail: __( 'Counting…', 'migrateplus' ) }, working ) );
		// A request that failed can be sent again: the next batch, or the start when there is no export yet.
		const showFailure = ( err, retry ) => modal.update( {
			title: __( 'Export stopped', 'migrateplus' ),
			message: '',
			tone: 'error',
			detail: '',
			note: err.message,
			dismissible: true,
			actions: ( retry ? [ { label: __( 'Try again', 'migrateplus' ), primary: true, onClick: () => {
				modal.update( working );
				retry();
			} } ] : [] ).concat( { label: __( 'Close', 'migrateplus' ), primary: ! retry, onClick: () => modal.close() } ),
		} );
		const nextBatch = ( id ) => apiWithRetry( 'export_run', { export: id } ).then( ( d ) => {
			if ( d.error ) {
				showFailure( { message: d.error } );
				return;
			}
			if ( d.complete ) {
				state.lastExport = d;
				const extras = [];
				if ( d.acf ) {
					/* translators: %s: number of ACF fields */
					extras.push( sprintf( _n( '%s ACF field', '%s ACF fields', d.acf, 'migrateplus' ), d.acf ) );
				}
				if ( d.meta ) {
					/* translators: %s: number of custom fields */
					extras.push( sprintf( _n( '%s custom field', '%s custom fields', d.meta, 'migrateplus' ), d.meta ) );
				}
				extras.push( ...sidesList( d.sides || { terms: d.terms, menus: d.menus } ) );
				/* translators: %s: number of items */
				let detail = sprintf( _n( '%s item', '%s items', d.done, 'migrateplus' ), d.done.toLocaleString() );
				if ( extras.length ) {
					/* translators: 1: the items, 2: what else is in the file, e.g. "3 ACF fields and 2 menus" */
					detail = sprintf( __( '%1$s with %2$s', 'migrateplus' ), detail, joinAnd( extras ) );
				}
				modal.update( {
					title: __( 'Export ready', 'migrateplus' ),
					message: __( 'Import it on the other site with Migrate Plus → Import. The fields map themselves.', 'migrateplus' ),
					percent: 100,
					tone: 'success',
					/* translators: 1: what is in the file, 2: file size */
					detail: sprintf( __( '%1$s, %2$s', 'migrateplus' ), detail, d.size ),
					dismissible: true,
					onDismiss: null,
					actions: [
						/* translators: %s: the file type, .json.gz or JSON */
						{ label: sprintf( __( 'Download %s', 'migrateplus' ), /\.json\.gz$/.test( d.filename ) ? '.json.gz' : 'JSON' ), primary: true, href: d.url },
						{ label: __( 'Close', 'migrateplus' ), onClick: () => modal.close() },
					],
				} );
				return;
			}
			modal.update( {
				percent: d.total ? ( d.done / d.total ) * 100 : null,
				detail: d.section
					/* translators: 1: items written, 2: items to write, 3: the part being written, e.g. pages */
					? sprintf( __( '%1$s of %2$s items, now %3$s', 'migrateplus' ), d.done.toLocaleString(), d.total.toLocaleString(), d.section.toLowerCase() )
					/* translators: 1: items written, 2: items to write */
					: sprintf( __( '%1$s of %2$s items', 'migrateplus' ), d.done.toLocaleString(), d.total.toLocaleString() ),
			} );
			return nextBatch( id );
		}, ( err ) => showFailure( err, () => nextBatch( id ) ) );
		apiWithRetry( 'export_start', { args: exportArgsToSend() } ).then( ( d ) => {
			modal.update( { percent: 0, detail: sprintf( __( '%1$s of %2$s items', 'migrateplus' ), 0, d.total.toLocaleString() ) } );
			return nextBatch( d.id );
		}, ( err ) => showFailure( err, runExport ) ).catch( ( err ) => showFailure( err ) );
	}

	// Find & Replace

	function Replace() {
		const r = state.replace;
		const all = D.tables || [];
		// Drop a validation message once what it complained about is fixed.
		const changed = () => {
			if ( r.search && r.tables.length ) {
				r.problem = '';
			}
			render();
		};
		const toggle = ( k, label, help ) => createElement( Toggle, { id: 'migrateplus-replace-' + k, label, help, checked: !! r[ k ], onChange: ( e ) => {
			r[ k ] = e.target.checked ? 1 : 0;
			changed();
		} } );
		const textInput = ( k, label, placeholder ) => createElement( 'div', { className: 'migrateplus-opt' },
			createElement( 'label', { className: 'migrateplus-opt-label', htmlFor: 'migrateplus-replace-' + k }, label ),
			createElement( 'input', { type: 'text', id: 'migrateplus-replace-' + k, className: 'migrateplus-code-input', value: r[ k ], placeholder, autoComplete: 'off', spellCheck: false, onChange: ( e ) => {
				r[ k ] = e.target.value;
				changed();
			} } ) );
		const selectAll = ( on ) => {
			r.tables = on ? all.map( ( t ) => t.name ) : [];
			changed();
		};

		return createElement( Fragment, null,
			createElement( 'form', { className: 'migrateplus-panel migrateplus-export migrateplus-replace', onSubmit: ( e ) => {
				e.preventDefault();
				r.problem = ! r.search ? __( 'Enter the text to search for.', 'migrateplus' ) : ( ! r.tables.length ? __( 'No database tables are selected. Please select at least one.', 'migrateplus' ) : '' );
				render();
				if ( ! r.problem ) {
					runReplace();
				}
			} },
				createElement( 'div', { 'aria-live': 'assertive' }, r.problem ? createElement( Notice, { type: 'error', message: r.problem } ) : null ),
				createElement( 'div', { className: 'migrateplus-export-grid' },
					createElement( 'section', null,
						createElement( 'h2', null, __( 'Find and replace', 'migrateplus' ) ),
						textInput( 'search', __( 'Search for', 'migrateplus' ), 'http://old-site.test' ),
						textInput( 'replace', __( 'Replace with', 'migrateplus' ), 'https://new-site.com' ),
						createElement( 'div', { className: 'migrateplus-toggles is-flush' },
							toggle( 'dry_run', __( 'Dry run', 'migrateplus' ), __( 'Count and preview the changes without saving anything.', 'migrateplus' ) ),
							toggle( 'whole_urls', __( 'Whole URLs only', 'migrateplus' ), __( 'When you search for a web address or domain, only that exact site matches: http://old.test is not changed inside http://old.test.example.com or http://old.testing.com, and old.test is not changed inside sub.old.test.', 'migrateplus' ) ),
							toggle( 'case_insensitive', __( 'Ignore case', 'migrateplus' ), __( 'Match "Example" and "example" alike.', 'migrateplus' ) ),
							toggle( 'guids', __( 'Replace GUIDs', 'migrateplus' ), __( 'Post GUIDs are permanent IDs that feed readers rely on. Leave this off unless you know you need it.', 'migrateplus' ) ) ),
						createElement( 'p', { className: 'migrateplus-help' }, __( 'Migrate Plus\'s own history and tracking data are left alone, so later imports still recognise content they brought in.', 'migrateplus' ) )
					),
					createElement( 'section', null,
						createElement( 'h2', null, __( 'Tables', 'migrateplus' ) ),
						createElement( 'div', { className: 'migrateplus-table-tools' },
							/* translators: 1: tables selected, 2: tables there are */
							createElement( 'span', { className: 'migrateplus-help' }, sprintf( __( '%1$s of %2$s tables selected', 'migrateplus' ), r.tables.length, all.length ) ),
							createElement( 'button', { type: 'button', className: 'button-link', onClick: () => selectAll( true ) }, __( 'All', 'migrateplus' ) ),
							createElement( 'button', { type: 'button', className: 'button-link', onClick: () => selectAll( false ) }, __( 'None', 'migrateplus' ) ) ),
						createElement( 'div', { className: 'migrateplus-table-list' }, all.map( ( t ) => {
							const id = 'migrateplus-table-' + t.name;
							return createElement( 'label', { key: t.name, className: 'migrateplus-table-option', htmlFor: id },
								createElement( 'input', { type: 'checkbox', id, value: t.name, checked: r.tables.includes( t.name ), onChange: ( e ) => {
									r.tables = e.target.checked ? r.tables.concat( t.name ) : r.tables.filter( ( n ) => n !== t.name );
									changed();
								} } ),
								createElement( 'code', null, t.name ),
								/* translators: 1: approximate number of rows, 2: table size */
								createElement( 'span', { className: 'migrateplus-help' }, sprintf( __( '~%1$s rows, %2$s', 'migrateplus' ), t.rows.toLocaleString(), formatBytes( t.size ) ) ) );
						} ) )
					)
				),
				createElement( 'footer', { className: 'migrateplus-panel-foot' },
					createElement( 'span', { className: 'migrateplus-help' }, __( 'Real runs keep the original values so they can be rolled back. A database backup is still the safest net.', 'migrateplus' ) ),
					createElement( 'button', { type: 'submit', className: 'button button-primary migrateplus-btn-lg' }, r.dry_run ? __( 'Run dry run', 'migrateplus' ) : __( 'Replace', 'migrateplus' ) ) )
			),
			r.result ? createElement( ReplaceResult, { d: r.result } ) : null,
			createElement( ReplaceRuns )
		);
	}

	function loadReplaceRuns() {
		state.replaceRuns = null;
		state.replaceRunsError = '';
		render();
		api( 'replace_history', {} ).then( ( d ) => {
			state.replaceRuns = d.runs;
			render();
		} ).catch( ( err ) => {
			state.replaceRunsError = err.message;
			render();
		} );
	}

	const REPLACE_STATUS_LABELS = { running: __( 'Running', 'migrateplus' ), complete: __( 'Complete', 'migrateplus' ), stopped: __( 'Stopped', 'migrateplus' ), failed: __( 'Failed', 'migrateplus' ), rolling_back: __( 'Rolling back', 'migrateplus' ), rollback_failed: __( 'Rollback incomplete', 'migrateplus' ), rolled_back: __( 'Rolled back', 'migrateplus' ) };

	/** Real runs, with rollback. Dry runs change nothing, so they are not listed. */
	function ReplaceRuns() {
		const className = 'migrateplus-panel migrateplus-history-panel migrateplus-replace-runs';
		if ( state.replaceRunsError ) {
			return createElement( 'section', { className }, createElement( Notice, { type: 'error', message: state.replaceRunsError } ) );
		}
		const runs = state.replaceRuns;
		if ( ! runs ) {
			return createElement( 'section', { className }, createElement( 'p', { className: 'migrateplus-muted migrateplus-loading' }, __( 'Loading past replacements…', 'migrateplus' ) ) );
		}
		return createElement( 'section', { className },
			createElement( 'header', { className: 'migrateplus-history-head' },
				createElement( 'h2', { className: 'migrateplus-history-title' }, __( 'Past replacements', 'migrateplus' ) ),
				/* translators: %s: number of runs */
				createElement( 'span', { className: 'migrateplus-muted' }, runs.length ? sprintf( _n( '%s run', '%s runs', runs.length, 'migrateplus' ), runs.length ) : '' ) ),
			runs.length ? createElement( 'div', { className: 'migrateplus-table-scroll' }, createElement( 'table', { className: 'migrateplus-history' },
				createElement( 'thead', null, createElement( 'tr', null, [ __( 'Replacement', 'migrateplus' ), __( 'Status', 'migrateplus' ), __( 'Result', 'migrateplus' ), '' ].map( ( th, i ) => createElement( 'th', { key: i, scope: 'col' }, th ) ) ) ),
				createElement( 'tbody', null, runs.map( ( run ) => createElement( ReplaceRunRow, { key: run.id, run } ) ) )
			) ) : createElement( 'p', { className: 'migrateplus-muted migrateplus-loading' }, __( 'Replacements you run for real show up here, and can be rolled back.', 'migrateplus' ) )
		);
	}

	/** "340 replacements in 120 rows". */
	function replacementsText( changes, rows ) {
		/* translators: %s: number of replacements */
		const what = sprintf( _n( '%s replacement', '%s replacements', changes, 'migrateplus' ), changes.toLocaleString() );
		/* translators: 1: the replacements, 2: number of rows */
		return sprintf( _n( '%1$s in %2$s row', '%1$s in %2$s rows', rows, 'migrateplus' ), what, rows.toLocaleString() );
	}

	function ReplaceRunRow( { run } ) {
		let result = replacementsText( run.changes, run.rows );
		if ( run.status === 'rolled_back' ) {
			/* translators: %s: number of values put back */
			result = sprintf( _n( '%s value restored', '%s values restored', run.restored, 'migrateplus' ), run.restored.toLocaleString() );
			if ( run.kept ) {
				/* translators: 1: values restored, 2: values left as they are */
				result = sprintf( __( '%1$s, %2$s kept because they were edited since', 'migrateplus' ), result, run.kept.toLocaleString() );
			}
		}
		const forget = () => ask( {
			title: __( 'Delete this run?', 'migrateplus' ),
			message: run.status === 'rolled_back' ? __( 'Remove this run from the list?', 'migrateplus' ) : __( 'Remove this run from the list? Its changes stay in the database, but can no longer be rolled back.', 'migrateplus' ),
			label: __( 'Delete', 'migrateplus' ),
			danger: true,
			onConfirm: () => api( 'replace_forget', { run: run.id } ).then( () => {
				state.replaceRuns = state.replaceRuns.filter( ( x ) => x.id !== run.id );
				modal.close();
			} ).catch( ( err ) => modalError( __( 'Could not delete', 'migrateplus' ), err ) ),
		} );
		return createElement( 'tr', null,
			createElement( 'td', null,
				createElement( 'code', { className: 'migrateplus-replace-pair' }, run.search + ' → ' + run.replace ),
				createElement( 'span', { className: 'migrateplus-help' },
					run.user ? run.user + ', ' : '',
					createElement( 'time', { dateTime: new Date( run.created * 1000 ).toISOString(), title: formatDate( run.created ) }, relativeTime( run.created ) ) ) ),
			createElement( 'td', null, createElement( 'span', { className: 'migrateplus-badge migrateplus-badge-' + run.status }, REPLACE_STATUS_LABELS[ run.status ] || run.status ) ),
			createElement( 'td', { className: 'migrateplus-h-result' }, createElement( 'span', { className: 'migrateplus-help' }, result ) ),
			createElement( 'td', null, createElement( 'div', { className: 'migrateplus-row-actions' },
				run.can_resume ? createElement( 'button', { type: 'button', className: 'button button-small button-primary', onClick: () => runReplace( run ) }, __( 'Resume', 'migrateplus' ) ) : null,
				run.can_undo
					? createElement( 'button', { type: 'button', className: 'button button-small migrateplus-danger', onClick: () => rollbackReplace( run ) }, { rolling_back: __( 'Finish rollback', 'migrateplus' ), rollback_failed: __( 'Retry rollback', 'migrateplus' ) }[ run.status ] || __( 'Roll back', 'migrateplus' ) )
					// Say why Roll back is gone rather than drop it silently.
					: ( run.undo_note ? createElement( 'span', { className: 'migrateplus-help migrateplus-undo-note' }, run.undo_note ) : null ),
				createElement( 'button', { type: 'button', className: 'migrateplus-link-danger', title: __( 'Remove from this list. The changes stay, but can no longer be rolled back.', 'migrateplus' ), onClick: forget }, __( 'Delete', 'migrateplus' ) )
			) )
		);
	}

	function rollbackReplace( run, confirmed ) {
		if ( ! confirmed ) {
			ask( {
				title: __( 'Roll back this replacement?', 'migrateplus' ),
				/* translators: 1: the text that was searched for, 2: what it was replaced with */
				message: sprintf( __( 'Roll back "%1$s" → "%2$s"? Original values are put back. Anything edited since the replacement is kept as it is.', 'migrateplus' ), run.search, run.replace ),
				label: __( 'Roll back', 'migrateplus' ),
				danger: true,
				onConfirm: () => rollbackReplace( run, true ),
			} );
			return;
		}
		modal.open( {
			kind: 'replace',
			title: __( 'Rolling back', 'migrateplus' ),
			message: __( 'Keep this tab open.', 'migrateplus' ),
			percent: null,
			detail: __( 'Starting…', 'migrateplus' ),
			actions: [],
			dismissible: false,
		} );
		/* translators: %s: number of values put back */
		const restoredText = ( n ) => sprintf( _n( '%s value restored', '%s values restored', n, 'migrateplus' ), n.toLocaleString() );
		const nextBatch = () => api( 'replace_rollback', { run: run.id } ).then( ( d ) => {
			if ( d.status !== 'rolled_back' ) {
				modal.update( {
					percent: d.size ? ( d.undo_offset / d.size ) * 100 : null,
					detail: restoredText( d.restored ),
				} );
				return nextBatch();
			}
			modal.update( {
				title: __( 'Rolled back', 'migrateplus' ),
				message: d.kept
					/* translators: %s: number of values edited after the replacement */
					? sprintf( _n( '%s value was edited after the replacement, so it was left as it is.', '%s values were edited after the replacement, so they were left as they are.', d.kept, 'migrateplus' ), d.kept.toLocaleString() )
					: __( 'Every replaced value is back to what it was.', 'migrateplus' ),
				percent: 100,
				tone: 'success',
				detail: restoredText( d.restored ),
				dismissible: true,
				onDismiss: null,
				actions: [ { label: __( 'Close', 'migrateplus' ), primary: true, onClick: () => modal.close() } ],
			} );
			loadReplaceRuns();
		} );
		nextBatch().catch( ( err ) => {
			modal.update( {
				title: __( 'Rollback stopped', 'migrateplus' ),
				message: __( 'Run it again to continue where it stopped.', 'migrateplus' ),
				tone: 'error',
				note: err.message,
				dismissible: true,
				actions: [ { label: __( 'Close', 'migrateplus' ), primary: true, onClick: () => modal.close() } ],
			} );
			loadReplaceRuns();
		} );
	}

	/** Start a run from the form, or pick up a stopped one (`resumeRun`, from Past replacements). */
	function runReplace( resumeRun, confirmed ) {
		const r = state.replace;
		let question = '';
		if ( resumeRun ) {
			/* translators: 1: the text searched for, 2: what it is replaced with */
			question = sprintf( __( 'Resume replacing "%1$s" with "%2$s"? It picks up where it stopped.', 'migrateplus' ), resumeRun.search, resumeRun.replace );
		} else if ( ! r.dry_run ) {
			/* translators: 1: the text searched for, 2: what it is replaced with, 3: number of tables */
			question = sprintf( _n( 'Replace "%1$s" with "%2$s" in %3$s table? This changes the database. You can roll it back afterwards from Past replacements.', 'Replace "%1$s" with "%2$s" in %3$s tables? This changes the database. You can roll it back afterwards from Past replacements.', r.tables.length, 'migrateplus' ), r.search, r.replace, r.tables.length );
		}
		if ( question && ! confirmed ) {
			ask( {
				title: resumeRun ? __( 'Resume this replacement?', 'migrateplus' ) : __( 'Replace in the database?', 'migrateplus' ),
				message: question,
				label: resumeRun ? __( 'Resume', 'migrateplus' ) : __( 'Replace', 'migrateplus' ),
				onConfirm: () => runReplace( resumeRun, true ),
			} );
			return;
		}
		const args = resumeRun
			? { search: resumeRun.search, replace: resumeRun.replace, dry_run: 0 }
			: { search: r.search, replace: r.replace, tables: r.tables, case_insensitive: r.case_insensitive, whole_urls: r.whole_urls, guids: r.guids, dry_run: r.dry_run };
		const working = {
			title: args.dry_run ? __( 'Dry run', 'migrateplus' ) : __( 'Replacing', 'migrateplus' ),
			message: __( 'Keep this tab open.', 'migrateplus' ),
			tone: '',
			note: '',
			actions: [],
			dismissible: false,
		};
		modal.open( Object.assign( { kind: 'replace', percent: null, detail: __( 'Starting…', 'migrateplus' ) }, working ) );
		// A request that failed can be sent again: the next batch, or the whole run when it never started.
		const showFailure = ( err, retry ) => modal.update( {
			title: __( 'Find and replace stopped', 'migrateplus' ),
			message: '',
			tone: 'error',
			note: err.message,
			dismissible: true,
			actions: ( retry ? [ { label: __( 'Try again', 'migrateplus' ), primary: true, onClick: () => {
				modal.update( working );
				retry();
			} } ] : [] ).concat( { label: __( 'Close', 'migrateplus' ), primary: ! retry, onClick: () => modal.close() } ),
		} );
		const nextBatch = ( id ) => apiWithRetry( 'replace_run', { run: id } ).then( ( d ) => {
			if ( ! d.complete ) {
				modal.update( {
					percent: d.total ? Math.min( 99, ( d.done / d.total ) * 100 ) : null,
					/* translators: 1: table name, 2: rows done, 3: rows in the table, roughly */
					detail: sprintf( __( '%1$s: %2$s of about %3$s rows', 'migrateplus' ), d.table, d.done.toLocaleString(), d.total.toLocaleString() ),
				} );
				return nextBatch( id );
			}
			const sum = d.tables.reduce( ( a, t ) => ( { rows: a.rows + t.rows, changes: a.changes + t.changes } ), { rows: 0, changes: 0 } );
			r.result = Object.assign( d, { args } );
			let title = d.dry_run ? __( 'Dry run finished', 'migrateplus' ) : __( 'Replace finished', 'migrateplus' );
			let message = d.dry_run ? __( 'Nothing was saved. Check the changes below, then turn off Dry run to replace for real.', 'migrateplus' ) : __( 'The database has been updated. You can roll it back from Past replacements.', 'migrateplus' );
			if ( d.error ) {
				title = __( 'Find and replace stopped', 'migrateplus' );
				// A dry run changed nothing, so there is nothing to roll back.
				message = d.dry_run ? d.error : d.error + ' ' + __( 'What was replaced so far can be rolled back.', 'migrateplus' );
			}
			modal.update( {
				title,
				message,
				percent: 100,
				tone: d.error ? 'error' : 'success',
				detail: replacementsText( sum.changes, sum.rows ),
				dismissible: true,
				onDismiss: null,
				actions: [ { label: __( 'See details', 'migrateplus' ), primary: true, onClick: () => {
					modal.close();
					const res = mount.querySelector( '.migrateplus-replace-results' );
					if ( res ) {
						res.scrollIntoView( { behavior: 'smooth' } );
					}
				} } ],
			} );
			if ( ! d.dry_run ) {
				loadReplaceRuns(); // Refresh the list of past runs.
			}
		}, ( err ) => showFailure( err, () => nextBatch( id ) ) );
		const begin = resumeRun
			? Promise.resolve( { id: resumeRun.id } )
			: apiWithRetry( 'replace_start', { args } );
		begin.then( ( d ) => nextBatch( d.id ), ( err ) => showFailure( err, () => runReplace( resumeRun, true ) ) ).catch( ( err ) => showFailure( err ) );
	}

	function ReplaceResult( { d } ) {
		const a = d.args;
		const changed = d.tables.filter( ( t ) => t.changes );
		const skipped = d.tables.filter( ( t ) => t.skipped );
		const unsafe = d.tables.filter( ( t ) => t.unsafe );
		return createElement( 'section', { className: 'migrateplus-panel migrateplus-history-panel migrateplus-replace-results' },
			createElement( 'header', { className: 'migrateplus-history-head' },
				createElement( 'h2', { className: 'migrateplus-history-title' }, d.dry_run ? __( 'Dry run results', 'migrateplus' ) : __( 'Results', 'migrateplus' ) ),
				createElement( 'span', { className: 'migrateplus-muted' }, '"' + a.search + '" → "' + a.replace + '"' ) ),
			createElement( 'div', { className: 'migrateplus-table-scroll' }, createElement( 'table', { className: 'migrateplus-history' },
				createElement( 'thead', null, createElement( 'tr', null, [ __( 'Table', 'migrateplus' ), d.dry_run ? __( 'Rows to change', 'migrateplus' ) : __( 'Rows changed', 'migrateplus' ), __( 'Replacements', 'migrateplus' ), '' ].map( ( th, i ) => createElement( 'th', { key: i, scope: 'col' }, th ) ) ) ),
				createElement( 'tbody', null, changed.length
					? changed.map( ( t ) => createElement( ReplaceTableRows, { key: t.name, t } ) )
					/* translators: %s: the text searched for */
					: createElement( 'tr', null, createElement( 'td', { colSpan: 4, className: 'migrateplus-muted migrateplus-no-match' }, sprintf( __( 'No matches for "%s" in the selected tables.', 'migrateplus' ), a.search ) ) ) )
			) ),
			/* translators: %s: tables and why they were skipped */
			skipped.length ? createElement( 'p', { className: 'migrateplus-help migrateplus-loading' }, sprintf( __( 'Skipped: %s', 'migrateplus' ), skipped.map( ( t ) => t.name + ' (' + t.skipped + ')' ).join( '; ' ) ) ) : null,
			/* translators: %s: tables and how many values were left */
			unsafe.length ? createElement( 'p', { className: 'migrateplus-help migrateplus-loading' }, sprintf( __( 'Values left as they are because they contain objects that can’t be safely edited: %s', 'migrateplus' ), unsafe.map( ( t ) => t.name + ' (' + t.unsafe + ')' ).join( '; ' ) ) ) : null
		);
	}

	/** A table's counts, plus a hidden row with sample changes. */
	function ReplaceTableRows( { t } ) {
		const [ open, setOpen ] = useImmediateState( false );
		return createElement( Fragment, null,
			createElement( 'tr', null,
				createElement( 'td', null, createElement( 'code', null, t.name ) ),
				createElement( 'td', null, t.rows.toLocaleString() ),
				createElement( 'td', null, t.changes.toLocaleString() ),
				createElement( 'td', null, createElement( 'div', { className: 'migrateplus-row-actions' },
					createElement( 'button', { type: 'button', className: 'button button-small', 'aria-expanded': open ? 'true' : 'false', onClick: () => setOpen( ! open ) }, open ? __( 'Hide changes', 'migrateplus' ) : __( 'Show changes', 'migrateplus' ) ) ) )
			),
			createElement( 'tr', { className: 'migrateplus-export-log-row', hidden: ! open }, createElement( 'td', { colSpan: 4, className: 'migrateplus-export-log' },
				createElement( 'ol', { className: 'migrateplus-log migrateplus-replace-samples' }, t.samples.map( ( s, i ) => createElement( 'li', { key: i },
					/* translators: 1: column name, 2: row number */
					createElement( 'span', { className: 'migrateplus-help' }, sprintf( __( '%1$s (row %2$s)', 'migrateplus' ), s.column, s.row ) ),
					createElement( 'del', null, ( s.cut[ 0 ] ? '…' : '' ) + s.before + ( s.cut[ 1 ] ? '…' : '' ) ),
					createElement( 'ins', null, ( s.cut[ 0 ] ? '…' : '' ) + s.after + ( s.cut[ 1 ] ? '…' : '' ) ) ) ) )
			) )
		);
	}

	// History

	function loadHistory() {
		state.historyJobs = null;
		state.historyError = '';
		state.historyExports = null;
		state.exportsError = '';
		render();
		api( 'jobs', {} ).then( ( data ) => {
			state.historyJobs = data.jobs;
			render();
		} ).catch( ( err ) => {
			state.historyError = err.message;
			render();
		} );
		api( 'exports', {} ).then( ( data ) => {
			state.historyExports = data.exports;
			render();
		} ).catch( ( err ) => {
			state.exportsError = err.message;
			render();
		} );
	}

	function History() {
		return createElement( Fragment, null, createElement( ImportsPanel ), createElement( ExportsPanel ) );
	}

	function shownJobs() {
		const q = state.historyQuery.trim().toLowerCase();
		return ( state.historyJobs || [] ).filter( ( job ) => ! q || [ job.settings.file_name, job.label, job.options.post_type, STATUS_LABELS[ job.status ], job.settings.site_url, job.settings.url, job.settings.file_url ]
			.some( ( v ) => v && String( v ).toLowerCase().includes( q ) ) );
	}

	function ImportsPanel() {
		const className = 'migrateplus-panel migrateplus-history-panel';
		const jobs = state.historyJobs;
		if ( ! jobs ) {
			return createElement( 'section', { className, 'aria-busy': 'true' }, state.historyError
				? createElement( Notice, { type: 'error', message: state.historyError } )
				: createElement( 'p', { className: 'migrateplus-muted migrateplus-loading' }, __( 'Loading history…', 'migrateplus' ) ) );
		}
		if ( ! jobs.length ) {
			return createElement( 'section', { className }, createElement( 'div', { className: 'migrateplus-empty' },
				createElement( 'h2', null, __( 'No imports yet', 'migrateplus' ) ),
				createElement( 'p', { className: 'migrateplus-muted' }, __( 'Every import you run shows up here, so you can resume it, run it again or roll it back.', 'migrateplus' ) ),
				createElement( 'a', { className: 'button button-primary', href: D.importUrl }, __( 'Start an import', 'migrateplus' ) ) ) );
		}
		const shown = shownJobs();
		/* translators: %s: number of imports */
		const question = ( n ) => sprintf( _n( 'Remove this import from history?', 'Remove these %s imports from history?', n, 'migrateplus' ), n );
		const pick = bulkSelection( 'job', question, __( 'Imported content is not deleted, but deleted entries can no longer be rolled back.', 'migrateplus' ), shown.map( ( job ) => ( { id: job.id, name: job.settings.file_name || job.label } ) ), ( d ) => {
			const gone = new Set( d.deleted.map( ( e ) => e.id ) );
			state.historyJobs = state.historyJobs.filter( ( j ) => ! gone.has( j.id ) );
		} );
		return createElement( 'section', { className },
			createElement( 'header', { className: 'migrateplus-history-head' },
				createElement( 'input', { type: 'search', id: 'migrateplus-history-search', className: 'migrateplus-search', placeholder: __( 'Search by source, site or status', 'migrateplus' ), 'aria-label': __( 'Search history', 'migrateplus' ), value: state.historyQuery, onChange: ( e ) => {
					state.historyQuery = e.target.value;
					// Rows hidden by the search leave the selection, so nothing out of sight is deleted.
					const ids = new Set( shownJobs().map( ( job ) => job.id ) );
					state.picked.job.forEach( ( id ) => ids.has( id ) || state.picked.job.delete( id ) );
					render();
				} } ),
				pick.button,
				createElement( 'span', { className: 'migrateplus-muted', 'aria-live': 'polite' }, shown.length === jobs.length
					/* translators: %s: number of imports */
					? sprintf( _n( '%s import', '%s imports', jobs.length, 'migrateplus' ), jobs.length )
					/* translators: 1: imports shown, 2: imports there are */
					: sprintf( __( '%1$s of %2$s', 'migrateplus' ), shown.length, jobs.length ) ),
				createElement( 'a', { className: 'button button-primary migrateplus-push', href: D.importUrl }, __( 'Import', 'migrateplus' ) )
			),
			createElement( 'div', { className: 'migrateplus-table-scroll' }, createElement( 'table', { className: 'migrateplus-history' },
				createElement( 'thead', null, createElement( 'tr', null, pick.head, [ __( 'Import', 'migrateplus' ), __( 'Status', 'migrateplus' ), __( 'Result', 'migrateplus' ), '' ].map( ( th, i ) => createElement( 'th', { key: i, scope: 'col' }, th ) ) ) ),
				createElement( 'tbody', null, shown.length
					? shown.map( ( job ) => createElement( HistoryRow, { key: job.id, job, check: pick.cell( job.id, job.settings.file_name || job.label ) } ) )
					/* translators: %s: what was searched for */
					: createElement( 'tr', null, createElement( 'td', { colSpan: 5, className: 'migrateplus-muted migrateplus-no-match' }, sprintf( __( 'No imports match "%s".', 'migrateplus' ), state.historyQuery ) ) ) )
			) )
		);
	}

	const EXPORT_STATUS_LABELS = { running: __( 'Running', 'migrateplus' ), complete: __( 'Complete', 'migrateplus' ), stopped: __( 'Stopped', 'migrateplus' ) };

	function ExportsPanel() {
		const className = 'migrateplus-panel migrateplus-history-panel migrateplus-exports-panel';
		const exports = state.historyExports;
		if ( ! exports ) {
			return createElement( 'section', { className, 'aria-busy': 'true' }, state.exportsError
				? createElement( Notice, { type: 'error', message: state.exportsError } )
				: createElement( 'p', { className: 'migrateplus-muted migrateplus-loading' }, __( 'Loading exports…', 'migrateplus' ) ) );
		}
		/* translators: %s: number of exports */
		const question = ( n ) => sprintf( _n( 'Remove this export from history?', 'Remove these %s exports from history?', n, 'migrateplus' ), n );
		const pick = exports.length ? bulkSelection( 'export', question, __( 'Their files are deleted too, if they are still kept.', 'migrateplus' ), exports.map( ( x ) => ( { id: x.id, name: x.label } ) ), ( d ) => {
			const gone = new Set( d.deleted.map( ( e ) => e.id ) );
			state.historyExports = state.historyExports.filter( ( e ) => ! gone.has( e.id ) );
		} ) : null;
		return createElement( 'section', { className },
			createElement( 'header', { className: 'migrateplus-history-head' },
				createElement( 'h2', { className: 'migrateplus-history-title' }, __( 'Exports', 'migrateplus' ) ),
				/* translators: %s: number of exports */
				createElement( 'span', { className: 'migrateplus-muted' }, exports.length ? sprintf( _n( '%s export', '%s exports', exports.length, 'migrateplus' ), exports.length ) : '' ),
				pick ? pick.button : null,
				createElement( 'a', { className: 'button migrateplus-push', href: D.exportUrl }, __( 'Export', 'migrateplus' ) )
			),
			exports.length ? createElement( 'div', { className: 'migrateplus-table-scroll' }, createElement( 'table', { className: 'migrateplus-history' },
				createElement( 'thead', null, createElement( 'tr', null, pick.head, [ __( 'Export', 'migrateplus' ), __( 'Status', 'migrateplus' ), __( 'Result', 'migrateplus' ), '' ].map( ( th, i ) => createElement( 'th', { key: i, scope: 'col' }, th ) ) ) ),
				createElement( 'tbody', null, exports.map( ( x ) => createElement( ExportRows, { key: x.id, x, check: pick.cell( x.id, x.label ) } ) ) )
			) ) : createElement( 'p', { className: 'migrateplus-muted migrateplus-loading' }, __( 'Exports you run show up here with a log of what went into the file.', 'migrateplus' ) )
		);
	}

	/**
	 * Row checkboxes, a select-all and a "Delete selected" button for one History table.
	 * kind goes to the server with each id; question( n ) and warning make the confirmation.
	 * rows are the { id, name } on screen; only those count as selected.
	 */
	function bulkSelection( kind, question, warning, rows, onDone ) {
		const picked = state.picked[ kind ];
		const ids = rows.filter( ( row ) => picked.has( row.id ) ).map( ( row ) => row.id );
		const n = ids.length;
		const names = new Map( rows.map( ( row ) => [ row.id, row.name ] ) );
		const done = ( d ) => {
			picked.clear();
			onDone( d );
		};
		return {
			button: createElement( 'button', { type: 'button', className: 'button migrateplus-danger', disabled: ! n, onClick: () => confirmBulkDelete( kind, question, warning, ids, names, done ) },
				/* translators: %s: number of History entries ticked */
				n ? sprintf( _n( 'Delete %s entry', 'Delete %s entries', n, 'migrateplus' ), n ) : __( 'Delete selected', 'migrateplus' ) ),
			head: createElement( 'td', { className: 'migrateplus-check-column' }, createElement( SelectAll, {
				checked: rows.length > 0 && n === rows.length,
				indeterminate: n > 0 && n < rows.length,
				disabled: ! rows.length,
				onChange: ( e ) => {
					rows.forEach( ( row ) => ( e.target.checked ? picked.add( row.id ) : picked.delete( row.id ) ) );
					render();
				},
			} ) ),
			cell: ( id, name ) => createElement( 'th', { scope: 'row', className: 'migrateplus-check-column' },
				/* translators: %s: the name of a History entry */
				createElement( 'input', { type: 'checkbox', checked: picked.has( id ), 'aria-label': sprintf( __( 'Select %s', 'migrateplus' ), name ), onChange: ( e ) => {
					if ( e.target.checked ) {
						picked.add( id );
					} else {
						picked.delete( id );
					}
					render();
				} } ) ),
		};
	}

	/** React has no indeterminate prop; it is set on the node after each render. */
	function SelectAll( { checked, indeterminate, disabled, onChange } ) {
		const box = useRef( null );
		useLayoutEffect( () => {
			box.current.indeterminate = indeterminate;
		} );
		return createElement( 'input', { ref: box, type: 'checkbox', 'aria-label': __( 'Select all', 'migrateplus' ), checked, disabled, onChange } );
	}

	/** Ask once, delete the chosen History entries in one request, and say what was deleted and what was skipped. */
	function confirmBulkDelete( kind, question, warning, ids, names, onDone ) {
		const n = ids.length;
		const run = () => {
			api( 'history_delete', { items: ids.map( ( id ) => ( { kind, id } ) ) } ).then( ( d ) => {
				const reasons = {};
				d.skipped.forEach( ( s ) => {
					reasons[ s.reason ] = ( reasons[ s.reason ] || 0 ) + 1;
				} );
				const keys = Object.keys( reasons );
				/* translators: %s: number of entries deleted */
				let summary = sprintf( __( 'Deleted %s.', 'migrateplus' ), d.deleted.length );
				if ( keys.length ) {
					/* translators: a number of skipped entries and why. 1: the number, 2: the reason, e.g. still running */
					const why = 1 === keys.length ? keys[ 0 ] : keys.map( ( r ) => sprintf( __( '%1$s %2$s', 'migrateplus' ), reasons[ r ], r ) ).join( ', ' );
					/* translators: 1: entries deleted, 2: entries skipped, 3: why they were skipped */
					summary = sprintf( __( 'Deleted %1$s, skipped %2$s (%3$s).', 'migrateplus' ), d.deleted.length, d.skipped.length, why );
				}
				onDone( d );
				modal.update( {
					title: summary,
					message: '',
					tone: d.deleted.length ? 'success' : 'error',
					/* translators: 1: the name of a History entry, 2: why it was skipped. Then: %s: those entries, separated by semicolons */
					note: d.skipped.length ? sprintf( __( 'Skipped: %s', 'migrateplus' ), d.skipped.map( ( s ) => sprintf( __( '“%1$s” (%2$s)', 'migrateplus' ), names.get( s.id ) || s.id, s.reason ) ).join( '; ' ) ) : '',
					dismissible: true,
					actions: [ { label: __( 'Close', 'migrateplus' ), primary: true, onClick: () => modal.close() } ],
				} );
			} ).catch( ( err ) => modalError( __( 'Could not delete', 'migrateplus' ), err ) );
		};
		ask( {
			/* translators: %s: number of History entries */
			title: sprintf( _n( 'Delete %s entry?', 'Delete %s entries?', n, 'migrateplus' ), n ),
			message: question( n ) + ' ' + warning,
			label: __( 'Delete', 'migrateplus' ),
			danger: true,
			onConfirm: run,
		} );
	}

	/** A history row for one export, plus a hidden row that holds its log. */
	function ExportRows( { x, check } ) {
		const [ open, setOpen ] = useImmediateState( false );
		const [ log, setLog ] = useImmediateState( null ); // null until opened, then 'Loading…', the log, or why it failed.
		const view = () => {
			setOpen( ! open );
			if ( ! open && null === log ) {
				setLog( __( 'Loading…', 'migrateplus' ) );
				api( 'export_log', { export: x.id } ).then( ( d ) => setLog( d.log ) ).catch( ( err ) => setLog( err.message ) );
			}
		};
		const forget = () => ask( {
			title: __( 'Delete this export?', 'migrateplus' ),
			message: __( 'Remove this export from history?', 'migrateplus' ),
			label: __( 'Delete', 'migrateplus' ),
			danger: true,
			onConfirm: () => api( 'export_forget', { export: x.id } ).then( () => {
				state.historyExports = state.historyExports.filter( ( e ) => e.id !== x.id );
				state.picked.export.delete( x.id ); // The rest of the selection stays.
				modal.close();
			} ).catch( ( err ) => modalError( __( 'Could not delete', 'migrateplus' ), err ) ),
		} );

		/* translators: 1: user name, 2: WP-CLI */
		const who = x.user && 'cli' === x.via ? sprintf( __( '%1$s via %2$s', 'migrateplus' ), x.user, 'WP-CLI' ) : x.user || ( 'cli' === x.via ? 'WP-CLI' : '' );
		/* translators: 1: items written, 2: items to write */
		let result = sprintf( __( '%1$s of %2$s items', 'migrateplus' ), x.done.toLocaleString(), x.total.toLocaleString() );
		if ( x.status === 'complete' ) {
			/* translators: %s: number of items */
			result = sprintf( _n( '%s item', '%s items', x.done, 'migrateplus' ), x.done.toLocaleString() );
			if ( x.size ) {
				/* translators: 1: number of items, 2: file size */
				result = sprintf( __( '%1$s, %2$s', 'migrateplus' ), result, x.size );
			}
			if ( ! x.url ) {
				/* translators: %s: what the export held */
				result = sprintf( __( '%s. File no longer kept', 'migrateplus' ), result );
			}
		}
		return createElement( Fragment, null,
			createElement( 'tr', null,
				check,
				createElement( 'td', { className: 'migrateplus-h-source' },
					createElement( 'span', { className: 'migrateplus-source-icon is-sm migrateplus-icon-json', 'aria-hidden': 'true' } ),
					createElement( 'div', null,
						createElement( 'strong', null, x.label ),
						createElement( 'span', { className: 'migrateplus-help' },
							who ? who + ', ' : '',
							createElement( 'time', { dateTime: new Date( x.created * 1000 ).toISOString(), title: formatDate( x.created ) }, relativeTime( x.created ) ) ) ) ),
				createElement( 'td', null, createElement( 'span', { className: 'migrateplus-badge migrateplus-badge-' + x.status }, EXPORT_STATUS_LABELS[ x.status ] || x.status ) ),
				createElement( 'td', { className: 'migrateplus-h-result' }, createElement( 'span', { className: 'migrateplus-help' }, result ) ),
				createElement( 'td', null, createElement( 'div', { className: 'migrateplus-row-actions' },
					x.url ? createElement( 'a', { className: 'button button-small button-primary', href: safeUrl( x.url ) }, __( 'Download', 'migrateplus' ) ) : null,
					createElement( 'button', { type: 'button', className: 'button button-small migrateplus-view', 'aria-expanded': open ? 'true' : 'false', onClick: view }, open ? __( 'Hide log', 'migrateplus' ) : __( 'View log', 'migrateplus' ) ),
					createElement( 'button', { type: 'button', className: 'migrateplus-link-danger', title: __( 'Remove from history, with its file if it is still kept.', 'migrateplus' ), onClick: forget }, __( 'Delete', 'migrateplus' ) ) ) )
			),
			createElement( 'tr', { className: 'migrateplus-export-log-row', hidden: ! open }, createElement( 'td', { colSpan: 5, className: 'migrateplus-export-log' }, Array.isArray( log )
				? createElement( 'ol', { className: 'migrateplus-log' }, log.map( ( e, i ) => createElement( 'li', { key: i, className: 'migrateplus-log-' + e.l },
					createElement( 'time', { dateTime: new Date( e.t * 1000 ).toISOString() }, new Date( e.t * 1000 ).toLocaleTimeString() ),
					createElement( 'span', null, e.m ) ) ) )
				: log ) )
		);
	}

	function restartJob( job ) {
		const remote = job.settings.file_url || job.settings.url || '';
		modal.open( {
			kind: 'restart',
			title: __( 'Preparing to run again', 'migrateplus' ),
			/* translators: %s: the host the file is downloaded from */
			message: remote ? sprintf( __( 'Fetching a fresh copy from %s…', 'migrateplus' ), hostOf( remote ) ) : __( 'Resetting the job…', 'migrateplus' ),
			percent: null,
			actions: [],
		} );
		api( 'restart', { job: job.id } ).then( () => openJob( job.id, true ) ).catch( ( e ) => {
			modal.update( {
				title: __( 'Could not run again', 'migrateplus' ),
				message: '',
				tone: 'error',
				percent: 100,
				note: e.message,
				dismissible: true,
				actions: [ { label: __( 'Close', 'migrateplus' ), primary: true, onClick: () => modal.close() } ],
			} );
		} );
	}

	function HistoryRow( { job, check } ) {
		const s = job.stats;
		const dry = Number( job.options.dry_run ) === 1;
		const where = job.settings.site_url || job.settings.url || job.settings.file_url || '';
		const types = isMultiType( job ) ? job.settings.migrateplus_post_types.split( ',' ).join( ', ' ) : job.options.post_type;
		const actionButton = ( label, fn, cls ) => createElement( 'button', { type: 'button', className: 'button button-small ' + ( cls || '' ), onClick: fn }, label );
		const forget = () => ask( {
			title: __( 'Delete this import?', 'migrateplus' ),
			message: __( 'Remove this import from history? Imported content is not deleted, but it can no longer be rolled back.', 'migrateplus' ),
			label: __( 'Delete', 'migrateplus' ),
			danger: true,
			onConfirm: () => api( 'delete', { job: job.id } ).then( () => {
				state.historyJobs = ( state.historyJobs || [] ).filter( ( j ) => j.id !== job.id );
				state.picked.job.delete( job.id ); // The rest of the selection stays.
				modal.close();
			} ).catch( ( err ) => modalError( __( 'Could not delete', 'migrateplus' ), err ) ),
		} );
		/* translators: a count of one outcome. 1: the number, 2: the outcome, e.g. created */
		const parts = OUTCOMES.filter( ( [ k ] ) => Number( s[ k ] ) > 0 ).map( ( [ k, label, dryLabel ] ) => sprintf( __( '%1$s %2$s', 'migrateplus' ), Number( s[ k ] ).toLocaleString(), ( dry ? dryLabel : label ).toLowerCase() ) );

		return createElement( 'tr', null,
			check,
			createElement( 'td', { className: 'migrateplus-h-source' },
				createElement( 'span', { className: 'migrateplus-source-icon is-sm migrateplus-icon-' + job.source, 'aria-hidden': 'true' } ),
				createElement( 'div', null,
					createElement( 'strong', null, job.settings.file_name || job.label ),
					createElement( 'span', { className: 'migrateplus-help' },
						/* translators: 1: the site imported from, 2: the post types imported into */
						where ? sprintf( __( '%1$s to %2$s', 'migrateplus' ), hostOf( where ), types ) : sprintf( __( 'To %s', 'migrateplus' ), types ),
						', ',
						createElement( 'time', { dateTime: new Date( job.created * 1000 ).toISOString(), title: formatDate( job.created ) }, relativeTime( job.created ) ) ) ) ),
			createElement( 'td', null,
				createElement( 'span', { className: 'migrateplus-badge migrateplus-badge-' + job.status }, STATUS_LABELS[ job.status ] || job.status ),
				dry ? createElement( 'span', { className: 'migrateplus-badge migrateplus-badge-dry' }, __( 'Dry run', 'migrateplus' ) ) : null ),
			createElement( 'td', { className: 'migrateplus-h-result' },
				createElement( OutcomeBar, { stats: s, total: job.total, size: 'sm', label: __( 'Result', 'migrateplus' ) } ),
				createElement( 'span', { className: 'migrateplus-help' }, parts.length ? parts.join( ', ' ) : __( 'Nothing processed yet', 'migrateplus' ) ) ),
			createElement( 'td', null, createElement( 'div', { className: 'migrateplus-row-actions' },
				[ 'running', 'ready', 'failed' ].includes( job.status ) ? actionButton( __( 'Resume', 'migrateplus' ), () => openJob( job.id, true ), 'button-primary' ) : null,
				actionButton( __( 'View log', 'migrateplus' ), () => openJob( job.id, false ), 'migrateplus-view' ),
				[ 'complete', 'rolled_back', 'failed' ].includes( job.status ) ? actionButton( __( 'Run again', 'migrateplus' ), () => restartJob( job ), 'migrateplus-run-again' ) : null,
				// Also for a running import: History runs none, so it is paused or abandoned. The server refuses while a batch holds the lock.
				! dry && 'rolled_back' !== job.status && importedCount( job ) > 0 ? actionButton( __( 'Roll back', 'migrateplus' ), () => rollback( job.id ), 'migrateplus-rollback' ) : null,
				createElement( 'button', { type: 'button', className: 'migrateplus-link-danger', title: __( 'Remove from history. Imported content is kept.', 'migrateplus' ), onClick: forget }, __( 'Delete', 'migrateplus' ) ) ) )
		);
	}

	function openJob( id, resume ) {
		if ( state.tab !== 'new' ) {
			window.location.href = D.importUrl + '&job=' + encodeURIComponent( id ) + ( resume ? '&resume=1' : '' );
			return;
		}
		api( 'job', { job: id } ).then( ( data ) => {
			// A draft was never started: it goes to the mapping step, and its options have not been through the duplicate check.
			// A draft export has no mapping step: as when it was dropped, the duplicate check and the confirmation come next.
			const draft = 'draft' === data.job.status;
			const draftExport = draft && isExport( data.job );
			resume = resume && ! draft;
			state.job = data.job;
			state.mapping = normalizeMapping( data.job.mapping );
			state.options = Object.assign( {}, D.defaults, data.job.options );
			// PHP sends positions 0, 1, 2… as a list: kept as an object, so a row's override is sent by its position.
			state.options.authors = Object.assign( {}, state.options.authors );
			state.fields = ( data.job.fields || [] ).map( String );
			state.records = data.job.records || [];
			// A job that ran was mapped on purpose: suggestions would add back what was left out. A draft gets them as a fresh file does.
			state.suggested = draft ? {} : { [ state.options.post_type ]: true };
			if ( draftExport ) {
				state.step = 'source';
			} else {
				state.step = draft ? 'map' : 'run';
			}
			state.direct = draftExport;
			state.error = '';
			state.paused = ! resume;
			syncJobUrl();
			if ( draftExport ) {
				render();
				modal.open( { kind: 'confirm', title: __( 'Checking for duplicates', 'migrateplus' ), message: __( 'Looking for items in this file that are already on this site…', 'migrateplus' ), percent: null, actions: [] } );
				checkDuplicates().then( confirmImport ).catch( duplicateCheckFailed );
				return;
			}
			if ( resume && ! [ 'complete', 'rolled_back' ].includes( data.job.status ) ) {
				modal.open( { kind: 'run' } );
			}
			render();
			if ( resume ) {
				runLoop();
			}
		} ).catch( ( err ) => {
			syncJobUrl(); // A job that can't load any more must not stick in the address.
			modalError( __( 'Could not open the import', 'migrateplus' ), err );
		} );
	}

	window.addEventListener( 'beforeunload', ( e ) => {
		// Uploads, restarts, rollbacks, exports and replacements run from their modal, which can't be dismissed until they stop.
		const working = modal.isOpen() && [ 'preview', 'restart', 'rollback', 'export', 'replace' ].includes( modal.kind() ) && ! modal.dismissible();
		if ( ( state.running && ! state.paused ) || working ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	mount.classList.add( 'migrateplus-app' );
	render();
	if ( 'history' === state.tab ) {
		loadHistory();
	} else if ( 'export' === state.tab ) {
		recount();
	} else if ( 'replace' === state.tab ) {
		loadReplaceRuns();
	} else if ( D.openJob ) {
		openJob( D.openJob, D.resume ); // Its answer syncs the address, so a reload reopens the job but never resumes it.
	}
}() );
