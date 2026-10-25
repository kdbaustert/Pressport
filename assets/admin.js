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
		suggested: {},
		running: false,
		paused: false,
		results: [],
		error: '',
		sourceError: '', // A failed preview, shown above the source form as it was typed.
		busy: false,
		fileMode: {}, // 'source:fieldKey' => 'upload' | 'url'
		modalHidden: false, // user chose to watch the import in the page instead of the modal
		runClock: null, // { t, processed } when the current run started, for the ETA
		logFilter: 'all',
		logQuery: '',
		review: null,
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
		exportCount: { text: 'Counting…', warn: false, empty: false },
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
			throw new Error( 'Unexpected server response (HTTP ' + status + '). ' + String( text ).replace( /<[^>]+>/g, ' ' ).trim().slice( 0, 300 ) );
		}
		if ( ! json.success ) {
			const err = new Error( ( json.data && json.data.message ) || 'Request failed.' );
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
				const err = new Error( e && 'AbortError' === e.name ? 'The server took too long to respond.' : 'Network error. Check your connection and try again.' );
				err.network = true;
				throw err;
			} )
			.finally( () => clearTimeout( timer ) );
	}

	/**
	 * Same as api(), but uses XHR so upload progress can be reported.
	 * onUpload( loaded, total ) fires while the request body is sent; onSent() once it is fully uploaded.
	 */
	function apiWithProgress( action, data, onUpload, onSent ) {
		return new Promise( ( resolve, reject ) => {
			const xhr = new XMLHttpRequest();
			xhr.open( 'POST', D.ajaxUrl );
			xhr.withCredentials = true;
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
			xhr.addEventListener( 'error', () => reject( new Error( 'Network error. Check your connection and try again.' ) ) );
			xhr.addEventListener( 'timeout', () => reject( new Error( 'The server took too long to respond.' ) ) );
			xhr.send( apiBody( action, data ) );
		} );
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
			return seconds + 's';
		}
		const m = Math.floor( seconds / 60 );
		if ( m < 60 ) {
			return m + 'm ' + String( seconds % 60 ).padStart( 2, '0' ) + 's';
		}
		return Math.floor( m / 60 ) + 'h ' + String( m % 60 ).padStart( 2, '0' ) + 'm';
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
	 * modal.open( { title, message, percent, detail, stats, actions, tone, icon, dismissible } )
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
	const FOCUSABLE = 'button:not(:disabled):not([hidden]), a[href]:not([hidden])';

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

		// Focus the default button when the dialog opens and when its buttons change, not on every tick.
		useLayoutEffect( () => {
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
				// Keep focus inside the dialog.
				const focusables = dialog.current.querySelectorAll( FOCUSABLE );
				if ( ! focusables.length ) {
					e.preventDefault();
					return;
				}
				const first = focusables[ 0 ];
				const last = focusables[ focusables.length - 1 ];
				if ( e.shiftKey && document.activeElement === first ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && document.activeElement === last ) {
					e.preventDefault();
					first.focus();
				}
			}
		};

		const barClass = 'migrateplus-progress migrateplus-modal-bar' +
			( indeterminate ? ' is-indeterminate' : '' ) +
			( 'error' === o.tone ? ' is-error' : '' ) +
			( 'paused' === o.tone ? ' is-paused' : '' ) +
			( 'success' === o.tone ? ' is-success' : '' ) +
			( running ? ' is-running' : '' );

		return createPortal( createElement( 'div', { className: 'migrateplus-modal-overlay', onKeyDown },
			createElement( 'div', { ref: dialog, className: 'migrateplus-modal', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'migrateplus-modal-title', 'aria-describedby': 'migrateplus-modal-message', 'data-status': status, tabIndex: -1 },
				createElement( 'header', { className: 'migrateplus-modal-head' },
					createElement( 'span', { className: 'migrateplus-modal-icon', 'aria-hidden': 'true' }, createElement( 'span', { className: 'dashicons ' + ( ICONS[ status ] || '' ) } ) ),
					createElement( 'div', { className: 'migrateplus-modal-heading' },
						// Announced when it changes, e.g. to "Import complete"; the message changes every batch, so it is not.
						createElement( 'h2', { className: 'migrateplus-modal-title', id: 'migrateplus-modal-title', 'aria-live': 'polite' }, o.title || '' ),
						createElement( 'p', { className: 'migrateplus-modal-message', id: 'migrateplus-modal-message', hidden: ! o.message }, o.message || '' )
					),
					createElement( 'button', { type: 'button', className: 'migrateplus-modal-close', 'aria-label': 'Close', title: 'Close', hidden: ! o.dismissible, onClick: dismiss },
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
				createElement( 'p', { className: 'migrateplus-modal-note' + ( 'error' === o.tone ? ' is-error' : '' ), 'aria-live': 'polite', hidden: ! o.note }, o.note || '' ),
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

	/**
	 * Suggest ACF and taxonomy mappings for the current post type, once per post type.
	 * Matches acf.<name>, meta.<name>, <name> and the field label (case-insensitive).
	 */
	function suggestExtras() {
		const pt = state.options.post_type;
		if ( state.suggested[ pt ] ) {
			return;
		}
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
				// Don't also write it as a plain custom field.
				m.meta = m.meta.filter( ( row ) => row.source !== hit );
			}
		} );
		// Custom fields from MigratePlus / WordPress exports ("meta.<key>"), skipping private keys.
		const acfNames = new Set( acfFields( pt ).map( ( f ) => f.name ) );
		state.fields.forEach( ( f ) => {
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
				return isList ? rows.size + ' item' + ( rows.size === 1 ? '' : 's' ) + ': ' + r[ keys[ 0 ] ] : keys.length + ' values: ' + r[ keys[ 0 ] ];
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
			'aria-label': label || 'Source field',
			value: value || '',
			onChange: ( e ) => onChange( e.target.value ),
			onFocus: filled ? null : () => setFilled( true ),
		},
			createElement( 'option', { value: '' }, '(not mapped)' ),
			filled ? state.fields.map( ( f ) => createElement( 'option', { key: f, value: f }, f ) ) : null,
			filled && parents.length ? createElement( 'optgroup', { label: 'Nested data (lists and objects)' }, parents.map( ( f ) => createElement( 'option', { key: f, value: f }, f + ' (all)' ) ) ) : null,
			// Until then only the mapped field, so the select still shows it.
			! filled && known ? createElement( 'option', { value }, isParent ? value + ' (all)' : value ) : null,
			value && ! known ? createElement( 'option', { value }, value + ' (not in preview)' ) : null
		);
	}

	function Notice( { type, message } ) {
		return createElement( 'div', { className: 'notice notice-' + type + ' inline migrateplus-notice' }, createElement( 'p', null, message ) );
	}

	/**
	 * A confirmation in the plugin's modal, in place of window.confirm. The modal does not block,
	 * so whatever follows the answer goes in onConfirm. Its button is disabled once clicked so the
	 * request can't be sent twice; onConfirm then closes the modal or updates it.
	 */
	function ask( { title, message, label, danger, onConfirm } ) {
		modal.open( {
			kind: 'confirm',
			// The spinner is for work in progress; until the user answers, show what is being asked.
			icon: danger ? 'danger' : 'question',
			bar: false,
			dismissible: true,
			title,
			message,
			actions: [
				{ label, primary: true, danger, onClick: () => {
					// No icon of its own: the tone's shows, the spinner now and the result's once onConfirm updates the modal.
					modal.update( { icon: null, dismissible: false, actions: [ { label: label + '…', primary: true, danger, disabled: true } ] } );
					onConfirm();
				} },
				{ label: 'Cancel', onClick: () => modal.close() },
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
			actions: [ { label: 'Close', primary: true, onClick: () => modal.close() } ],
		} );
	}

	function formatDate( ts ) {
		return new Date( ts * 1000 ).toLocaleString();
	}

	function relativeTime( ts ) {
		const diff = Date.now() / 1000 - ts;
		if ( diff < 60 ) {
			return 'just now';
		}
		if ( diff < 3600 ) {
			return Math.floor( diff / 60 ) + ' min ago';
		}
		if ( diff < 86400 ) {
			const hours = Math.floor( diff / 3600 );
			return hours + ' hour' + ( hours === 1 ? '' : 's' ) + ' ago';
		}
		if ( diff < 86400 * 7 ) {
			const d = Math.floor( diff / 86400 );
			return d === 1 ? 'yesterday' : d + ' days ago';
		}
		return new Date( ts * 1000 ).toLocaleDateString();
	}

	const STATUS_LABELS = {
		draft: 'Not started',
		ready: 'Ready',
		running: 'Running',
		complete: 'Complete',
		failed: 'Failed',
		rolled_back: 'Rolled back',
	};

	const OUTCOMES = [
		[ 'created', 'Created', 'Would create' ],
		[ 'updated', 'Updated', 'Would update' ],
		[ 'skipped', 'Skipped', 'Skipped' ],
		[ 'failed', 'Failed', 'Failed' ],
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
			'aria-label': label || 'Progress',
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

	const SOURCE_BLURBS = {
		json: 'A Migrate Plus export, or any JSON file or API',
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
		const dropScreen = state.step === 'source' && isDropSource();
		let body;
		if ( 'review' === state.step ) {
			body = createElement( Review );
		} else if ( dropScreen ) {
			body = createElement( Drop );
		} else if ( state.step === 'source' ) {
			body = createElement( Source );
		} else if ( state.step === 'map' ) {
			body = createElement( MapStep );
		} else {
			body = createElement( Run );
		}
		return createElement( Fragment, null,
			! dropScreen && ! state.direct && 'review' !== state.step ? createElement( Stepper ) : null,
			state.error ? createElement( Notice, { type: 'error', message: state.error } ) : null,
			body
		);
	}

	function Stepper() {
		const steps = [
			[ 'source', isDropSource() ? 'Upload a file' : 'Choose a source' ],
			[ 'map', 'Map fields' ],
			[ 'run', 'Import' ],
		];
		const idx = steps.findIndex( ( s ) => s[ 0 ] === state.step );
		return createElement( 'ol', { className: 'migrateplus-route', 'aria-label': 'Import steps' }, steps.map( ( s, i ) =>
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
			D.sources.length > 1 ? createElement( 'div', { className: 'migrateplus-tiles', role: 'radiogroup', 'aria-label': 'Source' }, D.sources.map( ( s ) =>
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
					createElement( 'p', { className: 'migrateplus-foot-note' }, 'Nothing is imported yet. You will see a preview and map the fields first.' ),
					createElement( 'button', { type: 'submit', className: 'button button-primary migrateplus-btn-lg', disabled: state.busy }, 'Connect and preview' )
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
				f.required ? createElement( 'span', { className: 'migrateplus-req' }, 'Required' ) : null ),
			input,
			f.help ? createElement( 'p', { className: 'migrateplus-field-help' }, f.help ) : null,
			f.type === 'file' && f.remote === false ? createElement( 'p', { className: 'migrateplus-field-help' }, 'Max upload size: ' + D.maxUpload ) : null
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
				createElement( 'strong', null, 'Drop a JSON file (.json or .json.gz) here to import it' ),
				createElement( 'span', { className: 'migrateplus-muted' }, 'A Migrate Plus export imports straight away. Other JSON files go to field mapping first.' ),
				createElement( 'button', { type: 'button', className: 'button button-primary migrateplus-btn-lg', disabled: state.busy, onClick: () => input.current.click() }, 'Choose a JSON file' )
			),
			createElement( 'input', { ref: input, type: 'file', id: 'migrateplus-import-file', accept: field.accept || '', className: 'migrateplus-drop-input', 'aria-label': 'File to import', onChange: ( e ) => {
				const file = e.target.files && e.target.files[ 0 ];
				e.target.value = ''; // Choosing the same file again still fires change.
				if ( file ) {
					uploadFile( field, file );
				}
			} } ),
			createElement( 'p', { className: 'migrateplus-import-limit' }, 'Maximum upload size: ' + D.maxUpload )
		);
	}

	/** Check the file type, then upload it. */
	function uploadFile( field, file ) {
		const exts = ( field.accept || '' ).split( ',' ).map( ( a ) => a.trim().toLowerCase() ).filter( ( a ) => a.startsWith( '.' ) );
		if ( exts.length && ! exts.some( ( x ) => file.name.toLowerCase().endsWith( x ) ) ) {
			modal.open( {
				kind: 'preview',
				bar: false,
				title: 'This file can’t be imported',
				tone: 'error',
				note: '“' + file.name + '” is not a ' + exts.join( ' or ' ) + ' file. Export one from Migrate Plus → Export on the other site.',
				dismissible: true,
				actions: [ { label: 'Close', primary: true, onClick: () => modal.close() } ],
			} );
			return;
		}
		if ( Number( D.maxUploadBytes ) && file.size > Number( D.maxUploadBytes ) ) {
			modal.open( {
				kind: 'preview',
				bar: false,
				title: 'This file is too large',
				tone: 'error',
				note: '“' + file.name + '” is ' + formatBytes( file.size ) + '. This server accepts uploads up to ' + D.maxUpload + '.' + ( D.zlib && ! /\.gz$/i.test( file.name ) ? ' Export it again with “Compress the file” on: a .json.gz is much smaller.' : '' ),
				dismissible: true,
				actions: [ { label: 'Close', primary: true, onClick: () => modal.close() } ],
			} );
			return;
		}
		const fd = new FormData();
		fd.append( 'source', state.sourceId );
		fd.append( 'file_' + field.key, file );
		requestPreview( fd, { upload: file } );
	}

	/** Whether a previewed job came from a MigratePlus export (it carries its own mapping). */
	function isExport( job ) {
		return !! ( job.settings && ( job.settings.migrateplus_site || job.settings.migrateplus_post_type ) );
	}

	/** Whether a MigratePlus export holds several post types (each record then names its own). */
	function isMultiType( job ) {
		return !! ( job && job.settings && String( job.settings.migrateplus_post_types || '' ).includes( ',' ) );
	}

	/** "Media, Pages and Posts", from a job's sections or a list of labels. */
	function listLabels( labels ) {
		return labels.length > 1 ? labels.slice( 0, -1 ).join( ', ' ) + ' and ' + labels[ labels.length - 1 ] : labels.join( '' );
	}

	/** Progress per part of a MigratePlus export ("Media 12 of 12, Pages 3 of 10, Menus 0 of 2"), when it has more than one. */
	function sectionsText( job ) {
		const list = job.sections || [];
		return list.length > 1 ? list.map( ( x ) => x.label + ' ' + Number( x.done ).toLocaleString() + ' of ' + Number( x.total ).toLocaleString() ).join( ', ' ) : '';
	}

	/** The part being imported now: the first one not done, or the menus once the records are. */
	function currentSection( job ) {
		const list = job.sections || [];
		if ( list.length < 2 || [ 'complete', 'rolled_back' ].includes( job.status ) ) {
			return null;
		}
		return 'menus' === job.stage ? list[ list.length - 1 ] : list.find( ( x ) => Number( x.done ) < Number( x.total ) ) || null;
	}

	/**
	 * Ask before importing a MigratePlus export straight away. With duplicates found
	 * (d from checkDuplicates), the choice is whether to skip them.
	 */
	function confirmImport( d ) {
		const job = state.job;
		const total = Number( job.total ) || 0;
		const from = job.settings.migrateplus_site ? hostOf( job.settings.migrateplus_site ) : '';
		const menus = Number( job.settings.migrateplus_menus ) || 0;
		const parts = ( job.sections || [] ).filter( ( x ) => 'nav_menu' !== x.type ).map( ( x ) => x.label );
		const menuNote = menus && Number( state.options.import_menus ) ? ' Then ' + menus + ( 1 === menus ? ' menu is' : ' menus are' ) + ' rebuilt, linked to the imported content.' : '';
		// An export of menus alone: they link to content already on this site.
		const menusOnly = ! total && menus > 0;
		let intro = ( from ? 'Exported from ' + from + '. ' : '' ) + ( isMultiType( job )
			? listLabels( parts ) + ' are imported in that order, each as its own type, with the field mapping saved in the file.'
			: 'Everything is imported as ' + postType( state.options.post_type ).label + ', with the field mapping saved in the file.' ) + menuNote;
		if ( menusOnly ) {
			intro = ( from ? 'Exported from ' + from + '. ' : '' ) + 'This file holds navigation menus only. Their items link to the posts, pages and terms already on this site; an item whose target is not here is skipped.' +
				( Number( state.options.import_menus ) ? '' : ' “Import menus” is off in Settings, so nothing would be imported.' );
		}
		// The mapping screen handles one post type; a file with several imports as it is, and menus alone have nothing to map.
		const review = isMultiType( job ) || menusOnly ? [] : [ { label: 'Review mapping', onClick: () => {
			modal.close();
			state.direct = false;
			state.step = 'map';
			render();
		} } ];
		const cancel = { label: 'Cancel', onClick: () => modal.close() };
		const base = {
			kind: 'confirm',
			icon: 'file',
			bar: false,
			title: menusOnly ? 'Import ' + menus + ( 1 === menus ? ' menu' : ' menus' ) + '?' : 'Import ' + total.toLocaleString() + ' ' + ( 1 === total ? 'item' : 'items' ) + '?',
			dismissible: true,
		};
		if ( d && d.count ) {
			modal.open( Object.assign( base, duplicateSummary( d ), {
				message: intro + ' ' + duplicateMessage( d ) + changedMessage( d ),
				actions: duplicateActions( d, () => start( false, true ), () => confirmImport( d ) ).concat( review, [ cancel ] ),
			} ) );
			return;
		}
		modal.open( Object.assign( base, {
			message: intro + ' You can roll the import back from History.',
			actions: [ { label: 'Import', primary: true, onClick: () => start( false, true ) } ].concat( review, [ cancel ] ),
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

	/** "12 of them are already on this site…"; `of` names what the count is out of. */
	function duplicateMessage( d, of ) {
		const n = Number( d.count );
		return n.toLocaleString() + ' ' + ( of || 'of them' ) + ( 1 === n ? ' is' : ' are' ) + ' already on this site. Skip them to import only the new items, or import everything anyway.';
	}

	/** Count chips and a few examples for the duplicates dialog. */
	function duplicateSummary( d ) {
		const examples = ( d.examples || [] ).map( ( x ) => '“' + x.title + '” (#' + x.post_id + ')' );
		const more = Number( d.count ) - examples.length;
		return {
			stats: [
				{ key: 'created', value: Number( d.total ) - Number( d.count ), label: 'New' },
				{ key: 'updated', value: d.before, label: 'Imported before' },
				{ key: 'updated', value: d.existing, label: 'Same slug, SKU or file' },
			].filter( ( s, i ) => 0 === i || Number( s.value ) ),
			note: examples.length ? 'Already here: ' + examples.join( ', ' ) + ( more > 0 ? ' and ' + more.toLocaleString() + ' more' : '' ) + '.' : '',
		};
	}

	/**
	 * "Review changes" (when some duplicates differ from what is here) / "Skip duplicates" /
	 * "Import anyway". Records imported before keep "Update them" when that was chosen in
	 * the options; otherwise skipping or copying covers both kinds.
	 */
	function duplicateActions( d, go, reopen ) {
		const choose = ( skip, updateIds, replaceIds, copyIds ) => {
			const update = state.options.duplicate === 'update';
			state.options.skip_existing = skip ? 1 : 0;
			state.options.duplicate = update ? 'update' : ( skip ? 'skip' : 'create' );
			state.options.update_ids = updateIds || [];
			state.options.replace_ids = replaceIds || [];
			state.options.copy_ids = copyIds || [];
			go();
		};
		const posts = ( d.changed || [] ).length;
		const images = ( d.images || [] ).length;
		const changed = posts + images;
		const actions = [
			{ label: 'Skip duplicates', primary: ! changed, onClick: () => choose( true ) },
			{ label: 'Import anyway', onClick: () => choose( false ) },
		];
		if ( changed ) {
			const noun = ! posts ? ( 1 === images ? ' image' : ' images' ) : ( ! images ? ( 1 === posts ? ' change' : ' changes' ) : ' items' );
			actions.unshift( { label: 'Review ' + changed + noun, primary: true, onClick: () => {
				modal.close();
				state.review = {
					d,
					selected: new Set(),
					media: new Map(), // attachment id => 'update' | 'replace'; unset means skip.
					back: state.step,
					reopen,
					apply: ( ids, replaceIds, copyIds ) => choose( true, ids, replaceIds, copyIds ),
				};
				state.step = 'review';
				render();
				window.scrollTo( 0, 0 );
			} } );
		}
		return actions;
	}

	function changedMessage( d ) {
		const n = ( d.changed || [] ).length;
		const m = ( d.images || [] ).length;
		return ( n ? ' ' + n.toLocaleString() + ( 1 === n ? ' of them differs' : ' of them differ' ) + ' from what is here: review the changes to choose which to update.' : '' ) +
			( m ? ' ' + m.toLocaleString() + ( 1 === m ? ' is media' : ' are media' ) + ': compare ' + ( 1 === m ? 'it' : 'them' ) + ' with the copy on this site to update the details or replace the file.' : '' );
	}

	/**
	 * Review screen: each duplicate that differs from the post on this site, with what
	 * would change. Only the ticked ones are updated; every other duplicate is skipped.
	 */
	function Review() {
		const r = state.review;
		const items = r.d.changed || [];
		const images = r.d.images || [];
		const n = r.selected.size + r.media.size;
		const setAll = ( on ) => {
			items.forEach( ( it ) => ( on ? r.selected.add( it.post_id ) : r.selected.delete( it.post_id ) ) );
			render();
		};
		const setAllMedia = ( v ) => {
			images.forEach( ( it ) => ( 'skip' === v ? r.media.delete( it.post_id ) : r.media.set( it.post_id, v ) ) );
			render();
		};
		const importSelected = () => {
			const ids = Array.from( r.selected );
			const lists = { update: ids, replace: [], copy: [] };
			r.media.forEach( ( v, id ) => lists[ v ].push( id ) );
			state.step = r.back;
			state.review = null;
			r.apply( ids, lists.replace, lists.copy );
		};

		return createElement( Fragment, null,
			items.length ? createElement( 'section', { className: 'migrateplus-panel migrateplus-review-head' },
				createElement( 'h2', null, 'Review changes' ),
				createElement( 'p', null, items.length + ( 1 === items.length ? ' item in this import is' : ' items in this import are' ) + ' already on this site and differ from it. Tick the ones to update with the imported version. Everything else that is already here is skipped.' ),
				createElement( 'p', { className: 'migrateplus-muted' }, 'Custom fields, terms and images are updated too and are not shown here. WordPress keeps a revision of each post or page that is updated, so you can restore the old version from the editor.' ),
				createElement( 'div', { className: 'migrateplus-review-tools' },
					createElement( 'button', { type: 'button', className: 'button button-small', onClick: () => setAll( true ) }, 'Select all' ),
					createElement( 'button', { type: 'button', className: 'button button-small', onClick: () => setAll( false ) }, 'Select none' ) )
			) : null,
			createElement( 'ol', { className: 'migrateplus-review' }, items.map( ( it, i ) => {
				const id = 'migrateplus-review-' + it.post_id;
				const fields = Object.keys( it.changes ).map( ( k ) => it.changes[ k ] );
				return createElement( 'li', { key: it.post_id, className: 'migrateplus-panel migrateplus-review-item' },
					createElement( 'div', { className: 'migrateplus-review-row' },
						createElement( 'input', { type: 'checkbox', id, value: it.post_id, checked: r.selected.has( it.post_id ), onChange: ( e ) => {
							if ( e.target.checked ) {
								r.selected.add( it.post_id );
							} else {
								r.selected.delete( it.post_id );
							}
							render();
						} } ),
						createElement( 'label', { htmlFor: id },
							createElement( 'strong', null, it.title || '(untitled)' ),
							createElement( 'span', { className: 'migrateplus-muted' }, it.type + ' #' + it.post_id + ' · ' + ( 'before' === it.kind ? 'imported before' : 'same ' + it.label ) + ' · changes: ' + fields.map( ( f ) => f.label.toLowerCase() ).join( ', ' ) ) )
					),
					createElement( 'details', { className: 'migrateplus-review-body', open: i < 3 },
						createElement( 'summary', null, 'Show changes' ),
						createElement( FieldChanges, { fields } ) )
				);
			} ) ),
			images.length ? createElement( 'section', { className: 'migrateplus-panel migrateplus-review-head' },
				createElement( 'h2', null, 'Compare media' ),
				createElement( 'p', null, images.length + ( 1 === images.length ? ' file in this import is' : ' files in this import are' ) + ' already in the Media Library. For each one, choose what to do. Skipped files are left as they are.' ),
				createElement( 'p', { className: 'migrateplus-muted' }, '"Update details" keeps the file on this site and copies the title, caption, description and alt text. "Replace image" also swaps in the imported file, in the same Media Library item, so pages that use it show the new image. A replaced file cannot be rolled back. "Keep both" adds the imported file as a new item and leaves this one alone.' ),
				createElement( 'div', { className: 'migrateplus-review-tools' },
					createElement( 'button', { type: 'button', className: 'button button-small', onClick: () => setAllMedia( 'skip' ) }, 'Skip all' ),
					createElement( 'button', { type: 'button', className: 'button button-small', onClick: () => setAllMedia( 'update' ) }, 'Update all details' ),
					createElement( 'button', { type: 'button', className: 'button button-small', onClick: () => setAllMedia( 'replace' ) }, 'Replace all' ),
					createElement( 'button', { type: 'button', className: 'button button-small', onClick: () => setAllMedia( 'copy' ) }, 'Keep both for all' ) )
			) : null,
			images.length ? createElement( 'ol', { className: 'migrateplus-review' }, images.map( ( it ) => createElement( ReviewMedia, { key: it.post_id, it, r } ) ) ) : null,
			createElement( 'div', { className: 'migrateplus-actionbar' },
				createElement( 'span', { className: 'migrateplus-actionbar-status', 'aria-live': 'polite' }, n + ' of ' + ( items.length + images.length ) + ' selected' ),
				createElement( 'div', { className: 'migrateplus-actionbar-buttons' },
					createElement( 'button', { type: 'button', className: 'button', onClick: () => {
						state.step = r.back;
						state.review = null;
						render();
						r.reopen();
					} }, 'Back' ),
					createElement( 'button', { type: 'button', className: 'button button-primary', onClick: importSelected }, n ? 'Import with ' + n + ' selected' : 'Import without updating' ) )
			)
		);
	}

	/** One media duplicate: both copies side by side, and what to do with it. */
	function ReviewMedia( { it, r } ) {
		const fields = Object.keys( it.changes ).map( ( k ) => it.changes[ k ] );
		const mediaChoice = ( v, label ) => {
			const id = 'migrateplus-media-' + it.post_id + '-' + v;
			return createElement( 'label', { className: 'migrateplus-check-chip', htmlFor: id },
				createElement( 'input', { type: 'radio', id, name: 'migrateplus-media-' + it.post_id, value: v, checked: ( r.media.get( it.post_id ) || 'skip' ) === v, onChange: () => {
					if ( 'skip' === v ) {
						r.media.delete( it.post_id );
					} else {
						r.media.set( it.post_id, v );
					}
					render();
				} } ),
				createElement( 'span', null, label ) );
		};
		const mediaFigure = ( src, href, caption ) => createElement( 'figure', { className: 'migrateplus-media-pick' },
			src && it.is_image
				? createElement( 'a', { href: safeUrl( href ), target: '_blank', rel: 'noopener' }, createElement( 'img', { src: safeUrl( src ), alt: caption + ': ' + ( it.title || 'image' ), loading: 'lazy' } ) )
				: createElement( 'span', { className: 'migrateplus-media-file' }, ( href || '' ).split( '/' ).pop() || '(no preview)' ),
			createElement( 'figcaption', null, caption ) );
		return createElement( 'li', { className: 'migrateplus-panel migrateplus-review-item' },
			createElement( 'div', { className: 'migrateplus-review-row' },
				createElement( 'div', { className: 'migrateplus-review-title' },
					createElement( 'strong', null, it.title || '(untitled)' ),
					createElement( 'span', { className: 'migrateplus-muted' }, 'Media #' + it.post_id + ' · ' + ( 'before' === it.kind ? 'imported before' : 'same ' + it.label ) + ( fields.length ? ' · details differ: ' + fields.map( ( f ) => f.label.toLowerCase() ).join( ', ' ) : ' · same details' ) ) )
			),
			createElement( 'div', { className: 'migrateplus-media-compare' },
				mediaFigure( it.current, it.current_full, 'On this site' ),
				mediaFigure( it.imported_preview || it.imported, it.imported, 'In the import' ) ),
			createElement( 'div', { className: 'migrateplus-chips', role: 'radiogroup', 'aria-label': 'What to do with ' + ( it.title || 'this file' ) },
				mediaChoice( 'skip', 'Skip' ),
				mediaChoice( 'update', 'Update details' ),
				mediaChoice( 'replace', 'Replace image' ),
				mediaChoice( 'copy', 'Keep both' ) ),
			fields.length ? createElement( 'details', { className: 'migrateplus-review-body' }, createElement( 'summary', null, 'Show detail changes' ), createElement( FieldChanges, { fields } ) ) : null
		);
	}

	/** Each changed field: a diff for longer text, "Now / Imported" for short values. */
	function FieldChanges( { fields } ) {
		return createElement( Fragment, null, fields.map( ( f, i ) => createElement( 'div', { key: i, className: 'migrateplus-review-field' },
			createElement( 'h3', null, f.label ),
			f.old.includes( '\n' ) || f.new.includes( '\n' ) || f.old.length + f.new.length > 160
				? createElement( DiffView, { oldText: f.old, newText: f.new } )
				: createElement( 'dl', { className: 'migrateplus-review-swap' },
					createElement( 'dt', null, 'Now' ), createElement( 'dd', { className: 'is-old' }, f.old || '(empty)' ),
					createElement( 'dt', null, 'Imported' ), createElement( 'dd', { className: 'is-new' }, f.new || '(empty)' ) )
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
				out.push( createElement( 'li', { key: 'fold-' + k, className: 'is-fold' }, '⋯ ' + folded + ( 1 === folded ? ' unchanged line' : ' unchanged lines' ) ) );
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
			out.push( createElement( 'li', { key: k, className: kind },
				createElement( 'span', { className: 'migrateplus-diff-sign', 'aria-label': { ' ': 'Unchanged', '-': 'Removed', '+': 'Added' }[ row[ 0 ] ] }, ' ' === row[ 0 ] ? '' : ( '-' === row[ 0 ] ? '−' : '+' ) ),
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
			setChosen( file ? file.name + ' (' + formatBytes( file.size ) + ')' : '' );
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
			onDragLeave: () => setDragging( false ),
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
				createElement( 'strong', null, 'Drop a file here or choose one' ),
				createElement( 'span', null, ( f.accept || '' ).split( ',' ).filter( ( a ) => a.trim().startsWith( '.' ) ).join( ', ' ) + ', up to ' + D.maxUpload ) ),
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
			createElement( 'div', { className: 'migrateplus-segmented', role: 'group', 'aria-label': f.label + ': how to provide it' }, modeButton( 'upload', 'Upload a file' ), modeButton( 'url', 'From a URL' ) ),
			createElement( 'div', { className: 'migrateplus-file-pane', hidden: isUrl }, zone ),
			createElement( 'div', { className: 'migrateplus-file-pane', hidden: ! isUrl },
				createElement( 'input', {
					ref: urlInput,
					id: id + '-url',
					name: 'settings[' + f.key + '_url]',
					type: 'url',
					placeholder: 'https://',
					autoComplete: 'off',
					'aria-label': f.label + ' URL',
					disabled: ! isUrl,
					required: isUrl && !! f.required,
				} ),
				createElement( 'p', { className: 'migrateplus-field-help' }, 'Your server downloads the file (up to ' + D.maxRemote + '). Google Sheets, Google Drive, Dropbox and GitHub share links work too, as long as they are shared publicly.' ) )
		);
	}

	function onPreview( e ) {
		e.preventDefault();
		const fd = new FormData( e.currentTarget );
		fd.append( 'source', state.sourceId );
		const source = D.sources.find( ( s ) => s.id === state.sourceId );

		// Work out what the server is about to do, for the modal copy.
		let upload = null;
		let remote = '';
		source.schema.filter( ( f ) => f.type === 'file' ).forEach( ( f ) => {
			const file = fd.get( 'file_' + f.key );
			if ( file && file.name && file.size ) {
				upload = file;
			}
			const url = fd.get( 'settings[' + f.key + '_url]' );
			if ( url ) {
				remote = String( url );
			}
		} );
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
		const readingMsg = ctx.remote
			? 'Downloading the file from ' + hostOf( ctx.remote ) + ' and reading the first records…'
			: ( ctx.connectTo ? 'Connecting to ' + ctx.connectTo + ' and reading the first records…' : 'Reading the first records…' );

		state.busy = true;
		state.error = '';
		modal.open( {
			kind: 'preview',
			title: upload ? 'Uploading ' + upload.name : ( ctx.remote ? 'Fetching remote file' : 'Connecting to ' + ctx.label ),
			message: upload ? 'Sending the file to your server.' : readingMsg,
			percent: upload ? 0 : null,
			detail: upload ? '0 B of ' + formatBytes( upload.size ) : '',
			actions: [],
		} );

		const req = upload
			? apiWithProgress( 'preview', fd, ( loaded, total ) => {
				modal.update( {
					percent: ( loaded / total ) * 100,
					detail: formatBytes( loaded ) + ' of ' + formatBytes( total ),
				} );
			}, () => {
				modal.update( { title: 'Reading ' + upload.name, message: 'Upload complete. Checking the file and reading the first records…', percent: null, detail: '' } );
			} )
			: api( 'preview', fd );

		req.then( ( data ) => {
			state.job = data.job;
			state.records = data.records;
			state.fields = ( data.fields || [] ).map( String ); // Numeric JSON keys may arrive as numbers.
			state.mapping = normalizeMapping( data.job.mapping );
			state.options = Object.assign( {}, D.defaults, data.job.options );
			state.suggested = {};
			state.busy = false;
			state.savedPick = data.savedMapping || '';
			state.savedNotice = data.savedMapping ? { type: 'info', text: 'This file has the same fields as the saved mapping “' + data.savedMapping + '”, so it is selected below. Choose Apply to use it, or map the fields yourself.' } : null;
			if ( isExport( data.job ) ) {
				state.direct = true;
				render();
				modal.update( { title: 'Checking for duplicates', message: 'Looking for items in this file that are already on this site…', percent: null, detail: '' } );
				checkDuplicates().then( confirmImport ).catch( duplicateCheckFailed );
				return;
			}
			modal.close();
			state.step = 'map';
			render();
		} ).catch( ( err ) => {
			state.busy = false;
			modal.update( {
				title: upload ? 'Could not read the file' : 'Could not connect',
				message: '',
				percent: 100,
				tone: 'error',
				detail: '',
				note: err.message,
				dismissible: true,
				onDismiss: null,
				actions: [ { label: 'Close', primary: true, onClick: () => modal.close() } ],
			} );
			if ( ctx.onError ) {
				ctx.onError( err );
			} else {
				state.error = err.message;
				render();
			}
		} );
	}

	// Step 2

	function MapStep() {
		const job = state.job;
		const source = D.sources.find( ( s ) => s.id === job.source ) || D.sources.find( ( s ) => s.id === state.sourceId ) || { id: job.source, label: job.source };
		const total = job.total === null || job.total === undefined ? null : Number( job.total );
		const cols = state.fields.slice( 0, 40 );
		const mapped = D.targets.filter( ( t ) => state.mapping.fields[ t.key ] ).length;
		const ready = !! ( state.mapping.fields.post_title || state.mapping.fields.post_content );
		const where = job.settings.file_url || job.settings.url || job.settings.site_url;

		return createElement( Fragment, null,
			createElement( 'section', { className: 'migrateplus-summary' },
				createElement( 'span', { className: 'migrateplus-source-icon migrateplus-icon-' + source.id, 'aria-hidden': 'true' } ),
				createElement( 'div', { className: 'migrateplus-summary-text' },
					createElement( 'strong', null, 'Connected to ' + source.label ),
					createElement( 'span', null, ( total === null ? 'Unknown number of' : total.toLocaleString() ) + ' records, ' + state.fields.length + ' fields' + ( where ? ' from ' + hostOf( where ) : '' ) ) ),
				createElement( 'button', { type: 'button', className: 'button', onClick: () => {
					state.step = 'source';
					state.error = '';
					render();
				} }, isDropSource() ? 'Choose another file' : 'Change source' )
			),
			isExport( job ) ? null : createElement( SavedMappings ),
			// No records when a job's cached copy is gone: an empty table would only say "First 0 records".
			state.records.length ? createElement( 'details', { className: 'migrateplus-panel migrateplus-preview-panel', open: true },
				createElement( 'summary', null,
					createElement( 'h2', null, 'Preview' ),
					createElement( 'span', { className: 'migrateplus-muted' }, 'First ' + state.records.length + ' records' + ( state.fields.length > cols.length ? ', ' + cols.length + ' of ' + state.fields.length + ' fields shown' : '' ) ) ),
				createElement( 'div', { className: 'migrateplus-preview-scroll', tabIndex: 0, 'aria-label': 'Preview records' }, createElement( 'table', { className: 'migrateplus-preview' },
					createElement( 'thead', null, createElement( 'tr', null, cols.map( ( c ) => createElement( 'th', { key: c, scope: 'col' }, c ) ) ) ),
					createElement( 'tbody', null, state.records.map( ( r, i ) => createElement( 'tr', { key: i }, cols.map( ( c ) => createElement( 'td', { key: c, title: r[ c ] || '' }, truncate( r[ c ], 80 ) ) ) ) ) )
				) )
			) : null,
			createElement( 'div', { className: 'migrateplus-grid' }, createElement( MappingCard ), createElement( OptionsCard ) ),
			createElement( 'div', { className: 'migrateplus-actionbar' },
				createElement( 'span', { className: 'migrateplus-actionbar-status' + ( ready ? '' : ' is-warn' ), 'aria-live': 'polite' }, ready
					? mapped + ' of ' + D.targets.length + ' fields mapped'
					: 'Map the title or the content to continue' ),
				createElement( 'div', { className: 'migrateplus-actionbar-buttons' },
					createElement( 'button', { type: 'button', className: 'button', disabled: state.busy, title: 'Process everything without saving, to check the mapping', onClick: () => start( true ) }, 'Dry run' ),
					createElement( 'button', { type: 'button', className: 'button button-primary', disabled: state.busy, onClick: () => start( false ) }, 'Start import' ) )
			)
		);
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
				showNotice( 'error', 'Give the mapping a name first.' );
				return;
			}
			const send = () => api( 'mapping_save', { job: state.job.id, name, mapping: mappingToSave(), options: state.options } ).then( ( d ) => {
				refresh( d );
				state.savedName = '';
				showNotice( 'success', 'Saved as “' + d.name + '”. A file with the same fields will offer it.' );
			} ).catch( ( err ) => showNotice( 'error', err.message ) );
			if ( ! list.some( ( m ) => m.name === name ) ) {
				send();
				return;
			}
			ask( {
				title: 'Replace saved mapping?',
				message: 'Replace the saved mapping “' + name + '” with this one?',
				label: 'Replace',
				onConfirm: () => {
					modal.close();
					send();
				},
			} );
		};
		const rename = () => {
			// eslint-disable-next-line no-alert
			const name = window.prompt( 'New name for “' + pick.name + '”:', pick.name );
			if ( ! name || name.trim() === pick.name ) {
				return;
			}
			api( 'mapping_rename', { name: pick.name, new_name: name.trim() } ).then( ( d ) => {
				refresh( d );
				showNotice( 'success', 'Renamed to “' + d.name + '”.' );
			} ).catch( ( err ) => showNotice( 'error', err.message ) );
		};
		const remove = () => ask( {
			title: 'Delete saved mapping?',
			message: 'Delete the saved mapping “' + pick.name + '”? It is deleted for everyone who imports on this site.',
			label: 'Delete',
			danger: true,
			onConfirm: () => {
				modal.close();
				api( 'mapping_delete', { name: pick.name } ).then( ( d ) => {
					refresh( d );
					showNotice( 'success', 'Deleted “' + pick.name + '”.' );
				} ).catch( ( err ) => showNotice( 'error', err.message ) );
			},
		} );
		return createElement( 'section', { className: 'migrateplus-panel migrateplus-saved' },
			createElement( 'h2', null, 'Saved mappings' ),
			createElement( 'p', { className: 'migrateplus-muted' }, 'Save this mapping and the import options to use them again for the next file from the same place. Saved mappings are shared by everyone who imports on this site.' ),
			state.savedNotice ? createElement( Notice, { type: state.savedNotice.type, message: state.savedNotice.text } ) : null,
			createElement( 'div', { className: 'migrateplus-saved-row' },
				createElement( 'select', { id: 'migrateplus-saved-select', 'aria-label': 'Load saved mapping', disabled: ! list.length, value: pick ? pick.name : '', onChange: ( e ) => {
					state.savedPick = e.target.value;
					state.savedNotice = null;
					render();
				} },
					createElement( 'option', { value: '' }, list.length ? 'Choose a saved mapping' : 'No saved mappings yet' ),
					list.map( ( m ) => createElement( 'option', { key: m.name, value: m.name }, m.name ) ) ),
				createElement( 'button', { type: 'button', className: 'button', disabled: ! pick, onClick: () => applySavedMapping( pick ) }, 'Apply' ),
				createElement( 'button', { type: 'button', className: 'button-link', disabled: ! pick, onClick: rename }, 'Rename' ),
				createElement( 'button', { type: 'button', className: 'migrateplus-link-danger', disabled: ! pick, onClick: remove }, 'Delete' )
			),
			createElement( 'div', { className: 'migrateplus-saved-row' },
				createElement( 'input', { type: 'text', id: 'migrateplus-saved-name', 'aria-label': 'Name for this mapping', placeholder: pick ? pick.name : 'Name, for example Books feed', value: state.savedName, maxLength: 100, onChange: ( e ) => {
					state.savedName = e.target.value;
					render();
				} } ),
				createElement( 'button', { type: 'button', className: 'button', onClick: save }, 'Save mapping' )
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
		const options = Object.assign( {}, entry.options );
		const missingType = options.post_type && ! D.postTypes.some( ( p ) => p.name === options.post_type ) ? options.post_type : '';
		if ( missingType ) {
			delete options.post_type;
		}
		state.mapping = m;
		state.options = Object.assign( {}, state.options, options );
		// Suggestions would add fields to a mapping chosen on purpose.
		state.suggested = { [ state.options.post_type ]: true };
		const notes = [ 'Applied “' + entry.name + '”.' ];
		if ( dropped.size ) {
			notes.push( 'Not in this file, so left unmapped: ' + Array.from( dropped ).join( ', ' ) + '.' );
		}
		if ( missingType ) {
			notes.push( 'Its post type “' + missingType + '” does not exist on this site; “Import as” was left as it was.' );
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
				createElement( 'h2', null, 'Field mapping' ),
				createElement( 'p', { className: 'migrateplus-muted' }, 'Pick which source field fills each WordPress field. Suggestions are already filled in.' ) ),
			createElement( 'table', { className: 'migrateplus-map-table' },
				createElement( 'thead', null, createElement( 'tr', null,
					createElement( 'th', { scope: 'col' }, 'WordPress field' ),
					createElement( 'th', { 'aria-hidden': 'true' } ),
					createElement( 'th', { scope: 'col' }, 'Source field' ),
					createElement( 'th', { scope: 'col' }, 'Sample value' ) ) ),
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
				createElement( 'h3', null, 'Custom fields' ),
				m.meta.length ? createElement( 'div', { className: 'migrateplus-pairs' }, m.meta.map( ( row, i ) => createElement( 'div', { key: i, className: 'migrateplus-pair' },
					createElement( 'input', { type: 'text', className: 'code', placeholder: 'meta_key', 'aria-label': 'Custom field name', value: row.key || '', onChange: ( e ) => {
						row.key = e.target.value;
						render();
					} } ),
					createElement( 'span', { className: 'migrateplus-arrow', 'aria-hidden': 'true' }, '←' ),
					createElement( FieldSelect, { value: row.source, onChange: ( v ) => {
						row.source = v;
						render();
					} } ),
					createElement( 'button', { type: 'button', className: 'migrateplus-icon-btn', 'aria-label': 'Remove custom field', title: 'Remove', onClick: remove( m.meta, i ) }, '×' )
				) ) ) : createElement( 'p', { className: 'migrateplus-muted' }, 'Save any source field as post meta. ACF field names work too.' ),
				createElement( 'button', { type: 'button', className: 'button migrateplus-add', onClick: () => {
					m.meta.push( { key: '', source: '' } );
					render();
				} }, 'Add custom field' )
			),
			createElement( 'div', { className: 'migrateplus-subsection' },
				createElement( 'h3', null, 'Other taxonomies' ),
				taxes.length
					? ( m.taxonomies.length ? createElement( 'div', { className: 'migrateplus-pairs' }, m.taxonomies.map( ( row, i ) => createElement( 'div', { key: i, className: 'migrateplus-pair' },
						createElement( 'select', { 'aria-label': 'Taxonomy', value: row.taxonomy || '', onChange: ( e ) => {
							row.taxonomy = e.target.value;
							render();
						} },
							createElement( 'option', { value: '' }, 'Choose taxonomy' ),
							taxes.map( ( t ) => createElement( 'option', { key: t.name, value: t.name }, t.label + ' (' + t.name + ')' ) ) ),
						createElement( 'span', { className: 'migrateplus-arrow', 'aria-hidden': 'true' }, '←' ),
						createElement( FieldSelect, { value: row.source, onChange: ( v ) => {
							row.source = v;
							render();
						} } ),
						createElement( 'button', { type: 'button', className: 'migrateplus-icon-btn', 'aria-label': 'Remove taxonomy', title: 'Remove', onClick: remove( m.taxonomies, i ) }, '×' )
					) ) ) : createElement( 'p', { className: 'migrateplus-muted' }, 'Assign terms in custom taxonomies registered for this post type.' ) )
					: createElement( 'p', { className: 'migrateplus-muted' }, 'This post type has no other taxonomies.' ),
				taxes.length ? createElement( 'button', { type: 'button', className: 'button migrateplus-add', onClick: () => {
					m.taxonomies.push( { taxonomy: '', source: '' } );
					render();
				} }, 'Add taxonomy' ) : null
			)
		);
	}

	const ACF_TYPE_LABELS = {
		text: 'Text', textarea: 'Text area', wysiwyg: 'Editor', number: 'Number', range: 'Range', email: 'Email', url: 'URL', password: 'Password',
		image: 'Image', file: 'File', gallery: 'Gallery', oembed: 'oEmbed', select: 'Select', checkbox: 'Checkbox', radio: 'Radio', button_group: 'Buttons',
		true_false: 'True / false', link: 'Link', post_object: 'Post', page_link: 'Page link', relationship: 'Relationship', taxonomy: 'Taxonomy', user: 'User',
		google_map: 'Map', date_picker: 'Date', date_time_picker: 'Date and time', time_picker: 'Time', color_picker: 'Color',
		group: 'Group', repeater: 'Repeater', flexible_content: 'Flexible content',
	};

	const ACF_HINTS = {
		image: 'Image URL. Downloaded into the Media Library.',
		file: 'File URL. Downloaded into the Media Library.',
		gallery: 'List of image URLs.',
		relationship: 'IDs, slugs or titles. Source IDs from earlier imports work.',
		post_object: 'ID, slug or title. Source IDs from earlier imports work.',
		page_link: 'ID, slug, title or URL.',
		taxonomy: 'Term names or IDs.',
		user: 'Email, login or ID.',
		select: 'Choice values or labels.',
		checkbox: 'Choice values or labels.',
		repeater: 'A list of rows, nested data or JSON.',
		group: 'An object, nested data or JSON.',
		flexible_content: 'Rows with acf_fc_layout, as nested data or JSON.',
		google_map: '"lat, lng" or an object.',
		date_picker: 'Any date format.',
		date_time_picker: 'Any date format.',
	};

	function AcfSection() {
		if ( ! D.acf || ! D.acf.active ) {
			return null;
		}
		const fields = acfFields( state.options.post_type );
		const m = state.mapping;
		const head = createElement( 'div', { className: 'migrateplus-acf-head' },
			createElement( 'h3', null, 'ACF fields' ),
			createElement( 'span', { className: 'migrateplus-badge' }, fields.length + ' field' + ( fields.length === 1 ? '' : 's' ) ) );
		if ( ! fields.length ) {
			return createElement( 'div', { className: 'migrateplus-subsection migrateplus-acf' },
				head,
				createElement( 'p', { className: 'migrateplus-muted' }, 'No ACF field groups are assigned to this post type. Assign one in ACF, or pick another post type under "Import as".' ) );
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
			createElement( 'p', { className: 'migrateplus-muted' }, 'Values are converted for each field type: image URLs are downloaded, related posts and terms are looked up, dates and choices are normalised.' ),
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
		const statuses = [ [ 'publish', 'Published' ], [ 'draft', 'Draft' ], [ 'pending', 'Pending review' ], [ 'private', 'Private' ] ];

		return createElement( 'aside', { className: 'migrateplus-panel migrateplus-options' },
			createElement( 'header', { className: 'migrateplus-section-head' },
				createElement( 'h2', null, 'Import options' ),
				D.settingsUrl ? createElement( 'p', { className: 'migrateplus-muted' }, 'Defaults come from ', createElement( 'a', { href: D.settingsUrl }, 'Settings' ), '.' ) : null ),
			optionRow( 'migrateplus-opt-row-1', 'Import as', createElement( 'select', { id: 'migrateplus-opt-row-1', value: o.post_type, onChange: setOption( 'post_type' ) },
				D.postTypes.map( ( p ) => createElement( 'option', { key: p.name, value: p.name }, p.label + ' (' + p.name + ')' ) ) ), 'Used for every record, unless "Post type" is mapped and names a type this site has.' ),
			optionRow( 'migrateplus-opt-row-2', 'Default status', createElement( 'select', { id: 'migrateplus-opt-row-2', value: o.default_status, onChange: setOption( 'default_status' ) }, choices( statuses ) ), 'Used when a record has no status, or one Migrate Plus does not recognise.' ),
			optionRow( 'migrateplus-opt-row-3', 'Status for every item', createElement( 'select', { id: 'migrateplus-opt-row-3', value: o.force_status || '', onChange: setOption( 'force_status' ) }, choices( [ [ '', 'Keep each record’s status' ] ].concat( statuses ) ) ), 'Overrides the status in the file, also for posts that are updated. Published posts with a future date are scheduled.' ),
			optionRow( 'migrateplus-opt-row-4', 'Default author', createElement( 'select', { id: 'migrateplus-opt-row-4', value: String( o.default_author ), onChange: setOption( 'default_author' ) },
				D.users.map( ( u ) => createElement( 'option', { key: u.id, value: String( u.id ) }, u.name ) ) ) ),
			optionRow( 'migrateplus-opt-row-5', 'Records imported before', createElement( 'select', { id: 'migrateplus-opt-row-5', value: o.duplicate, onChange: setOption( 'duplicate' ) }, choices( [
				[ 'skip', 'Skip them' ], [ 'update', 'Update them' ], [ 'create', 'Create duplicates' ],
			] ) ), 'Matched by the mapped Source ID.' ),
			optionRow( 'migrateplus-opt-row-6', 'Batch size', createElement( 'input', { id: 'migrateplus-opt-row-6', type: 'number', min: 1, max: 100, value: o.batch_size, onChange: setOption( 'batch_size' ) } ), 'Records per request. Lower it if image downloads time out.' ),
			createElement( 'div', { className: 'migrateplus-toggles' },
				toggle( 'import_featured_image', 'Download featured images' ),
				toggle( 'import_content_images', 'Download images in content', 'Copies <img> files into the Media Library and rewrites their URLs.' ),
				toggle( 'create_terms', 'Create missing categories and terms' ),
				toggle( 'create_authors', 'Create missing authors', 'New users get the Author role and a random password.' ),
				toggle( 'rewrite_links', 'Point links to the old site here', 'Migrate Plus exports only. Files in wp-content keep their old URLs unless images in content are downloaded.' ),
				toggle( 'import_comments', 'Import comments', 'Migrate Plus exports that include comments. Importing the file again updates them instead of adding them twice.' ),
				toggle( 'import_menus', 'Import menus', 'Migrate Plus exports that include menus, after all posts. A menu with the same slug is filled rather than copied.' ) )
		);
	}

	/**
	 * Save and run. A real import first looks for records already on this site and
	 * asks what to do with them; `checked` skips that once the user has answered.
	 */
	function start( dry, checked ) {
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
			modal.open( { kind: 'confirm', title: 'Checking for duplicates', message: 'Looking for items in this import that are already on this site…', percent: null, actions: [] } );
			const askAbout = ( d ) => modal.open( Object.assign( {
				kind: 'confirm',
				icon: 'file',
				bar: false,
				dismissible: true,
				title: 'Some items are already here',
				message: duplicateMessage( d, 'of ' + Number( d.total ).toLocaleString() + ' records' ) + changedMessage( d ),
				actions: duplicateActions( d, () => start( false, true ), () => askAbout( d ) ).concat( { label: 'Cancel', onClick: () => modal.close() } ),
			}, duplicateSummary( d ) ) );
			checkDuplicates().then( ( d ) => {
				if ( ! d.count ) {
					start( false, true );
					return;
				}
				askAbout( d );
			} ).catch( duplicateCheckFailed );
			return;
		}
		if ( state.busy ) {
			return; // Already saving: a second click on Import must not start it twice.
		}
		state.busy = true;
		state.error = '';
		modal.update( { actions: [ { label: 'Starting…', primary: true, disabled: true } ] } );
		render();
		const mapping = mappingToSave();
		api( 'save', { job: state.job.id, mapping, options: state.options } ).then( ( data ) => {
			state.job = data.job;
			state.results = [];
			state.step = 'run';
			state.busy = false;
			state.paused = false;
			state.modalHidden = false;
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
			title: 'Could not check for duplicates',
			message: 'Items that are already on this site may be imported again.',
			note: err.message,
			actions: [
				{ label: 'Import anyway', primary: true, onClick: () => start( false, true ) },
				{ label: 'Cancel', onClick: () => modal.close() },
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

		let statusText;
		let tone = '';
		if ( job.status === 'rolled_back' ) {
			statusText = 'Rolled back';
		} else if ( done ) {
			statusText = dry ? 'Dry run complete. Nothing was saved.' : 'Import complete';
			tone = 'success';
		} else if ( job.status === 'draft' ) {
			statusText = 'Not started. Nothing was imported.';
		} else if ( state.error ) {
			statusText = 'Stopped. Progress is saved.';
			tone = 'error';
		} else if ( state.paused ) {
			statusText = state.running ? 'Pausing after this batch…' : 'Paused. Progress is saved.';
			tone = 'paused';
		} else {
			statusText = ( dry ? 'Dry run in progress' : 'Importing' ) + '. Keep this tab open.';
		}

		return createElement( Fragment, null,
			createElement( 'section', { className: 'migrateplus-panel migrateplus-run' + ( tone ? ' is-' + tone : '' ) },
				createElement( 'div', { className: 'migrateplus-run-head' },
					createElement( 'div', { className: 'migrateplus-run-figure', 'aria-hidden': 'true' }, pct === null
						? createElement( 'span', { className: 'migrateplus-run-count' }, processed.toLocaleString() )
						: createElement( Fragment, null, createElement( 'span', { className: 'migrateplus-run-count' }, String( pct ) ), createElement( 'span', { className: 'migrateplus-run-unit' }, '%' ) ) ),
					createElement( 'div', { className: 'migrateplus-run-text' },
						createElement( 'h2', { 'aria-live': 'polite' }, statusText ),
						createElement( 'p', { className: 'migrateplus-muted' }, processed.toLocaleString() + ( total ? ' of ' + total.toLocaleString() : '' ) + ' records processed' + ( dry ? ' (dry run)' : '' ) ),
						sectionsText( job ) ? createElement( 'p', { className: 'migrateplus-muted' }, sectionsText( job ) ) : null )
				),
				createElement( OutcomeBar, { stats: s, total, size: 'lg', indeterminate: ! done && ! total && ! state.paused, tone, label: 'Import progress' } ),
				createElement( OutcomeLegend, { stats: s, dry } ),
				createElement( 'div', { className: 'migrateplus-actions' },
					// One button for Pause and Resume, so focus stays on it when it flips after each batch.
					idle ? null : createElement( 'button', state.paused
						? { type: 'button', id: 'migrateplus-run-toggle', className: 'button button-primary', disabled: state.running, onClick: resume }
						: { type: 'button', id: 'migrateplus-run-toggle', className: 'button', onClick: pause }, state.paused ? 'Resume' : 'Pause' ),
					! idle && ! modal.isOpen() ? createElement( 'button', { type: 'button', className: 'button', onClick: showRunModal }, 'Show progress window' ) : null,
					idle && dry ? createElement( 'button', { type: 'button', className: 'button button-primary', onClick: () => start( false ) }, 'Run the real import' ) : null,
					idle && dry ? createElement( 'button', { type: 'button', className: 'button', onClick: () => {
						state.step = 'map';
						render();
					} }, 'Adjust mapping' ) : null,
					idle && ! dry && job.status === 'complete' ? createElement( 'a', { className: 'button button-primary', href: contentUrl( job ) }, 'View imported content' ) : null,
					idle ? createElement( 'button', { type: 'button', className: 'button', onClick: reset }, 'Import' ) : null,
					idle && ! dry && job.status === 'complete' && importedCount( job ) > 0 ? createElement( 'button', { type: 'button', className: 'button migrateplus-danger migrateplus-push', onClick: () => rollback( job.id ) }, 'Roll back this import' ) : null
				)
			),
			createElement( RunLog, { job } )
		);
	}

	/** The run's log, newest first, with filters and search. */
	function RunLog( { job } ) {
		const counts = { all: 0, error: 0, warning: 0, success: 0 };
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
			className: 'migrateplus-chip' + ( state.logFilter === key ? ' is-active' : '' ),
			'aria-pressed': state.logFilter === key ? 'true' : 'false',
			onClick: () => {
				state.logFilter = key;
				render();
			},
		}, label, createElement( 'span', { className: 'migrateplus-chip-count' }, String( counts[ key ] || 0 ) ) );

		return createElement( 'section', { className: 'migrateplus-panel migrateplus-log-panel' },
			createElement( 'header', { className: 'migrateplus-log-head' },
				createElement( 'h2', null, 'Log' ),
				createElement( 'div', { className: 'migrateplus-chips', role: 'group', 'aria-label': 'Filter log' },
					filterChip( 'all', 'All' ), filterChip( 'success', 'Saved' ), filterChip( 'warning', 'Warnings' ), filterChip( 'error', 'Errors' ) ),
				createElement( 'input', { type: 'search', id: 'migrateplus-log-search', className: 'migrateplus-search', placeholder: 'Search the log', 'aria-label': 'Search the log', value: state.logQuery, onChange: ( e ) => {
					state.logQuery = e.target.value;
					render();
				} } )
			),
			createElement( 'ol', { className: 'migrateplus-log' }, entries.length
				? entries.map( ( e, i ) => createElement( 'li', { key: i, className: 'migrateplus-log-' + e.l },
					createElement( 'time', { dateTime: new Date( e.t * 1000 ).toISOString() }, new Date( e.t * 1000 ).toLocaleTimeString() ),
					createElement( 'span', null, e.m ) ) )
				: createElement( 'li', { className: 'migrateplus-log-empty' }, counts.all ? 'No log lines match.' : 'Waiting for the first batch…' ) )
		);
	}

	/** Where the imported content is listed: the menus screen for an export of menus alone. */
	function contentUrl( job ) {
		const menusOnly = ! Number( job.total ) && ( job.sections || [] ).some( ( x ) => 'nav_menu' === x.type );
		return D.adminUrl + ( menusOnly ? 'nav-menus.php' : 'edit.php?post_type=' + encodeURIComponent( job.options.post_type ) );
	}

	function pause() {
		state.paused = true;
		render();
	}

	function resume() {
		state.paused = false;
		state.error = '';
		render();
		runLoop();
	}

	function hideRunModal() {
		state.modalHidden = true;
		modal.close();
	}

	function showRunModal() {
		state.modalHidden = false;
		modal.open( { kind: 'run' } );
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
		const done = [ 'complete', 'rolled_back' ].includes( job.status );
		const pausing = state.paused && state.running;
		const noun = dry ? 'Dry run' : 'Import';

		let percent = null;
		if ( done ) {
			percent = 100;
		} else if ( total ) {
			percent = Math.min( 100, ( processed / total ) * 100 );
		}

		let detail = processed.toLocaleString() + ( total ? ' of ' + total.toLocaleString() : '' ) + ' records processed';
		const clock = state.runClock;
		if ( ! done && ! state.paused && total && clock && processed > clock.processed ) {
			const rate = ( processed - clock.processed ) / ( ( Date.now() - clock.t ) / 1000 );
			if ( rate > 0 ) {
				detail += ', about ' + formatDuration( ( total - processed ) / rate ) + ' left';
			}
		}

		let title;
		let message;
		let tone = '';
		let note = '';
		let actions;
		if ( done ) {
			title = job.status === 'rolled_back' ? 'Rolled back' : noun + ' complete';
			message = dry ? 'Nothing was saved. Review the numbers below, then run the real import.' : 'All records have been processed.';
			tone = Number( s.failed ) > 0 ? '' : 'success';
			if ( Number( s.failed ) > 0 ) {
				note = s.failed + ' record' + ( Number( s.failed ) === 1 ? '' : 's' ) + ' failed. See the log for details.';
			}
			actions = dry
				? [
					{ label: 'Run the real import', primary: true, onClick: () => start( false ) },
					{ label: 'Adjust mapping', onClick: () => {
						modal.close();
						state.step = 'map';
						render();
					} },
					{ label: 'Close', onClick: () => modal.close() },
				]
				: [
					{ label: 'View imported content', primary: true, href: contentUrl( job ) },
					{ label: 'Close', onClick: () => modal.close() },
				];
		} else if ( state.error ) {
			title = noun + ' stopped';
			message = 'Progress is saved. Fix the problem, then resume to pick up where it stopped.';
			tone = 'error';
			note = state.error.replace( / Fix the problem, then press Resume to pick up where it stopped\.$/, '' );
			actions = [
				{ label: 'Resume', primary: true, onClick: resume },
				{ label: 'Close', onClick: hideRunModal },
			];
		} else if ( pausing ) {
			title = 'Pausing…';
			message = 'Finishing the current batch, then pausing.';
			tone = 'paused';
			actions = [ { label: 'Pausing…', disabled: true }, { label: 'Hide', onClick: hideRunModal } ];
		} else if ( state.paused ) {
			title = noun + ' paused';
			message = 'Progress is saved. Resume any time, or come back later from History.';
			tone = 'paused';
			actions = [
				{ label: 'Resume', primary: true, onClick: resume },
				{ label: 'Close', onClick: hideRunModal },
			];
		} else {
			title = ( dry ? 'Dry run in progress' : 'Importing content' ) + '…';
			const part = currentSection( job );
			message = ( part ? 'Now: ' + part.label.toLowerCase() + ', ' + Number( part.done ).toLocaleString() + ' of ' + Number( part.total ).toLocaleString() + '. ' + sectionsText( job ) + '. ' : '' ) +
				'Keep this tab open. Records are processed in batches of ' + job.options.batch_size + '.';
			const last = ( job.log || [] )[ ( job.log || [] ).length - 1 ];
			note = last ? truncate( last.m, 140 ) : 'Waiting for the first batch…';
			actions = [
				{ label: 'Pause', onClick: pause },
				{ label: 'Hide', onClick: hideRunModal },
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
			stats: [
				[ 'created', dry ? 'Would create' : 'Created' ], [ 'updated', dry ? 'Would update' : 'Updated' ], [ 'skipped', 'Skipped' ], [ 'failed', 'Failed' ],
			].map( ( [ key, label ] ) => ( { key, label, value: s[ key ] } ) ),
			actions,
			dismissible: true,
			onDismiss: done ? () => modal.close() : hideRunModal,
		};
	}

	function runLoop() {
		if ( state.running ) {
			return;
		}
		state.running = true;
		state.runClock = { t: Date.now(), processed: Number( state.job.stats.processed ) || 0 };
		let retries = 0;
		const tick = () => {
			if ( state.paused ) {
				state.running = false;
				render();
				return;
			}
			api( 'run', { job: state.job.id } ).then( ( data ) => {
				retries = 0;
				state.job = data.job;
				if ( state.tab === 'new' && state.step === 'run' ) {
					render();
				}
				if ( data.done ) {
					state.running = false;
					render();
					return;
				}
				tick();
			} ).catch( ( err ) => {
				// Another tab or request holds the batch lock: wait for it.
				if ( 'migrateplus_locked' === err.code ) {
					setTimeout( tick, 3000 );
					return;
				}
				// A dropped connection shouldn't stop a long import: try again after 2, 4 and 8 seconds.
				if ( err.network && retries < 3 ) {
					setTimeout( tick, 2000 * Math.pow( 2, retries++ ) );
					return;
				}
				state.running = false;
				state.paused = true;
				state.error = err.message.replace( /([^.!?])$/, '$1.' ) + ' Fix the problem, then press Resume to pick up where it stopped.';
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
		state.error = '';
		state.paused = false;
		state.direct = false;
		state.savedPick = '';
		state.savedNotice = null;
		if ( state.tab !== 'new' ) {
			window.location.href = D.importUrl;
			return;
		}
		render();
	}

	/** Posts the job created over all its runs (stats only cover the last; jobs saved before created_total fall back to them). */
	function importedCount( job ) {
		return ( Number( job.created_total ) || Number( job.stats.created ) || 0 ) + ( Number( job.comments_total ) || 0 );
	}

	function rollback( jobId, confirmed ) {
		if ( ! confirmed ) {
			ask( {
				title: 'Roll back this import?',
				message: 'Permanently delete every post, media file, comment and menu created by this import? Posts that were only updated are kept, without the comments it added to them.',
				label: 'Roll back',
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
		modal.open( {
			kind: 'rollback',
			title: 'Rolling back import',
			message: 'Deleting the posts and media files this import created. Keep this tab open.',
			percent: null,
			detail: 'Counting items…',
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
					title: 'Rollback complete',
					message: 'The imported content was deleted. Posts that were only updated were kept.',
					percent: 100,
					tone: 'success',
					detail: removed.toLocaleString() + ' item' + ( removed === 1 ? '' : 's' ) + ' deleted',
					dismissible: true,
					onDismiss: null,
					actions: [ { label: 'Close', primary: true, onClick: () => modal.close() } ],
				} );
				refresh();
				return;
			}
			modal.update( {
				percent: total ? ( removed / total ) * 100 : null,
				detail: removed.toLocaleString() + ' of ' + total.toLocaleString() + ' items deleted',
			} );
			return nextBatch();
		} ).catch( ( err ) => {
			modal.update( {
				title: 'Rollback stopped',
				message: 'Some items may already be deleted. You can run the rollback again to finish it.',
				tone: 'error',
				detail: removed ? removed.toLocaleString() + ' item' + ( removed === 1 ? '' : 's' ) + ' deleted' : '',
				note: err.message,
				dismissible: true,
				onDismiss: null,
				actions: [
					{ label: 'Try again', primary: true, onClick: () => {
						modal.update( { title: 'Rolling back import', tone: '', note: '', dismissible: false, actions: [] } );
						nextBatch();
					} },
					{ label: 'Close', onClick: () => {
						modal.close();
						refresh();
					} },
				],
			} );
		} );
		nextBatch();
	}

	// Export

	const EXPORT_STATUSES = [ [ 'publish', 'Published' ], [ 'draft', 'Draft' ], [ 'pending', 'Pending review' ], [ 'private', 'Private' ], [ 'future', 'Scheduled' ] ];

	// One timer for the screen, so changes made close together still send one count.
	let exportCountTimer = null;
	// Counts can come back out of order; only the latest request's answer is shown.
	let exportCountSeq = 0;

	/** Ask the server how much the export would hold, 250 ms after the last change. */
	function recount() {
		clearTimeout( exportCountTimer );
		state.exportCount.text = 'Counting…';
		render();
		exportCountTimer = setTimeout( () => {
			const seq = ++exportCountSeq;
			api( 'export_count', { args: state.exportArgs } ).then( ( d ) => {
				if ( seq !== exportCountSeq ) {
					return;
				}
				const counts = Object.entries( d.counts || {} );
				const each = counts.length > 1 ? ' (' + counts.map( ( [ type, n ] ) => n.toLocaleString() + ' ' + ( 1 === n ? postType( type ).label : postType( type ).plural || postType( type ).label ).toLowerCase() ).join( ', ' ) + ')' : '';
				const menus = d.menus ? ', with ' + d.menus + ( 1 === d.menus ? ' menu' : ' menus' ) : '';
				state.exportCount = {
					text: ! d.count && d.menus
						? 'No content matches: only ' + d.menus + ( 1 === d.menus ? ' menu' : ' menus' ) + ' will be exported'
						: d.count.toLocaleString() + ' ' + ( d.count === 1 && 1 === counts.length ? postType( counts[ 0 ][ 0 ] ).label.toLowerCase() : 'items' ) + each + menus + ' will be exported',
					warn: ! d.count,
					empty: ! d.count && ! d.menus, // Menus alone are worth a file.
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

	function Export() {
		const x = state.exportArgs;
		const setArg = ( k, v ) => {
			x[ k ] = v;
			recount();
		};
		const toggle = ( k, label, help, disabled ) => createElement( Toggle, { id: 'migrateplus-export-' + k, label, help, disabled, checked: ! disabled && !! Number( x[ k ] ), onChange: ( e ) => setArg( k, e.target.checked ? 1 : 0 ) } );
		const acfCount = x.post_types.reduce( ( n, t ) => n + acfFields( t ).length, 0 );
		// Statuses, featured images and the like don't apply to media.
		const isMedia = x.post_types.every( ( t ) => t === 'attachment' );
		const le = state.lastExport;

		return createElement( Fragment, null,
			createElement( 'form', { className: 'migrateplus-panel migrateplus-export', onSubmit: ( e ) => {
				e.preventDefault();
				runExport();
			} },
				createElement( 'div', { className: 'migrateplus-export-grid' },
					createElement( 'section', null,
						createElement( 'h2', null, 'What to export' ),
						createElement( 'fieldset', { className: 'migrateplus-opt' },
							createElement( 'legend', { className: 'migrateplus-opt-label' }, 'Content types' ),
							createElement( 'div', { className: 'migrateplus-chips' }, exportTypes().map( ( p ) => {
								const id = 'migrateplus-export-type-' + p.name;
								return createElement( 'label', { key: p.name, className: 'migrateplus-check-chip', htmlFor: id, title: p.name },
									createElement( 'input', { type: 'checkbox', id, checked: x.post_types.includes( p.name ), onChange: ( e ) => {
										const picked = e.target.checked ? x.post_types.concat( p.name ) : x.post_types.filter( ( t ) => t !== p.name );
										if ( ! picked.length ) {
											render(); // The last one stays ticked.
											return;
										}
										x.post_types = exportTypes().map( ( t ) => t.name ).filter( ( t ) => picked.includes( t ) ); // In the order shown.
										recount();
									} } ),
									createElement( 'span', null, p.label ) );
							} ) ),
							x.post_types.length > 1 ? createElement( 'span', { className: 'migrateplus-help' }, 'One file, imported in one go: media first, so featured images and ACF images reuse the files it brings, then the rest in the order shown.' ) : null
						),
						isMedia ? null : createElement( 'fieldset', { className: 'migrateplus-opt' },
							createElement( 'legend', { className: 'migrateplus-opt-label' }, 'Status' ),
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
							createElement( 'legend', { className: 'migrateplus-opt-label' }, isMedia ? 'Uploaded between' : 'Published between' ),
							createElement( 'div', { className: 'migrateplus-date-range' },
								createElement( 'input', { type: 'date', 'aria-label': 'From', value: x.after, onChange: ( e ) => setArg( 'after', e.target.value ) } ),
								createElement( 'span', { className: 'migrateplus-muted' }, 'and' ),
								createElement( 'input', { type: 'date', 'aria-label': 'To', value: x.before, onChange: ( e ) => setArg( 'before', e.target.value ) } ) ),
							createElement( 'span', { className: 'migrateplus-help' }, 'Leave empty to export everything.' )
						)
					),
					createElement( 'section', null,
						createElement( 'h2', null, 'Include' ),
						createElement( 'div', { className: 'migrateplus-toggles is-flush' },
							toggle( 'include_terms', 'Categories, tags and terms', 'Hierarchy is kept as "Parent > Child".' ),
							isMedia ? null : toggle( 'include_image', 'Featured images', 'Exported as URLs; the importing site downloads them.' ),
							toggle( 'include_meta', 'Custom fields', 'Public post meta. Private keys starting with "_" and keys listed in Settings are left out.' ),
							D.acf && D.acf.active
								? toggle( 'include_acf', 'ACF fields', acfCount ? acfCount + ' field' + ( acfCount === 1 ? '' : 's' ) + ' on this content type. Images become URLs, related posts become references.' : 'No ACF fields on this content type.', ! acfCount )
								: toggle( 'include_acf', 'ACF fields', 'Advanced Custom Fields is not active on this site.', true ),
							D.woo && D.woo.active && x.post_types.includes( 'product' )
								? toggle( 'include_woo', 'WooCommerce product data', 'Prices, stock, attributes, downloads and variations. Images become URLs; related products link up by ID or SKU.' )
								: null,
							toggle( 'include_comments', 'Comments', 'Approved comments and replies, with their public comment fields. Pending, spam and trashed comments are left out.' ),
							D.menus ? toggle( 'include_menus', 'Navigation menus', 'Every menu with its items and theme locations. Items point at posts and terms, so export the content they link to in this file too.' ) : null,
							D.zlib ? toggle( 'gzip', 'Compress the file (.json.gz)', 'Much smaller, for a site whose upload limit is lower than the export. Migrate Plus imports it as it is.' ) : null )
					)
				),
				createElement( 'footer', { className: 'migrateplus-panel-foot' },
					createElement( 'span', { className: 'migrateplus-export-count' + ( state.exportCount.warn ? ' is-warn' : '' ), 'aria-live': 'polite' }, state.exportCount.text ),
					createElement( 'button', { type: 'submit', className: 'button button-primary migrateplus-btn-lg', disabled: state.exportCount.empty }, 'Export' ) )
			),
			le ? createElement( 'section', { className: 'migrateplus-summary' },
				createElement( 'span', { className: 'migrateplus-source-icon migrateplus-icon-json', 'aria-hidden': 'true' } ),
				createElement( 'div', { className: 'migrateplus-summary-text' },
					createElement( 'strong', null, le.filename ),
					createElement( 'span', null, le.done.toLocaleString() + ' items, ' + le.size + '. The download link works for 24 hours.' ) ),
				createElement( 'a', { className: 'button button-primary', href: safeUrl( le.url ) }, 'Download' )
			) : null
		);
	}

	/** The post types the Export screen offers (the server leaves out ACF's settings, orders and the like). */
	function exportTypes() {
		return D.postTypes.filter( ( p ) => false !== p.export );
	}

	function runExport() {
		const x = state.exportArgs;
		modal.open( {
			kind: 'export',
			title: 'Exporting ' + ( 1 === x.post_types.length ? postType( x.post_types[ 0 ] ).label.toLowerCase() + ' ' : '' ) + 'content',
			message: 'Writing a JSON file. Keep this tab open.',
			percent: null,
			detail: 'Counting…',
			actions: [],
			dismissible: false,
		} );
		const showFailure = ( err ) => modal.update( {
			title: 'Export stopped',
			message: '',
			tone: 'error',
			detail: '',
			note: err.message,
			dismissible: true,
			actions: [ { label: 'Close', primary: true, onClick: () => modal.close() } ],
		} );
		const nextBatch = ( id ) => api( 'export_run', { export: id } ).then( ( d ) => {
			if ( d.error ) {
				showFailure( { message: d.error } );
				return;
			}
			if ( d.complete ) {
				state.lastExport = d;
				const extras = [];
				if ( d.acf ) {
					extras.push( d.acf + ' ACF field' + ( d.acf === 1 ? '' : 's' ) );
				}
				if ( d.meta ) {
					extras.push( d.meta + ' custom field' + ( d.meta === 1 ? '' : 's' ) );
				}
				if ( d.menus ) {
					extras.push( d.menus + ' menu' + ( d.menus === 1 ? '' : 's' ) );
				}
				modal.update( {
					title: 'Export ready',
					message: 'Import it on the other site with Migrate Plus → Import. The fields map themselves.',
					percent: 100,
					tone: 'success',
					detail: d.done.toLocaleString() + ' items' + ( extras.length ? ' with ' + extras.join( ' and ' ) : '' ) + ', ' + d.size,
					dismissible: true,
					onDismiss: null,
					actions: [
						{ label: 'Download ' + ( /\.json\.gz$/.test( d.filename ) ? '.json.gz' : 'JSON' ), primary: true, href: d.url },
						{ label: 'Close', onClick: () => modal.close() },
					],
				} );
				return;
			}
			modal.update( {
				percent: d.total ? ( d.done / d.total ) * 100 : null,
				detail: d.done.toLocaleString() + ' of ' + d.total.toLocaleString() + ' items' + ( d.section ? ', now ' + d.section.toLowerCase() : '' ),
			} );
			return nextBatch( id );
		} );
		api( 'export_start', { args: x } ).then( ( d ) => {
			modal.update( { percent: 0, detail: '0 of ' + d.total.toLocaleString() + ' items' } );
			return nextBatch( d.id );
		} ).catch( showFailure );
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
				r.problem = ! r.search ? 'Enter the text to search for.' : ( ! r.tables.length ? 'No database tables are selected. Please select at least one.' : '' );
				render();
				if ( ! r.problem ) {
					runReplace();
				}
			} },
				createElement( 'div', { 'aria-live': 'assertive' }, r.problem ? createElement( Notice, { type: 'error', message: r.problem } ) : null ),
				createElement( 'div', { className: 'migrateplus-export-grid' },
					createElement( 'section', null,
						createElement( 'h2', null, 'Find and replace' ),
						textInput( 'search', 'Search for', 'http://old-site.test' ),
						textInput( 'replace', 'Replace with', 'https://new-site.com' ),
						createElement( 'div', { className: 'migrateplus-toggles is-flush' },
							toggle( 'dry_run', 'Dry run', 'Count and preview the changes without saving anything.' ),
							toggle( 'whole_urls', 'Whole URLs only', 'When you search for a web address or domain, only that exact site matches: http://old.test is not changed inside http://old.test.example.com or http://old.testing.com, and old.test is not changed inside sub.old.test.' ),
							toggle( 'case_insensitive', 'Ignore case', 'Match "Example" and "example" alike.' ),
							toggle( 'guids', 'Replace GUIDs', 'Post GUIDs are permanent IDs that feed readers rely on. Leave this off unless you know you need it.' ) ),
						createElement( 'p', { className: 'migrateplus-help' }, 'Migrate Plus\'s own history and tracking data are left alone, so later imports still recognise content they brought in.' )
					),
					createElement( 'section', null,
						createElement( 'h2', null, 'Tables' ),
						createElement( 'div', { className: 'migrateplus-table-tools' },
							createElement( 'span', { className: 'migrateplus-help' }, r.tables.length + ' of ' + all.length + ' tables selected' ),
							createElement( 'button', { type: 'button', className: 'button-link', onClick: () => selectAll( true ) }, 'All' ),
							createElement( 'button', { type: 'button', className: 'button-link', onClick: () => selectAll( false ) }, 'None' ) ),
						createElement( 'div', { className: 'migrateplus-table-list' }, all.map( ( t ) => {
							const id = 'migrateplus-table-' + t.name;
							return createElement( 'label', { key: t.name, className: 'migrateplus-table-option', htmlFor: id },
								createElement( 'input', { type: 'checkbox', id, value: t.name, checked: r.tables.includes( t.name ), onChange: ( e ) => {
									r.tables = e.target.checked ? r.tables.concat( t.name ) : r.tables.filter( ( n ) => n !== t.name );
									changed();
								} } ),
								createElement( 'code', null, t.name ),
								createElement( 'span', { className: 'migrateplus-help' }, '~' + t.rows.toLocaleString() + ' rows, ' + formatBytes( t.size ) ) );
						} ) )
					)
				),
				createElement( 'footer', { className: 'migrateplus-panel-foot' },
					createElement( 'span', { className: 'migrateplus-help' }, 'Real runs keep the original values so they can be rolled back. A database backup is still the safest net.' ),
					createElement( 'button', { type: 'submit', className: 'button button-primary migrateplus-btn-lg' }, r.dry_run ? 'Run dry run' : 'Replace' ) )
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

	const REPLACE_STATUS_LABELS = { running: 'Running', complete: 'Complete', stopped: 'Stopped', failed: 'Failed', rolling_back: 'Rolling back', rollback_failed: 'Rollback incomplete', rolled_back: 'Rolled back' };

	/** Real runs, with rollback. Dry runs change nothing, so they are not listed. */
	function ReplaceRuns() {
		const className = 'migrateplus-panel migrateplus-history-panel migrateplus-replace-runs';
		if ( state.replaceRunsError ) {
			return createElement( 'section', { className }, createElement( Notice, { type: 'error', message: state.replaceRunsError } ) );
		}
		const runs = state.replaceRuns;
		if ( ! runs ) {
			return createElement( 'section', { className }, createElement( 'p', { className: 'migrateplus-muted migrateplus-loading' }, 'Loading past replacements…' ) );
		}
		return createElement( 'section', { className },
			createElement( 'header', { className: 'migrateplus-history-head' },
				createElement( 'h2', { className: 'migrateplus-history-title' }, 'Past replacements' ),
				createElement( 'span', { className: 'migrateplus-muted' }, runs.length ? runs.length + ' run' + ( runs.length === 1 ? '' : 's' ) : '' ) ),
			runs.length ? createElement( 'div', { className: 'migrateplus-table-scroll' }, createElement( 'table', { className: 'migrateplus-history' },
				createElement( 'thead', null, createElement( 'tr', null, [ 'Replacement', 'Status', 'Result', '' ].map( ( th, i ) => createElement( 'th', { key: i, scope: 'col' }, th ) ) ) ),
				createElement( 'tbody', null, runs.map( ( run ) => createElement( ReplaceRunRow, { key: run.id, run } ) ) )
			) ) : createElement( 'p', { className: 'migrateplus-muted migrateplus-loading' }, 'Replacements you run for real show up here, and can be rolled back.' )
		);
	}

	function ReplaceRunRow( { run } ) {
		let result = run.changes.toLocaleString() + ' replacement' + ( run.changes === 1 ? '' : 's' ) + ' in ' + run.rows.toLocaleString() + ' row' + ( run.rows === 1 ? '' : 's' );
		if ( run.status === 'rolled_back' ) {
			result = run.restored.toLocaleString() + ' value' + ( run.restored === 1 ? '' : 's' ) + ' restored' + ( run.kept ? ', ' + run.kept.toLocaleString() + ' kept because they were edited since' : '' );
		}
		const forget = () => ask( {
			title: 'Delete this run?',
			message: run.status === 'rolled_back' ? 'Remove this run from the list?' : 'Remove this run from the list? Its changes stay in the database, but can no longer be rolled back.',
			label: 'Delete',
			danger: true,
			onConfirm: () => api( 'replace_forget', { run: run.id } ).then( () => {
				state.replaceRuns = state.replaceRuns.filter( ( x ) => x.id !== run.id );
				modal.close();
			} ).catch( ( err ) => modalError( 'Could not delete', err ) ),
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
				run.can_resume ? createElement( 'button', { type: 'button', className: 'button button-small button-primary', onClick: () => runReplace( run ) }, 'Resume' ) : null,
				run.can_undo
					? createElement( 'button', { type: 'button', className: 'button button-small migrateplus-danger', onClick: () => rollbackReplace( run ) }, { rolling_back: 'Finish rollback', rollback_failed: 'Retry rollback' }[ run.status ] || 'Roll back' )
					// Say why Roll back is gone rather than drop it silently.
					: ( run.undo_note ? createElement( 'span', { className: 'migrateplus-help migrateplus-undo-note' }, run.undo_note ) : null ),
				createElement( 'button', { type: 'button', className: 'migrateplus-link-danger', title: 'Remove from this list. The changes stay, but can no longer be rolled back.', onClick: forget }, 'Delete' )
			) )
		);
	}

	function rollbackReplace( run, confirmed ) {
		if ( ! confirmed ) {
			ask( {
				title: 'Roll back this replacement?',
				message: 'Roll back "' + run.search + '" → "' + run.replace + '"? Original values are put back. Anything edited since the replacement is kept as it is.',
				label: 'Roll back',
				danger: true,
				onConfirm: () => rollbackReplace( run, true ),
			} );
			return;
		}
		modal.open( {
			kind: 'replace',
			title: 'Rolling back',
			message: 'Keep this tab open.',
			percent: null,
			detail: 'Starting…',
			actions: [],
			dismissible: false,
		} );
		const nextBatch = () => api( 'replace_rollback', { run: run.id } ).then( ( d ) => {
			if ( d.status !== 'rolled_back' ) {
				modal.update( {
					percent: d.size ? ( d.undo_offset / d.size ) * 100 : null,
					detail: d.restored.toLocaleString() + ' values restored',
				} );
				return nextBatch();
			}
			modal.update( {
				title: 'Rolled back',
				message: d.kept ? d.kept.toLocaleString() + ' value' + ( d.kept === 1 ? ' was' : 's were' ) + ' edited after the replacement, so ' + ( d.kept === 1 ? 'it was' : 'they were' ) + ' left as ' + ( d.kept === 1 ? 'it is' : 'they are' ) + '.' : 'Every replaced value is back to what it was.',
				percent: 100,
				tone: 'success',
				detail: d.restored.toLocaleString() + ' value' + ( d.restored === 1 ? '' : 's' ) + ' restored',
				dismissible: true,
				onDismiss: null,
				actions: [ { label: 'Close', primary: true, onClick: () => modal.close() } ],
			} );
			loadReplaceRuns();
		} );
		nextBatch().catch( ( err ) => {
			modal.update( {
				title: 'Rollback stopped',
				message: 'Run it again to continue where it stopped.',
				tone: 'error',
				note: err.message,
				dismissible: true,
				actions: [ { label: 'Close', primary: true, onClick: () => modal.close() } ],
			} );
			loadReplaceRuns();
		} );
	}

	/** Start a run from the form, or pick up a stopped one (`resumeRun`, from Past replacements). */
	function runReplace( resumeRun, confirmed ) {
		const r = state.replace;
		const question = resumeRun
			? 'Resume replacing "' + resumeRun.search + '" with "' + resumeRun.replace + '"? It picks up where it stopped.'
			: ( r.dry_run ? '' : 'Replace "' + r.search + '" with "' + r.replace + '" in ' + r.tables.length + ' tables? This changes the database. You can roll it back afterwards from Past replacements.' );
		if ( question && ! confirmed ) {
			ask( {
				title: resumeRun ? 'Resume this replacement?' : 'Replace in the database?',
				message: question,
				label: resumeRun ? 'Resume' : 'Replace',
				onConfirm: () => runReplace( resumeRun, true ),
			} );
			return;
		}
		const args = resumeRun
			? { search: resumeRun.search, replace: resumeRun.replace, dry_run: 0 }
			: { search: r.search, replace: r.replace, tables: r.tables, case_insensitive: r.case_insensitive, whole_urls: r.whole_urls, guids: r.guids, dry_run: r.dry_run };
		modal.open( {
			kind: 'replace',
			title: args.dry_run ? 'Dry run' : 'Replacing',
			message: 'Keep this tab open.',
			percent: null,
			detail: 'Starting…',
			actions: [],
			dismissible: false,
		} );
		const showFailure = ( err ) => modal.update( {
			title: 'Find and replace stopped',
			message: '',
			tone: 'error',
			note: err.message,
			dismissible: true,
			actions: [ { label: 'Close', primary: true, onClick: () => modal.close() } ],
		} );
		const nextBatch = ( id ) => api( 'replace_run', { run: id } ).then( ( d ) => {
			if ( ! d.complete ) {
				modal.update( {
					percent: d.total ? Math.min( 99, ( d.done / d.total ) * 100 ) : null,
					detail: d.table + ': ' + d.done.toLocaleString() + ' of about ' + d.total.toLocaleString() + ' rows',
				} );
				return nextBatch( id );
			}
			const sum = d.tables.reduce( ( a, t ) => ( { rows: a.rows + t.rows, changes: a.changes + t.changes } ), { rows: 0, changes: 0 } );
			r.result = Object.assign( d, { args } );
			modal.update( {
				title: d.error ? 'Find and replace stopped' : ( d.dry_run ? 'Dry run finished' : 'Replace finished' ),
				message: d.error ? d.error + ' What was replaced so far can be rolled back.' : ( d.dry_run ? 'Nothing was saved. Check the changes below, then turn off Dry run to replace for real.' : 'The database has been updated. You can roll it back from Past replacements.' ),
				percent: 100,
				tone: d.error ? 'error' : 'success',
				detail: sum.changes.toLocaleString() + ' replacement' + ( sum.changes === 1 ? '' : 's' ) + ' in ' + sum.rows.toLocaleString() + ' row' + ( sum.rows === 1 ? '' : 's' ),
				dismissible: true,
				onDismiss: null,
				actions: [ { label: 'See details', primary: true, onClick: () => {
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
		} );
		( resumeRun ? nextBatch( resumeRun.id ) : api( 'replace_start', { args } ).then( ( d ) => nextBatch( d.id ) ) ).catch( showFailure );
	}

	function ReplaceResult( { d } ) {
		const a = d.args;
		const changed = d.tables.filter( ( t ) => t.changes );
		const skipped = d.tables.filter( ( t ) => t.skipped );
		const unsafe = d.tables.filter( ( t ) => t.unsafe );
		return createElement( 'section', { className: 'migrateplus-panel migrateplus-history-panel migrateplus-replace-results' },
			createElement( 'header', { className: 'migrateplus-history-head' },
				createElement( 'h2', { className: 'migrateplus-history-title' }, d.dry_run ? 'Dry run results' : 'Results' ),
				createElement( 'span', { className: 'migrateplus-muted' }, '"' + a.search + '" → "' + a.replace + '"' ) ),
			createElement( 'div', { className: 'migrateplus-table-scroll' }, createElement( 'table', { className: 'migrateplus-history' },
				createElement( 'thead', null, createElement( 'tr', null, [ 'Table', d.dry_run ? 'Rows to change' : 'Rows changed', 'Replacements', '' ].map( ( th, i ) => createElement( 'th', { key: i, scope: 'col' }, th ) ) ) ),
				createElement( 'tbody', null, changed.length
					? changed.map( ( t ) => createElement( ReplaceTableRows, { key: t.name, t } ) )
					: createElement( 'tr', null, createElement( 'td', { colSpan: 4, className: 'migrateplus-muted migrateplus-no-match' }, 'No matches for "' + a.search + '" in the selected tables.' ) ) )
			) ),
			skipped.length ? createElement( 'p', { className: 'migrateplus-help migrateplus-loading' }, 'Skipped: ' + skipped.map( ( t ) => t.name + ' (' + t.skipped + ')' ).join( '; ' ) ) : null,
			unsafe.length ? createElement( 'p', { className: 'migrateplus-help migrateplus-loading' }, 'Values left as they are because they contain objects that can’t be safely edited: ' + unsafe.map( ( t ) => t.name + ' (' + t.unsafe + ')' ).join( '; ' ) ) : null
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
					createElement( 'button', { type: 'button', className: 'button button-small', 'aria-expanded': open ? 'true' : 'false', onClick: () => setOpen( ! open ) }, open ? 'Hide changes' : 'Show changes' ) ) )
			),
			createElement( 'tr', { className: 'migrateplus-export-log-row', hidden: ! open }, createElement( 'td', { colSpan: 4, className: 'migrateplus-export-log' },
				createElement( 'ol', { className: 'migrateplus-log migrateplus-replace-samples' }, t.samples.map( ( s, i ) => createElement( 'li', { key: i },
					createElement( 'span', { className: 'migrateplus-help' }, s.column + ' (row ' + s.row + ')' ),
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
				: createElement( 'p', { className: 'migrateplus-muted migrateplus-loading' }, 'Loading history…' ) );
		}
		if ( ! jobs.length ) {
			return createElement( 'section', { className }, createElement( 'div', { className: 'migrateplus-empty' },
				createElement( 'h2', null, 'No imports yet' ),
				createElement( 'p', { className: 'migrateplus-muted' }, 'Every import you run shows up here, so you can resume it, run it again or roll it back.' ),
				createElement( 'a', { className: 'button button-primary', href: D.importUrl }, 'Start an import' ) ) );
		}
		const shown = shownJobs();
		const pick = bulkSelection( 'job', 'import', 'Imported content is not deleted, but deleted entries can no longer be rolled back.', shown.map( ( job ) => ( { id: job.id, name: job.settings.file_name || job.label } ) ), ( d ) => {
			const gone = new Set( d.deleted.map( ( e ) => e.id ) );
			state.historyJobs = state.historyJobs.filter( ( j ) => ! gone.has( j.id ) );
		} );
		return createElement( 'section', { className },
			createElement( 'header', { className: 'migrateplus-history-head' },
				createElement( 'input', { type: 'search', id: 'migrateplus-history-search', className: 'migrateplus-search', placeholder: 'Search by source, site or status', 'aria-label': 'Search history', value: state.historyQuery, onChange: ( e ) => {
					state.historyQuery = e.target.value;
					// Rows hidden by the search leave the selection, so nothing out of sight is deleted.
					const ids = new Set( shownJobs().map( ( job ) => job.id ) );
					state.picked.job.forEach( ( id ) => ids.has( id ) || state.picked.job.delete( id ) );
					render();
				} } ),
				pick.button,
				createElement( 'span', { className: 'migrateplus-muted', 'aria-live': 'polite' }, shown.length === jobs.length ? jobs.length + ' import' + ( jobs.length === 1 ? '' : 's' ) : shown.length + ' of ' + jobs.length ),
				createElement( 'a', { className: 'button button-primary migrateplus-push', href: D.importUrl }, 'Import' )
			),
			createElement( 'div', { className: 'migrateplus-table-scroll' }, createElement( 'table', { className: 'migrateplus-history' },
				createElement( 'thead', null, createElement( 'tr', null, pick.head, [ 'Import', 'Status', 'Result', '' ].map( ( th, i ) => createElement( 'th', { key: i, scope: 'col' }, th ) ) ) ),
				createElement( 'tbody', null, shown.length
					? shown.map( ( job ) => createElement( HistoryRow, { key: job.id, job, check: pick.cell( job.id, job.settings.file_name || job.label ) } ) )
					: createElement( 'tr', null, createElement( 'td', { colSpan: 5, className: 'migrateplus-muted migrateplus-no-match' }, 'No imports match "' + state.historyQuery + '".' ) ) )
			) )
		);
	}

	const EXPORT_STATUS_LABELS = { running: 'Running', complete: 'Complete', stopped: 'Stopped' };

	function ExportsPanel() {
		const className = 'migrateplus-panel migrateplus-history-panel migrateplus-exports-panel';
		const exports = state.historyExports;
		if ( ! exports ) {
			return createElement( 'section', { className, 'aria-busy': 'true' }, state.exportsError
				? createElement( Notice, { type: 'error', message: state.exportsError } )
				: createElement( 'p', { className: 'migrateplus-muted migrateplus-loading' }, 'Loading exports…' ) );
		}
		const pick = exports.length ? bulkSelection( 'export', 'export', 'Their files are deleted too, if they are still kept.', exports.map( ( x ) => ( { id: x.id, name: x.label } ) ), ( d ) => {
			const gone = new Set( d.deleted.map( ( e ) => e.id ) );
			state.historyExports = state.historyExports.filter( ( e ) => ! gone.has( e.id ) );
		} ) : null;
		return createElement( 'section', { className },
			createElement( 'header', { className: 'migrateplus-history-head' },
				createElement( 'h2', { className: 'migrateplus-history-title' }, 'Exports' ),
				createElement( 'span', { className: 'migrateplus-muted' }, exports.length ? exports.length + ' export' + ( exports.length === 1 ? '' : 's' ) : '' ),
				pick ? pick.button : null,
				createElement( 'a', { className: 'button migrateplus-push', href: D.exportUrl }, 'Export' )
			),
			exports.length ? createElement( 'div', { className: 'migrateplus-table-scroll' }, createElement( 'table', { className: 'migrateplus-history' },
				createElement( 'thead', null, createElement( 'tr', null, pick.head, [ 'Export', 'Status', 'Result', '' ].map( ( th, i ) => createElement( 'th', { key: i, scope: 'col' }, th ) ) ) ),
				createElement( 'tbody', null, exports.map( ( x ) => createElement( ExportRows, { key: x.id, x, check: pick.cell( x.id, x.label ) } ) ) )
			) ) : createElement( 'p', { className: 'migrateplus-muted migrateplus-loading' }, 'Exports you run show up here with a log of what went into the file.' )
		);
	}

	/**
	 * Row checkboxes, a select-all and a "Delete selected" button for one History table.
	 * kind goes to the server with each id; warning is added to the confirmation.
	 * rows are the { id, name } on screen; only those count as selected.
	 */
	function bulkSelection( kind, noun, warning, rows, onDone ) {
		const picked = state.picked[ kind ];
		const ids = rows.filter( ( row ) => picked.has( row.id ) ).map( ( row ) => row.id );
		const n = ids.length;
		const names = new Map( rows.map( ( row ) => [ row.id, row.name ] ) );
		const done = ( d ) => {
			picked.clear();
			onDone( d );
		};
		return {
			button: createElement( 'button', { type: 'button', className: 'button migrateplus-danger', disabled: ! n, onClick: () => confirmBulkDelete( kind, noun, warning, ids, names, done ) },
				n ? 'Delete ' + n + ' ' + ( n === 1 ? 'entry' : 'entries' ) : 'Delete selected' ),
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
				createElement( 'input', { type: 'checkbox', checked: picked.has( id ), 'aria-label': 'Select ' + name, onChange: ( e ) => {
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
		return createElement( 'input', { ref: box, type: 'checkbox', 'aria-label': 'Select all', checked, disabled, onChange } );
	}

	/** Ask once, delete the chosen History entries in one request, and say what was deleted and what was skipped. */
	function confirmBulkDelete( kind, noun, warning, ids, names, onDone ) {
		const n = ids.length;
		const plural = ( c, word ) => c + ' ' + word + ( c === 1 ? '' : 's' );
		const run = () => {
			api( 'history_delete', { items: ids.map( ( id ) => ( { kind, id } ) ) } ).then( ( d ) => {
				const reasons = {};
				d.skipped.forEach( ( s ) => {
					reasons[ s.reason ] = ( reasons[ s.reason ] || 0 ) + 1;
				} );
				const keys = Object.keys( reasons );
				let summary = 'Deleted ' + d.deleted.length;
				if ( keys.length === 1 ) {
					summary += ', skipped ' + d.skipped.length + ' (' + keys[ 0 ] + ')';
				} else if ( keys.length ) {
					summary += ', skipped ' + d.skipped.length + ' (' + keys.map( ( r ) => reasons[ r ] + ' ' + r ).join( ', ' ) + ')';
				}
				onDone( d );
				modal.update( {
					title: summary + '.',
					message: '',
					tone: d.deleted.length ? 'success' : 'error',
					note: d.skipped.length ? 'Skipped: ' + d.skipped.map( ( s ) => '“' + ( names.get( s.id ) || s.id ) + '” (' + s.reason + ')' ).join( '; ' ) : '',
					dismissible: true,
					actions: [ { label: 'Close', primary: true, onClick: () => modal.close() } ],
				} );
			} ).catch( ( err ) => modalError( 'Could not delete', err ) );
		};
		ask( {
			title: 'Delete ' + n + ' ' + ( n === 1 ? 'entry' : 'entries' ) + '?',
			message: ( n === 1 ? 'Remove this ' + noun : 'Remove these ' + plural( n, noun ) ) + ' from history? ' + warning,
			label: 'Delete',
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
				setLog( 'Loading…' );
				api( 'export_log', { export: x.id } ).then( ( d ) => setLog( d.log ) ).catch( ( err ) => setLog( err.message ) );
			}
		};
		const forget = () => ask( {
			title: 'Delete this export?',
			message: 'Remove this export from history?',
			label: 'Delete',
			danger: true,
			onConfirm: () => api( 'export_forget', { export: x.id } ).then( () => {
				state.historyExports = state.historyExports.filter( ( e ) => e.id !== x.id );
				state.picked.export.clear();
				modal.close();
			} ).catch( ( err ) => modalError( 'Could not delete', err ) ),
		} );

		const who = [ x.user, x.via === 'cli' ? 'WP-CLI' : '' ].filter( Boolean ).join( ' via ' );
		let result = x.done.toLocaleString() + ' of ' + x.total.toLocaleString() + ' items';
		if ( x.status === 'complete' ) {
			result = x.done.toLocaleString() + ' item' + ( x.done === 1 ? '' : 's' ) + ( x.size ? ', ' + x.size : '' ) + ( x.url ? '' : '. File no longer kept' );
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
					x.url ? createElement( 'a', { className: 'button button-small button-primary', href: safeUrl( x.url ) }, 'Download' ) : null,
					createElement( 'button', { type: 'button', className: 'button button-small migrateplus-view', 'aria-expanded': open ? 'true' : 'false', onClick: view }, open ? 'Hide log' : 'View log' ),
					createElement( 'button', { type: 'button', className: 'migrateplus-link-danger', title: 'Remove from history, with its file if it is still kept.', onClick: forget }, 'Delete' ) ) )
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
			title: 'Preparing to run again',
			message: remote ? 'Fetching a fresh copy from ' + hostOf( remote ) + '…' : 'Resetting the job…',
			percent: null,
			actions: [],
		} );
		api( 'restart', { job: job.id } ).then( () => openJob( job.id, true ) ).catch( ( e ) => {
			modal.update( {
				title: 'Could not run again',
				message: '',
				tone: 'error',
				percent: 100,
				note: e.message,
				dismissible: true,
				actions: [ { label: 'Close', primary: true, onClick: () => modal.close() } ],
			} );
		} );
	}

	function HistoryRow( { job, check } ) {
		const s = job.stats;
		const dry = Number( job.options.dry_run ) === 1;
		const where = job.settings.site_url || job.settings.url || job.settings.file_url || '';
		const actionButton = ( label, fn, cls ) => createElement( 'button', { type: 'button', className: 'button button-small ' + ( cls || '' ), onClick: fn }, label );
		const forget = () => ask( {
			title: 'Delete this import?',
			message: 'Remove this import from history? Imported content is not deleted, but it can no longer be rolled back.',
			label: 'Delete',
			danger: true,
			onConfirm: () => api( 'delete', { job: job.id } ).then( () => {
				state.historyJobs = ( state.historyJobs || [] ).filter( ( j ) => j.id !== job.id );
				state.picked.job.clear();
				modal.close();
			} ).catch( ( err ) => modalError( 'Could not delete', err ) ),
		} );
		const parts = OUTCOMES.filter( ( [ k ] ) => Number( s[ k ] ) > 0 ).map( ( [ k, label, dryLabel ] ) => Number( s[ k ] ).toLocaleString() + ' ' + ( dry ? dryLabel : label ).toLowerCase() );

		return createElement( 'tr', null,
			check,
			createElement( 'td', { className: 'migrateplus-h-source' },
				createElement( 'span', { className: 'migrateplus-source-icon is-sm migrateplus-icon-' + job.source, 'aria-hidden': 'true' } ),
				createElement( 'div', null,
					createElement( 'strong', null, job.settings.file_name || job.label ),
					createElement( 'span', { className: 'migrateplus-help' },
						where ? hostOf( where ) + ' to ' : 'To ',
						( isMultiType( job ) ? job.settings.migrateplus_post_types.split( ',' ).join( ', ' ) : job.options.post_type ) + ', ',
						createElement( 'time', { dateTime: new Date( job.created * 1000 ).toISOString(), title: formatDate( job.created ) }, relativeTime( job.created ) ) ) ) ),
			createElement( 'td', null,
				createElement( 'span', { className: 'migrateplus-badge migrateplus-badge-' + job.status }, STATUS_LABELS[ job.status ] || job.status ),
				dry ? createElement( 'span', { className: 'migrateplus-badge migrateplus-badge-dry' }, 'Dry run' ) : null ),
			createElement( 'td', { className: 'migrateplus-h-result' },
				createElement( OutcomeBar, { stats: s, total: job.total, size: 'sm', label: 'Result' } ),
				createElement( 'span', { className: 'migrateplus-help' }, parts.length ? parts.join( ', ' ) : 'Nothing processed yet' ) ),
			createElement( 'td', null, createElement( 'div', { className: 'migrateplus-row-actions' },
				[ 'running', 'ready', 'failed' ].includes( job.status ) ? actionButton( 'Resume', () => openJob( job.id, true ), 'button-primary' ) : null,
				actionButton( 'View log', () => openJob( job.id, false ), 'migrateplus-view' ),
				[ 'complete', 'rolled_back', 'failed' ].includes( job.status ) ? actionButton( 'Run again', () => restartJob( job ), 'migrateplus-run-again' ) : null,
				! dry && ! [ 'rolled_back', 'running' ].includes( job.status ) && importedCount( job ) > 0 ? actionButton( 'Roll back', () => rollback( job.id ), 'migrateplus-rollback' ) : null,
				createElement( 'button', { type: 'button', className: 'migrateplus-link-danger', title: 'Remove from history. Imported content is kept.', onClick: forget }, 'Delete' ) ) )
		);
	}

	function openJob( id, resume ) {
		if ( state.tab !== 'new' ) {
			window.location.href = D.importUrl + '&job=' + encodeURIComponent( id ) + ( resume ? '&resume=1' : '' );
			return;
		}
		api( 'job', { job: id } ).then( ( data ) => {
			// A draft was never started, so its options have not been through the duplicate check.
			resume = resume && 'draft' !== data.job.status;
			state.job = data.job;
			state.mapping = normalizeMapping( data.job.mapping );
			state.options = Object.assign( {}, D.defaults, data.job.options );
			state.fields = ( data.job.fields || [] ).map( String );
			state.records = data.job.records || [];
			state.suggested = {};
			state.step = 'run';
			state.error = '';
			state.paused = ! resume;
			if ( resume && ! [ 'complete', 'rolled_back' ].includes( data.job.status ) ) {
				state.modalHidden = false;
				modal.open( { kind: 'run' } );
			}
			render();
			if ( resume ) {
				runLoop();
			}
		} ).catch( ( err ) => modalError( 'Could not open the import', err ) );
	}

	window.addEventListener( 'beforeunload', ( e ) => {
		// Rollbacks, exports and replacements run from their modal, which can't be dismissed until they stop.
		const working = modal.isOpen() && [ 'rollback', 'export', 'replace' ].includes( modal.kind() ) && ! modal.dismissible();
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
		openJob( D.openJob, D.resume );
	}
}() );
