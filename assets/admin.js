/* PressPort admin screen. Plain JS, no build step. */
( function () {
	'use strict';

	const D = window.PRESSPORT_DATA;
	const root = document.getElementById( 'pressport-app' );
	if ( ! D || ! root ) {
		return;
	}

	const state = {
		tab: 'new',
		step: 'source',
		sourceId: D.sources[ 0 ] ? D.sources[ 0 ].id : '',
		job: null,
		records: [],
		fields: [],
		mapping: { fields: {}, meta: [], taxonomies: [] },
		options: Object.assign( {}, D.defaults ),
		running: false,
		paused: false,
		results: [],
		error: '',
		busy: false,
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

	function api( action, data ) {
		const body = data instanceof FormData ? data : new FormData();
		if ( ! ( data instanceof FormData ) ) {
			Object.entries( data || {} ).forEach( ( [ k, v ] ) => body.append( k, typeof v === 'object' ? JSON.stringify( v ) : v ) );
		}
		body.append( 'action', 'pressport_' + action );
		body.append( 'nonce', D.nonce );
		return fetch( D.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' } )
			.then( ( r ) => r.text().then( ( t ) => {
				let json;
				try {
					json = JSON.parse( t );
				} catch ( e ) {
					throw new Error( 'Unexpected server response (HTTP ' + r.status + '). ' + t.replace( /<[^>]+>/g, ' ' ).trim().slice( 0, 300 ) );
				}
				if ( ! json.success ) {
					throw new Error( ( json.data && json.data.message ) || 'Request failed.' );
				}
				return json.data || {};
			} ) );
	}

	function normalizeMapping( m ) {
		m = m || {};
		return {
			fields: m.fields && ! Array.isArray( m.fields ) ? Object.assign( {}, m.fields ) : {},
			meta: Array.isArray( m.meta ) ? m.meta.slice() : [],
			taxonomies: Array.isArray( m.taxonomies ) ? m.taxonomies.slice() : [],
		};
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
		return '';
	}

	function truncate( s, n ) {
		s = String( s === null || s === undefined ? '' : s );
		return s.length > n ? s.slice( 0, n ) + '…' : s;
	}

	function fieldSelect( value, onChange ) {
		const select = el( 'select', { class: 'pressport-field-select', onchange: ( e ) => onChange( e.target.value ) }, [
			el( 'option', { value: '', text: '(not mapped)' } ),
		] );
		state.fields.forEach( ( f ) => select.appendChild( el( 'option', { value: f, text: f, selected: f === value } ) ) );
		return select;
	}

	function notice( type, message ) {
		return el( 'div', { class: 'notice notice-' + type + ' inline pressport-notice' }, el( 'p', { text: message } ) );
	}

	function formatDate( ts ) {
		return new Date( ts * 1000 ).toLocaleString();
	}

	// Tabs

	document.querySelectorAll( '.pressport-tabs .nav-tab' ).forEach( ( a ) => {
		a.addEventListener( 'click', ( e ) => {
			e.preventDefault();
			if ( state.running ) {
				return;
			}
			state.tab = a.dataset.tab;
			document.querySelectorAll( '.pressport-tabs .nav-tab' ).forEach( ( b ) => b.classList.toggle( 'nav-tab-active', b === a ) );
			render();
		} );
	} );

	function setTab( tab ) {
		state.tab = tab;
		document.querySelectorAll( '.pressport-tabs .nav-tab' ).forEach( ( b ) => b.classList.toggle( 'nav-tab-active', b.dataset.tab === tab ) );
	}

	// Render

	function render() {
		root.innerHTML = '';
		if ( state.tab === 'history' ) {
			renderHistory();
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
		const steps = [ [ 'source', '1. Source' ], [ 'map', '2. Map & options' ], [ 'run', '3. Import' ] ];
		const idx = steps.findIndex( ( s ) => s[ 0 ] === state.step );
		return el( 'ol', { class: 'pressport-steps' }, steps.map( ( s, i ) =>
			el( 'li', { class: i === idx ? 'is-current' : ( i < idx ? 'is-done' : '' ), text: s[ 1 ] } )
		) );
	}

	// Step 1

	function renderSource() {
		const cards = el( 'div', { class: 'pressport-source-cards' }, D.sources.map( ( s ) =>
			el( 'label', { class: 'pressport-source-card' + ( s.id === state.sourceId ? ' is-selected' : '' ) }, [
				el( 'input', { type: 'radio', name: 'pressport_source', value: s.id, checked: s.id === state.sourceId, onchange: () => {
					state.sourceId = s.id;
					state.error = '';
					render();
				} } ),
				el( 'span', { class: 'pressport-source-icon pressport-icon-' + s.id, 'aria-hidden': 'true' } ),
				el( 'strong', { text: s.label } ),
			] )
		) );

		const source = D.sources.find( ( s ) => s.id === state.sourceId );
		const form = el( 'form', { class: 'pressport-card pressport-source-form', onsubmit: onPreview } );
		const table = el( 'table', { class: 'form-table', role: 'presentation' } );
		source.schema.forEach( ( f ) => {
			const id = 'pressport-set-' + f.key;
			let input;
			const name = f.type === 'file' ? 'file_' + f.key : 'settings[' + f.key + ']';
			if ( f.type === 'select' ) {
				input = el( 'select', { id, name }, Object.entries( f.options ).map( ( [ v, l ] ) =>
					el( 'option', { value: v, text: l, selected: v === f.default } )
				) );
			} else if ( f.type === 'textarea' ) {
				input = el( 'textarea', { id, name, rows: 3, class: 'large-text code' } );
			} else if ( f.type === 'file' ) {
				input = el( 'input', { id, name, type: 'file', accept: f.accept || '' } );
			} else {
				input = el( 'input', {
					id, name,
					type: f.type === 'password' ? 'password' : ( f.type === 'url' ? 'url' : ( f.type === 'number' ? 'number' : 'text' ) ),
					class: 'regular-text',
					value: f.default || '',
					autocomplete: f.type === 'password' ? 'new-password' : 'off',
					required: f.required && f.type !== 'file' ? true : null,
				} );
			}
			const help = [];
			if ( f.help ) {
				help.push( el( 'p', { class: 'description', text: f.help } ) );
			}
			if ( f.type === 'file' ) {
				help.push( el( 'p', { class: 'description', text: 'Max upload size: ' + D.maxUpload } ) );
			}
			table.appendChild( el( 'tr', {}, [
				el( 'th', { scope: 'row' }, el( 'label', { for: id, text: f.label + ( f.required ? ' *' : '' ) } ) ),
				el( 'td', {}, [ input ].concat( help ) ),
			] ) );
		} );
		form.appendChild( el( 'h2', { text: source.label } ) );
		form.appendChild( table );
		form.appendChild( el( 'p', { class: 'submit' }, [
			el( 'button', { type: 'submit', class: 'button button-primary button-hero', disabled: state.busy, text: state.busy ? 'Connecting…' : 'Connect & preview' } ),
		] ) );

		root.appendChild( cards );
		root.appendChild( form );
	}

	function onPreview( e ) {
		e.preventDefault();
		const fd = new FormData( e.target );
		fd.append( 'source', state.sourceId );
		state.busy = true;
		state.error = '';
		render();
		api( 'preview', fd ).then( ( data ) => {
			state.job = data.job;
			state.records = data.records;
			state.fields = data.fields;
			state.mapping = normalizeMapping( data.job.mapping );
			state.options = Object.assign( {}, D.defaults, data.job.options );
			state.step = 'map';
		} ).catch( ( err ) => {
			state.error = err.message;
		} ).finally( () => {
			state.busy = false;
			render();
		} );
	}

	// Step 2

	function renderMap() {
		const job = state.job;
		const total = job.total === null || job.total === undefined ? 'an unknown number of' : job.total;
		root.appendChild( notice( 'success', 'Connected. Found ' + total + ' records with ' + state.fields.length + ' fields. Map them to WordPress below.' ) );

		// Preview table.
		const cols = state.fields.slice( 0, 40 );
		const preview = el( 'div', { class: 'pressport-card' }, [
			el( 'h2', { text: 'Preview (first ' + state.records.length + ' records)' } ),
			el( 'div', { class: 'pressport-preview-scroll' }, el( 'table', { class: 'widefat striped pressport-preview' }, [
				el( 'thead', {}, el( 'tr', {}, cols.map( ( c ) => el( 'th', { text: c } ) ) ) ),
				el( 'tbody', {}, state.records.map( ( r ) => el( 'tr', {}, cols.map( ( c ) => el( 'td', { title: r[ c ] || '', text: truncate( r[ c ], 80 ) } ) ) ) ) ),
			] ) ),
			state.fields.length > cols.length ? el( 'p', { class: 'description', text: 'Showing 40 of ' + state.fields.length + ' fields. All fields are available in the mapping menus.' } ) : null,
		] );
		root.appendChild( preview );

		const grid = el( 'div', { class: 'pressport-grid' } );
		grid.appendChild( renderMappingCard() );
		grid.appendChild( renderOptionsCard() );
		root.appendChild( grid );

		root.appendChild( el( 'p', { class: 'pressport-actions' }, [
			el( 'button', { type: 'button', class: 'button', text: '← Back', onclick: () => {
				state.step = 'source';
				state.error = '';
				render();
			} } ),
			el( 'button', { type: 'button', class: 'button', disabled: state.busy, text: 'Dry run', onclick: () => start( true ) } ),
			el( 'button', { type: 'button', class: 'button button-primary', disabled: state.busy, text: 'Start import', onclick: () => start( false ) } ),
		] ) );
	}

	function renderMappingCard() {
		const m = state.mapping;
		const rows = D.targets.map( ( t ) => {
			const sampleCell = el( 'td', { class: 'pressport-sample', text: truncate( sample( m.fields[ t.key ] ), 120 ) } );
			return el( 'tr', {}, [
				el( 'th', { scope: 'row' }, [ el( 'span', { text: t.label } ), t.help ? el( 'span', { class: 'pressport-help', text: t.help } ) : null ] ),
				el( 'td', {}, fieldSelect( m.fields[ t.key ] || '', ( v ) => {
					if ( v ) {
						m.fields[ t.key ] = v;
					} else {
						delete m.fields[ t.key ];
					}
					sampleCell.textContent = truncate( sample( v ), 120 );
				} ) ),
				sampleCell,
			] );
		} );

		const metaRows = m.meta.map( ( row, i ) => el( 'tr', {}, [
			el( 'td', {}, el( 'input', { type: 'text', class: 'regular-text', placeholder: 'meta_key', value: row.key || '', oninput: ( e ) => {
				row.key = e.target.value;
			} } ) ),
			el( 'td', {}, fieldSelect( row.source, ( v ) => {
				row.source = v;
			} ) ),
			el( 'td', {}, el( 'button', { type: 'button', class: 'button-link pressport-remove', text: 'Remove', onclick: () => {
				m.meta.splice( i, 1 );
				render();
			} } ) ),
		] ) );

		const taxes = postType( state.options.post_type ).taxonomies.filter( ( t ) => t.name !== 'category' && t.name !== 'post_tag' );
		const taxRows = m.taxonomies.map( ( row, i ) => el( 'tr', {}, [
			el( 'td', {}, el( 'select', { onchange: ( e ) => {
				row.taxonomy = e.target.value;
			} }, [ el( 'option', { value: '', text: 'Choose taxonomy' } ) ].concat( taxes.map( ( t ) => el( 'option', { value: t.name, text: t.label + ' (' + t.name + ')', selected: t.name === row.taxonomy } ) ) ) ) ),
			el( 'td', {}, fieldSelect( row.source, ( v ) => {
				row.source = v;
			} ) ),
			el( 'td', {}, el( 'button', { type: 'button', class: 'button-link pressport-remove', text: 'Remove', onclick: () => {
				m.taxonomies.splice( i, 1 );
				render();
			} } ) ),
		] ) );

		return el( 'div', { class: 'pressport-card pressport-mapping' }, [
			el( 'h2', { text: 'Field mapping' } ),
			el( 'table', { class: 'widefat pressport-map-table' }, [
				el( 'thead', {}, el( 'tr', {}, [ el( 'th', { text: 'WordPress field' } ), el( 'th', { text: 'Source field' } ), el( 'th', { text: 'Sample value' } ) ] ) ),
				el( 'tbody', {}, rows ),
			] ),
			el( 'h3', { text: 'Custom fields (post meta)' } ),
			m.meta.length ? el( 'table', { class: 'widefat pressport-sub-table' }, el( 'tbody', {}, metaRows ) ) : el( 'p', { class: 'description', text: 'Store any source field as post meta (works with ACF field names too).' } ),
			el( 'p', {}, el( 'button', { type: 'button', class: 'button', text: '+ Add custom field', onclick: () => {
				m.meta.push( { key: '', source: '' } );
				render();
			} } ) ),
			el( 'h3', { text: 'Other taxonomies' } ),
			taxes.length ? ( m.taxonomies.length ? el( 'table', { class: 'widefat pressport-sub-table' }, el( 'tbody', {}, taxRows ) ) : el( 'p', { class: 'description', text: 'Assign terms in custom taxonomies registered for this post type.' } ) ) : el( 'p', { class: 'description', text: 'This post type has no additional taxonomies.' } ),
			taxes.length ? el( 'p', {}, el( 'button', { type: 'button', class: 'button', text: '+ Add taxonomy', onclick: () => {
				m.taxonomies.push( { taxonomy: '', source: '' } );
				render();
			} } ) ) : null,
		] );
	}

	function renderOptionsCard() {
		const o = state.options;
		const set = ( k ) => ( e ) => {
			o[ k ] = e.target.type === 'checkbox' ? ( e.target.checked ? 1 : 0 ) : e.target.value;
		};
		const check = ( k, label, help ) => el( 'p', {}, [
			el( 'label', {}, [ el( 'input', { type: 'checkbox', checked: !! Number( o[ k ] ), onchange: set( k ) } ), ' ' + label ] ),
			help ? el( 'span', { class: 'pressport-help', text: help } ) : null,
		] );
		const row = ( label, control, help ) => el( 'p', { class: 'pressport-opt' }, [
			el( 'label', {}, [ el( 'span', { class: 'pressport-opt-label', text: label } ), control ] ),
			help ? el( 'span', { class: 'pressport-help', text: help } ) : null,
		] );

		return el( 'div', { class: 'pressport-card pressport-options' }, [
			el( 'h2', { text: 'Import options' } ),
			row( 'Import as', el( 'select', { onchange: ( e ) => {
				o.post_type = e.target.value;
				render();
			} }, D.postTypes.map( ( p ) => el( 'option', { value: p.name, text: p.label + ' (' + p.name + ')', selected: p.name === o.post_type } ) ) ) ),
			row( 'Default status', el( 'select', { onchange: set( 'default_status' ) }, [ 'publish', 'draft', 'pending', 'private' ].map( ( s ) =>
				el( 'option', { value: s, text: s, selected: s === o.default_status } ) ) ), 'Used when the status field is not mapped or not recognised.' ),
			row( 'Default author', el( 'select', { onchange: set( 'default_author' ) }, D.users.map( ( u ) =>
				el( 'option', { value: u.id, text: u.name, selected: Number( u.id ) === Number( o.default_author ) } ) ) ) ),
			row( 'Already imported records', el( 'select', { onchange: set( 'duplicate' ) }, [
				[ 'skip', 'Skip them' ], [ 'update', 'Update them' ], [ 'create', 'Create duplicates' ],
			].map( ( [ v, l ] ) => el( 'option', { value: v, text: l, selected: v === o.duplicate } ) ) ), 'Detected by the mapped Source ID.' ),
			row( 'Batch size', el( 'input', { type: 'number', min: 1, max: 100, value: o.batch_size, class: 'small-text', onchange: set( 'batch_size' ) } ), 'Records per request. Lower it if image imports time out.' ),
			check( 'import_featured_image', 'Download featured images' ),
			check( 'import_content_images', 'Download images inside content', 'Copies <img> files into the Media Library and rewrites their URLs.' ),
			check( 'create_terms', 'Create missing categories, tags and terms' ),
			check( 'create_authors', 'Create missing authors', 'New users get the Author role and a random password.' ),
		] );
	}

	function start( dry ) {
		state.options.dry_run = dry ? 1 : 0;
		state.busy = true;
		state.error = '';
		render();
		api( 'save', { job: state.job.id, mapping: state.mapping, options: state.options } ).then( ( data ) => {
			state.job = data.job;
			state.results = [];
			state.step = 'run';
			state.busy = false;
			state.paused = false;
			render();
			runLoop();
		} ).catch( ( err ) => {
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
		const pct = total ? Math.min( 100, Math.round( ( s.processed / total ) * 100 ) ) : ( job.status === 'complete' ? 100 : 0 );
		const dry = Number( job.options.dry_run ) === 1;
		const done = [ 'complete', 'rolled_back' ].includes( job.status );

		let statusText;
		if ( job.status === 'rolled_back' ) {
			statusText = 'Rolled back.';
		} else if ( done ) {
			statusText = dry ? 'Dry run complete. Nothing was saved.' : 'Import complete.';
		} else if ( state.paused ) {
			statusText = 'Paused.';
		} else {
			statusText = ( dry ? 'Dry run in progress… ' : 'Importing… ' ) + 'Keep this tab open.';
		}

		const card = el( 'div', { class: 'pressport-card pressport-run' }, [
			el( 'h2', { text: statusText } ),
			el( 'div', { class: 'pressport-progress', role: 'progressbar', 'aria-valuenow': pct, 'aria-valuemin': 0, 'aria-valuemax': 100 }, el( 'span', { style: 'width:' + pct + '%' } ) ),
			el( 'p', { class: 'pressport-progress-text', text: s.processed + ( total ? ' of ' + total : '' ) + ' processed' + ( total ? ' (' + pct + '%)' : '' ) } ),
			el( 'ul', { class: 'pressport-stats' }, [
				[ 'created', dry ? 'Would create' : 'Created' ], [ 'updated', dry ? 'Would update' : 'Updated' ], [ 'skipped', 'Skipped' ], [ 'failed', 'Failed' ],
			].map( ( [ k, l ] ) => el( 'li', { class: 'pressport-stat-' + k }, [ el( 'strong', { text: s[ k ] } ), el( 'span', { text: l } ) ] ) ) ),
		] );

		const actions = el( 'p', { class: 'pressport-actions' } );
		if ( ! done ) {
			actions.appendChild( state.paused
				? el( 'button', { type: 'button', class: 'button button-primary', text: 'Resume', onclick: () => {
					state.paused = false;
					state.error = '';
					render();
					runLoop();
				} } )
				: el( 'button', { type: 'button', class: 'button', text: 'Pause', onclick: () => {
					state.paused = true;
					render();
				} } ) );
		} else {
			if ( dry ) {
				actions.appendChild( el( 'button', { type: 'button', class: 'button button-primary', text: 'Run the real import', onclick: () => start( false ) } ) );
				actions.appendChild( el( 'button', { type: 'button', class: 'button', text: 'Adjust mapping', onclick: () => {
					state.step = 'map';
					render();
				} } ) );
			} else if ( job.status === 'complete' ) {
				actions.appendChild( el( 'a', { class: 'button button-primary', href: D.adminUrl + 'edit.php?post_type=' + encodeURIComponent( job.options.post_type ), text: 'View imported content' } ) );
				if ( Number( s.created ) > 0 ) {
					actions.appendChild( el( 'button', { type: 'button', class: 'button pressport-danger', text: 'Roll back this import', onclick: () => rollback( job.id ) } ) );
				}
			}
			actions.appendChild( el( 'button', { type: 'button', class: 'button', text: 'New import', onclick: reset } ) );
		}
		card.appendChild( actions );
		root.appendChild( card );

		const log = el( 'div', { class: 'pressport-card' }, [ el( 'h2', { text: 'Log' } ) ] );
		const list = el( 'ol', { class: 'pressport-log', reversed: true } );
		const entries = ( job.log || [] ).slice().reverse();
		entries.forEach( ( e ) => list.appendChild( el( 'li', { class: 'pressport-log-' + e.l }, [ el( 'time', { text: new Date( e.t * 1000 ).toLocaleTimeString() } ), ' ', e.m ] ) ) );
		if ( ! entries.length ) {
			list.appendChild( el( 'li', { text: 'Waiting for the first batch…' } ) );
		}
		log.appendChild( list );
		root.appendChild( log );
	}

	function runLoop() {
		if ( state.running ) {
			return;
		}
		state.running = true;
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
		setTab( 'new' );
		render();
	}

	function rollback( jobId ) {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( 'Permanently delete every post and media file created by this import? Posts that were only updated are kept.' ) ) {
			return;
		}
		const status = el( 'div', { class: 'notice notice-warning inline pressport-notice' }, el( 'p', { text: 'Rolling back…' } ) );
		root.prepend( status );
		const step = () => api( 'rollback', { job: jobId } ).then( ( data ) => {
			status.firstChild.textContent = 'Rolling back… ' + data.remaining + ' items remaining.';
			if ( state.job && state.job.id === jobId ) {
				state.job = data.job;
			}
			if ( data.done ) {
				if ( state.tab === 'history' ) {
					renderHistory();
				} else {
					render();
				}
				return;
			}
			return step();
		} ).catch( ( err ) => {
			status.className = 'notice notice-error inline pressport-notice';
			status.firstChild.textContent = err.message;
		} );
		step();
	}

	// History

	function renderHistory() {
		root.innerHTML = '';
		const wrap = el( 'div', { class: 'pressport-card' }, [ el( 'h2', { text: 'Import history' } ), el( 'p', { text: 'Loading…' } ) ] );
		root.appendChild( wrap );
		api( 'jobs', {} ).then( ( data ) => {
			wrap.innerHTML = '';
			wrap.appendChild( el( 'h2', { text: 'Import history' } ) );
			if ( ! data.jobs.length ) {
				wrap.appendChild( el( 'p', { text: 'No imports yet.' } ) );
				return;
			}
			const tbody = el( 'tbody' );
			data.jobs.forEach( ( job ) => {
				const s = job.stats;
				const dry = Number( job.options.dry_run ) === 1;
				const actions = el( 'div', { class: 'pressport-row-actions' } );
				const btn = ( label, fn, cls ) => actions.appendChild( el( 'button', { type: 'button', class: 'button button-small ' + ( cls || '' ), text: label, onclick: fn } ) );

				btn( 'View log', () => openJob( job.id, false ) );
				if ( [ 'running', 'ready', 'failed' ].includes( job.status ) ) {
					btn( 'Resume', () => openJob( job.id, true ), 'button-primary' );
				}
				if ( [ 'complete', 'rolled_back', 'failed' ].includes( job.status ) ) {
					btn( 'Run again', () => {
						api( 'restart', { job: job.id } ).then( () => openJob( job.id, true ) ).catch( ( e ) => window.alert( e.message ) ); // eslint-disable-line no-alert
					} );
				}
				if ( ! dry && job.status !== 'rolled_back' && Number( s.created ) > 0 ) {
					btn( 'Roll back', () => rollback( job.id ), 'pressport-danger' );
				}
				btn( 'Delete record', () => {
					// eslint-disable-next-line no-alert
					if ( window.confirm( 'Remove this job from history? Imported content is not deleted.' ) ) {
						api( 'delete', { job: job.id } ).then( renderHistory );
					}
				} );

				tbody.appendChild( el( 'tr', {}, [
					el( 'td', { text: formatDate( job.created ) } ),
					el( 'td', {}, [ el( 'strong', { text: job.label } ), el( 'br' ), el( 'span', { class: 'pressport-help', text: job.settings.site_url || job.settings.url || '' } ) ] ),
					el( 'td', { text: job.options.post_type } ),
					el( 'td', {}, el( 'span', { class: 'pressport-badge pressport-badge-' + job.status, text: ( dry ? 'dry run · ' : '' ) + job.status.replace( '_', ' ' ) } ) ),
					el( 'td', { text: s.created + ' / ' + s.updated + ' / ' + s.skipped + ' / ' + s.failed } ),
					el( 'td', {}, actions ),
				] ) );
			} );
			wrap.appendChild( el( 'table', { class: 'widefat striped pressport-history' }, [
				el( 'thead', {}, el( 'tr', {}, [ 'Date', 'Source', 'Post type', 'Status', 'Created / Updated / Skipped / Failed', '' ].map( ( h ) => el( 'th', { text: h } ) ) ) ),
				tbody,
			] ) );
		} ).catch( ( err ) => {
			wrap.appendChild( notice( 'error', err.message ) );
		} );
	}

	function openJob( id, resume ) {
		api( 'job', { job: id } ).then( ( data ) => {
			state.job = data.job;
			state.step = 'run';
			state.error = '';
			state.paused = ! resume;
			setTab( 'new' );
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
}() );
