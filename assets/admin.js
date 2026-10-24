/* PressPort admin screen. Plain JS, no build step. */
( function () {
	'use strict';

	const D = window.PRESSPORT_DATA;
	const root = document.getElementById( 'pressport-app' );
	if ( ! D || ! root ) {
		return;
	}

	const state = {
		tab: [ 'history', 'export' ].includes( D.view ) ? D.view : 'new',
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
		body.append( 'action', 'pressport_' + action );
		body.append( 'nonce', D.nonce );
		return body;
	}

	function parseResponse( status, text ) {
		let json;
		try {
			json = JSON.parse( text );
		} catch ( e ) {
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
	 * A single reusable modal with a progress bar. Lives outside #pressport-app so
	 * re-renders of the page never tear it down.
	 *
	 * modal.open( { title, message, percent, detail, stats, actions, tone, dismissible } )
	 * modal.update( { ...same keys } )   percent: number 0-100, or null for indeterminate.
	 * modal.close()
	 */
	const modal = ( function () {
		let overlay = null;
		let parts = {};
		let opts = {};
		let lastFocus = null;

		function build() {
			parts.title = el( 'h2', { class: 'pressport-modal-title', id: 'pressport-modal-title' } );
			parts.message = el( 'p', { class: 'pressport-modal-message', id: 'pressport-modal-message' } );
			parts.fill = el( 'span', { class: 'pressport-modal-fill' } );
			parts.obar = outcomeBar( {}, 0, {} );
			parts.bar = el( 'div', { class: 'pressport-progress pressport-modal-bar', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-labelledby': 'pressport-modal-title' }, parts.fill );
			parts.percent = el( 'span', { class: 'pressport-modal-percent' } );
			parts.detail = el( 'span', { class: 'pressport-modal-detail' } );
			parts.meta = el( 'div', { class: 'pressport-modal-meta' }, [ parts.detail, parts.percent ] );
			parts.stats = el( 'ul', { class: 'pressport-legend pressport-modal-stats' } );
			parts.note = el( 'p', { class: 'pressport-modal-note', 'aria-live': 'polite' } );
			parts.actions = el( 'div', { class: 'pressport-modal-actions' } );
			parts.dialog = el( 'div', { class: 'pressport-modal', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'pressport-modal-title', 'aria-describedby': 'pressport-modal-message' }, [
				parts.title, parts.message, parts.bar, parts.obar, parts.meta, parts.stats, parts.note, parts.actions,
			] );
			overlay = el( 'div', { class: 'pressport-modal-overlay' }, parts.dialog );
			overlay.addEventListener( 'keydown', onKey );
			document.body.appendChild( overlay );
		}

		function onKey( e ) {
			if ( e.key === 'Escape' && opts.dismissible ) {
				e.preventDefault();
				if ( typeof opts.onDismiss === 'function' ) {
					opts.onDismiss();
				} else {
					close();
				}
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
			parts.bar.hidden = !! opts.outcome;
			parts.obar.hidden = ! opts.outcome;
			if ( opts.outcome ) {
				setOutcomeBar( parts.obar, opts.outcome.stats, opts.outcome.total, { size: 'lg', indeterminate, tone: opts.tone, label: opts.title } );
			}
			parts.detail.textContent = opts.detail || '';

			parts.stats.innerHTML = '';
			( opts.stats || [] ).forEach( ( s ) => parts.stats.appendChild(
				el( 'li', { class: 'is-' + s.key + ( Number( s.value ) ? '' : ' is-zero' ) }, [ el( 'strong', { text: ( Number( s.value ) || 0 ).toLocaleString() } ), el( 'span', { text: s.label } ) ] )
			) );
			parts.stats.hidden = ! ( opts.stats && opts.stats.length );

			parts.note.textContent = opts.note || '';
			parts.note.hidden = ! opts.note;
			parts.note.classList.toggle( 'is-error', opts.tone === 'error' );

			// Only rebuild buttons when they change, so focus is not lost on every tick.
			const sig = JSON.stringify( ( opts.actions || [] ).map( ( a ) => [ a.label, a.primary, a.danger, a.href, a.disabled ] ) );
			if ( sig !== parts.actionsSig ) {
				parts.actionsSig = sig;
				const hadFocus = parts.dialog.contains( document.activeElement );
				parts.actions.innerHTML = '';
				( opts.actions || [] ).forEach( ( a ) => {
					const cls = 'button' + ( a.primary ? ' button-primary' : '' ) + ( a.danger ? ' pressport-danger' : '' );
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
					const a = opts.actions[ i ];
					if ( a && ! a.href ) {
						node.onclick = a.onClick;
					}
				} );
			}
			parts.actions.hidden = ! ( opts.actions && opts.actions.length );
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
				document.body.classList.add( 'pressport-modal-open' );
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
			document.body.classList.remove( 'pressport-modal-open' );
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
		// Custom fields from PressPort / WordPress exports ("meta.<key>"), skipping private keys.
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
		const select = el( 'select', { class: 'pressport-field-select', 'aria-label': ( opts && opts.label ) || 'Source field', onchange: ( e ) => onChange( e.target.value ) }, [
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
		return el( 'div', { class: 'notice notice-' + type + ' inline pressport-notice' }, el( 'p', { text: message } ) );
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
		OUTCOMES.forEach( ( [ key ] ) => bar.appendChild( el( 'span', { class: 'pressport-obar-seg is-' + key } ) ) );
		bar.appendChild( el( 'span', { class: 'pressport-obar-runner' } ) );
		setOutcomeBar( bar, stats, total, opts );
		return bar;
	}

	/** Update an outcome bar in place so widths animate. */
	function setOutcomeBar( bar, stats, total, opts ) {
		opts = opts || {};
		const processed = Number( stats.processed ) || 0;
		const base = Math.max( Number( total ) || 0, processed, 1 );
		bar.className = 'pressport-obar' + ( opts.size ? ' is-' + opts.size : '' ) + ( opts.indeterminate ? ' is-indeterminate' : '' ) + ( opts.tone ? ' is-' + opts.tone : '' );
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
		return el( 'ul', { class: 'pressport-legend' }, OUTCOMES.map( ( [ key, label, dryLabel ] ) =>
			el( 'li', { class: 'is-' + key + ( Number( stats[ key ] ) ? '' : ' is-zero' ) }, [
				el( 'strong', { text: ( Number( stats[ key ] ) || 0 ).toLocaleString() } ),
				el( 'span', { text: dry ? dryLabel : label } ),
			] )
		) );
	}

	const SOURCE_BLURBS = {
		csv: 'A spreadsheet export, or a Google Sheet link',
		json: 'A JSON file or an API that returns JSON',
		remote: 'RSS or Atom feeds, WordPress exports, XML',
		wordpress: 'Another WordPress site, through its REST API',
		drupal: 'Drupal 8 or later, through JSON:API',
	};

	const URL_PLACEHOLDERS = {
		csv: 'https://example.com/exports/posts.csv',
		remote: 'https://blog.example.com/feed/',
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
		root.classList.add( 'pressport-app' );
		if ( state.tab === 'history' ) {
			renderHistory();
			return;
		}
		if ( state.tab === 'export' ) {
			renderExport();
			return;
		}
		root.appendChild( stepper() );
		if ( state.error ) {
			root.appendChild( notice( 'error', state.error ) );
		}
		if ( state.step === 'source' ) {
			renderSource();
		} else if ( state.step === 'map' ) {
			renderMap();
		} else {
			renderRun();
		}
	}

	function stepper() {
		const steps = [
			[ 'source', 'Choose a source' ],
			[ 'map', 'Map fields' ],
			[ 'run', 'Import' ],
		];
		const idx = steps.findIndex( ( s ) => s[ 0 ] === state.step );
		return el( 'ol', { class: 'pressport-route', 'aria-label': 'Import steps' }, steps.map( ( s, i ) =>
			el( 'li', {
				class: i === idx ? 'is-current' : ( i < idx ? 'is-done' : '' ),
				'aria-current': i === idx ? 'step' : null,
			}, [
				el( 'span', { class: 'pressport-route-dot', 'aria-hidden': 'true', text: i < idx ? '✓' : String( i + 1 ) } ),
				el( 'span', { class: 'pressport-route-label', text: s[ 1 ] } ),
			] )
		) );
	}

	// Step 1

	function renderSource() {
		const cards = el( 'div', { class: 'pressport-tiles', role: 'radiogroup', 'aria-label': 'Source' }, D.sources.map( ( s ) =>
			el( 'label', { class: 'pressport-tile' + ( s.id === state.sourceId ? ' is-selected' : '' ) }, [
				el( 'input', { type: 'radio', name: 'pressport_source', value: s.id, checked: s.id === state.sourceId, onchange: () => {
					state.sourceId = s.id;
					state.error = '';
					render();
					const first = root.querySelector( '.pressport-tile.is-selected input' );
					if ( first ) {
						first.focus();
					}
				} } ),
				el( 'span', { class: 'pressport-source-icon pressport-icon-' + s.id, 'aria-hidden': 'true' } ),
				el( 'span', { class: 'pressport-tile-text' }, [
					el( 'strong', { text: s.label } ),
					SOURCE_BLURBS[ s.id ] ? el( 'span', { text: SOURCE_BLURBS[ s.id ] } ) : null,
				] ),
			] )
		) );

		const source = D.sources.find( ( s ) => s.id === state.sourceId );
		const form = el( 'form', { class: 'pressport-panel pressport-source-form', onsubmit: onPreview } );
		const fields = el( 'div', { class: 'pressport-fields' } );
		source.schema.forEach( ( f ) => {
			const id = 'pressport-set-' + f.key;
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
				help.push( el( 'p', { class: 'pressport-field-help', text: f.help } ) );
			}
			if ( f.type === 'file' && f.remote === false ) {
				help.push( el( 'p', { class: 'pressport-field-help', text: 'Max upload size: ' + D.maxUpload } ) );
			}
			fields.appendChild( el( 'div', { class: 'pressport-field pressport-field-' + f.type }, [
				el( 'label', { class: 'pressport-field-label', for: f.type === 'file' ? null : id }, [
					f.label,
					f.required ? el( 'span', { class: 'pressport-req', text: 'Required' } ) : null,
				] ),
				input,
			].concat( help ) ) );
		} );
		form.appendChild( el( 'header', { class: 'pressport-panel-head' }, [
			el( 'span', { class: 'pressport-source-icon pressport-icon-' + source.id, 'aria-hidden': 'true' } ),
			el( 'div', {}, [
				el( 'h2', { text: source.label } ),
				SOURCE_BLURBS[ source.id ] ? el( 'p', { text: SOURCE_BLURBS[ source.id ] } ) : null,
			] ),
		] ) );
		form.appendChild( fields );
		form.appendChild( el( 'footer', { class: 'pressport-panel-foot' }, [
			el( 'p', { class: 'pressport-foot-note', text: 'Nothing is imported yet. You will see a preview and map the fields first.' } ),
			el( 'button', { type: 'submit', class: 'button button-primary pressport-btn-lg', disabled: state.busy, text: 'Connect and preview' } ),
		] ) );

		root.appendChild( cards );
		root.appendChild( form );
	}

	/**
	 * File field with an "Upload file / From URL" switch (unless the schema sets remote: false).
	 * The upload side is a drop zone.
	 */
	function fileInput( f, id, name ) {
		const upload = el( 'input', { id, name, type: 'file', accept: f.accept || '', class: 'pressport-drop-input' } );
		const fileName = el( 'span', { class: 'pressport-drop-file' } );
		const zone = el( 'label', { class: 'pressport-drop', for: id }, [
			upload,
			el( 'span', { class: 'pressport-drop-icon', 'aria-hidden': 'true' } ),
			el( 'span', { class: 'pressport-drop-text' }, [
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
		const mode = state.fileMode[ modeKey ] || ( f.remote_default === 'url' ? 'url' : 'upload' );
		const urlInput = el( 'input', {
			id: id + '-url',
			name: 'settings[' + f.key + '_url]',
			type: 'url',
			placeholder: URL_PLACEHOLDERS[ state.sourceId ] || 'https://',
			autocomplete: 'off',
			'aria-label': f.label + ' URL',
		} );
		const uploadPane = el( 'div', { class: 'pressport-file-pane' }, zone );
		const urlPane = el( 'div', { class: 'pressport-file-pane' }, [
			urlInput,
			el( 'p', { class: 'pressport-field-help', text: 'Your server downloads the file (up to ' + D.maxRemote + '). Google Sheets, Google Drive, Dropbox and GitHub share links work too, as long as they are shared publicly.' } ),
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
		tabs.url = el( 'button', { type: 'button', class: 'pressport-seg', text: 'From a URL', onclick: () => setMode( 'url', true ) } );
		tabs.upload = el( 'button', { type: 'button', class: 'pressport-seg', text: 'Upload a file', onclick: () => setMode( 'upload', true ) } );
		const order = f.remote_default === 'url' ? [ tabs.url, tabs.upload ] : [ tabs.upload, tabs.url ];

		const wrap = el( 'div', { class: 'pressport-file-field' }, [
			el( 'div', { class: 'pressport-segmented', role: 'group', 'aria-label': f.label + ': how to provide it' }, order ),
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
		const readingMsg = remote
			? 'Downloading the file from ' + hostOf( remote ) + ' and reading the first records…'
			: ( connectTo ? 'Connecting to ' + connectTo + ' and reading the first records…' : 'Reading the first records…' );

		state.busy = true;
		state.error = '';
		const submit = form.querySelector( '[type="submit"]' );
		if ( submit ) {
			submit.disabled = true;
		}
		const oldNotice = root.querySelector( '.pressport-notice.notice-error' );
		if ( oldNotice ) {
			oldNotice.remove();
		}

		modal.open( {
			kind: 'preview',
			title: upload ? 'Uploading ' + upload.name : ( remote ? 'Fetching remote file' : 'Connecting to ' + source.label ),
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
			modal.close();
			state.job = data.job;
			state.records = data.records;
			state.fields = data.fields;
			state.mapping = normalizeMapping( data.job.mapping );
			state.options = Object.assign( {}, D.defaults, data.job.options );
			state.suggested = {};
			state.step = 'map';
			state.busy = false;
			render();
		} ).catch( ( err ) => {
			state.busy = false;
			if ( submit ) {
				submit.disabled = false;
			}
			// Keep the form as typed; just surface the error.
			modal.update( {
				title: 'Could not connect',
				message: '',
				percent: 100,
				tone: 'error',
				detail: '',
				note: err.message,
				dismissible: true,
				onDismiss: null,
				actions: [ { label: 'Close', primary: true, onClick: () => modal.close() } ],
			} );
			form.parentNode.insertBefore( notice( 'error', err.message ), form );
		} );
	}

	// Step 2

	function renderMap() {
		suggestExtras();
		const job = state.job;
		const source = D.sources.find( ( s ) => s.id === job.source ) || D.sources.find( ( s ) => s.id === state.sourceId ) || { id: job.source, label: job.source };
		const total = job.total === null || job.total === undefined ? null : Number( job.total );

		root.appendChild( el( 'section', { class: 'pressport-summary' }, [
			el( 'span', { class: 'pressport-source-icon pressport-icon-' + source.id, 'aria-hidden': 'true' } ),
			el( 'div', { class: 'pressport-summary-text' }, [
				el( 'strong', { text: 'Connected to ' + source.label } ),
				el( 'span', { text: ( total === null ? 'Unknown number of' : total.toLocaleString() ) + ' records, ' + state.fields.length + ' fields' + ( job.settings.file_url || job.settings.url || job.settings.site_url ? ' from ' + hostOf( job.settings.file_url || job.settings.url || job.settings.site_url ) : '' ) } ),
			] ),
			el( 'button', { type: 'button', class: 'button', text: 'Change source', onclick: () => {
				state.step = 'source';
				state.error = '';
				render();
			} } ),
		] ) );

		// Preview table.
		const cols = state.fields.slice( 0, 40 );
		root.appendChild( el( 'details', { class: 'pressport-panel pressport-preview-panel', open: true }, [
			el( 'summary', {}, [
				el( 'h2', { text: 'Preview' } ),
				el( 'span', { class: 'pressport-muted', text: 'First ' + state.records.length + ' records' + ( state.fields.length > cols.length ? ', ' + cols.length + ' of ' + state.fields.length + ' fields shown' : '' ) } ),
			] ),
			el( 'div', { class: 'pressport-preview-scroll', tabindex: 0, 'aria-label': 'Preview records' }, el( 'table', { class: 'pressport-preview' }, [
				el( 'thead', {}, el( 'tr', {}, cols.map( ( c ) => el( 'th', { scope: 'col', text: c } ) ) ) ),
				el( 'tbody', {}, state.records.map( ( r ) => el( 'tr', {}, cols.map( ( c ) => el( 'td', { title: r[ c ] || '', text: truncate( r[ c ], 80 ) } ) ) ) ) ),
			] ) ),
		] ) );

		const grid = el( 'div', { class: 'pressport-grid' } );
		grid.appendChild( renderMappingCard() );
		grid.appendChild( renderOptionsCard() );
		root.appendChild( grid );

		const mapped = D.targets.filter( ( t ) => state.mapping.fields[ t.key ] ).length;
		const ready = !! ( state.mapping.fields.post_title || state.mapping.fields.post_content );
		root.appendChild( el( 'div', { class: 'pressport-actionbar' }, [
			el( 'span', { class: 'pressport-actionbar-status' + ( ready ? '' : ' is-warn' ), 'aria-live': 'polite', text: ready
				? mapped + ' of ' + D.targets.length + ' fields mapped'
				: 'Map the title or the content to continue' } ),
			el( 'div', { class: 'pressport-actionbar-buttons' }, [
				el( 'button', { type: 'button', class: 'button', disabled: state.busy, text: 'Dry run', title: 'Process everything without saving, to check the mapping', onclick: () => start( true ) } ),
				el( 'button', { type: 'button', class: 'button button-primary', disabled: state.busy, text: 'Start import', onclick: () => start( false ) } ),
			] ),
		] ) );
	}

	function updateActionbar() {
		const status = root.querySelector( '.pressport-actionbar-status' );
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
			const sampleCell = el( 'td', { class: 'pressport-sample', text: truncate( sample( m.fields[ t.key ] ), 120 ) } );
			const row = el( 'tr', { class: m.fields[ t.key ] ? 'is-mapped' : '' } );
			[
				el( 'th', { scope: 'row' }, [ el( 'span', { text: t.label } ), t.help ? el( 'span', { class: 'pressport-help', text: t.help } ) : null ] ),
				el( 'td', { class: 'pressport-arrow', 'aria-hidden': 'true', text: '←' } ),
				el( 'td', {}, fieldSelect( m.fields[ t.key ] || '', ( v ) => {
					if ( v ) {
						m.fields[ t.key ] = v;
					} else {
						delete m.fields[ t.key ];
					}
					row.classList.toggle( 'is-mapped', !! v );
					sampleCell.textContent = truncate( sample( v ), 120 );
					updateActionbar();
				} ) ),
				sampleCell,
			].forEach( ( c ) => row.appendChild( c ) );
			return row;
		} );

		const metaRows = m.meta.map( ( row, i ) => el( 'div', { class: 'pressport-pair' }, [
			el( 'input', { type: 'text', class: 'code', placeholder: 'meta_key', 'aria-label': 'Custom field name', value: row.key || '', oninput: ( e ) => {
				row.key = e.target.value;
			} } ),
			el( 'span', { class: 'pressport-arrow', 'aria-hidden': 'true', text: '←' } ),
			fieldSelect( row.source, ( v ) => {
				row.source = v;
			} ),
			el( 'button', { type: 'button', class: 'pressport-icon-btn', 'aria-label': 'Remove custom field', title: 'Remove', text: '×', onclick: () => {
				m.meta.splice( i, 1 );
				render();
			} } ),
		] ) );

		const taxes = postType( state.options.post_type ).taxonomies.filter( ( t ) => t.name !== 'category' && t.name !== 'post_tag' );
		const taxRows = m.taxonomies.map( ( row, i ) => el( 'div', { class: 'pressport-pair' }, [
			el( 'select', { 'aria-label': 'Taxonomy', onchange: ( e ) => {
				row.taxonomy = e.target.value;
			} }, [ el( 'option', { value: '', text: 'Choose taxonomy' } ) ].concat( taxes.map( ( t ) => el( 'option', { value: t.name, text: t.label + ' (' + t.name + ')', selected: t.name === row.taxonomy } ) ) ) ),
			el( 'span', { class: 'pressport-arrow', 'aria-hidden': 'true', text: '←' } ),
			fieldSelect( row.source, ( v ) => {
				row.source = v;
			} ),
			el( 'button', { type: 'button', class: 'pressport-icon-btn', 'aria-label': 'Remove taxonomy', title: 'Remove', text: '×', onclick: () => {
				m.taxonomies.splice( i, 1 );
				render();
			} } ),
		] ) );

		return el( 'section', { class: 'pressport-panel pressport-mapping' }, [
			el( 'header', { class: 'pressport-section-head' }, [
				el( 'h2', { text: 'Field mapping' } ),
				el( 'p', { class: 'pressport-muted', text: 'Pick which source field fills each WordPress field. Suggestions are already filled in.' } ),
			] ),
			el( 'table', { class: 'pressport-map-table' }, [
				el( 'thead', {}, el( 'tr', {}, [ el( 'th', { scope: 'col', text: 'WordPress field' } ), el( 'th', { 'aria-hidden': 'true' } ), el( 'th', { scope: 'col', text: 'Source field' } ), el( 'th', { scope: 'col', text: 'Sample value' } ) ] ) ),
				el( 'tbody', {}, rows ),
			] ),
			renderAcfSection(),
			el( 'div', { class: 'pressport-subsection' }, [
				el( 'h3', { text: 'Custom fields' } ),
				m.meta.length ? el( 'div', { class: 'pressport-pairs' }, metaRows ) : el( 'p', { class: 'pressport-muted', text: 'Save any source field as post meta. ACF field names work too.' } ),
				el( 'button', { type: 'button', class: 'button pressport-add', text: 'Add custom field', onclick: () => {
					m.meta.push( { key: '', source: '' } );
					render();
				} } ),
			] ),
			el( 'div', { class: 'pressport-subsection' }, [
				el( 'h3', { text: 'Other taxonomies' } ),
				taxes.length
					? ( m.taxonomies.length ? el( 'div', { class: 'pressport-pairs' }, taxRows ) : el( 'p', { class: 'pressport-muted', text: 'Assign terms in custom taxonomies registered for this post type.' } ) )
					: el( 'p', { class: 'pressport-muted', text: 'This post type has no other taxonomies.' } ),
				taxes.length ? el( 'button', { type: 'button', class: 'button pressport-add', text: 'Add taxonomy', onclick: () => {
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
		const head = el( 'div', { class: 'pressport-acf-head' }, [
			el( 'h3', { text: 'ACF fields' } ),
			el( 'span', { class: 'pressport-badge', text: fields.length + ' field' + ( fields.length === 1 ? '' : 's' ) } ),
		] );
		if ( ! fields.length ) {
			return el( 'div', { class: 'pressport-subsection pressport-acf' }, [
				head,
				el( 'p', { class: 'pressport-muted', text: 'No ACF field groups are assigned to this post type. Assign one in ACF, or pick another post type under "Import as".' } ),
			] );
		}
		const groups = {};
		fields.forEach( ( f ) => {
			( groups[ f.group ] = groups[ f.group ] || [] ).push( f );
		} );
		const body = [];
		Object.entries( groups ).forEach( ( [ group, list ] ) => {
			body.push( el( 'tr', { class: 'pressport-acf-group' }, el( 'th', { colspan: 4, scope: 'colgroup', text: group } ) ) );
			list.forEach( ( f ) => {
				const current = m.acf[ f.key ] || m.acf[ f.name ] || '';
				const sampleCell = el( 'td', { class: 'pressport-sample', text: truncate( sample( current ), 120 ) } );
				const row = el( 'tr', { class: current ? 'is-mapped' : '' } );
				[
					el( 'th', { scope: 'row' }, [
						el( 'span', { text: f.label + ( f.required ? ' *' : '' ) } ),
						el( 'span', { class: 'pressport-help' }, [
							el( 'code', { text: f.name } ),
							' ' + ( ACF_TYPE_LABELS[ f.type ] || f.type ),
							f.sub && f.sub.length ? ' (' + f.sub.join( ', ' ) + ')' : '',
						] ),
					] ),
					el( 'td', { class: 'pressport-arrow', 'aria-hidden': 'true', text: '←' } ),
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
						ACF_HINTS[ f.type ] ? el( 'span', { class: 'pressport-help', text: ACF_HINTS[ f.type ] } ) : null,
					] ),
					sampleCell,
				].forEach( ( c ) => row.appendChild( c ) );
				body.push( row );
			} );
		} );
		return el( 'div', { class: 'pressport-subsection pressport-acf' }, [
			head,
			el( 'p', { class: 'pressport-muted', text: 'Values are converted for each field type: image URLs are downloaded, related posts and terms are looked up, dates and choices are normalised.' } ),
			el( 'table', { class: 'pressport-map-table' }, el( 'tbody', {}, body ) ),
		] );
	}

	function renderOptionsCard() {
		const o = state.options;
		const set = ( k ) => ( e ) => {
			o[ k ] = e.target.type === 'checkbox' ? ( e.target.checked ? 1 : 0 ) : e.target.value;
		};
		let uid = 0;
		const toggle = ( k, label, help ) => {
			const id = 'pressport-opt-' + k;
			return el( 'div', { class: 'pressport-toggle' }, [
				el( 'input', { type: 'checkbox', role: 'switch', id, class: 'pressport-switch', checked: !! Number( o[ k ] ), onchange: set( k ) } ),
				el( 'label', { for: id }, [ el( 'span', { text: label } ), help ? el( 'span', { class: 'pressport-help', text: help } ) : null ] ),
			] );
		};
		const row = ( label, control, help ) => {
			const id = 'pressport-opt-row-' + ( ++uid );
			control.id = id;
			return el( 'div', { class: 'pressport-opt' }, [
				el( 'label', { class: 'pressport-opt-label', for: id, text: label } ),
				control,
				help ? el( 'span', { class: 'pressport-help', text: help } ) : null,
			] );
		};

		return el( 'aside', { class: 'pressport-panel pressport-options' }, [
			el( 'header', { class: 'pressport-section-head' }, [
				el( 'h2', { text: 'Import options' } ),
				D.settingsUrl ? el( 'p', { class: 'pressport-muted' }, [ 'Defaults come from ', el( 'a', { href: D.settingsUrl, text: 'Settings' } ), '.' ] ) : null,
			] ),
			row( 'Import as', el( 'select', { onchange: ( e ) => {
				o.post_type = e.target.value;
				render();
			} }, D.postTypes.map( ( p ) => el( 'option', { value: p.name, text: p.label + ' (' + p.name + ')', selected: p.name === o.post_type } ) ) ) ),
			row( 'Default status', el( 'select', { onchange: set( 'default_status' ) }, [ [ 'publish', 'Published' ], [ 'draft', 'Draft' ], [ 'pending', 'Pending review' ], [ 'private', 'Private' ] ].map( ( [ v, l ] ) =>
				el( 'option', { value: v, text: l, selected: v === o.default_status } ) ) ), 'Used when a record has no status, or one PressPort does not recognise.' ),
			row( 'Default author', el( 'select', { onchange: set( 'default_author' ) }, D.users.map( ( u ) =>
				el( 'option', { value: u.id, text: u.name, selected: Number( u.id ) === Number( o.default_author ) } ) ) ) ),
			row( 'Records imported before', el( 'select', { onchange: set( 'duplicate' ) }, [
				[ 'skip', 'Skip them' ], [ 'update', 'Update them' ], [ 'create', 'Create duplicates' ],
			].map( ( [ v, l ] ) => el( 'option', { value: v, text: l, selected: v === o.duplicate } ) ) ), 'Matched by the mapped Source ID.' ),
			row( 'Batch size', el( 'input', { type: 'number', min: 1, max: 100, value: o.batch_size, onchange: set( 'batch_size' ) } ), 'Records per request. Lower it if image downloads time out.' ),
			el( 'div', { class: 'pressport-toggles' }, [
				toggle( 'import_featured_image', 'Download featured images' ),
				toggle( 'import_content_images', 'Download images in content', 'Copies <img> files into the Media Library and rewrites their URLs.' ),
				toggle( 'create_terms', 'Create missing categories and terms' ),
				toggle( 'create_authors', 'Create missing authors', 'New users get the Author role and a random password.' ),
			] ),
		] );
	}

	function start( dry ) {
		state.options.dry_run = dry ? 1 : 0;
		state.busy = true;
		state.error = '';
		render();
		const allowed = new Set( acfFields( state.options.post_type ).flatMap( ( f ) => [ f.key, f.name ] ) );
		const mapping = Object.assign( {}, state.mapping, { acf: {} } );
		Object.entries( state.mapping.acf ).forEach( ( [ k, v ] ) => {
			if ( v && ( allowed.has( k ) || ! ( D.acf && D.acf.active ) ) ) {
				mapping.acf[ k ] = v;
			}
		} );
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
		} else if ( state.error ) {
			statusText = 'Stopped. Progress is saved.';
			tone = 'error';
		} else if ( state.paused ) {
			statusText = state.running ? 'Pausing after this batch…' : 'Paused. Progress is saved.';
			tone = 'paused';
		} else {
			statusText = ( dry ? 'Dry run in progress' : 'Importing' ) + '. Keep this tab open.';
		}

		const actions = el( 'div', { class: 'pressport-actions' } );
		if ( ! done ) {
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
				actions.appendChild( el( 'a', { class: 'button button-primary', href: D.adminUrl + 'edit.php?post_type=' + encodeURIComponent( job.options.post_type ), text: 'View imported content' } ) );
			}
			actions.appendChild( el( 'button', { type: 'button', class: 'button', text: 'Import', onclick: reset } ) );
			if ( ! dry && job.status === 'complete' && Number( s.created ) > 0 ) {
				actions.appendChild( el( 'button', { type: 'button', class: 'button pressport-danger pressport-push', text: 'Roll back this import', onclick: () => rollback( job.id ) } ) );
			}
		}

		root.appendChild( el( 'section', { class: 'pressport-panel pressport-run' + ( tone ? ' is-' + tone : '' ) }, [
			el( 'div', { class: 'pressport-run-head' }, [
				el( 'div', { class: 'pressport-run-figure', 'aria-hidden': 'true' }, pct === null
					? el( 'span', { class: 'pressport-run-count', text: processed.toLocaleString() } )
					: [ el( 'span', { class: 'pressport-run-count', text: String( pct ) } ), el( 'span', { class: 'pressport-run-unit', text: '%' } ) ] ),
				el( 'div', { class: 'pressport-run-text' }, [
					el( 'h2', { 'aria-live': 'polite', text: statusText } ),
					el( 'p', { class: 'pressport-muted', text: processed.toLocaleString() + ( total ? ' of ' + total.toLocaleString() : '' ) + ' records processed' + ( dry ? ' (dry run)' : '' ) } ),
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
		const list = el( 'ol', { class: 'pressport-log' } );
		const fillLog = () => {
			list.innerHTML = '';
			const q = state.logQuery.trim().toLowerCase();
			const entries = ( job.log || [] ).slice().reverse().filter( ( e ) =>
				( state.logFilter === 'all' || e.l === state.logFilter ) && ( ! q || String( e.m ).toLowerCase().includes( q ) )
			);
			entries.forEach( ( e ) => list.appendChild( el( 'li', { class: 'pressport-log-' + e.l }, [
				el( 'time', { datetime: new Date( e.t * 1000 ).toISOString(), text: new Date( e.t * 1000 ).toLocaleTimeString() } ),
				el( 'span', { text: e.m } ),
			] ) ) );
			if ( ! entries.length ) {
				list.appendChild( el( 'li', { class: 'pressport-log-empty', text: counts.all ? 'No log lines match.' : 'Waiting for the first batch…' } ) );
			}
		};
		const chip = ( key, label ) => el( 'button', {
			type: 'button',
			class: 'pressport-chip' + ( state.logFilter === key ? ' is-active' : '' ),
			'aria-pressed': state.logFilter === key ? 'true' : 'false',
			onclick: ( e ) => {
				state.logFilter = key;
				Array.from( e.currentTarget.parentNode.children ).forEach( ( b ) => {
					b.classList.toggle( 'is-active', b === e.currentTarget );
					b.setAttribute( 'aria-pressed', b === e.currentTarget ? 'true' : 'false' );
				} );
				fillLog();
			},
		}, [ label, el( 'span', { class: 'pressport-chip-count', text: String( counts[ key === 'all' ? 'all' : key ] || 0 ) } ) ] );

		fillLog();
		root.appendChild( el( 'section', { class: 'pressport-panel pressport-log-panel' }, [
			el( 'header', { class: 'pressport-log-head' }, [
				el( 'h2', { text: 'Log' } ),
				el( 'div', { class: 'pressport-chips', role: 'group', 'aria-label': 'Filter log' }, [
					chip( 'all', 'All' ), chip( 'success', 'Saved' ), chip( 'warning', 'Warnings' ), chip( 'error', 'Errors' ),
				] ),
				el( 'input', { type: 'search', id: 'pressport-log-search', class: 'pressport-search', placeholder: 'Search the log', 'aria-label': 'Search the log', value: state.logQuery, oninput: ( e ) => {
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
					{ label: 'View imported content', primary: true, href: D.adminUrl + 'edit.php?post_type=' + encodeURIComponent( job.options.post_type ) },
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
			message = 'Keep this tab open. Records are processed in batches of ' + job.options.batch_size + '.';
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
		if ( state.tab !== 'new' ) {
			window.location.href = D.importUrl;
			return;
		}
		render();
	}

	function rollback( jobId ) {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( 'Permanently delete every post and media file created by this import? Posts that were only updated are kept.' ) ) {
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
	const EXPORT_FORMATS = [
		[ 'json', 'JSON', 'Best for moving content between sites. Importing it with PressPort maps every field automatically.' ],
		[ 'csv', 'CSV', 'Opens in Excel or Google Sheets for editing. Lists use "|", nested ACF data is stored as JSON.' ],
		[ 'xml', 'XML (WordPress export)', 'Standard WXR file. Imports with PressPort or with Tools > Import > WordPress.' ],
	];

	function renderExport() {
		const x = state.exportArgs = state.exportArgs || Object.assign( {}, D.exportDefaults, { statuses: ( D.exportDefaults.statuses || [ 'publish' ] ).slice() } );
		const countEl = el( 'span', { class: 'pressport-export-count', 'aria-live': 'polite', text: 'Counting…' } );
		const submit = el( 'button', { type: 'submit', class: 'button button-primary pressport-btn-lg', text: 'Export' } );
		let timer = null;
		const recount = () => {
			clearTimeout( timer );
			countEl.textContent = 'Counting…';
			timer = setTimeout( () => {
				const pt = postType( x.post_type );
				api( 'export_count', { args: x } ).then( ( d ) => {
					countEl.textContent = d.count.toLocaleString() + ' ' + ( d.count === 1 ? pt.label.toLowerCase() : 'items' ) + ' will be exported';
					countEl.classList.toggle( 'is-warn', ! d.count );
					submit.disabled = ! d.count;
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
			const id = 'pressport-export-' + k;
			return el( 'div', { class: 'pressport-toggle' + ( disabled ? ' is-disabled' : '' ) }, [
				el( 'input', { type: 'checkbox', role: 'switch', id, class: 'pressport-switch', disabled, checked: ! disabled && !! Number( x[ k ] ), onchange: ( e ) => set( k, e.target.checked ? 1 : 0 ) } ),
				el( 'label', { for: id }, [ el( 'span', { text: label } ), help ? el( 'span', { class: 'pressport-help', text: help } ) : null ] ),
			] );
		};
		const acfCount = acfFields( x.post_type ).length;

		const form = el( 'form', { class: 'pressport-panel pressport-export', onsubmit: ( e ) => {
			e.preventDefault();
			runExport();
		} }, [
			el( 'div', { class: 'pressport-export-grid' }, [
				el( 'section', {}, [
					el( 'h2', { text: 'What to export' } ),
					el( 'div', { class: 'pressport-opt' }, [
						el( 'label', { class: 'pressport-opt-label', for: 'pressport-export-type', text: 'Content type' } ),
						el( 'select', { id: 'pressport-export-type', onchange: ( e ) => {
							x.post_type = e.target.value;
							render();
						} }, D.postTypes.map( ( p ) => el( 'option', { value: p.name, text: p.label + ' (' + p.name + ')', selected: p.name === x.post_type } ) ) ),
					] ),
					el( 'fieldset', { class: 'pressport-opt' }, [
						el( 'legend', { class: 'pressport-opt-label', text: 'Status' } ),
						el( 'div', { class: 'pressport-chips' }, EXPORT_STATUSES.map( ( [ v, l ] ) => {
							const id = 'pressport-export-status-' + v;
							return el( 'label', { class: 'pressport-check-chip', for: id }, [
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
					el( 'fieldset', { class: 'pressport-opt' }, [
						el( 'legend', { class: 'pressport-opt-label', text: 'Published between' } ),
						el( 'div', { class: 'pressport-date-range' }, [
							el( 'input', { type: 'date', 'aria-label': 'From', value: x.after, onchange: ( e ) => set( 'after', e.target.value ) } ),
							el( 'span', { class: 'pressport-muted', text: 'and' } ),
							el( 'input', { type: 'date', 'aria-label': 'To', value: x.before, onchange: ( e ) => set( 'before', e.target.value ) } ),
						] ),
						el( 'span', { class: 'pressport-help', text: 'Leave empty to export everything.' } ),
					] ),
				] ),
				el( 'section', {}, [
					el( 'h2', { text: 'Include' } ),
					el( 'div', { class: 'pressport-toggles is-flush' }, [
						toggle( 'include_terms', 'Categories, tags and terms', 'Hierarchy is kept as "Parent > Child".' ),
						toggle( 'include_image', 'Featured images', 'Exported as URLs; the importing site downloads them.' ),
						toggle( 'include_meta', 'Custom fields', 'Public post meta. Private keys starting with "_" are left out.' ),
						D.acf && D.acf.active
							? toggle( 'include_acf', 'ACF fields', acfCount ? acfCount + ' field' + ( acfCount === 1 ? '' : 's' ) + ' on this content type. Images become URLs, related posts become references.' : 'No ACF fields on this content type.', ! acfCount )
							: toggle( 'include_acf', 'ACF fields', 'Advanced Custom Fields is not active on this site.', true ),
						D.woo && D.woo.active && x.post_type === 'product'
							? toggle( 'include_woo', 'WooCommerce product data', 'Prices, stock, attributes, downloads and variations. Images become URLs; related products travel as SKUs.' )
							: null,
					] ),
				] ),
			] ),
			el( 'fieldset', { class: 'pressport-opt pressport-export-formats' }, [
				el( 'legend', { class: 'pressport-opt-label', text: 'Format' } ),
				el( 'div', { class: 'pressport-tiles' }, EXPORT_FORMATS.map( ( [ v, l, help ] ) =>
					el( 'label', { class: 'pressport-tile' + ( x.format === v ? ' is-selected' : '' ) }, [
						el( 'input', { type: 'radio', name: 'pressport_export_format', value: v, checked: x.format === v, onchange: () => {
							x.format = v;
							render();
						} } ),
						el( 'span', { class: 'pressport-source-icon pressport-icon-fmt-' + v, 'aria-hidden': 'true' } ),
						el( 'span', { class: 'pressport-tile-text' }, [ el( 'strong', { text: l } ), el( 'span', { text: help } ) ] ),
					] )
				) ),
			] ),
			el( 'footer', { class: 'pressport-panel-foot' }, [ countEl, submit ] ),
		] );
		root.appendChild( form );

		if ( state.lastExport ) {
			const le = state.lastExport;
			root.appendChild( el( 'section', { class: 'pressport-summary' }, [
				el( 'span', { class: 'pressport-source-icon pressport-icon-fmt-' + le.format, 'aria-hidden': 'true' } ),
				el( 'div', { class: 'pressport-summary-text' }, [
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
		const fmt = EXPORT_FORMATS.find( ( f ) => f[ 0 ] === x.format );
		modal.open( {
			kind: 'export',
			title: 'Exporting ' + postType( x.post_type ).label.toLowerCase() + ' content',
			message: 'Writing a ' + fmt[ 1 ] + ' file. Keep this tab open.',
			percent: null,
			detail: 'Counting…',
			actions: [],
			dismissible: false,
		} );
		const fail = ( err ) => modal.update( {
			title: 'Export stopped',
			message: '',
			tone: 'error',
			note: err.message,
			dismissible: true,
			actions: [ { label: 'Close', primary: true, onClick: () => modal.close() } ],
		} );
		const step = ( id ) => api( 'export_run', { export: id } ).then( ( d ) => {
			if ( d.complete ) {
				state.lastExport = d;
				const extras = [];
				if ( d.acf ) {
					extras.push( d.acf + ' ACF field' + ( d.acf === 1 ? '' : 's' ) );
				}
				if ( d.meta ) {
					extras.push( d.meta + ' custom field' + ( d.meta === 1 ? '' : 's' ) );
				}
				modal.update( {
					title: 'Export ready',
					message: d.format === 'json'
						? 'Import it on the other site with PressPort (JSON feed / file, or URL or feed). The fields map themselves.'
						: ( d.format === 'xml' ? 'Import it with PressPort (URL or feed) or with Tools > Import > WordPress.' : 'Import it with PressPort as a CSV file.' ),
					percent: 100,
					tone: 'success',
					detail: d.done.toLocaleString() + ' items' + ( extras.length ? ' with ' + extras.join( ' and ' ) : '' ) + ', ' + d.size,
					dismissible: true,
					onDismiss: null,
					actions: [
						{ label: 'Download ' + d.filename.split( '.' ).pop().toUpperCase(), primary: true, href: d.url },
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
				detail: d.done.toLocaleString() + ' of ' + d.total.toLocaleString() + ' items',
			} );
			return step( id );
		} );
		api( 'export_start', { args: x } ).then( ( d ) => {
			modal.update( { percent: 0, detail: '0 of ' + d.total.toLocaleString() + ' items' } );
			return step( d.id );
		} ).catch( fail );
	}

	// History

	function renderHistory() {
		root.innerHTML = '';
		root.classList.add( 'pressport-app' );
		const wrap = el( 'section', { class: 'pressport-panel pressport-history-panel', 'aria-busy': 'true' }, el( 'p', { class: 'pressport-muted pressport-loading', text: 'Loading history…' } ) );
		root.appendChild( wrap );
		api( 'jobs', {} ).then( ( data ) => {
			state.historyJobs = data.jobs;
			wrap.removeAttribute( 'aria-busy' );
			drawHistory( wrap );
		} ).catch( ( err ) => {
			wrap.innerHTML = '';
			wrap.appendChild( notice( 'error', err.message ) );
		} );
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
			wrap.appendChild( el( 'div', { class: 'pressport-empty' }, [
				el( 'h2', { text: 'No imports yet' } ),
				el( 'p', { class: 'pressport-muted', text: 'Every import you run shows up here, so you can resume it, run it again or roll it back.' } ),
				el( 'a', { class: 'button button-primary', href: D.importUrl, text: 'Start an import' } ),
			] ) );
			return;
		}

		const body = el( 'tbody' );
		const count = el( 'span', { class: 'pressport-muted', 'aria-live': 'polite' } );
		const fill = () => {
			body.innerHTML = '';
			const q = state.historyQuery.trim().toLowerCase();
			const shown = jobs.filter( ( job ) => ! q || [ job.label, job.options.post_type, STATUS_LABELS[ job.status ], job.settings.site_url, job.settings.url, job.settings.file_url ]
				.some( ( v ) => v && String( v ).toLowerCase().includes( q ) ) );
			shown.forEach( ( job ) => body.appendChild( historyRow( job ) ) );
			if ( ! shown.length ) {
				body.appendChild( el( 'tr', {}, el( 'td', { colspan: 4, class: 'pressport-muted pressport-no-match', text: 'No imports match "' + state.historyQuery + '".' } ) ) );
			}
			count.textContent = shown.length === jobs.length ? jobs.length + ' import' + ( jobs.length === 1 ? '' : 's' ) : shown.length + ' of ' + jobs.length;
		};

		wrap.appendChild( el( 'header', { class: 'pressport-history-head' }, [
			el( 'input', { type: 'search', id: 'pressport-history-search', class: 'pressport-search', placeholder: 'Search by source, site or status', 'aria-label': 'Search history', value: state.historyQuery, oninput: ( e ) => {
				state.historyQuery = e.target.value;
				fill();
			} } ),
			count,
			el( 'a', { class: 'button button-primary pressport-push', href: D.importUrl, text: 'Import' } ),
		] ) );
		wrap.appendChild( el( 'div', { class: 'pressport-table-scroll' }, el( 'table', { class: 'pressport-history' }, [
			el( 'thead', {}, el( 'tr', {}, [ 'Import', 'Status', 'Result', '' ].map( ( h ) => el( 'th', { scope: 'col', text: h } ) ) ) ),
			body,
		] ) ) );
		fill();
	}

	function historyRow( job ) {
		const s = job.stats;
		const dry = Number( job.options.dry_run ) === 1;
		const where = job.settings.site_url || job.settings.url || job.settings.file_url || '';
		const actions = el( 'div', { class: 'pressport-row-actions' } );
		const btn = ( label, fn, cls ) => actions.appendChild( el( 'button', { type: 'button', class: 'button button-small ' + ( cls || '' ), text: label, onclick: fn } ) );

		if ( [ 'running', 'ready', 'failed' ].includes( job.status ) ) {
			btn( 'Resume', () => openJob( job.id, true ), 'button-primary' );
		}
		btn( 'View log', () => openJob( job.id, false ) );
		if ( [ 'complete', 'rolled_back', 'failed' ].includes( job.status ) ) {
			btn( 'Run again', () => restartJob( job ) );
		}
		if ( ! dry && job.status !== 'rolled_back' && Number( s.created ) > 0 ) {
			btn( 'Roll back', () => rollback( job.id ), 'pressport-danger' );
		}
		actions.appendChild( el( 'button', { type: 'button', class: 'pressport-link-danger', text: 'Delete', title: 'Remove from history. Imported content is kept.', onclick: () => {
			// eslint-disable-next-line no-alert
			if ( window.confirm( 'Remove this import from history? Imported content is not deleted, but it can no longer be rolled back.' ) ) {
				api( 'delete', { job: job.id } ).then( () => {
					state.historyJobs = ( state.historyJobs || [] ).filter( ( j ) => j.id !== job.id );
					drawHistory( root.querySelector( '.pressport-history-panel' ) );
				} );
			}
		} } ) );

		const parts = OUTCOMES.filter( ( [ k ] ) => Number( s[ k ] ) > 0 ).map( ( [ k, label, dryLabel ] ) => Number( s[ k ] ).toLocaleString() + ' ' + ( dry ? dryLabel : label ).toLowerCase() );
		return el( 'tr', {}, [
			el( 'td', { class: 'pressport-h-source' }, [
				el( 'span', { class: 'pressport-source-icon is-sm pressport-icon-' + job.source, 'aria-hidden': 'true' } ),
				el( 'div', {}, [
					el( 'strong', { text: job.label } ),
					el( 'span', { class: 'pressport-help' }, [
						where ? hostOf( where ) + ' to ' : 'To ',
						job.options.post_type + ', ',
						el( 'time', { datetime: new Date( job.created * 1000 ).toISOString(), title: formatDate( job.created ), text: relativeTime( job.created ) } ),
					] ),
				] ),
			] ),
			el( 'td', {}, [
				el( 'span', { class: 'pressport-badge pressport-badge-' + job.status, text: STATUS_LABELS[ job.status ] || job.status } ),
				dry ? el( 'span', { class: 'pressport-badge pressport-badge-dry', text: 'Dry run' } ) : null,
			] ),
			el( 'td', { class: 'pressport-h-result' }, [
				outcomeBar( s, job.total, { size: 'sm', label: 'Result' } ),
				el( 'span', { class: 'pressport-help', text: parts.length ? parts.join( ', ' ) : 'Nothing processed yet' } ),
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
			state.job = data.job;
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
		} ).catch( ( err ) => window.alert( err.message ) ); // eslint-disable-line no-alert
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
