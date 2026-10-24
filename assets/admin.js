/* MigratePlus admin screen. Plain JS, no build step. */
( function () {
	'use strict';

	const D = window.MIGRATEPLUS_DATA;
	const root = document.getElementById( 'migrateplus-app' );
	if ( ! D || ! root ) {
		return;
	}

	const state = {
		tab: [ 'history', 'export', 'replace' ].includes( D.view ) ? D.view : 'new',
		step: 'source',
		sourceId: D.sources[ 0 ] ? D.sources[ 0 ].id : '',
		job: null,
		records: [],
		fields: [],
		mapping: { fields: {}, meta: [], taxonomies: [], acf: {} },
		options: Object.assign( {}, D.defaults ),
		running: false,
		paused: false,
		results: [],
		error: '',
		busy: false,
		fileMode: {}, // 'source:fieldKey' => 'upload' | 'url'
		modalHidden: false, // user chose to watch the import in the page instead of the modal
		runClock: null, // { t, processed } when the current run started, for the ETA
		logFilter: 'all',
		logQuery: '',
		historyQuery: '',
		historyJobs: null,
		direct: false, // A MigratePlus export imported straight from the upload screen, without the mapping step.
		savedMappings: D.mappings || [], // Saved mappings for JSON that is not a MigratePlus export.
		savedPick: '', // The saved mapping chosen in the select, or offered because the file has its fields.
		savedName: '',
		savedNotice: null, // { type, text } shown on the saved mappings panel.
	};

	// Helpers

	function el( tag, attrs, children ) {
		const node = document.createElement( tag );
		Object.entries( attrs || {} ).forEach( ( [ k, v ] ) => {
			if ( v === null || v === undefined || v === false ) {
				return;
			}
			if ( k === 'class' ) {
				node.className = v;
			} else if ( k === 'text' ) {
				node.textContent = v;
			} else if ( k.startsWith( 'on' ) && typeof v === 'function' ) {
				node.addEventListener( k.slice( 2 ), v );
			} else if ( k === 'value' ) {
				node.value = v;
			} else if ( k === 'checked' || k === 'selected' || k === 'disabled' ) {
				node[ k ] = !! v;
			} else {
				node.setAttribute( k, v === true ? '' : v );
			}
		} );
		( Array.isArray( children ) ? children : [ children ] ).forEach( ( c ) => {
			if ( c === null || c === undefined || c === false ) {
				return;
			}
			node.appendChild( typeof c === 'string' || typeof c === 'number' ? document.createTextNode( String( c ) ) : c );
		} );
		return node;
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
			throw new Error( ( json.data && json.data.message ) || 'Request failed.' );
		}
		return json.data || {};
	}

	function api( action, data ) {
		return fetch( D.ajaxUrl, { method: 'POST', body: apiBody( action, data ), credentials: 'same-origin' } )
			.then( ( r ) => r.text().then( ( t ) => parseResponse( r.status, t ) ) );
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
	 * A single reusable modal with a progress bar. Lives outside #migrateplus-app so
	 * re-renders of the page never tear it down.
	 *
	 * modal.open( { title, message, percent, detail, stats, actions, tone, icon, dismissible } )
	 * modal.update( { ...same keys } )   percent: number 0-100, or null for indeterminate.
	 * modal.close()
	 *
	 * The header icon follows the tone (spinner while working, check on success, pause, error);
	 * icon: 'file' overrides it. The primary action is always placed last (rightmost).
	 */
	const modal = ( function () {
		let overlay = null;
		let parts = {};
		let opts = {};
		let lastFocus = null;

		const ICONS = { success: 'dashicons-yes', error: 'dashicons-warning', paused: 'dashicons-controls-pause', file: 'dashicons-database-import', danger: 'dashicons-trash', question: 'dashicons-editor-help', working: '' };

		function build() {
			parts.icon = el( 'span', { class: 'migrateplus-modal-icon', 'aria-hidden': 'true' }, el( 'span', { class: 'dashicons' } ) );
			parts.title = el( 'h2', { class: 'migrateplus-modal-title', id: 'migrateplus-modal-title' } );
			parts.message = el( 'p', { class: 'migrateplus-modal-message', id: 'migrateplus-modal-message' } );
			parts.close = el( 'button', { type: 'button', class: 'migrateplus-modal-close', 'aria-label': 'Close', title: 'Close', onclick: dismiss }, el( 'span', { class: 'dashicons dashicons-no-alt', 'aria-hidden': 'true' } ) );
			parts.head = el( 'header', { class: 'migrateplus-modal-head' }, [
				parts.icon,
				el( 'div', { class: 'migrateplus-modal-heading' }, [ parts.title, parts.message ] ),
				parts.close,
			] );
			parts.fill = el( 'span', { class: 'migrateplus-modal-fill' } );
			parts.obar = outcomeBar( {}, 0, {} );
			parts.bar = el( 'div', { class: 'migrateplus-progress migrateplus-modal-bar', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-labelledby': 'migrateplus-modal-title' }, parts.fill );
			parts.percent = el( 'span', { class: 'migrateplus-modal-percent' } );
			parts.detail = el( 'span', { class: 'migrateplus-modal-detail' } );
			parts.meta = el( 'div', { class: 'migrateplus-modal-meta' }, [ parts.percent, parts.detail ] );
			parts.stats = el( 'ul', { class: 'migrateplus-legend migrateplus-modal-stats' } );
			parts.note = el( 'p', { class: 'migrateplus-modal-note', 'aria-live': 'polite' } );
			parts.actions = el( 'div', { class: 'migrateplus-modal-actions' } );
			parts.dialog = el( 'div', { class: 'migrateplus-modal', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'migrateplus-modal-title', 'aria-describedby': 'migrateplus-modal-message' }, [
				parts.head, parts.meta, parts.bar, parts.obar, parts.stats, parts.note, parts.actions,
			] );
			overlay = el( 'div', { class: 'migrateplus-modal-overlay' }, parts.dialog );
			overlay.addEventListener( 'keydown', onKey );
			document.body.appendChild( overlay );
		}

		/** Escape and the ✕ button do the same thing: the caller's onDismiss, or close. */
		function dismiss() {
			if ( typeof opts.onDismiss === 'function' ) {
				opts.onDismiss();
			} else {
				close();
			}
		}

		function onKey( e ) {
			if ( e.key === 'Escape' && opts.dismissible ) {
				e.preventDefault();
				dismiss();
			} else if ( e.key === 'Tab' ) {
				// Keep focus inside the dialog.
				const focusables = parts.dialog.querySelectorAll( 'button, a[href]' );
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
		}

		function update( next ) {
			if ( ! overlay ) {
				return;
			}
			opts = Object.assign( opts, next || {} );
			parts.title.textContent = opts.title || '';
			parts.message.textContent = opts.message || '';
			parts.message.hidden = ! opts.message;

			const status = opts.icon || { success: 'success', error: 'error', paused: 'paused' }[ opts.tone ] || 'working';
			parts.dialog.setAttribute( 'data-status', status );
			parts.icon.firstChild.className = 'dashicons ' + ( ICONS[ status ] || '' );
			parts.close.hidden = ! opts.dismissible;

			const indeterminate = opts.percent === null || opts.percent === undefined;
			const pct = indeterminate ? 0 : Math.max( 0, Math.min( 100, opts.percent ) );
			parts.bar.classList.toggle( 'is-indeterminate', indeterminate );
			parts.bar.classList.toggle( 'is-error', opts.tone === 'error' );
			parts.bar.classList.toggle( 'is-paused', opts.tone === 'paused' );
			parts.bar.classList.toggle( 'is-success', opts.tone === 'success' );
			parts.fill.style.width = indeterminate ? '' : pct + '%';
			if ( indeterminate ) {
				parts.bar.removeAttribute( 'aria-valuenow' );
			} else {
				parts.bar.setAttribute( 'aria-valuenow', Math.round( pct ) );
			}
			parts.percent.textContent = indeterminate || opts.tone === 'error' ? '' : Math.floor( pct ) + '%';
			// Outcome mode: the bar is split into created / updated / skipped / failed.
			// bar: false hides the progress bar and its numbers (e.g. for a confirmation).
			parts.bar.hidden = !! opts.outcome || false === opts.bar;
			parts.obar.hidden = ! opts.outcome;
			parts.meta.hidden = false === opts.bar;
			if ( opts.outcome ) {
				setOutcomeBar( parts.obar, opts.outcome.stats, opts.outcome.total, { size: 'lg', indeterminate, tone: opts.tone, label: opts.title } );
			}
			// A soft moving sheen on the bar while work is in progress.
			const running = 'working' === status && ! indeterminate && pct < 100;
			parts.bar.classList.toggle( 'is-running', running );
			parts.obar.classList.toggle( 'is-running', running );
			parts.detail.textContent = opts.detail || '';

			parts.stats.innerHTML = '';
			( opts.stats || [] ).forEach( ( s ) => parts.stats.appendChild(
				el( 'li', { class: 'is-' + s.key + ( Number( s.value ) ? '' : ' is-zero' ) }, [ el( 'strong', { text: ( Number( s.value ) || 0 ).toLocaleString() } ), el( 'span', { text: s.label } ) ] )
			) );
			parts.stats.hidden = ! ( opts.stats && opts.stats.length );

			parts.note.textContent = opts.note || '';
			parts.note.hidden = ! opts.note;
			parts.note.classList.toggle( 'is-error', opts.tone === 'error' );

			// Primary action last, so it sits on the right.
			const actions = ( opts.actions || [] ).filter( ( a ) => ! a.primary ).concat( ( opts.actions || [] ).filter( ( a ) => a.primary ) );

			// Only rebuild buttons when they change, so focus is not lost on every tick.
			const sig = JSON.stringify( actions.map( ( a ) => [ a.label, a.primary, a.danger, a.href, a.disabled ] ) );
			if ( sig !== parts.actionsSig ) {
				parts.actionsSig = sig;
				const hadFocus = parts.dialog.contains( document.activeElement );
				parts.actions.innerHTML = '';
				actions.forEach( ( a ) => {
					const cls = 'button' + ( a.primary ? ' button-primary' : '' ) + ( a.danger ? ' migrateplus-danger' : '' );
					parts.actions.appendChild( a.href
						? el( 'a', { class: cls, href: a.href, text: a.label } )
						: el( 'button', { type: 'button', class: cls, text: a.label, disabled: a.disabled, onclick: a.onClick } ) );
				} );
				if ( hadFocus || ! parts.dialog.contains( document.activeElement ) ) {
					focusDefault();
				}
			} else {
				// Handlers may close over fresh state; refresh them without rebuilding.
				Array.from( parts.actions.children ).forEach( ( node, i ) => {
					const a = actions[ i ];
					if ( a && ! a.href ) {
						node.onclick = a.onClick;
					}
				} );
			}
			parts.actions.hidden = ! actions.length;
		}

		function focusDefault() {
			const target = parts.actions.querySelector( '.button-primary' ) || parts.actions.querySelector( 'button, a' ) || parts.dialog;
			if ( target === parts.dialog ) {
				parts.dialog.setAttribute( 'tabindex', '-1' );
			}
			target.focus( { preventScroll: true } );
		}

		function open( next ) {
			if ( ! overlay ) {
				lastFocus = document.activeElement;
				build();
				document.body.classList.add( 'migrateplus-modal-open' );
			}
			opts = {};
			parts.actionsSig = null;
			update( next );
			if ( ! parts.dialog.contains( document.activeElement ) ) {
				focusDefault();
			}
		}

		function close() {
			if ( ! overlay ) {
				return;
			}
			overlay.remove();
			overlay = null;
			parts = {};
			opts = {};
			document.body.classList.remove( 'migrateplus-modal-open' );
			if ( lastFocus && document.body.contains( lastFocus ) ) {
				lastFocus.focus( { preventScroll: true } );
			}
			lastFocus = null;
		}

		return {
			open,
			update,
			close,
			isOpen: () => !! overlay,
			kind: () => opts.kind || '',
		};
	}() );

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
		state.suggested = state.suggested || {};
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

	function fieldSelect( value, onChange, opts ) {
		const select = el( 'select', { class: 'migrateplus-field-select', 'aria-label': ( opts && opts.label ) || 'Source field', onchange: ( e ) => onChange( e.target.value ) }, [
			el( 'option', { value: '', text: '(not mapped)' } ),
		] );
		state.fields.forEach( ( f ) => select.appendChild( el( 'option', { value: f, text: f, selected: f === value } ) ) );
		if ( opts && opts.nested ) {
			const parents = nestedFields();
			if ( parents.length ) {
				select.appendChild( el( 'optgroup', { label: 'Nested data (lists and objects)' }, parents.map( ( f ) =>
					el( 'option', { value: f, text: f + ' (all)', selected: f === value } ) ) ) );
			}
		}
		if ( value && ! select.value ) {
			select.appendChild( el( 'option', { value, text: value + ' (not in preview)', selected: true } ) );
		}
		return select;
	}

	function notice( type, message ) {
		return el( 'div', { class: 'notice notice-' + type + ' inline migrateplus-notice' }, el( 'p', { text: message } ) );
	}

	/** Show a failed request's message at the top of the page. */
	function showError( err ) {
		const n = notice( 'error', err.message );
		root.insertBefore( n, root.firstChild );
		n.scrollIntoView( { block: 'nearest' } );
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
					modal.update( { icon: 'working', dismissible: false, actions: [ { label: label + '…', primary: true, danger, disabled: true } ] } );
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
			const h = Math.floor( diff / 3600 );
			return h + ' hour' + ( h === 1 ? '' : 's' ) + ' ago';
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
	 * Segments are sized against the total (or the processed count when the total is unknown).
	 * opts: { size: 'lg'|'sm', indeterminate, tone, label }
	 */
	function outcomeBar( stats, total, opts ) {
		const bar = el( 'div', { role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100 } );
		OUTCOMES.forEach( ( [ key ] ) => bar.appendChild( el( 'span', { class: 'migrateplus-obar-seg is-' + key } ) ) );
		bar.appendChild( el( 'span', { class: 'migrateplus-obar-runner' } ) );
		setOutcomeBar( bar, stats, total, opts );
		return bar;
	}

	/** Update an outcome bar in place so widths animate. */
	function setOutcomeBar( bar, stats, total, opts ) {
		opts = opts || {};
		const processed = Number( stats.processed ) || 0;
		const base = Math.max( Number( total ) || 0, processed, 1 );
		bar.className = 'migrateplus-obar' + ( opts.size ? ' is-' + opts.size : '' ) + ( opts.indeterminate ? ' is-indeterminate' : '' ) + ( opts.tone ? ' is-' + opts.tone : '' );
		bar.setAttribute( 'aria-label', opts.label || 'Progress' );
		if ( opts.indeterminate ) {
			bar.removeAttribute( 'aria-valuenow' );
		} else {
			bar.setAttribute( 'aria-valuenow', Math.round( ( processed / base ) * 100 ) );
		}
		OUTCOMES.forEach( ( [ key ], i ) => {
			const n = Number( stats[ key ] ) || 0;
			bar.children[ i ].style.width = ( ( n / base ) * 100 ) + '%';
		} );
	}

	function outcomeLegend( stats, dry ) {
		return el( 'ul', { class: 'migrateplus-legend' }, OUTCOMES.map( ( [ key, label, dryLabel ] ) =>
			el( 'li', { class: 'is-' + key + ( Number( stats[ key ] ) ? '' : ' is-zero' ) }, [
				el( 'strong', { text: ( Number( stats[ key ] ) || 0 ).toLocaleString() } ),
				el( 'span', { text: dry ? dryLabel : label } ),
			] )
		) );
	}

	const SOURCE_BLURBS = {
		json: 'A MigratePlus export, or any JSON file or API',
	};

	// Render

	function render() {
		// Re-renders rebuild the DOM; keep focus (and caret) on the same control.
		const active = document.activeElement;
		const keep = active && root.contains( active ) && active.id ? { id: active.id, start: active.selectionStart, end: active.selectionEnd } : null;
		draw();
		if ( keep ) {
			const again = document.getElementById( keep.id );
			if ( again ) {
				again.focus( { preventScroll: true } );
				if ( typeof keep.start === 'number' && again.setSelectionRange ) {
					try {
						again.setSelectionRange( keep.start, keep.end );
					} catch ( e ) {}
				}
			}
		}
	}

	function draw() {
		root.innerHTML = '';
		root.classList.add( 'migrateplus-app' );
		if ( state.tab === 'history' ) {
			renderHistory();
			return;
		}
		if ( state.tab === 'export' ) {
			renderExport();
			return;
		}
		if ( state.tab === 'replace' ) {
			renderReplace();
			return;
		}
		const dropScreen = state.step === 'source' && isDropSource();
		if ( ! dropScreen && ! state.direct && 'review' !== state.step ) {
			root.appendChild( stepper() );
		}
		if ( state.error ) {
			root.appendChild( notice( 'error', state.error ) );
		}
		if ( 'review' === state.step ) {
			renderReview();
		} else if ( dropScreen ) {
			renderDrop();
		} else if ( state.step === 'source' ) {
			renderSource();
		} else if ( state.step === 'map' ) {
			renderMap();
		} else {
			renderRun();
		}
	}

	function stepper() {
		const steps = [
			[ 'source', isDropSource() ? 'Upload a file' : 'Choose a source' ],
			[ 'map', 'Map fields' ],
			[ 'run', 'Import' ],
		];
		const idx = steps.findIndex( ( s ) => s[ 0 ] === state.step );
		return el( 'ol', { class: 'migrateplus-route', 'aria-label': 'Import steps' }, steps.map( ( s, i ) =>
			el( 'li', {
				class: i === idx ? 'is-current' : ( i < idx ? 'is-done' : '' ),
				'aria-current': i === idx ? 'step' : null,
			}, [
				el( 'span', { class: 'migrateplus-route-dot', 'aria-hidden': 'true', text: i < idx ? '✓' : String( i + 1 ) } ),
				el( 'span', { class: 'migrateplus-route-label', text: s[ 1 ] } ),
			] )
		) );
	}

	// Step 1

	function renderSource() {
		const cards = el( 'div', { class: 'migrateplus-tiles', role: 'radiogroup', 'aria-label': 'Source' }, D.sources.map( ( s ) =>
			el( 'label', { class: 'migrateplus-tile' + ( s.id === state.sourceId ? ' is-selected' : '' ) }, [
				el( 'input', { type: 'radio', name: 'migrateplus_source', value: s.id, checked: s.id === state.sourceId, onchange: () => {
					state.sourceId = s.id;
					state.error = '';
					render();
					const first = root.querySelector( '.migrateplus-tile.is-selected input' );
					if ( first ) {
						first.focus();
					}
				} } ),
				el( 'span', { class: 'migrateplus-source-icon migrateplus-icon-' + s.id, 'aria-hidden': 'true' } ),
				el( 'span', { class: 'migrateplus-tile-text' }, [
					el( 'strong', { text: s.label } ),
					SOURCE_BLURBS[ s.id ] ? el( 'span', { text: SOURCE_BLURBS[ s.id ] } ) : null,
				] ),
			] )
		) );

		const source = D.sources.find( ( s ) => s.id === state.sourceId );
		const form = el( 'form', { class: 'migrateplus-panel migrateplus-source-form', onsubmit: onPreview } );
		const fields = el( 'div', { class: 'migrateplus-fields' } );
		source.schema.forEach( ( f ) => {
			const id = 'migrateplus-set-' + f.key;
			let input;
			const name = f.type === 'file' ? 'file_' + f.key : 'settings[' + f.key + ']';
			if ( f.type === 'select' ) {
				input = el( 'select', { id, name }, Object.entries( f.options ).map( ( [ v, l ] ) =>
					el( 'option', { value: v, text: l, selected: v === f.default } )
				) );
			} else if ( f.type === 'textarea' ) {
				input = el( 'textarea', { id, name, rows: 3, class: 'code', spellcheck: 'false' } );
			} else if ( f.type === 'file' ) {
				input = fileInput( f, id, name );
			} else {
				input = el( 'input', {
					id, name,
					type: f.type === 'password' ? 'password' : ( f.type === 'url' ? 'url' : ( f.type === 'number' ? 'number' : 'text' ) ),
					value: f.default || '',
					placeholder: f.type === 'url' ? 'https://' : null,
					autocomplete: f.type === 'password' ? 'new-password' : 'off',
					required: f.required && f.type !== 'file' ? true : null,
				} );
			}
			const help = [];
			if ( f.help ) {
				help.push( el( 'p', { class: 'migrateplus-field-help', text: f.help } ) );
			}
			if ( f.type === 'file' && f.remote === false ) {
				help.push( el( 'p', { class: 'migrateplus-field-help', text: 'Max upload size: ' + D.maxUpload } ) );
			}
			fields.appendChild( el( 'div', { class: 'migrateplus-field migrateplus-field-' + f.type }, [
				el( 'label', { class: 'migrateplus-field-label', for: f.type === 'file' ? null : id }, [
					f.label,
					f.required ? el( 'span', { class: 'migrateplus-req', text: 'Required' } ) : null,
				] ),
				input,
			].concat( help ) ) );
		} );
		form.appendChild( el( 'header', { class: 'migrateplus-panel-head' }, [
			el( 'span', { class: 'migrateplus-source-icon migrateplus-icon-' + source.id, 'aria-hidden': 'true' } ),
			el( 'div', {}, [
				el( 'h2', { text: source.label } ),
				SOURCE_BLURBS[ source.id ] ? el( 'p', { text: SOURCE_BLURBS[ source.id ] } ) : null,
			] ),
		] ) );
		form.appendChild( fields );
		form.appendChild( el( 'footer', { class: 'migrateplus-panel-foot' }, [
			el( 'p', { class: 'migrateplus-foot-note', text: 'Nothing is imported yet. You will see a preview and map the fields first.' } ),
			el( 'button', { type: 'submit', class: 'button button-primary migrateplus-btn-lg', disabled: state.busy, text: 'Connect and preview' } ),
		] ) );

		if ( D.sources.length > 1 ) {
			root.appendChild( cards );
		}
		root.appendChild( form );
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
	function renderDrop() {
		const field = D.sources.find( ( s ) => s.id === state.sourceId ).schema[ 0 ];
		const input = el( 'input', { type: 'file', id: 'migrateplus-import-file', accept: field.accept || '', class: 'migrateplus-drop-input', 'aria-label': 'File to import', onchange: ( e ) => {
			const file = e.target.files && e.target.files[ 0 ];
			e.target.value = ''; // Choosing the same file again still fires change.
			if ( file ) {
				uploadFile( field, file );
			}
		} } );

		const area = el( 'div', { class: 'migrateplus-import-drop' }, [
			el( 'span', { class: 'dashicons dashicons-cloud-upload', 'aria-hidden': 'true' } ),
			el( 'strong', { text: 'Drop a JSON file (.json or .json.gz) here to import it' } ),
			el( 'span', { class: 'migrateplus-muted', text: 'A MigratePlus export imports straight away. Other JSON files go to field mapping first.' } ),
			el( 'button', { type: 'button', class: 'button button-primary migrateplus-btn-lg', disabled: state.busy, text: 'Choose a JSON file', onclick: () => input.click() } ),
		] );
		[ 'dragenter', 'dragover' ].forEach( ( t ) => area.addEventListener( t, ( e ) => {
			e.preventDefault();
			area.classList.add( 'is-dragover' );
		} ) );
		[ 'dragleave', 'dragend', 'drop' ].forEach( ( t ) => area.addEventListener( t, ( e ) => {
			if ( 'dragleave' !== t || ! area.contains( e.relatedTarget ) ) {
				area.classList.remove( 'is-dragover' );
			}
		} ) );
		area.addEventListener( 'drop', ( e ) => {
			e.preventDefault();
			const file = e.dataTransfer && e.dataTransfer.files[ 0 ];
			if ( file && ! state.busy ) {
				uploadFile( field, file );
			}
		} );

		root.appendChild( el( 'section', { class: 'migrateplus-panel migrateplus-import' }, [
			area,
			input,
			el( 'p', { class: 'migrateplus-import-limit', text: 'Maximum upload size: ' + D.maxUpload } ),
		] ) );
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
				note: '“' + file.name + '” is not a ' + exts.join( ' or ' ) + ' file. Export one from MigratePlus → Export on the other site.',
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
	function renderReview() {
		const r = state.review;
		const items = r.d.changed || [];
		const images = r.d.images || [];
		const count = el( 'span', { class: 'migrateplus-actionbar-status', 'aria-live': 'polite' } );
		const go = el( 'button', { type: 'button', class: 'button button-primary' } );
		const boxes = [];
		const radios = [];
		const sync = () => {
			const n = r.selected.size + r.media.size;
			count.textContent = n + ' of ' + ( items.length + images.length ) + ' selected';
			go.textContent = n ? 'Import with ' + n + ' selected' : 'Import without updating';
			boxes.forEach( ( b ) => {
				b.checked = r.selected.has( Number( b.value ) );
			} );
			radios.forEach( ( [ b, id, v ] ) => {
				b.checked = ( r.media.get( id ) || 'skip' ) === v;
			} );
		};
		const setAll = ( on ) => {
			items.forEach( ( it ) => ( on ? r.selected.add( it.post_id ) : r.selected.delete( it.post_id ) ) );
			sync();
		};
		const setAllMedia = ( v ) => {
			images.forEach( ( it ) => ( 'skip' === v ? r.media.delete( it.post_id ) : r.media.set( it.post_id, v ) ) );
			sync();
		};

		if ( items.length ) {
			root.appendChild( el( 'section', { class: 'migrateplus-panel migrateplus-review-head' }, [
				el( 'h2', { text: 'Review changes' } ),
				el( 'p', { text: items.length + ( 1 === items.length ? ' item in this import is' : ' items in this import are' ) + ' already on this site and differ from it. Tick the ones to update with the imported version. Everything else that is already here is skipped.' } ),
				el( 'p', { class: 'migrateplus-muted', text: 'Custom fields, terms and images are updated too and are not shown here. WordPress keeps a revision of each post or page that is updated, so you can restore the old version from the editor.' } ),
				el( 'div', { class: 'migrateplus-review-tools' }, [
					el( 'button', { type: 'button', class: 'button button-small', text: 'Select all', onclick: () => setAll( true ) } ),
					el( 'button', { type: 'button', class: 'button button-small', text: 'Select none', onclick: () => setAll( false ) } ),
				] ),
			] ) );
		}

		root.appendChild( el( 'ol', { class: 'migrateplus-review' }, items.map( ( it, i ) => {
			const id = 'migrateplus-review-' + it.post_id;
			const box = el( 'input', { type: 'checkbox', id, value: it.post_id, onchange: ( e ) => {
				if ( e.target.checked ) {
					r.selected.add( it.post_id );
				} else {
					r.selected.delete( it.post_id );
				}
				sync();
			} } );
			boxes.push( box );
			const fields = Object.keys( it.changes ).map( ( k ) => it.changes[ k ] );
			return el( 'li', { class: 'migrateplus-panel migrateplus-review-item' }, [
				el( 'div', { class: 'migrateplus-review-row' }, [
					box,
					el( 'label', { for: id }, [
						el( 'strong', { text: it.title || '(untitled)' } ),
						el( 'span', { class: 'migrateplus-muted', text: it.type + ' #' + it.post_id + ' · ' + ( 'before' === it.kind ? 'imported before' : 'same ' + it.label ) + ' · changes: ' + fields.map( ( f ) => f.label.toLowerCase() ).join( ', ' ) } ),
					] ),
				] ),
				el( 'details', { class: 'migrateplus-review-body', open: i < 3 }, [
					el( 'summary', { text: 'Show changes' } ),
					...fieldChanges( fields ),
				] ),
			] );
		} ) ) );

		if ( images.length ) {
			root.appendChild( el( 'section', { class: 'migrateplus-panel migrateplus-review-head' }, [
				el( 'h2', { text: 'Compare media' } ),
				el( 'p', { text: images.length + ( 1 === images.length ? ' file in this import is' : ' files in this import are' ) + ' already in the Media Library. For each one, choose what to do. Skipped files are left as they are.' } ),
				el( 'p', { class: 'migrateplus-muted', text: '"Update details" keeps the file on this site and copies the title, caption, description and alt text. "Replace image" also swaps in the imported file, in the same Media Library item, so pages that use it show the new image. A replaced file cannot be rolled back. "Keep both" adds the imported file as a new item and leaves this one alone.' } ),
				el( 'div', { class: 'migrateplus-review-tools' }, [
					el( 'button', { type: 'button', class: 'button button-small', text: 'Skip all', onclick: () => setAllMedia( 'skip' ) } ),
					el( 'button', { type: 'button', class: 'button button-small', text: 'Update all details', onclick: () => setAllMedia( 'update' ) } ),
					el( 'button', { type: 'button', class: 'button button-small', text: 'Replace all', onclick: () => setAllMedia( 'replace' ) } ),
					el( 'button', { type: 'button', class: 'button button-small', text: 'Keep both for all', onclick: () => setAllMedia( 'copy' ) } ),
				] ),
			] ) );
			root.appendChild( el( 'ol', { class: 'migrateplus-review' }, images.map( ( it ) => {
				const fields = Object.keys( it.changes ).map( ( k ) => it.changes[ k ] );
				const choice = ( v, label ) => {
					const id = 'migrateplus-media-' + it.post_id + '-' + v;
					const radio = el( 'input', { type: 'radio', id, name: 'migrateplus-media-' + it.post_id, value: v, onchange: () => {
						if ( 'skip' === v ) {
							r.media.delete( it.post_id );
						} else {
							r.media.set( it.post_id, v );
						}
						sync();
					} } );
					radios.push( [ radio, it.post_id, v ] );
					return el( 'label', { class: 'migrateplus-check-chip', for: id }, [ radio, el( 'span', { text: label } ) ] );
				};
				const figure = ( src, href, caption ) => el( 'figure', { class: 'migrateplus-media-pick' }, [
					src && it.is_image
						? el( 'a', { href, target: '_blank', rel: 'noopener' }, el( 'img', { src, alt: caption + ': ' + ( it.title || 'image' ), loading: 'lazy' } ) )
						: el( 'span', { class: 'migrateplus-media-file', text: ( href || '' ).split( '/' ).pop() || '(no preview)' } ),
					el( 'figcaption', { text: caption } ),
				] );
				return el( 'li', { class: 'migrateplus-panel migrateplus-review-item' }, [
					el( 'div', { class: 'migrateplus-review-row' }, [
						el( 'div', { class: 'migrateplus-review-title' }, [
							el( 'strong', { text: it.title || '(untitled)' } ),
							el( 'span', { class: 'migrateplus-muted', text: 'Media #' + it.post_id + ' · ' + ( 'before' === it.kind ? 'imported before' : 'same ' + it.label ) + ( fields.length ? ' · details differ: ' + fields.map( ( f ) => f.label.toLowerCase() ).join( ', ' ) : ' · same details' ) } ),
						] ),
					] ),
					el( 'div', { class: 'migrateplus-media-compare' }, [
						figure( it.current, it.current_full, 'On this site' ),
						figure( it.imported_preview || it.imported, it.imported, 'In the import' ),
					] ),
					el( 'div', { class: 'migrateplus-chips', role: 'radiogroup', 'aria-label': 'What to do with ' + ( it.title || 'this file' ) }, [
						choice( 'skip', 'Skip' ),
						choice( 'update', 'Update details' ),
						choice( 'replace', 'Replace image' ),
						choice( 'copy', 'Keep both' ),
					] ),
					fields.length ? el( 'details', { class: 'migrateplus-review-body' }, [ el( 'summary', { text: 'Show detail changes' } ), ...fieldChanges( fields ) ] ) : null,
				] );
			} ) ) );
		}

		go.addEventListener( 'click', () => {
			const ids = Array.from( r.selected );
			const lists = { update: ids, replace: [], copy: [] };
			r.media.forEach( ( v, id ) => lists[ v ].push( id ) );
			state.step = r.back;
			state.review = null;
			r.apply( ids, lists.replace, lists.copy );
		} );
		root.appendChild( el( 'div', { class: 'migrateplus-actionbar' }, [
			count,
			el( 'div', { class: 'migrateplus-actionbar-buttons' }, [
				el( 'button', { type: 'button', class: 'button', text: 'Back', onclick: () => {
					state.step = r.back;
					state.review = null;
					render();
					r.reopen();
				} } ),
				go,
			] ),
		] ) );
		sync();
	}

	/** Each changed field: a diff for longer text, "Now / Imported" for short values. */
	function fieldChanges( fields ) {
		return fields.map( ( f ) => el( 'div', { class: 'migrateplus-review-field' }, [
			el( 'h3', { text: f.label } ),
			f.old.includes( '\n' ) || f.new.includes( '\n' ) || f.old.length + f.new.length > 160
				? diffView( f.old, f.new )
				: el( 'dl', { class: 'migrateplus-review-swap' }, [
					el( 'dt', { text: 'Now' } ), el( 'dd', { class: 'is-old', text: f.old || '(empty)' } ),
					el( 'dt', { text: 'Imported' } ), el( 'dd', { class: 'is-new', text: f.new || '(empty)' } ),
				] ),
		] ) );
	}

	/**
	 * Line diff of two texts: unchanged lines around each change, the rest folded.
	 * Uses a longest-common-subsequence table after trimming the shared start and end;
	 * a pair too large for that is shown as "all removed, all added".
	 */
	function diffView( oldText, newText ) {
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
		const flush = () => {
			if ( folded ) {
				out.push( el( 'li', { class: 'is-fold', text: '⋯ ' + folded + ( 1 === folded ? ' unchanged line' : ' unchanged lines' ) } ) );
				folded = 0;
			}
		};
		rows.forEach( ( row, k ) => {
			if ( ! near[ k ] ) {
				folded++;
				return;
			}
			flush();
			const kind = { ' ': 'is-same', '-': 'is-old', '+': 'is-new' }[ row[ 0 ] ];
			out.push( el( 'li', { class: kind }, [ el( 'span', { class: 'migrateplus-diff-sign', 'aria-label': { ' ': 'Unchanged', '-': 'Removed', '+': 'Added' }[ row[ 0 ] ], text: ' ' === row[ 0 ] ? '' : ( '-' === row[ 0 ] ? '−' : '+' ) } ), el( 'span', { text: row[ 1 ] || ' ' } ) ] ) );
		} );
		flush();
		return el( 'ol', { class: 'migrateplus-diff' }, out );
	}

	/**
	 * File field with an "Upload file / From URL" switch (unless the schema sets remote: false).
	 * The upload side is a drop zone.
	 */
	function fileInput( f, id, name ) {
		const upload = el( 'input', { id, name, type: 'file', accept: f.accept || '', class: 'migrateplus-drop-input' } );
		const fileName = el( 'span', { class: 'migrateplus-drop-file' } );
		const zone = el( 'label', { class: 'migrateplus-drop', for: id }, [
			upload,
			el( 'span', { class: 'migrateplus-drop-icon', 'aria-hidden': 'true' } ),
			el( 'span', { class: 'migrateplus-drop-text' }, [
				el( 'strong', { text: 'Drop a file here or choose one' } ),
				el( 'span', { text: ( f.accept || '' ).split( ',' ).filter( ( a ) => a.trim().startsWith( '.' ) ).join( ', ' ) + ', up to ' + D.maxUpload } ),
			] ),
			fileName,
		] );
		const showFile = () => {
			const file = upload.files && upload.files[ 0 ];
			zone.classList.toggle( 'has-file', !! file );
			fileName.textContent = file ? file.name + ' (' + formatBytes( file.size ) + ')' : '';
		};
		upload.addEventListener( 'change', showFile );
		[ 'dragenter', 'dragover' ].forEach( ( t ) => zone.addEventListener( t, ( e ) => {
			e.preventDefault();
			zone.classList.add( 'is-dragging' );
		} ) );
		[ 'dragleave', 'dragend', 'drop' ].forEach( ( t ) => zone.addEventListener( t, () => zone.classList.remove( 'is-dragging' ) ) );
		zone.addEventListener( 'drop', ( e ) => {
			e.preventDefault();
			if ( e.dataTransfer && e.dataTransfer.files.length ) {
				upload.files = e.dataTransfer.files;
				showFile();
			}
		} );

		if ( f.remote === false ) {
			return zone;
		}
		const modeKey = state.sourceId + ':' + f.key;
		const mode = state.fileMode[ modeKey ] || 'upload';
		const urlInput = el( 'input', {
			id: id + '-url',
			name: 'settings[' + f.key + '_url]',
			type: 'url',
			placeholder: 'https://',
			autocomplete: 'off',
			'aria-label': f.label + ' URL',
		} );
		const uploadPane = el( 'div', { class: 'migrateplus-file-pane' }, zone );
		const urlPane = el( 'div', { class: 'migrateplus-file-pane' }, [
			urlInput,
			el( 'p', { class: 'migrateplus-field-help', text: 'Your server downloads the file (up to ' + D.maxRemote + '). Google Sheets, Google Drive, Dropbox and GitHub share links work too, as long as they are shared publicly.' } ),
		] );

		const tabs = {};
		const setMode = ( m, focus ) => {
			state.fileMode[ modeKey ] = m;
			const isUrl = m === 'url';
			uploadPane.hidden = isUrl;
			urlPane.hidden = ! isUrl;
			// Disabled inputs are left out of the submitted form.
			upload.disabled = isUrl;
			urlInput.disabled = ! isUrl;
			urlInput.required = isUrl && !! f.required;
			Object.entries( tabs ).forEach( ( [ k, b ] ) => {
				b.classList.toggle( 'is-active', k === m );
				b.setAttribute( 'aria-pressed', k === m ? 'true' : 'false' );
			} );
			if ( focus ) {
				( isUrl ? urlInput : upload ).focus();
			}
		};
		tabs.url = el( 'button', { type: 'button', class: 'migrateplus-seg', text: 'From a URL', onclick: () => setMode( 'url', true ) } );
		tabs.upload = el( 'button', { type: 'button', class: 'migrateplus-seg', text: 'Upload a file', onclick: () => setMode( 'upload', true ) } );

		const wrap = el( 'div', { class: 'migrateplus-file-field' }, [
			el( 'div', { class: 'migrateplus-segmented', role: 'group', 'aria-label': f.label + ': how to provide it' }, [ tabs.upload, tabs.url ] ),
			uploadPane,
			urlPane,
		] );
		setMode( mode, false );
		return wrap;
	}

	function onPreview( e ) {
		e.preventDefault();
		const form = e.target;
		const fd = new FormData( form );
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

		const submit = form.querySelector( '[type="submit"]' );
		if ( submit ) {
			submit.disabled = true;
		}
		const oldNotice = root.querySelector( '.migrateplus-notice.notice-error' );
		if ( oldNotice ) {
			oldNotice.remove();
		}
		requestPreview( fd, { upload, remote, connectTo, label: source.label, onError: ( err ) => {
			if ( submit ) {
				submit.disabled = false;
			}
			// Keep the form as typed; just surface the error.
			form.parentNode.insertBefore( notice( 'error', err.message ), form );
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

	function renderMap() {
		suggestExtras();
		const job = state.job;
		const source = D.sources.find( ( s ) => s.id === job.source ) || D.sources.find( ( s ) => s.id === state.sourceId ) || { id: job.source, label: job.source };
		const total = job.total === null || job.total === undefined ? null : Number( job.total );

		root.appendChild( el( 'section', { class: 'migrateplus-summary' }, [
			el( 'span', { class: 'migrateplus-source-icon migrateplus-icon-' + source.id, 'aria-hidden': 'true' } ),
			el( 'div', { class: 'migrateplus-summary-text' }, [
				el( 'strong', { text: 'Connected to ' + source.label } ),
				el( 'span', { text: ( total === null ? 'Unknown number of' : total.toLocaleString() ) + ' records, ' + state.fields.length + ' fields' + ( job.settings.file_url || job.settings.url || job.settings.site_url ? ' from ' + hostOf( job.settings.file_url || job.settings.url || job.settings.site_url ) : '' ) } ),
			] ),
			el( 'button', { type: 'button', class: 'button', text: isDropSource() ? 'Choose another file' : 'Change source', onclick: () => {
				state.step = 'source';
				state.error = '';
				render();
			} } ),
		] ) );

		if ( ! isExport( job ) ) {
			root.appendChild( renderSavedMappings() );
		}

		// Preview table.
		const cols = state.fields.slice( 0, 40 );
		// No records when a job's cached copy is gone: an empty table would only say "First 0 records".
		if ( state.records.length ) {
			root.appendChild( el( 'details', { class: 'migrateplus-panel migrateplus-preview-panel', open: true }, [
				el( 'summary', {}, [
					el( 'h2', { text: 'Preview' } ),
					el( 'span', { class: 'migrateplus-muted', text: 'First ' + state.records.length + ' records' + ( state.fields.length > cols.length ? ', ' + cols.length + ' of ' + state.fields.length + ' fields shown' : '' ) } ),
				] ),
				el( 'div', { class: 'migrateplus-preview-scroll', tabindex: 0, 'aria-label': 'Preview records' }, el( 'table', { class: 'migrateplus-preview' }, [
					el( 'thead', {}, el( 'tr', {}, cols.map( ( c ) => el( 'th', { scope: 'col', text: c } ) ) ) ),
					el( 'tbody', {}, state.records.map( ( r ) => el( 'tr', {}, cols.map( ( c ) => el( 'td', { title: r[ c ] || '', text: truncate( r[ c ], 80 ) } ) ) ) ) ),
				] ) ),
			] ) );
		}

		const grid = el( 'div', { class: 'migrateplus-grid' } );
		grid.appendChild( renderMappingCard() );
		grid.appendChild( renderOptionsCard() );
		root.appendChild( grid );

		const mapped = D.targets.filter( ( t ) => state.mapping.fields[ t.key ] ).length;
		const ready = !! ( state.mapping.fields.post_title || state.mapping.fields.post_content );
		root.appendChild( el( 'div', { class: 'migrateplus-actionbar' }, [
			el( 'span', { class: 'migrateplus-actionbar-status' + ( ready ? '' : ' is-warn' ), 'aria-live': 'polite', text: ready
				? mapped + ' of ' + D.targets.length + ' fields mapped'
				: 'Map the title or the content to continue' } ),
			el( 'div', { class: 'migrateplus-actionbar-buttons' }, [
				el( 'button', { type: 'button', class: 'button', disabled: state.busy, text: 'Dry run', title: 'Process everything without saving, to check the mapping', onclick: () => start( true ) } ),
				el( 'button', { type: 'button', class: 'button button-primary', disabled: state.busy, text: 'Start import', onclick: () => start( false ) } ),
			] ),
		] ) );
	}

	/**
	 * Save the mapping and options under a name, or load, rename or delete a saved one.
	 * Saved mappings are only for JSON that is not a MigratePlus export.
	 */
	function renderSavedMappings() {
		const list = state.savedMappings;
		const pick = list.find( ( m ) => m.name === state.savedPick ) || null;
		const say = ( type, text ) => {
			state.savedNotice = { type, text };
			render();
		};
		const refresh = ( d ) => {
			state.savedMappings = d.mappings || [];
			state.savedPick = d.name || '';
		};
		const select = el( 'select', { id: 'migrateplus-saved-select', 'aria-label': 'Load saved mapping', disabled: ! list.length, onchange: ( e ) => {
			state.savedPick = e.target.value;
			state.savedNotice = null;
			render();
		} }, [ el( 'option', { value: '', text: list.length ? 'Choose a saved mapping' : 'No saved mappings yet' } ) ].concat(
			list.map( ( m ) => el( 'option', { value: m.name, text: m.name, selected: pick && m.name === pick.name } ) )
		) );
		const save = () => {
			const name = ( state.savedName || '' ).trim() || ( pick ? pick.name : '' );
			if ( ! name ) {
				say( 'error', 'Give the mapping a name first.' );
				return;
			}
			const send = () => api( 'mapping_save', { job: state.job.id, name, mapping: mappingToSave(), options: state.options } ).then( ( d ) => {
				refresh( d );
				state.savedName = '';
				say( 'success', 'Saved as “' + d.name + '”. A file with the same fields will offer it.' );
			} ).catch( ( err ) => say( 'error', err.message ) );
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
				say( 'success', 'Renamed to “' + d.name + '”.' );
			} ).catch( ( err ) => say( 'error', err.message ) );
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
					say( 'success', 'Deleted “' + pick.name + '”.' );
				} ).catch( ( err ) => say( 'error', err.message ) );
			},
		} );
		return el( 'section', { class: 'migrateplus-panel migrateplus-saved' }, [
			el( 'h2', { text: 'Saved mappings' } ),
			el( 'p', { class: 'migrateplus-muted', text: 'Save this mapping and the import options to use them again for the next file from the same place. Saved mappings are shared by everyone who imports on this site.' } ),
			state.savedNotice ? notice( state.savedNotice.type, state.savedNotice.text ) : null,
			el( 'div', { class: 'migrateplus-saved-row' }, [
				select,
				el( 'button', { type: 'button', class: 'button', disabled: ! pick, text: 'Apply', onclick: () => applySavedMapping( pick ) } ),
				el( 'button', { type: 'button', class: 'button-link', disabled: ! pick, text: 'Rename', onclick: rename } ),
				el( 'button', { type: 'button', class: 'migrateplus-link-danger', disabled: ! pick, text: 'Delete', onclick: remove } ),
			] ),
			el( 'div', { class: 'migrateplus-saved-row' }, [
				el( 'input', { type: 'text', id: 'migrateplus-saved-name', 'aria-label': 'Name for this mapping', placeholder: pick ? pick.name : 'Name, for example Books feed', value: state.savedName, maxlength: 100, oninput: ( e ) => {
					state.savedName = e.target.value;
				} } ),
				el( 'button', { type: 'button', class: 'button', text: 'Save mapping', onclick: save } ),
			] ),
		] );
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

	function updateActionbar() {
		const status = root.querySelector( '.migrateplus-actionbar-status' );
		if ( ! status ) {
			return;
		}
		const mapped = D.targets.filter( ( t ) => state.mapping.fields[ t.key ] ).length;
		const ready = !! ( state.mapping.fields.post_title || state.mapping.fields.post_content );
		status.classList.toggle( 'is-warn', ! ready );
		status.textContent = ready ? mapped + ' of ' + D.targets.length + ' fields mapped' : 'Map the title or the content to continue';
	}

	function renderMappingCard() {
		const m = state.mapping;
		const rows = D.targets.map( ( t ) => {
			const sampleCell = el( 'td', { class: 'migrateplus-sample', text: truncate( sample( m.fields[ t.key ] ), 120 ) } );
			const row = el( 'tr', { class: m.fields[ t.key ] ? 'is-mapped' : '' } );
			[
				el( 'th', { scope: 'row' }, [ el( 'span', { text: t.label } ), t.help ? el( 'span', { class: 'migrateplus-help', text: t.help } ) : null ] ),
				el( 'td', { class: 'migrateplus-arrow', 'aria-hidden': 'true', text: '←' } ),
				el( 'td', {}, fieldSelect( m.fields[ t.key ] || '', ( v ) => {
					if ( v ) {
						m.fields[ t.key ] = v;
					} else {
						delete m.fields[ t.key ];
					}
					row.classList.toggle( 'is-mapped', !! v );
					sampleCell.textContent = truncate( sample( v ), 120 );
					updateActionbar();
				}, { nested: t.key === 'comments' } ) ),
				sampleCell,
			].forEach( ( c ) => row.appendChild( c ) );
			return row;
		} );

		const metaRows = m.meta.map( ( row, i ) => el( 'div', { class: 'migrateplus-pair' }, [
			el( 'input', { type: 'text', class: 'code', placeholder: 'meta_key', 'aria-label': 'Custom field name', value: row.key || '', oninput: ( e ) => {
				row.key = e.target.value;
			} } ),
			el( 'span', { class: 'migrateplus-arrow', 'aria-hidden': 'true', text: '←' } ),
			fieldSelect( row.source, ( v ) => {
				row.source = v;
			} ),
			el( 'button', { type: 'button', class: 'migrateplus-icon-btn', 'aria-label': 'Remove custom field', title: 'Remove', text: '×', onclick: () => {
				m.meta.splice( i, 1 );
				render();
			} } ),
		] ) );

		const taxes = postType( state.options.post_type ).taxonomies.filter( ( t ) => t.name !== 'category' && t.name !== 'post_tag' );
		const taxRows = m.taxonomies.map( ( row, i ) => el( 'div', { class: 'migrateplus-pair' }, [
			el( 'select', { 'aria-label': 'Taxonomy', onchange: ( e ) => {
				row.taxonomy = e.target.value;
			} }, [ el( 'option', { value: '', text: 'Choose taxonomy' } ) ].concat( taxes.map( ( t ) => el( 'option', { value: t.name, text: t.label + ' (' + t.name + ')', selected: t.name === row.taxonomy } ) ) ) ),
			el( 'span', { class: 'migrateplus-arrow', 'aria-hidden': 'true', text: '←' } ),
			fieldSelect( row.source, ( v ) => {
				row.source = v;
			} ),
			el( 'button', { type: 'button', class: 'migrateplus-icon-btn', 'aria-label': 'Remove taxonomy', title: 'Remove', text: '×', onclick: () => {
				m.taxonomies.splice( i, 1 );
				render();
			} } ),
		] ) );

		return el( 'section', { class: 'migrateplus-panel migrateplus-mapping' }, [
			el( 'header', { class: 'migrateplus-section-head' }, [
				el( 'h2', { text: 'Field mapping' } ),
				el( 'p', { class: 'migrateplus-muted', text: 'Pick which source field fills each WordPress field. Suggestions are already filled in.' } ),
			] ),
			el( 'table', { class: 'migrateplus-map-table' }, [
				el( 'thead', {}, el( 'tr', {}, [ el( 'th', { scope: 'col', text: 'WordPress field' } ), el( 'th', { 'aria-hidden': 'true' } ), el( 'th', { scope: 'col', text: 'Source field' } ), el( 'th', { scope: 'col', text: 'Sample value' } ) ] ) ),
				el( 'tbody', {}, rows ),
			] ),
			renderAcfSection(),
			el( 'div', { class: 'migrateplus-subsection' }, [
				el( 'h3', { text: 'Custom fields' } ),
				m.meta.length ? el( 'div', { class: 'migrateplus-pairs' }, metaRows ) : el( 'p', { class: 'migrateplus-muted', text: 'Save any source field as post meta. ACF field names work too.' } ),
				el( 'button', { type: 'button', class: 'button migrateplus-add', text: 'Add custom field', onclick: () => {
					m.meta.push( { key: '', source: '' } );
					render();
				} } ),
			] ),
			el( 'div', { class: 'migrateplus-subsection' }, [
				el( 'h3', { text: 'Other taxonomies' } ),
				taxes.length
					? ( m.taxonomies.length ? el( 'div', { class: 'migrateplus-pairs' }, taxRows ) : el( 'p', { class: 'migrateplus-muted', text: 'Assign terms in custom taxonomies registered for this post type.' } ) )
					: el( 'p', { class: 'migrateplus-muted', text: 'This post type has no other taxonomies.' } ),
				taxes.length ? el( 'button', { type: 'button', class: 'button migrateplus-add', text: 'Add taxonomy', onclick: () => {
					m.taxonomies.push( { taxonomy: '', source: '' } );
					render();
				} } ) : null,
			] ),
		] );
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

	function renderAcfSection() {
		if ( ! D.acf || ! D.acf.active ) {
			return null;
		}
		const pt = state.options.post_type;
		const fields = acfFields( pt );
		const m = state.mapping;
		const head = el( 'div', { class: 'migrateplus-acf-head' }, [
			el( 'h3', { text: 'ACF fields' } ),
			el( 'span', { class: 'migrateplus-badge', text: fields.length + ' field' + ( fields.length === 1 ? '' : 's' ) } ),
		] );
		if ( ! fields.length ) {
			return el( 'div', { class: 'migrateplus-subsection migrateplus-acf' }, [
				head,
				el( 'p', { class: 'migrateplus-muted', text: 'No ACF field groups are assigned to this post type. Assign one in ACF, or pick another post type under "Import as".' } ),
			] );
		}
		const groups = {};
		fields.forEach( ( f ) => {
			( groups[ f.group ] = groups[ f.group ] || [] ).push( f );
		} );
		const body = [];
		Object.entries( groups ).forEach( ( [ group, list ] ) => {
			body.push( el( 'tr', { class: 'migrateplus-acf-group' }, el( 'th', { colspan: 4, scope: 'colgroup', text: group } ) ) );
			list.forEach( ( f ) => {
				const current = m.acf[ f.key ] || m.acf[ f.name ] || '';
				const sampleCell = el( 'td', { class: 'migrateplus-sample', text: truncate( sample( current ), 120 ) } );
				const row = el( 'tr', { class: current ? 'is-mapped' : '' } );
				[
					el( 'th', { scope: 'row' }, [
						el( 'span', { text: f.label + ( f.required ? ' *' : '' ) } ),
						el( 'span', { class: 'migrateplus-help' }, [
							el( 'code', { text: f.name } ),
							' ' + ( ACF_TYPE_LABELS[ f.type ] || f.type ),
							f.sub && f.sub.length ? ' (' + f.sub.join( ', ' ) + ')' : '',
						] ),
					] ),
					el( 'td', { class: 'migrateplus-arrow', 'aria-hidden': 'true', text: '←' } ),
					el( 'td', {}, [
						fieldSelect( current, ( v ) => {
							delete m.acf[ f.name ];
							if ( v ) {
								m.acf[ f.key ] = v;
							} else {
								delete m.acf[ f.key ];
							}
							row.classList.toggle( 'is-mapped', !! v );
							sampleCell.textContent = truncate( sample( v ), 120 );
						}, { nested: true, label: f.label } ),
						ACF_HINTS[ f.type ] ? el( 'span', { class: 'migrateplus-help', text: ACF_HINTS[ f.type ] } ) : null,
					] ),
					sampleCell,
				].forEach( ( c ) => row.appendChild( c ) );
				body.push( row );
			} );
		} );
		return el( 'div', { class: 'migrateplus-subsection migrateplus-acf' }, [
			head,
			el( 'p', { class: 'migrateplus-muted', text: 'Values are converted for each field type: image URLs are downloaded, related posts and terms are looked up, dates and choices are normalised.' } ),
			el( 'table', { class: 'migrateplus-map-table' }, el( 'tbody', {}, body ) ),
		] );
	}

	function renderOptionsCard() {
		const o = state.options;
		const set = ( k ) => ( e ) => {
			o[ k ] = e.target.type === 'checkbox' ? ( e.target.checked ? 1 : 0 ) : e.target.value;
		};
		let uid = 0;
		const toggle = ( k, label, help ) => {
			const id = 'migrateplus-opt-' + k;
			return el( 'div', { class: 'migrateplus-toggle' }, [
				el( 'input', { type: 'checkbox', role: 'switch', id, class: 'migrateplus-switch', checked: !! Number( o[ k ] ), onchange: set( k ) } ),
				el( 'label', { for: id }, [ el( 'span', { text: label } ), help ? el( 'span', { class: 'migrateplus-help', text: help } ) : null ] ),
			] );
		};
		const row = ( label, control, help ) => {
			const id = 'migrateplus-opt-row-' + ( ++uid );
			control.id = id;
			return el( 'div', { class: 'migrateplus-opt' }, [
				el( 'label', { class: 'migrateplus-opt-label', for: id, text: label } ),
				control,
				help ? el( 'span', { class: 'migrateplus-help', text: help } ) : null,
			] );
		};

		return el( 'aside', { class: 'migrateplus-panel migrateplus-options' }, [
			el( 'header', { class: 'migrateplus-section-head' }, [
				el( 'h2', { text: 'Import options' } ),
				D.settingsUrl ? el( 'p', { class: 'migrateplus-muted' }, [ 'Defaults come from ', el( 'a', { href: D.settingsUrl, text: 'Settings' } ), '.' ] ) : null,
			] ),
			row( 'Import as', el( 'select', { onchange: ( e ) => {
				o.post_type = e.target.value;
				render();
			} }, D.postTypes.map( ( p ) => el( 'option', { value: p.name, text: p.label + ' (' + p.name + ')', selected: p.name === o.post_type } ) ) ), 'Used for every record, unless "Post type" is mapped and names a type this site has.' ),
			row( 'Default status', el( 'select', { onchange: set( 'default_status' ) }, [ [ 'publish', 'Published' ], [ 'draft', 'Draft' ], [ 'pending', 'Pending review' ], [ 'private', 'Private' ] ].map( ( [ v, l ] ) =>
				el( 'option', { value: v, text: l, selected: v === o.default_status } ) ) ), 'Used when a record has no status, or one MigratePlus does not recognise.' ),
			row( 'Status for every item', el( 'select', { onchange: set( 'force_status' ) }, [ [ '', 'Keep each record’s status' ], [ 'publish', 'Published' ], [ 'draft', 'Draft' ], [ 'pending', 'Pending review' ], [ 'private', 'Private' ] ].map( ( [ v, l ] ) =>
				el( 'option', { value: v, text: l, selected: v === ( o.force_status || '' ) } ) ) ), 'Overrides the status in the file, also for posts that are updated. Published posts with a future date are scheduled.' ),
			row( 'Default author', el( 'select', { onchange: set( 'default_author' ) }, D.users.map( ( u ) =>
				el( 'option', { value: u.id, text: u.name, selected: Number( u.id ) === Number( o.default_author ) } ) ) ) ),
			row( 'Records imported before', el( 'select', { onchange: set( 'duplicate' ) }, [
				[ 'skip', 'Skip them' ], [ 'update', 'Update them' ], [ 'create', 'Create duplicates' ],
			].map( ( [ v, l ] ) => el( 'option', { value: v, text: l, selected: v === o.duplicate } ) ) ), 'Matched by the mapped Source ID.' ),
			row( 'Batch size', el( 'input', { type: 'number', min: 1, max: 100, value: o.batch_size, onchange: set( 'batch_size' ) } ), 'Records per request. Lower it if image downloads time out.' ),
			el( 'div', { class: 'migrateplus-toggles' }, [
				toggle( 'import_featured_image', 'Download featured images' ),
				toggle( 'import_content_images', 'Download images in content', 'Copies <img> files into the Media Library and rewrites their URLs.' ),
				toggle( 'create_terms', 'Create missing categories and terms' ),
				toggle( 'create_authors', 'Create missing authors', 'New users get the Author role and a random password.' ),
				toggle( 'rewrite_links', 'Point links to the old site here', 'MigratePlus exports only. Files in wp-content keep their old URLs unless images in content are downloaded.' ),
				toggle( 'import_comments', 'Import comments', 'MigratePlus exports that include comments. Importing the file again updates them instead of adding them twice.' ),
				toggle( 'import_menus', 'Import menus', 'MigratePlus exports that include menus, after all posts. A menu with the same slug is filled rather than copied.' ),
			] ),
		] );
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
			const ask = ( d ) => modal.open( Object.assign( {
				kind: 'confirm',
				icon: 'file',
				bar: false,
				dismissible: true,
				title: 'Some items are already here',
				message: duplicateMessage( d, 'of ' + Number( d.total ).toLocaleString() + ' records' ) + changedMessage( d ),
				actions: duplicateActions( d, () => start( false, true ), () => ask( d ) ).concat( { label: 'Cancel', onClick: () => modal.close() } ),
			}, duplicateSummary( d ) ) );
			checkDuplicates().then( ( d ) => {
				if ( ! d.count ) {
					start( false, true );
					return;
				}
				ask( d );
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
			modal.open( runModalOptions() );
			render();
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

	function renderRun() {
		const job = state.job;
		const s = job.stats;
		const total = job.total ? Number( job.total ) : 0;
		const processed = Number( s.processed ) || 0;
		const dry = Number( job.options.dry_run ) === 1;
		const done = [ 'complete', 'rolled_back' ].includes( job.status );
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

		const actions = el( 'div', { class: 'migrateplus-actions' } );
		if ( ! done && 'draft' !== job.status ) {
			actions.appendChild( state.paused
				? el( 'button', { type: 'button', class: 'button button-primary', text: 'Resume', disabled: state.running, onclick: resume } )
				: el( 'button', { type: 'button', class: 'button', text: 'Pause', onclick: pause } ) );
			if ( ! modal.isOpen() ) {
				actions.appendChild( el( 'button', { type: 'button', class: 'button', text: 'Show progress window', onclick: showRunModal } ) );
			}
		} else {
			if ( dry ) {
				actions.appendChild( el( 'button', { type: 'button', class: 'button button-primary', text: 'Run the real import', onclick: () => start( false ) } ) );
				actions.appendChild( el( 'button', { type: 'button', class: 'button', text: 'Adjust mapping', onclick: () => {
					state.step = 'map';
					render();
				} } ) );
			} else if ( job.status === 'complete' ) {
				actions.appendChild( el( 'a', { class: 'button button-primary', href: contentUrl( job ), text: 'View imported content' } ) );
			}
			actions.appendChild( el( 'button', { type: 'button', class: 'button', text: 'Import', onclick: reset } ) );
			if ( ! dry && job.status === 'complete' && importedCount( job ) > 0 ) {
				actions.appendChild( el( 'button', { type: 'button', class: 'button migrateplus-danger migrateplus-push', text: 'Roll back this import', onclick: () => rollback( job.id ) } ) );
			}
		}

		root.appendChild( el( 'section', { class: 'migrateplus-panel migrateplus-run' + ( tone ? ' is-' + tone : '' ) }, [
			el( 'div', { class: 'migrateplus-run-head' }, [
				el( 'div', { class: 'migrateplus-run-figure', 'aria-hidden': 'true' }, pct === null
					? el( 'span', { class: 'migrateplus-run-count', text: processed.toLocaleString() } )
					: [ el( 'span', { class: 'migrateplus-run-count', text: String( pct ) } ), el( 'span', { class: 'migrateplus-run-unit', text: '%' } ) ] ),
				el( 'div', { class: 'migrateplus-run-text' }, [
					el( 'h2', { 'aria-live': 'polite', text: statusText } ),
					el( 'p', { class: 'migrateplus-muted', text: processed.toLocaleString() + ( total ? ' of ' + total.toLocaleString() : '' ) + ' records processed' + ( dry ? ' (dry run)' : '' ) } ),
					sectionsText( job ) ? el( 'p', { class: 'migrateplus-muted', text: sectionsText( job ) } ) : null,
				] ),
			] ),
			outcomeBar( s, total, { size: 'lg', indeterminate: ! done && ! total && ! state.paused, tone, label: 'Import progress' } ),
			outcomeLegend( s, dry ),
			actions,
		] ) );

		// Log with filters and search.
		const counts = { all: 0, error: 0, warning: 0, success: 0 };
		( job.log || [] ).forEach( ( e ) => {
			counts.all++;
			if ( counts[ e.l ] !== undefined ) {
				counts[ e.l ]++;
			}
		} );
		const list = el( 'ol', { class: 'migrateplus-log' } );
		const fillLog = () => {
			list.innerHTML = '';
			const q = state.logQuery.trim().toLowerCase();
			const entries = ( job.log || [] ).slice().reverse().filter( ( e ) =>
				( state.logFilter === 'all' || e.l === state.logFilter ) && ( ! q || String( e.m ).toLowerCase().includes( q ) )
			);
			entries.forEach( ( e ) => list.appendChild( el( 'li', { class: 'migrateplus-log-' + e.l }, [
				el( 'time', { datetime: new Date( e.t * 1000 ).toISOString(), text: new Date( e.t * 1000 ).toLocaleTimeString() } ),
				el( 'span', { text: e.m } ),
			] ) ) );
			if ( ! entries.length ) {
				list.appendChild( el( 'li', { class: 'migrateplus-log-empty', text: counts.all ? 'No log lines match.' : 'Waiting for the first batch…' } ) );
			}
		};
		const chip = ( key, label ) => el( 'button', {
			type: 'button',
			class: 'migrateplus-chip' + ( state.logFilter === key ? ' is-active' : '' ),
			'aria-pressed': state.logFilter === key ? 'true' : 'false',
			onclick: ( e ) => {
				state.logFilter = key;
				Array.from( e.currentTarget.parentNode.children ).forEach( ( b ) => {
					b.classList.toggle( 'is-active', b === e.currentTarget );
					b.setAttribute( 'aria-pressed', b === e.currentTarget ? 'true' : 'false' );
				} );
				fillLog();
			},
		}, [ label, el( 'span', { class: 'migrateplus-chip-count', text: String( counts[ key === 'all' ? 'all' : key ] || 0 ) } ) ] );

		fillLog();
		root.appendChild( el( 'section', { class: 'migrateplus-panel migrateplus-log-panel' }, [
			el( 'header', { class: 'migrateplus-log-head' }, [
				el( 'h2', { text: 'Log' } ),
				el( 'div', { class: 'migrateplus-chips', role: 'group', 'aria-label': 'Filter log' }, [
					chip( 'all', 'All' ), chip( 'success', 'Saved' ), chip( 'warning', 'Warnings' ), chip( 'error', 'Errors' ),
				] ),
				el( 'input', { type: 'search', id: 'migrateplus-log-search', class: 'migrateplus-search', placeholder: 'Search the log', 'aria-label': 'Search the log', value: state.logQuery, oninput: ( e ) => {
					state.logQuery = e.target.value;
					fillLog();
				} } ),
			] ),
			list,
		] ) );

		if ( modal.kind() === 'run' ) {
			modal.update( runModalOptions() );
		}
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
		render();
	}

	function showRunModal() {
		state.modalHidden = false;
		modal.open( runModalOptions() );
		render();
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
					{ label: 'Close', onClick: () => {
						modal.close();
						render();
					} },
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
			onDismiss: done ? () => {
				modal.close();
				render();
			} : hideRunModal,
		};
	}

	function runLoop() {
		if ( state.running ) {
			return;
		}
		state.running = true;
		state.runClock = { t: Date.now(), processed: Number( state.job.stats.processed ) || 0 };
		const tick = () => {
			if ( state.paused ) {
				state.running = false;
				render();
				return;
			}
			api( 'run', { job: state.job.id } ).then( ( data ) => {
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
				if ( /already running/i.test( err.message ) ) {
					setTimeout( tick, 3000 );
					return;
				}
				state.running = false;
				state.paused = true;
				state.error = err.message + ' Fix the problem, then press Resume to pick up where it stopped.';
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
				renderHistory();
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
		const step = () => api( 'rollback', { job: jobId } ).then( ( data ) => {
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
			return step();
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
						step();
					} },
					{ label: 'Close', onClick: () => {
						modal.close();
						refresh();
					} },
				],
			} );
		} );
		step();
	}

	// Export

	const EXPORT_STATUSES = [ [ 'publish', 'Published' ], [ 'draft', 'Draft' ], [ 'pending', 'Pending review' ], [ 'private', 'Private' ], [ 'future', 'Scheduled' ] ];

	function renderExport() {
		const x = state.exportArgs = state.exportArgs || Object.assign( {}, D.exportDefaults, {
			statuses: ( D.exportDefaults.statuses || [ 'publish' ] ).slice(),
			post_types: ( D.exportDefaults.post_types || [ 'post' ] ).slice(),
		} );
		const countEl = el( 'span', { class: 'migrateplus-export-count', 'aria-live': 'polite', text: 'Counting…' } );
		const submit = el( 'button', { type: 'submit', class: 'button button-primary migrateplus-btn-lg', text: 'Export' } );
		let timer = null;
		const recount = () => {
			clearTimeout( timer );
			countEl.textContent = 'Counting…';
			timer = setTimeout( () => {
				api( 'export_count', { args: x } ).then( ( d ) => {
					const counts = Object.entries( d.counts || {} );
					const each = counts.length > 1 ? ' (' + counts.map( ( [ type, n ] ) => n.toLocaleString() + ' ' + ( 1 === n ? postType( type ).label : postType( type ).plural || postType( type ).label ).toLowerCase() ).join( ', ' ) + ')' : '';
					const menus = d.menus ? ', with ' + d.menus + ( 1 === d.menus ? ' menu' : ' menus' ) : '';
					countEl.textContent = ! d.count && d.menus
						? 'No content matches: only ' + d.menus + ( 1 === d.menus ? ' menu' : ' menus' ) + ' will be exported'
						: d.count.toLocaleString() + ' ' + ( d.count === 1 && 1 === counts.length ? postType( counts[ 0 ][ 0 ] ).label.toLowerCase() : 'items' ) + each + menus + ' will be exported';
					countEl.classList.toggle( 'is-warn', ! d.count );
					submit.disabled = ! d.count && ! d.menus; // Menus alone are worth a file.
				} ).catch( ( e ) => {
					countEl.textContent = e.message;
					countEl.classList.add( 'is-warn' );
				} );
			}, 250 );
		};
		const set = ( k, v ) => {
			x[ k ] = v;
			recount();
		};
		const toggle = ( k, label, help, disabled ) => {
			const id = 'migrateplus-export-' + k;
			return el( 'div', { class: 'migrateplus-toggle' + ( disabled ? ' is-disabled' : '' ) }, [
				el( 'input', { type: 'checkbox', role: 'switch', id, class: 'migrateplus-switch', disabled, checked: ! disabled && !! Number( x[ k ] ), onchange: ( e ) => set( k, e.target.checked ? 1 : 0 ) } ),
				el( 'label', { for: id }, [ el( 'span', { text: label } ), help ? el( 'span', { class: 'migrateplus-help', text: help } ) : null ] ),
			] );
		};
		const acfCount = x.post_types.reduce( ( n, t ) => n + acfFields( t ).length, 0 );
		// Statuses, featured images and the like don't apply to media.
		const isMedia = x.post_types.every( ( t ) => t === 'attachment' );

		const form = el( 'form', { class: 'migrateplus-panel migrateplus-export', onsubmit: ( e ) => {
			e.preventDefault();
			runExport();
		} }, [
			el( 'div', { class: 'migrateplus-export-grid' }, [
				el( 'section', {}, [
					el( 'h2', { text: 'What to export' } ),
					el( 'fieldset', { class: 'migrateplus-opt' }, [
						el( 'legend', { class: 'migrateplus-opt-label', text: 'Content types' } ),
						el( 'div', { class: 'migrateplus-chips' }, D.postTypes.map( ( p ) => {
							const id = 'migrateplus-export-type-' + p.name;
							return el( 'label', { class: 'migrateplus-check-chip', for: id, title: p.name }, [
								el( 'input', { type: 'checkbox', id, checked: x.post_types.includes( p.name ), onchange: ( e ) => {
									const picked = e.target.checked ? x.post_types.concat( p.name ) : x.post_types.filter( ( t ) => t !== p.name );
									x.post_types = D.postTypes.map( ( t ) => t.name ).filter( ( t ) => picked.includes( t ) ); // In the order shown.
									if ( ! x.post_types.length ) {
										x.post_types = [ p.name ];
										e.target.checked = true;
										return;
									}
									render();
								} } ),
								el( 'span', { text: p.label } ),
							] );
						} ) ),
						x.post_types.length > 1 ? el( 'span', { class: 'migrateplus-help', text: 'One file, imported in one go: media first, so featured images and ACF images reuse the files it brings, then the rest in the order shown.' } ) : null,
					] ),
					isMedia ? null : el( 'fieldset', { class: 'migrateplus-opt' }, [
						el( 'legend', { class: 'migrateplus-opt-label', text: 'Status' } ),
						el( 'div', { class: 'migrateplus-chips' }, EXPORT_STATUSES.map( ( [ v, l ] ) => {
							const id = 'migrateplus-export-status-' + v;
							return el( 'label', { class: 'migrateplus-check-chip', for: id }, [
								el( 'input', { type: 'checkbox', id, checked: x.statuses.includes( v ), onchange: ( e ) => {
									x.statuses = e.target.checked ? x.statuses.concat( v ) : x.statuses.filter( ( s ) => s !== v );
									if ( ! x.statuses.length ) {
										x.statuses = [ v ];
										e.target.checked = true;
										return;
									}
									recount();
								} } ),
								el( 'span', { text: l } ),
							] );
						} ) ),
					] ),
					el( 'fieldset', { class: 'migrateplus-opt' }, [
						el( 'legend', { class: 'migrateplus-opt-label', text: isMedia ? 'Uploaded between' : 'Published between' } ),
						el( 'div', { class: 'migrateplus-date-range' }, [
							el( 'input', { type: 'date', 'aria-label': 'From', value: x.after, onchange: ( e ) => set( 'after', e.target.value ) } ),
							el( 'span', { class: 'migrateplus-muted', text: 'and' } ),
							el( 'input', { type: 'date', 'aria-label': 'To', value: x.before, onchange: ( e ) => set( 'before', e.target.value ) } ),
						] ),
						el( 'span', { class: 'migrateplus-help', text: 'Leave empty to export everything.' } ),
					] ),
				] ),
				el( 'section', {}, [
					el( 'h2', { text: 'Include' } ),
					el( 'div', { class: 'migrateplus-toggles is-flush' }, [
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
						D.zlib ? toggle( 'gzip', 'Compress the file (.json.gz)', 'Much smaller, for a site whose upload limit is lower than the export. MigratePlus imports it as it is.' ) : null,
					] ),
				] ),
			] ),
			el( 'footer', { class: 'migrateplus-panel-foot' }, [ countEl, submit ] ),
		] );
		root.appendChild( form );

		if ( state.lastExport ) {
			const le = state.lastExport;
			root.appendChild( el( 'section', { class: 'migrateplus-summary' }, [
				el( 'span', { class: 'migrateplus-source-icon migrateplus-icon-json', 'aria-hidden': 'true' } ),
				el( 'div', { class: 'migrateplus-summary-text' }, [
					el( 'strong', { text: le.filename } ),
					el( 'span', { text: le.done.toLocaleString() + ' items, ' + le.size + '. The download link works for 24 hours.' } ),
				] ),
				el( 'a', { class: 'button button-primary', href: le.url, text: 'Download' } ),
			] ) );
		}
		recount();
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
		const fail = ( err ) => modal.update( {
			title: 'Export stopped',
			message: '',
			tone: 'error',
			detail: '',
			note: err.message,
			dismissible: true,
			actions: [ { label: 'Close', primary: true, onClick: () => modal.close() } ],
		} );
		const step = ( id ) => api( 'export_run', { export: id } ).then( ( d ) => {
			if ( d.error ) {
				fail( { message: d.error } );
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
					message: 'Import it on the other site with MigratePlus → Import. The fields map themselves.',
					percent: 100,
					tone: 'success',
					detail: d.done.toLocaleString() + ' items' + ( extras.length ? ' with ' + extras.join( ' and ' ) : '' ) + ', ' + d.size,
					dismissible: true,
					onDismiss: null,
					actions: [
						{ label: 'Download ' + ( /\.json\.gz$/.test( d.filename ) ? '.json.gz' : 'JSON' ), primary: true, href: d.url },
						{ label: 'Close', onClick: () => {
							modal.close();
							render();
						} },
					],
				} );
				render();
				return;
			}
			modal.update( {
				percent: d.total ? ( d.done / d.total ) * 100 : null,
				detail: d.done.toLocaleString() + ' of ' + d.total.toLocaleString() + ' items' + ( d.section ? ', now ' + d.section.toLowerCase() : '' ),
			} );
			return step( id );
		} );
		api( 'export_start', { args: x } ).then( ( d ) => {
			modal.update( { percent: 0, detail: '0 of ' + d.total.toLocaleString() + ' items' } );
			return step( d.id );
		} ).catch( fail );
	}

	// Find & Replace

	function renderReplace() {
		const r = state.replace = state.replace || {
			search: '',
			replace: '',
			tables: ( D.tables || [] ).map( ( t ) => t.name ),
			case_insensitive: 0,
			whole_urls: 1,
			guids: 0,
			dry_run: 1,
			result: null,
		};
		const submit = el( 'button', { type: 'submit', class: 'button button-primary migrateplus-btn-lg', text: r.dry_run ? 'Run dry run' : 'Replace' } );
		const tableCount = el( 'span', { class: 'migrateplus-help' } );
		const errorSlot = el( 'div', { 'aria-live': 'assertive' } );
		const sync = () => {
			tableCount.textContent = r.tables.length + ' of ' + ( D.tables || [] ).length + ' tables selected';
			submit.textContent = r.dry_run ? 'Run dry run' : 'Replace';
			// Drop a validation message once what it complained about is fixed.
			if ( errorSlot.firstChild && r.search && r.tables.length ) {
				errorSlot.innerHTML = '';
			}
		};
		const toggle = ( k, label, help ) => {
			const id = 'migrateplus-replace-' + k;
			return el( 'div', { class: 'migrateplus-toggle' }, [
				el( 'input', { type: 'checkbox', role: 'switch', id, class: 'migrateplus-switch', checked: !! r[ k ], onchange: ( e ) => {
					r[ k ] = e.target.checked ? 1 : 0;
					sync();
				} } ),
				el( 'label', { for: id }, [ el( 'span', { text: label } ), help ? el( 'span', { class: 'migrateplus-help', text: help } ) : null ] ),
			] );
		};
		const text = ( k, label, placeholder ) => el( 'div', { class: 'migrateplus-opt' }, [
			el( 'label', { class: 'migrateplus-opt-label', for: 'migrateplus-replace-' + k, text: label } ),
			el( 'input', { type: 'text', id: 'migrateplus-replace-' + k, class: 'migrateplus-code-input', value: r[ k ], placeholder, autocomplete: 'off', spellcheck: 'false', oninput: ( e ) => {
				r[ k ] = e.target.value;
				sync();
			} } ),
		] );
		const boxes = ( D.tables || [] ).map( ( t ) => {
			const id = 'migrateplus-table-' + t.name;
			return el( 'label', { class: 'migrateplus-table-option', for: id }, [
				el( 'input', { type: 'checkbox', id, value: t.name, checked: r.tables.includes( t.name ), onchange: ( e ) => {
					r.tables = e.target.checked ? r.tables.concat( t.name ) : r.tables.filter( ( n ) => n !== t.name );
					sync();
				} } ),
				el( 'code', { text: t.name } ),
				el( 'span', { class: 'migrateplus-help', text: '~' + t.rows.toLocaleString() + ' rows, ' + formatBytes( t.size ) } ),
			] );
		} );
		const selectAll = ( on ) => {
			r.tables = on ? ( D.tables || [] ).map( ( t ) => t.name ) : [];
			boxes.forEach( ( b ) => {
				b.firstChild.checked = on;
			} );
			sync();
		};

		root.appendChild( el( 'form', { class: 'migrateplus-panel migrateplus-export migrateplus-replace', onsubmit: ( e ) => {
			e.preventDefault();
			const problem = ! r.search ? 'Enter the text to search for.' : ( ! r.tables.length ? 'No database tables are selected. Please select at least one.' : '' );
			errorSlot.innerHTML = '';
			if ( problem ) {
				errorSlot.appendChild( notice( 'error', problem ) );
				return;
			}
			runReplace();
		} }, [
			errorSlot,
			el( 'div', { class: 'migrateplus-export-grid' }, [
				el( 'section', {}, [
					el( 'h2', { text: 'Find and replace' } ),
					text( 'search', 'Search for', 'http://old-site.test' ),
					text( 'replace', 'Replace with', 'https://new-site.com' ),
					el( 'div', { class: 'migrateplus-toggles is-flush' }, [
						toggle( 'dry_run', 'Dry run', 'Count and preview the changes without saving anything.' ),
						toggle( 'whole_urls', 'Whole URLs only', 'When you search for a web address or domain, only that exact site matches: http://old.test is not changed inside http://old.test.example.com or http://old.testing.com, and old.test is not changed inside sub.old.test.' ),
						toggle( 'case_insensitive', 'Ignore case', 'Match "Example" and "example" alike.' ),
						toggle( 'guids', 'Replace GUIDs', 'Post GUIDs are permanent IDs that feed readers rely on. Leave this off unless you know you need it.' ),
					] ),
					el( 'p', { class: 'migrateplus-help', text: 'MigratePlus\'s own history and tracking data are left alone, so later imports still recognise content they brought in.' } ),
				] ),
				el( 'section', {}, [
					el( 'h2', { text: 'Tables' } ),
					el( 'div', { class: 'migrateplus-table-tools' }, [
						tableCount,
						el( 'button', { type: 'button', class: 'button-link', text: 'All', onclick: () => selectAll( true ) } ),
						el( 'button', { type: 'button', class: 'button-link', text: 'None', onclick: () => selectAll( false ) } ),
					] ),
					el( 'div', { class: 'migrateplus-table-list' }, boxes ),
				] ),
			] ),
			el( 'footer', { class: 'migrateplus-panel-foot' }, [
				el( 'span', { class: 'migrateplus-help', text: 'Real runs keep the original values so they can be rolled back. A database backup is still the safest net.' } ),
				submit,
			] ),
		] ) );
		sync();

		if ( r.result ) {
			drawReplaceResult( r.result );
		}
		drawReplaceRuns();
	}

	/** Real runs, with rollback. Dry runs change nothing, so they are not listed. */
	function drawReplaceRuns() {
		const wrap = el( 'section', { class: 'migrateplus-panel migrateplus-history-panel migrateplus-replace-runs' } );
		root.appendChild( wrap );
		if ( ! state.replaceRuns ) {
			wrap.appendChild( el( 'p', { class: 'migrateplus-muted migrateplus-loading', text: 'Loading past replacements…' } ) );
			api( 'replace_history', {} ).then( ( d ) => {
				state.replaceRuns = d.runs;
				wrap.remove();
				drawReplaceRuns();
			} ).catch( ( err ) => {
				wrap.innerHTML = '';
				wrap.appendChild( notice( 'error', err.message ) );
			} );
			return;
		}
		const runs = state.replaceRuns;
		wrap.appendChild( el( 'header', { class: 'migrateplus-history-head' }, [
			el( 'h2', { class: 'migrateplus-history-title', text: 'Past replacements' } ),
			el( 'span', { class: 'migrateplus-muted', text: runs.length ? runs.length + ' run' + ( runs.length === 1 ? '' : 's' ) : '' } ),
		] ) );
		if ( ! runs.length ) {
			wrap.appendChild( el( 'p', { class: 'migrateplus-muted migrateplus-loading', text: 'Replacements you run for real show up here, and can be rolled back.' } ) );
			return;
		}
		const labels = { running: 'Running', complete: 'Complete', stopped: 'Stopped', failed: 'Failed', rolling_back: 'Rolling back', rollback_failed: 'Rollback incomplete', rolled_back: 'Rolled back' };
		const body = el( 'tbody' );
		runs.forEach( ( run ) => {
			const actions = el( 'div', { class: 'migrateplus-row-actions' } );
			if ( run.can_resume ) {
				actions.appendChild( el( 'button', { type: 'button', class: 'button button-small button-primary', text: 'Resume', onclick: () => runReplace( run ) } ) );
			}
			if ( run.can_undo ) {
				actions.appendChild( el( 'button', { type: 'button', class: 'button button-small migrateplus-danger', text: { rolling_back: 'Finish rollback', rollback_failed: 'Retry rollback' }[ run.status ] || 'Roll back', onclick: () => rollbackReplace( run ) } ) );
			}
			actions.appendChild( el( 'button', { type: 'button', class: 'migrateplus-link-danger', text: 'Delete', title: 'Remove from this list. The changes stay, but can no longer be rolled back.', onclick: () => ask( {
				title: 'Delete this run?',
				message: run.status === 'rolled_back' ? 'Remove this run from the list?' : 'Remove this run from the list? Its changes stay in the database, but can no longer be rolled back.',
				label: 'Delete',
				danger: true,
				onConfirm: () => api( 'replace_forget', { run: run.id } ).then( () => {
					modal.close();
					state.replaceRuns = state.replaceRuns.filter( ( x ) => x.id !== run.id );
					render();
				} ).catch( ( err ) => modalError( 'Could not delete', err ) ),
			} ) } ) );
			let result = run.changes.toLocaleString() + ' replacement' + ( run.changes === 1 ? '' : 's' ) + ' in ' + run.rows.toLocaleString() + ' row' + ( run.rows === 1 ? '' : 's' );
			if ( run.status === 'rolled_back' ) {
				result = run.restored.toLocaleString() + ' value' + ( run.restored === 1 ? '' : 's' ) + ' restored' + ( run.kept ? ', ' + run.kept.toLocaleString() + ' kept because they were edited since' : '' );
			}
			body.appendChild( el( 'tr', {}, [
				el( 'td', {}, [
					el( 'code', { class: 'migrateplus-replace-pair', text: run.search + ' → ' + run.replace } ),
					el( 'span', { class: 'migrateplus-help' }, [
						run.user ? run.user + ', ' : '',
						el( 'time', { datetime: new Date( run.created * 1000 ).toISOString(), title: formatDate( run.created ), text: relativeTime( run.created ) } ),
					] ),
				] ),
				el( 'td', {}, el( 'span', { class: 'migrateplus-badge migrateplus-badge-' + run.status, text: labels[ run.status ] || run.status } ) ),
				el( 'td', { class: 'migrateplus-h-result' }, el( 'span', { class: 'migrateplus-help', text: result } ) ),
				el( 'td', {}, actions ),
			] ) );
		} );
		wrap.appendChild( el( 'div', { class: 'migrateplus-table-scroll' }, el( 'table', { class: 'migrateplus-history' }, [
			el( 'thead', {}, el( 'tr', {}, [ 'Replacement', 'Status', 'Result', '' ].map( ( h ) => el( 'th', { scope: 'col', text: h } ) ) ) ),
			body,
		] ) ) );
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
		const done = () => {
			state.replaceRuns = null;
			render();
		};
		const step = () => api( 'replace_rollback', { run: run.id } ).then( ( d ) => {
			if ( d.status !== 'rolled_back' ) {
				modal.update( {
					percent: d.size ? ( d.undo_offset / d.size ) * 100 : null,
					detail: d.restored.toLocaleString() + ' values restored',
				} );
				return step();
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
			done();
		} );
		step().catch( ( err ) => {
			modal.update( {
				title: 'Rollback stopped',
				message: 'Run it again to continue where it stopped.',
				tone: 'error',
				note: err.message,
				dismissible: true,
				actions: [ { label: 'Close', primary: true, onClick: () => modal.close() } ],
			} );
			done();
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
		const fail = ( err ) => modal.update( {
			title: 'Find and replace stopped',
			message: '',
			tone: 'error',
			note: err.message,
			dismissible: true,
			actions: [ { label: 'Close', primary: true, onClick: () => modal.close() } ],
		} );
		const step = ( id ) => api( 'replace_run', { run: id } ).then( ( d ) => {
			if ( ! d.complete ) {
				modal.update( {
					percent: d.total ? Math.min( 99, ( d.done / d.total ) * 100 ) : null,
					detail: d.table + ': ' + d.done.toLocaleString() + ' of about ' + d.total.toLocaleString() + ' rows',
				} );
				return step( id );
			}
			const sum = d.tables.reduce( ( a, t ) => ( { rows: a.rows + t.rows, changes: a.changes + t.changes } ), { rows: 0, changes: 0 } );
			r.result = Object.assign( d, { args } );
			if ( ! d.dry_run ) {
				state.replaceRuns = null; // Refresh the list of past runs.
			}
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
					render();
					const res = root.querySelector( '.migrateplus-replace-results' );
					if ( res ) {
						res.scrollIntoView( { behavior: 'smooth' } );
					}
				} } ],
			} );
			render();
		} );
		( resumeRun ? step( resumeRun.id ) : api( 'replace_start', { args } ).then( ( d ) => step( d.id ) ) ).catch( fail );
	}

	function drawReplaceResult( d ) {
		const a = d.args;
		const changed = d.tables.filter( ( t ) => t.changes );
		const skipped = d.tables.filter( ( t ) => t.skipped );
		const unsafe = d.tables.filter( ( t ) => t.unsafe );
		const body = el( 'tbody' );
		changed.forEach( ( t ) => {
			const detail = el( 'tr', { class: 'migrateplus-export-log-row', hidden: true }, el( 'td', { colspan: 4, class: 'migrateplus-export-log' },
				el( 'ol', { class: 'migrateplus-log migrateplus-replace-samples' }, t.samples.map( ( s ) => el( 'li', {}, [
					el( 'span', { class: 'migrateplus-help', text: s.column + ' (row ' + s.row + ')' } ),
					el( 'del', { text: ( s.cut[ 0 ] ? '…' : '' ) + s.before + ( s.cut[ 1 ] ? '…' : '' ) } ),
					el( 'ins', { text: ( s.cut[ 0 ] ? '…' : '' ) + s.after + ( s.cut[ 1 ] ? '…' : '' ) } ),
				] ) ) ) ) );
			const btn = el( 'button', { type: 'button', class: 'button button-small', 'aria-expanded': 'false', text: 'Show changes', onclick: () => {
				detail.hidden = ! detail.hidden;
				btn.setAttribute( 'aria-expanded', detail.hidden ? 'false' : 'true' );
				btn.textContent = detail.hidden ? 'Show changes' : 'Hide changes';
			} } );
			body.appendChild( el( 'tr', {}, [
				el( 'td', {}, el( 'code', { text: t.name } ) ),
				el( 'td', { text: t.rows.toLocaleString() } ),
				el( 'td', { text: t.changes.toLocaleString() } ),
				el( 'td', {}, el( 'div', { class: 'migrateplus-row-actions' }, btn ) ),
			] ) );
			body.appendChild( detail );
		} );
		if ( ! changed.length ) {
			body.appendChild( el( 'tr', {}, el( 'td', { colspan: 4, class: 'migrateplus-muted migrateplus-no-match', text: 'No matches for "' + a.search + '" in the selected tables.' } ) ) );
		}
		root.appendChild( el( 'section', { class: 'migrateplus-panel migrateplus-history-panel migrateplus-replace-results' }, [
			el( 'header', { class: 'migrateplus-history-head' }, [
				el( 'h2', { class: 'migrateplus-history-title', text: d.dry_run ? 'Dry run results' : 'Results' } ),
				el( 'span', { class: 'migrateplus-muted', text: '"' + a.search + '" → "' + a.replace + '"' } ),
			] ),
			el( 'div', { class: 'migrateplus-table-scroll' }, el( 'table', { class: 'migrateplus-history' }, [
				el( 'thead', {}, el( 'tr', {}, [ 'Table', d.dry_run ? 'Rows to change' : 'Rows changed', 'Replacements', '' ].map( ( h ) => el( 'th', { scope: 'col', text: h } ) ) ) ),
				body,
			] ) ),
			skipped.length ? el( 'p', { class: 'migrateplus-help migrateplus-loading', text: 'Skipped: ' + skipped.map( ( t ) => t.name + ' (' + t.skipped + ')' ).join( '; ' ) } ) : null,
			unsafe.length ? el( 'p', { class: 'migrateplus-help migrateplus-loading', text: 'Values left as they are because they contain objects that can’t be safely edited: ' + unsafe.map( ( t ) => t.name + ' (' + t.unsafe + ')' ).join( '; ' ) } ) : null,
		] ) );
	}

	// History

	function renderHistory() {
		root.innerHTML = '';
		root.classList.add( 'migrateplus-app' );
		const wrap = el( 'section', { class: 'migrateplus-panel migrateplus-history-panel', 'aria-busy': 'true' }, el( 'p', { class: 'migrateplus-muted migrateplus-loading', text: 'Loading history…' } ) );
		root.appendChild( wrap );
		api( 'jobs', {} ).then( ( data ) => {
			state.historyJobs = data.jobs;
			wrap.removeAttribute( 'aria-busy' );
			drawHistory( wrap );
		} ).catch( ( err ) => {
			wrap.innerHTML = '';
			wrap.appendChild( notice( 'error', err.message ) );
		} );

		const exportsWrap = el( 'section', { class: 'migrateplus-panel migrateplus-history-panel migrateplus-exports-panel', 'aria-busy': 'true' }, el( 'p', { class: 'migrateplus-muted migrateplus-loading', text: 'Loading exports…' } ) );
		root.appendChild( exportsWrap );
		api( 'exports', {} ).then( ( data ) => {
			exportsWrap.removeAttribute( 'aria-busy' );
			drawExports( exportsWrap, data.exports );
		} ).catch( ( err ) => {
			exportsWrap.innerHTML = '';
			exportsWrap.appendChild( notice( 'error', err.message ) );
		} );
	}

	const EXPORT_STATUS_LABELS = { running: 'Running', complete: 'Complete', stopped: 'Stopped' };

	function drawExports( wrap, exports ) {
		wrap.innerHTML = '';
		wrap.appendChild( el( 'header', { class: 'migrateplus-history-head' }, [
			el( 'h2', { class: 'migrateplus-history-title', text: 'Exports' } ),
			el( 'span', { class: 'migrateplus-muted', text: exports.length ? exports.length + ' export' + ( exports.length === 1 ? '' : 's' ) : '' } ),
			el( 'a', { class: 'button migrateplus-push', href: D.exportUrl, text: 'Export' } ),
		] ) );
		if ( ! exports.length ) {
			wrap.appendChild( el( 'p', { class: 'migrateplus-muted migrateplus-loading', text: 'Exports you run show up here with a log of what went into the file.' } ) );
			return;
		}
		const body = el( 'tbody' );
		const pick = bulkSelection( 'export', 'export', 'Their files are deleted too, if they are still kept.', ( d ) => {
			const gone = new Set( d.deleted.map( ( e ) => e.id ) );
			drawExports( wrap, exports.filter( ( e ) => ! gone.has( e.id ) ) );
		} );
		wrap.querySelector( '.migrateplus-history-head' ).insertBefore( pick.button, wrap.querySelector( '.migrateplus-push' ) );
		exports.forEach( ( x ) => exportRows( x, body, () => drawExports( wrap, exports.filter( ( e ) => e.id !== x.id ) ), pick ) );
		wrap.appendChild( el( 'div', { class: 'migrateplus-table-scroll' }, el( 'table', { class: 'migrateplus-history' }, [
			el( 'thead', {}, el( 'tr', {}, [ pick.head() ].concat( [ 'Export', 'Status', 'Result', '' ].map( ( h ) => el( 'th', { scope: 'col', text: h } ) ) ) ) ),
			body,
		] ) ) );
		pick.sync();
	}

	/**
	 * Row checkboxes, a select-all and a "Delete selected" button for one History table.
	 * kind goes to the server with each id; warning is added to the confirmation.
	 * Call reset() before redrawing the rows and sync() after.
	 */
	function bulkSelection( kind, noun, warning, onDone ) {
		const selected = new Set();
		let boxes = new Map(); // id => checkbox, for the rows on screen.
		const names = new Map();
		const button = el( 'button', { type: 'button', class: 'button migrateplus-danger', disabled: true, text: 'Delete selected', onclick: () => confirmBulkDelete( kind, noun, warning, [ ...selected ], names, onDone ) } );
		const all = el( 'input', { type: 'checkbox', 'aria-label': 'Select all', onchange: ( e ) => {
			boxes.forEach( ( box, id ) => {
				box.checked = e.target.checked;
				if ( box.checked ) {
					selected.add( id );
				} else {
					selected.delete( id );
				}
			} );
			sync();
		} } );
		// Rows hidden by the search are dropped from the selection, so nothing out of sight is deleted.
		function sync() {
			selected.forEach( ( id ) => ! boxes.has( id ) && selected.delete( id ) );
			const n = selected.size;
			button.disabled = ! n;
			button.textContent = n ? 'Delete ' + n + ' ' + ( n === 1 ? 'entry' : 'entries' ) : 'Delete selected';
			all.checked = boxes.size > 0 && n === boxes.size;
			all.indeterminate = n > 0 && n < boxes.size;
			all.disabled = ! boxes.size;
		}
		return {
			button,
			sync,
			reset: () => {
				boxes = new Map();
			},
			head: () => el( 'td', { class: 'migrateplus-check-column' }, all ),
			cell: ( id, name ) => {
				names.set( id, name );
				const box = el( 'input', { type: 'checkbox', checked: selected.has( id ), 'aria-label': 'Select ' + name, onchange: ( e ) => {
					if ( e.target.checked ) {
						selected.add( id );
					} else {
						selected.delete( id );
					}
					sync();
				} } );
				boxes.set( id, box );
				return el( 'th', { scope: 'row', class: 'migrateplus-check-column' }, box );
			},
		};
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
	function exportRows( x, body, onForget, pick ) {
		const logRow = el( 'tr', { class: 'migrateplus-export-log-row', hidden: true }, el( 'td', { colspan: 5, class: 'migrateplus-export-log' } ) );
		const actions = el( 'div', { class: 'migrateplus-row-actions' } );
		if ( x.url ) {
			actions.appendChild( el( 'a', { class: 'button button-small button-primary', href: x.url, text: 'Download' } ) );
		}
		const viewBtn = el( 'button', { type: 'button', class: 'button button-small', 'aria-expanded': 'false', text: 'View log', onclick: () => {
			const open = logRow.hidden;
			logRow.hidden = ! open;
			viewBtn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			viewBtn.textContent = open ? 'Hide log' : 'View log';
			if ( open && ! logRow.dataset.loaded ) {
				const cell = logRow.firstChild;
				cell.textContent = 'Loading…';
				api( 'export_log', { export: x.id } ).then( ( d ) => {
					logRow.dataset.loaded = '1';
					cell.innerHTML = '';
					cell.appendChild( el( 'ol', { class: 'migrateplus-log' }, d.log.map( ( e ) => el( 'li', { class: 'migrateplus-log-' + e.l }, [
						el( 'time', { datetime: new Date( e.t * 1000 ).toISOString(), text: new Date( e.t * 1000 ).toLocaleTimeString() } ),
						el( 'span', { text: e.m } ),
					] ) ) ) );
				} ).catch( ( err ) => {
					cell.textContent = err.message;
				} );
			}
		} } );
		actions.appendChild( viewBtn );
		actions.appendChild( el( 'button', { type: 'button', class: 'migrateplus-link-danger', text: 'Delete', title: 'Remove from history, with its file if it is still kept.', onclick: () => ask( {
			title: 'Delete this export?',
			message: 'Remove this export from history?',
			label: 'Delete',
			danger: true,
			onConfirm: () => api( 'export_forget', { export: x.id } ).then( () => {
				modal.close();
				onForget();
			} ).catch( ( err ) => modalError( 'Could not delete', err ) ),
		} ) } ) );

		const who = [ x.user, x.via === 'cli' ? 'WP-CLI' : '' ].filter( Boolean ).join( ' via ' );
		let result = x.done.toLocaleString() + ' of ' + x.total.toLocaleString() + ' items';
		if ( x.status === 'complete' ) {
			result = x.done.toLocaleString() + ' item' + ( x.done === 1 ? '' : 's' ) + ( x.size ? ', ' + x.size : '' ) + ( x.url ? '' : '. File no longer kept' );
		}
		body.appendChild( el( 'tr', {}, [
			pick.cell( x.id, x.label ),
			el( 'td', { class: 'migrateplus-h-source' }, [
				el( 'span', { class: 'migrateplus-source-icon is-sm migrateplus-icon-json', 'aria-hidden': 'true' } ),
				el( 'div', {}, [
					el( 'strong', { text: x.label } ),
					el( 'span', { class: 'migrateplus-help' }, [
						who ? who + ', ' : '',
						el( 'time', { datetime: new Date( x.created * 1000 ).toISOString(), title: formatDate( x.created ), text: relativeTime( x.created ) } ),
					] ),
				] ),
			] ),
			el( 'td', {}, el( 'span', { class: 'migrateplus-badge migrateplus-badge-' + x.status, text: EXPORT_STATUS_LABELS[ x.status ] || x.status } ) ),
			el( 'td', { class: 'migrateplus-h-result' }, el( 'span', { class: 'migrateplus-help', text: result } ) ),
			el( 'td', {}, actions ),
		] ) );
		body.appendChild( logRow );
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

	function drawHistory( wrap ) {
		wrap.innerHTML = '';
		const jobs = state.historyJobs || [];
		if ( ! jobs.length ) {
			wrap.appendChild( el( 'div', { class: 'migrateplus-empty' }, [
				el( 'h2', { text: 'No imports yet' } ),
				el( 'p', { class: 'migrateplus-muted', text: 'Every import you run shows up here, so you can resume it, run it again or roll it back.' } ),
				el( 'a', { class: 'button button-primary', href: D.importUrl, text: 'Start an import' } ),
			] ) );
			return;
		}

		const body = el( 'tbody' );
		const count = el( 'span', { class: 'migrateplus-muted', 'aria-live': 'polite' } );
		const pick = bulkSelection( 'job', 'import', 'Imported content is not deleted, but deleted entries can no longer be rolled back.', ( d ) => {
			const gone = new Set( d.deleted.map( ( e ) => e.id ) );
			state.historyJobs = ( state.historyJobs || [] ).filter( ( j ) => ! gone.has( j.id ) );
			drawHistory( wrap );
		} );
		const fill = () => {
			body.innerHTML = '';
			pick.reset();
			const q = state.historyQuery.trim().toLowerCase();
			const shown = jobs.filter( ( job ) => ! q || [ job.label, job.options.post_type, STATUS_LABELS[ job.status ], job.settings.site_url, job.settings.url, job.settings.file_url ]
				.some( ( v ) => v && String( v ).toLowerCase().includes( q ) ) );
			shown.forEach( ( job ) => body.appendChild( historyRow( job, pick ) ) );
			if ( ! shown.length ) {
				body.appendChild( el( 'tr', {}, el( 'td', { colspan: 5, class: 'migrateplus-muted migrateplus-no-match', text: 'No imports match "' + state.historyQuery + '".' } ) ) );
			}
			count.textContent = shown.length === jobs.length ? jobs.length + ' import' + ( jobs.length === 1 ? '' : 's' ) : shown.length + ' of ' + jobs.length;
			pick.sync();
		};

		wrap.appendChild( el( 'header', { class: 'migrateplus-history-head' }, [
			el( 'input', { type: 'search', id: 'migrateplus-history-search', class: 'migrateplus-search', placeholder: 'Search by source, site or status', 'aria-label': 'Search history', value: state.historyQuery, oninput: ( e ) => {
				state.historyQuery = e.target.value;
				fill();
			} } ),
			pick.button,
			count,
			el( 'a', { class: 'button button-primary migrateplus-push', href: D.importUrl, text: 'Import' } ),
		] ) );
		wrap.appendChild( el( 'div', { class: 'migrateplus-table-scroll' }, el( 'table', { class: 'migrateplus-history' }, [
			el( 'thead', {}, el( 'tr', {}, [ pick.head() ].concat( [ 'Import', 'Status', 'Result', '' ].map( ( h ) => el( 'th', { scope: 'col', text: h } ) ) ) ) ),
			body,
		] ) ) );
		fill();
	}

	function historyRow( job, pick ) {
		const s = job.stats;
		const dry = Number( job.options.dry_run ) === 1;
		const where = job.settings.site_url || job.settings.url || job.settings.file_url || '';
		const actions = el( 'div', { class: 'migrateplus-row-actions' } );
		const btn = ( label, fn, cls ) => actions.appendChild( el( 'button', { type: 'button', class: 'button button-small ' + ( cls || '' ), text: label, onclick: fn } ) );

		if ( [ 'running', 'ready', 'failed' ].includes( job.status ) ) {
			btn( 'Resume', () => openJob( job.id, true ), 'button-primary' );
		}
		btn( 'View log', () => openJob( job.id, false ) );
		if ( [ 'complete', 'rolled_back', 'failed' ].includes( job.status ) ) {
			btn( 'Run again', () => restartJob( job ) );
		}
		if ( ! dry && ! [ 'rolled_back', 'running' ].includes( job.status ) && importedCount( job ) > 0 ) {
			btn( 'Roll back', () => rollback( job.id ), 'migrateplus-danger' );
		}
		actions.appendChild( el( 'button', { type: 'button', class: 'migrateplus-link-danger', text: 'Delete', title: 'Remove from history. Imported content is kept.', onclick: () => ask( {
			title: 'Delete this import?',
			message: 'Remove this import from history? Imported content is not deleted, but it can no longer be rolled back.',
			label: 'Delete',
			danger: true,
			onConfirm: () => api( 'delete', { job: job.id } ).then( () => {
				modal.close();
				state.historyJobs = ( state.historyJobs || [] ).filter( ( j ) => j.id !== job.id );
				drawHistory( root.querySelector( '.migrateplus-history-panel' ) );
			} ).catch( ( err ) => modalError( 'Could not delete', err ) ),
		} ) } ) );

		const parts = OUTCOMES.filter( ( [ k ] ) => Number( s[ k ] ) > 0 ).map( ( [ k, label, dryLabel ] ) => Number( s[ k ] ).toLocaleString() + ' ' + ( dry ? dryLabel : label ).toLowerCase() );
		return el( 'tr', {}, [
			pick.cell( job.id, job.settings.file_name || job.label ),
			el( 'td', { class: 'migrateplus-h-source' }, [
				el( 'span', { class: 'migrateplus-source-icon is-sm migrateplus-icon-' + job.source, 'aria-hidden': 'true' } ),
				el( 'div', {}, [
					el( 'strong', { text: job.settings.file_name || job.label } ),
					el( 'span', { class: 'migrateplus-help' }, [
						where ? hostOf( where ) + ' to ' : 'To ',
						( isMultiType( job ) ? job.settings.migrateplus_post_types.split( ',' ).join( ', ' ) : job.options.post_type ) + ', ',
						el( 'time', { datetime: new Date( job.created * 1000 ).toISOString(), title: formatDate( job.created ), text: relativeTime( job.created ) } ),
					] ),
				] ),
			] ),
			el( 'td', {}, [
				el( 'span', { class: 'migrateplus-badge migrateplus-badge-' + job.status, text: STATUS_LABELS[ job.status ] || job.status } ),
				dry ? el( 'span', { class: 'migrateplus-badge migrateplus-badge-dry', text: 'Dry run' } ) : null,
			] ),
			el( 'td', { class: 'migrateplus-h-result' }, [
				outcomeBar( s, job.total, { size: 'sm', label: 'Result' } ),
				el( 'span', { class: 'migrateplus-help', text: parts.length ? parts.join( ', ' ) : 'Nothing processed yet' } ),
			] ),
			el( 'td', {}, actions ),
		] );
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
				modal.open( runModalOptions() );
			}
			render();
			if ( resume ) {
				runLoop();
			}
		} ).catch( ( err ) => modalError( 'Could not open the import', err ) );
	}

	window.addEventListener( 'beforeunload', ( e ) => {
		if ( state.running && ! state.paused ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	render();
	if ( D.openJob && state.tab === 'new' ) {
		openJob( D.openJob, D.resume );
	}
}() );
