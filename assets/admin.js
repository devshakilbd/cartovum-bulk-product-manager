/**
 * Bulk Stock & Attributes screen.
 *
 * The browser drives the work: it finds products, holds the selection, and then feeds the
 * selected IDs to the server in small batches so no single request has to do everything.
 */
( function () {
	'use strict';

	var state = {
		rows: [],
		selected: new Map(),
		page: 1,
		pages: 0,
		total: 0,
		running: false,
		stop: false,
	};

	var $ = function ( id ) {
		return document.getElementById( id );
	};

	/**
	 * A whole number from the settings WordPress passes in. wp_localize_script hands every setting over
	 * as text, so "10" has to become 10 before it is used for counting or slicing.
	 *
	 * @param {*} value    The setting.
	 * @param {number} fallback Used when the setting is missing or not a positive number.
	 * @return {number}
	 */
	function whole( value, fallback ) {
		var number = parseInt( value, 10 );

		return number > 0 ? number : fallback;
	}

	/** Products per request, and the most attribute conditions the screen offers. */
	var BATCH_SIZE     = whole( SWBM.batchSize, 10 );
	var MAX_CONDITIONS = whole( SWBM.maxConditions, 10 );

	function esc( value ) {
		return String( value === null || value === undefined ? '' : value ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	/**
	 * Add one request field, the way PHP reads nested fields: a list of plain values becomes key[],
	 * a list of objects becomes key[0][field], and an object becomes key[field].
	 */
	function appendField( body, key, value ) {
		if ( Array.isArray( value ) ) {
			value.forEach( function ( item, index ) {
				if ( item && typeof item === 'object' ) {
					appendField( body, key + '[' + index + ']', item );
				} else {
					body.append( key + '[]', item );
				}
			} );
		} else if ( value && typeof value === 'object' ) {
			Object.keys( value ).forEach( function ( sub ) {
				appendField( body, key + '[' + sub + ']', value[ sub ] );
			} );
		} else if ( value !== undefined && value !== null ) {
			body.append( key, value );
		}
	}

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', 'swbm_' + action );
		body.append( 'nonce', SWBM.nonce );

		Object.keys( data || {} ).forEach( function ( key ) {
			appendField( body, key, data[ key ] );
		} );

		return fetch( SWBM.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( json && json.data && json.data.message ? json.data.message : 'The server refused that request.' );
				}
				return json.data;
			} );
	}

	/* ---------------------------------------------------------------- filters */

	function attributeOptions( includeAny ) {
		var options = includeAny ? '<option value="">Any attribute</option>' : '';

		SWBM.attributes.forEach( function ( attribute ) {
			options += '<option value="' + esc( attribute.key ) + '">' + esc( attribute.label ) + ' (global)</option>';
		} );
		SWBM.localNames.forEach( function ( name ) {
			options += '<option value="local:' + esc( name ) + '">' + esc( name ) + ' (local)</option>';
		} );

		return options;
	}

	function findAttribute( key ) {
		return SWBM.attributes.filter( function ( attribute ) {
			return attribute.key === key;
		} )[ 0 ];
	}

	/* Attribute conditions: every row is one condition, and a product has to match all of them. */

	function conditionRows() {
		return Array.prototype.slice.call( $( 'swbm-condition-list' ).querySelectorAll( '.swbm-condition' ) );
	}

	function valueOptions( key ) {
		var options = '<option value="">Any value</option>';
		var attribute = findAttribute( key );

		if ( attribute ) {
			return options + attribute.terms.map( function ( term ) {
				return '<option value="' + term.id + '">' + esc( term.name ) + ' (' + term.count + ')</option>';
			} ).join( '' );
		}

		if ( key && key.indexOf( 'local:' ) === 0 ) {
			return options + ( ( SWBM.localValues || {} )[ key.slice( 6 ) ] || [] ).map( function ( item ) {
				return '<option value="' + esc( item.value ) + '">' + esc( item.value ) + ' (' + item.count + ')</option>';
			} ).join( '' );
		}

		return options;
	}

	function fillConditionValues( row ) {
		var key = row.querySelector( '.swbm-cond-attribute' ).value;
		var select = row.querySelector( '.swbm-cond-value' );

		select.innerHTML = valueOptions( key );
		select.disabled = ! key;
	}

	function updateConditionControls() {
		var rows = conditionRows();
		var full = rows.length >= MAX_CONDITIONS;

		rows.forEach( function ( row, index ) {
			row.setAttribute( 'aria-label', 'Condition ' + ( index + 1 ) );
			row.querySelector( '.swbm-cond-remove' ).hidden = index === 0;
		} );

		$( 'swbm-add-condition' ).disabled = full;
		$( 'swbm-condition-limit' ).hidden = ! full;
	}

	function addCondition() {
		var rows = conditionRows();
		if ( rows.length >= MAX_CONDITIONS ) {
			return;
		}

		// The new row copies the first row's structure; ids stay unique to the first row.
		var row = rows[ 0 ].cloneNode( true );
		Array.prototype.forEach.call( row.querySelectorAll( '[id]' ), function ( element ) {
			element.removeAttribute( 'id' );
		} );

		var attribute = row.querySelector( '.swbm-cond-attribute' );
		attribute.innerHTML = attributeOptions( true );
		attribute.value = '';
		fillConditionValues( row );

		$( 'swbm-condition-list' ).appendChild( row );
		updateConditionControls();
		attribute.focus();
	}

	function removeCondition( row ) {
		if ( conditionRows().indexOf( row ) < 1 ) {
			return;
		}

		row.remove();
		updateConditionControls();
		$( 'swbm-add-condition' ).focus();
	}

	function resetConditions() {
		conditionRows().forEach( function ( row, index ) {
			if ( index > 0 ) {
				row.remove();
			}
		} );

		var first = conditionRows()[ 0 ];
		first.querySelector( '.swbm-cond-attribute' ).value = '';
		fillConditionValues( first );
		updateConditionControls();
	}

	function readConditions() {
		return conditionRows().map( function ( row ) {
			var attribute = row.querySelector( '.swbm-cond-attribute' ).value;
			var value = row.querySelector( '.swbm-cond-value' ).value;

			if ( ! attribute ) {
				return null;
			}

			return attribute.indexOf( 'local:' ) === 0
				? { attribute: attribute, value: value }
				: { attribute: attribute, term: value };
		} ).filter( Boolean );
	}

	function fillOpFields() {
		var op = $( 'swbm-op' ).value;
		var key = $( 'swbm-op-attribute' ).value;
		var attribute = findAttribute( key );
		var wantsTerms = op === 'add_terms' || op === 'remove_terms' || op === 'set_terms';

		$( 'swbm-op-terms-row' ).hidden = ! wantsTerms;
		$( 'swbm-op-value-row' ).hidden = op !== 'set_local_value';

		$( 'swbm-op-terms' ).innerHTML = attribute
			? attribute.terms.map( function ( term ) {
				return '<option value="' + term.id + '">' + esc( term.name ) + ' (' + term.count + ')</option>';
			} ).join( '' )
			: '';
	}

	function filters() {
		return {
			search: $( 'swbm-search' ).value,
			skus: $( 'swbm-skus' ).value,
			category: $( 'swbm-category' ).value,
			stock_status: $( 'swbm-stock-filter' ).value,
			post_status: $( 'swbm-post-status' ).value,
			conditions: readConditions(),
			per_page: $( 'swbm-per-page' ).value,
		};
	}

	/* ------------------------------------------------------------------ table */

	/**
	 * Run a search. A fresh search reads the form; paging and refreshes reuse the filters of the last
	 * search, so its conditions stay in force until "Find products" is pressed again.
	 */
	function find( page, fresh ) {
		if ( fresh || ! state.applied ) {
			state.applied = filters();
		}

		var args = Object.assign( {}, state.applied, { paged: page || 1 } );

		$( 'swbm-table-wrap' ).innerHTML = '<p class="swbm-empty">Searching…</p>';

		return post( 'search', args ).then( function ( data ) {
			state.rows = data.items;
			state.page = data.paged;
			state.pages = data.pages;
			state.total = data.total;
			renderTable();
		} ).catch( showError );
	}

	function renderTable() {
		var wrap = $( 'swbm-table-wrap' );

		if ( ! state.rows.length ) {
			wrap.innerHTML = '<p class="swbm-empty">No products matched.</p>';
		} else {
			var rows = state.rows.map( function ( row ) {
				var attributes = row.attributes.map( function ( attribute ) {
					return '<span class="swbm-attr swbm-attr-' + esc( attribute.type ) + '" title="' + esc( attribute.type === 'global' ? 'Global attribute' : 'Local attribute' ) + '">' +
						esc( attribute.label ) + ': ' + esc( attribute.values.join( ', ' ) || '—' ) + '</span>';
				} ).join( ' ' );

				return '<tr>' +
					'<td><input type="checkbox" class="swbm-row" value="' + row.id + '"' + ( state.selected.has( row.id ) ? ' checked' : '' ) + '></td>' +
					'<td><a href="' + esc( row.edit_link ) + '" target="_blank" rel="noopener">' + esc( row.name ) + '</a></td>' +
					'<td>' + esc( row.sku ) + '</td>' +
					'<td>' + esc( row.status ) + '</td>' +
					'<td class="swbm-stock-' + esc( row.stock_status ) + '">' + esc( row.stock_status ) + '</td>' +
					'<td>' + esc( row.type ) + '</td>' +
					'<td>' + esc( row.categories.slice( 0, 2 ).join( ', ' ) ) + '</td>' +
					'<td>' + attributes + '</td>' +
					'</tr>';
			} ).join( '' );

			wrap.innerHTML = '<table class="widefat striped swbm-table"><thead><tr>' +
				'<th class="check-column"></th><th>Product</th><th>SKU</th><th>State</th><th>Stock</th><th>Type</th><th>Categories</th><th>Attributes</th>' +
				'</tr></thead><tbody>' + rows + '</tbody></table>';

			Array.prototype.forEach.call( wrap.querySelectorAll( '.swbm-row' ), function ( box ) {
				box.addEventListener( 'change', function () {
					var id = parseInt( box.value, 10 );
					if ( box.checked ) {
						state.selected.set( id, true );
					} else {
						state.selected.delete( id );
					}
					updateCounts();
				} );
			} );
		}

		$( 'swbm-found-count' ).textContent = state.total + ' found';
		$( 'swbm-page-info' ).textContent = state.pages ? 'Page ' + state.page + ' of ' + state.pages : '';
		$( 'swbm-prev' ).disabled = state.page <= 1;
		$( 'swbm-next' ).disabled = state.page >= state.pages;
		updateCounts();
	}

	function updateCounts() {
		$( 'swbm-selected-count' ).textContent = state.selected.size;
	}

	function showError( error ) {
		window.alert( error.message || String( error ) );
	}

	/* ------------------------------------------------------------------- runs */

	function selectedIds() {
		return Array.from( state.selected.keys() );
	}

	function chunk( list, size ) {
		var step = whole( size, 10 );
		var out = [];

		for ( var i = 0; i < list.length; i += step ) {
			out.push( list.slice( i, i + step ) );
		}

		return out;
	}

	function startRun( total ) {
		state.running = true;
		state.stop = false;
		$( 'swbm-run-panel' ).hidden = false;
		$( 'swbm-stop' ).hidden = false;
		$( 'swbm-results' ).innerHTML = '';
		progress( 0, total );
	}

	function progress( done, total ) {
		var percent = total ? Math.round( ( done / total ) * 100 ) : 0;
		$( 'swbm-progress-bar' ).style.width = percent + '%';
		$( 'swbm-progress-text' ).textContent = done + ' of ' + total + ' processed (' + percent + '%)';
	}

	function appendResults( results ) {
		var counts = { changed: 0, skipped: 0, failed: 0 };
		var html = results.map( function ( row ) {
			counts[ row.status ] = ( counts[ row.status ] || 0 ) + 1;
			return '<li class="swbm-result swbm-' + esc( row.status ) + '"><strong>' + esc( row.status ) + '</strong> ' +
				esc( row.sku || row.id ) + ' — ' + esc( row.name ) + '<br><span>' + esc( row.message ) + '</span></li>';
		} ).join( '' );

		var list = $( 'swbm-results' ).querySelector( 'ul' );
		if ( ! list ) {
			$( 'swbm-results' ).innerHTML = '<ul class="swbm-result-list"></ul>';
			list = $( 'swbm-results' ).querySelector( 'ul' );
		}
		list.insertAdjacentHTML( 'beforeend', html );

		return counts;
	}

	function endRun( totals ) {
		state.running = false;
		$( 'swbm-stop' ).hidden = true;
		$( 'swbm-progress-text' ).textContent += ' — done. ' +
			totals.changed + ' changed, ' + totals.skipped + ' skipped, ' + totals.failed + ' failed.';
		loadJobs();
	}

	/**
	 * Feed IDs to the server in batches.
	 *
	 * options.perBatch( batch ) adds request fields for one batch.
	 * options.stopOnFailure stops before the next batch once any product fails its check.
	 */
	function runBatches( kind, params, ids, options ) {
		var opts = options || {};
		var batches = chunk( ids, BATCH_SIZE );
		var done = 0;
		var totals = { changed: 0, skipped: 0, failed: 0 };

		startRun( ids.length );

		return batches.reduce( function ( chain, batch ) {
			return chain.then( function () {
				if ( state.stop ) {
					return;
				}

				var args = Object.assign( {}, params, opts.perBatch ? opts.perBatch( batch ) : {}, { kind: kind, ids: batch } );

				return post( 'run_batch', args ).then( function ( data ) {
					var counts = appendResults( data.results );
					totals.changed += counts.changed || 0;
					totals.skipped += counts.skipped || 0;
					totals.failed += counts.failed || 0;
					// Count what the server reported on, not what was sent, so nothing can go missing quietly.
					done += data.results.length;
					progress( done, ids.length );

					if ( opts.stopOnFailure && counts.failed ) {
						state.stop = true;
						$( 'swbm-progress-text' ).textContent += ' — stopped: a product failed its check, so nothing after it was changed. Review the results, then use Put back if needed.';
					}
				} );
			} );
		}, Promise.resolve() ).then( function () {
			endRun( totals );

			if ( ! state.stop && done < ids.length ) {
				$( 'swbm-progress-text' ).textContent += ' Warning: ' + ( ids.length - done ) +
					' of the ' + ids.length + ' selected products were not processed and are unchanged. Run them again.';
			}

			find( state.page );
		} ).catch( function ( error ) {
			state.running = false;
			$( 'swbm-stop' ).hidden = true;
			showError( error );
		} );
	}

	/* ---------------------------------------------------------------- convert */

	var convert = { products: [], byId: {} };

	function convertDryRun() {
		var button = $( 'swbm-convert-dryrun' );
		var summary = $( 'swbm-convert-summary' );
		var collected = [];

		button.disabled = true;
		$( 'swbm-convert-run' ).disabled = true;
		$( 'swbm-convert-download' ).disabled = true;
		summary.innerHTML = '<p class="swbm-empty">Reading the catalogue…</p>';

		var page = function ( paged ) {
			return post( 'convert_dryrun', { paged: paged, per_page: 100 } ).then( function ( data ) {
				collected = collected.concat( data.products );
				summary.innerHTML = '<p class="swbm-empty">Reading the catalogue… page ' + paged + ' of ' + data.pages + '</p>';
				return paged < data.pages ? page( paged + 1 ) : null;
			} );
		};

		page( 1 ).then( function () {
			convert.products = collected;
			convert.byId = {};
			collected.forEach( function ( product ) {
				convert.byId[ product.id ] = product;
			} );
			renderConvertSummary();
			$( 'swbm-convert-download' ).disabled = ! collected.length;
			$( 'swbm-convert-run' ).disabled = ! collected.some( function ( product ) {
				return product.state === 'ready';
			} );
		} ).catch( function ( error ) {
			summary.innerHTML = '';
			showError( error );
		} ).then( function () {
			button.disabled = false;
		} );
	}

	function renderConvertSummary() {
		var states = {};
		var values = {};

		convert.products.forEach( function ( product ) {
			states[ product.state ] = ( states[ product.state ] || 0 ) + 1;
			product.rows.forEach( function ( row ) {
				var key = [ row.attribute, row.current, row.new, row.mapping, row.state ].join( '\u0000' );
				values[ key ] = ( values[ key ] || 0 ) + 1;
			} );
		} );

		var rows = Object.keys( values ).sort().map( function ( key ) {
			var parts = key.split( '\u0000' );
			return '<tr><td>' + esc( parts[ 0 ] ) + '</td><td>' + esc( parts[ 1 ] ) + '</td><td>' + esc( parts[ 2 ] || '—' ) + '</td><td>' + esc( parts[ 3 ] ) + '</td><td>' + esc( parts[ 4 ] ) + '</td><td class="num">' + values[ key ] + '</td></tr>';
		} ).join( '' );

		var attention = convert.products.filter( function ( product ) {
			return product.state !== 'ready';
		} ).map( function ( product ) {
			return '<li class="swbm-result swbm-skipped"><strong>' + esc( product.state ) + '</strong> ' + esc( product.sku || product.id ) + ' — ' + esc( product.name ) + '<br><span>' + esc( product.note ) + '</span></li>';
		} ).join( '' );

		$( 'swbm-convert-summary' ).innerHTML =
			'<p>' + convert.products.length + ' products have typed attributes: <strong>' + ( states.ready || 0 ) + '</strong> ready, <strong>' + ( states[ 'needs-approval' ] || 0 ) + '</strong> awaiting approval, <strong>' + ( states.blocked || 0 ) + '</strong> blocked, <strong>' + ( states.skipped || 0 ) + '</strong> skipped.</p>' +
			'<table class="widefat striped"><thead><tr><th>Attribute</th><th>Typed value</th><th>Shared value</th><th>Mapping</th><th>State</th><th>Products</th></tr></thead><tbody>' + rows + '</tbody></table>' +
			( attention ? '<h3>Needs attention</h3><ul class="swbm-result-list">' + attention + '</ul>' : '' );
	}

	function csvCell( value ) {
		var text = String( value === null || value === undefined ? '' : value );
		if ( /^[=+\-@]/.test( text ) ) {
			text = "'" + text;
		}
		return '"' + text.replace( /"/g, '""' ) + '"';
	}

	function downloadConvertCsv() {
		var lines = [ [ 'Product ID', 'Product name', 'SKU', 'Product status', 'Attribute', 'Current value', 'New shared value', 'Mapping', 'Action', 'Row state', 'Product state', 'Requires manual approval', 'Note' ].map( csvCell ).join( ',' ) ];

		convert.products.forEach( function ( product ) {
			product.rows.forEach( function ( row ) {
				lines.push( [
					product.id, product.name, product.sku, product.status, row.attribute, row.current, row.new, row.mapping,
					row.action === 'drop-duplicate' ? 'remove typed copy (already shared)' : 'convert',
					row.state, product.state, row.state === 'needs-approval' ? 'yes' : 'no', row.note,
				].map( csvCell ).join( ',' ) );
			} );
		} );

		var link = document.createElement( 'a' );
		link.href = URL.createObjectURL( new Blob( [ '\ufeff' + lines.join( '\r\n' ) ], { type: 'text/csv;charset=utf-8' } ) );
		link.download = 'attribute-conversion-dry-run.csv';
		document.body.appendChild( link );
		link.click();
		link.remove();
	}

	function runConvert() {
		var ids = selectedIds();
		var ready = ids.filter( function ( id ) {
			return convert.byId[ id ] && convert.byId[ id ].state === 'ready';
		} );
		var notReady = ids.length - ready.length;

		if ( ! ids.length ) {
			return showError( new Error( 'Select some products first.' ) );
		}
		if ( ! ready.length ) {
			return showError( new Error( 'None of the selected products were ready in the latest dry run.' ) );
		}
		if ( ! window.confirm( 'Convert typed attributes on ' + ready.length + ' selected product' + ( ready.length === 1 ? '' : 's' ) + '?' + ( notReady ? ' ' + notReady + ' selected product' + ( notReady === 1 ? ' is' : 's are' ) + ' not ready and will be left alone.' : '' ) ) ) {
			return;
		}

		post( 'start', { kind: 'convert', count: ready.length } ).then( function ( data ) {
			return runBatches( 'convert', { job_id: data.job_id }, ready, {
				stopOnFailure: true,
				perBatch: function ( batch ) {
					var expect = {};
					batch.forEach( function ( id ) {
						expect[ id ] = convert.byId[ id ].signature;
					} );
					return { expect: expect };
				},
			} );
		} ).then( function () {
			$( 'swbm-convert-run' ).disabled = true;
			$( 'swbm-convert-summary' ).insertAdjacentHTML( 'afterbegin', '<p><strong>Run the dry run again before converting more products.</strong></p>' );
		} ).catch( showError );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var writes = $( 'swbm-convert-panel' ).getAttribute( 'data-writes' ) === '1';

		$( 'swbm-convert-dryrun' ).addEventListener( 'click', convertDryRun );
		$( 'swbm-convert-download' ).addEventListener( 'click', downloadConvertCsv );

		if ( writes ) {
			$( 'swbm-convert-run' ).addEventListener( 'click', runConvert );
		} else {
			// Dry-run-only site: the convert control is not offered at all. The server refuses it too.
			$( 'swbm-convert-run' ).parentNode.hidden = true;
		}
	} );

	function runStock() {
		var ids = selectedIds();
		var status = $( 'swbm-stock-target' ).value;
		var labels = { instock: 'In stock', outofstock: 'Out of stock', onbackorder: 'On backorder' };

		if ( ! ids.length ) {
			return showError( new Error( 'Select some products first.' ) );
		}
		if ( ! window.confirm( 'Set ' + ids.length + ' selected product' + ( ids.length === 1 ? '' : 's' ) + ' to ' + labels[ status ] + '?' ) ) {
			return;
		}

		post( 'start', { kind: 'stock', stock_status: status, count: ids.length } ).then( function ( data ) {
			return runBatches( 'stock', { job_id: data.job_id, stock_status: status }, ids );
		} ).catch( showError );
	}

	function runAttributes() {
		var ids = selectedIds();
		var op = $( 'swbm-op' ).value;
		var attributeKey = $( 'swbm-op-attribute' ).value;
		var attribute = findAttribute( attributeKey );
		var label = attribute ? attribute.label : attributeKey.replace( /^local:/, '' );
		var terms = Array.from( $( 'swbm-op-terms' ).selectedOptions ).map( function ( option ) {
			return option.value;
		} );
		var termNames = Array.from( $( 'swbm-op-terms' ).selectedOptions ).map( function ( option ) {
			return option.textContent.replace( /\s*\(\d+\)$/, '' );
		} );
		var value = $( 'swbm-op-value' ).value;

		if ( ! ids.length ) {
			return showError( new Error( 'Select some products first.' ) );
		}

		var sentences = {
			add_terms: 'Add ' + termNames.join( ', ' ) + ' to "' + label + '"',
			remove_terms: 'Remove ' + termNames.join( ', ' ) + ' from "' + label + '"',
			set_terms: 'Replace the values of "' + label + '" with ' + termNames.join( ', ' ),
			remove_attribute: 'Remove the attribute "' + label + '"',
			set_local_value: 'Set "' + label + '" to ' + value,
		};

		if ( ! window.confirm( sentences[ op ] + ' on ' + ids.length + ' selected product' + ( ids.length === 1 ? '' : 's' ) + '?' ) ) {
			return;
		}

		var params = { op: op, attribute: attributeKey, terms: terms, value: value };

		post( 'start', Object.assign( { kind: 'attributes', count: ids.length }, params ) ).then( function ( data ) {
			return runBatches( 'attributes', Object.assign( { job_id: data.job_id }, params ), ids );
		} ).catch( showError );
	}

	/* ------------------------------------------------------------------- log */

	function loadJobs() {
		return post( 'jobs', {} ).then( function ( data ) {
			if ( ! data.jobs.length ) {
				$( 'swbm-jobs' ).innerHTML = '<p class="swbm-empty">Nothing has been run yet.</p>';
				return;
			}

			var rows = data.jobs.map( function ( job ) {
				var counts = job.counts || {};
				var revertable = job.type !== 'revert' && ! job.reverted_at && ( counts.changed || 0 ) > 0;

				return '<tr>' +
					'<td>' + esc( job.created_gmt ) + ' UTC</td>' +
					'<td>' + esc( job.type ) + '</td>' +
					'<td>' + esc( describeJob( job ) ) + '</td>' +
					'<td>' + ( counts.changed || 0 ) + ' changed, ' + ( counts.skipped || 0 ) + ' skipped, ' + ( counts.failed || 0 ) + ' failed</td>' +
					'<td>' + esc( job.user_login ) + '</td>' +
					'<td>' + ( job.reverted_at ? 'Put back' : ( revertable ? '<button type="button" class="button swbm-revert" data-job="' + esc( job.id ) + '">Put back</button>' : '' ) ) + '</td>' +
					'</tr>';
			} ).join( '' );

			$( 'swbm-jobs' ).innerHTML = '<table class="widefat striped"><thead><tr>' +
				'<th>When</th><th>Kind</th><th>What</th><th>Result</th><th>By</th><th></th>' +
				'</tr></thead><tbody>' + rows + '</tbody></table>';

			Array.prototype.forEach.call( $( 'swbm-jobs' ).querySelectorAll( '.swbm-revert' ), function ( button ) {
				button.addEventListener( 'click', function () {
					revert( button.getAttribute( 'data-job' ) );
				} );
			} );
		} ).catch( showError );
	}

	function describeJob( job ) {
		var params = job.params || {};

		if ( 'stock' === job.type ) {
			return 'set to ' + params.stock_status;
		}
		if ( 'revert' === job.type ) {
			return 'put back run ' + params.revert_of;
		}
		if ( 'convert' === job.type ) {
			return 'typed attributes to shared (' + ( params.count || 0 ) + ' products)';
		}

		return ( params.op || '' ) + ' ' + ( params.label || params.attribute || '' );
	}

	function revert( jobId ) {
		if ( ! window.confirm( 'Put every product this run changed back as it was?' ) ) {
			return;
		}

		post( 'revert_start', { job_id: jobId } ).then( function ( data ) {
			var total = data.total;
			var offset = 0;
			var totals = { changed: 0, skipped: 0, failed: 0 };

			startRun( total );

			var step = function () {
				if ( state.stop || offset >= total ) {
					endRun( totals );
					return find( state.page );
				}

				return post( 'revert_batch', {
					job_id: data.job_id,
					source_job_id: jobId,
					offset: offset,
					limit: BATCH_SIZE,
				} ).then( function ( batch ) {
					var counts = appendResults( batch.results );
					totals.changed += counts.changed || 0;
					totals.skipped += counts.skipped || 0;
					totals.failed += counts.failed || 0;
					offset += BATCH_SIZE;
					progress( Math.min( offset, total ), total );

					if ( batch.done ) {
						endRun( totals );
						return find( state.page );
					}

					return step();
				} );
			};

			return step();
		} ).catch( showError );
	}

	/* ---------------------------------------------------------------- details */

	function openDetails() {
		var dialog = $( 'swbm-details' );

		if ( typeof dialog.showModal === 'function' ) {
			dialog.showModal();
		} else {
			dialog.setAttribute( 'open', '' );
		}
	}

	function closeDetails() {
		var dialog = $( 'swbm-details' );

		if ( typeof dialog.close === 'function' ) {
			dialog.close();
		} else {
			dialog.removeAttribute( 'open' );
		}
	}

	/* ------------------------------------------------------------------ setup */

	document.addEventListener( 'DOMContentLoaded', function () {
		var conditionList = $( 'swbm-condition-list' );
		var details = $( 'swbm-details' );

		$( 'swbm-attribute-filter' ).innerHTML = attributeOptions( true );
		$( 'swbm-op-attribute' ).innerHTML = attributeOptions( false );
		fillConditionValues( conditionRows()[ 0 ] );
		updateConditionControls();
		fillOpFields();

		conditionList.addEventListener( 'change', function ( event ) {
			if ( event.target.classList.contains( 'swbm-cond-attribute' ) ) {
				fillConditionValues( event.target.closest( '.swbm-condition' ) );
			}
		} );
		conditionList.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.swbm-cond-remove' );
			if ( button ) {
				removeCondition( button.closest( '.swbm-condition' ) );
			}
		} );
		$( 'swbm-add-condition' ).addEventListener( 'click', addCondition );
		$( 'swbm-op-attribute' ).addEventListener( 'change', fillOpFields );
		$( 'swbm-op' ).addEventListener( 'change', fillOpFields );

		$( 'swbm-find' ).addEventListener( 'click', function () {
			find( 1, true );
		} );
		$( 'swbm-reset' ).addEventListener( 'click', function () {
			[ 'swbm-search', 'swbm-skus' ].forEach( function ( id ) {
				$( id ).value = '';
			} );
			[ 'swbm-category', 'swbm-stock-filter', 'swbm-post-status' ].forEach( function ( id ) {
				$( id ).selectedIndex = 0;
			} );
			resetConditions();
		} );

		$( 'swbm-prev' ).addEventListener( 'click', function () {
			find( Math.max( 1, state.page - 1 ) );
		} );
		$( 'swbm-next' ).addEventListener( 'click', function () {
			find( state.page + 1 );
		} );

		$( 'swbm-view-details' ).addEventListener( 'click', openDetails );
		Array.prototype.forEach.call( details.querySelectorAll( '[data-swbm-close]' ), function ( button ) {
			button.addEventListener( 'click', closeDetails );
		} );
		// A click on the dimmed area around the panel closes it as well.
		details.addEventListener( 'click', function ( event ) {
			if ( event.target === details ) {
				closeDetails();
			}
		} );
		// Esc closes the panel. Browsers do this for dialogs natively; handling it here too covers every
		// keyboard and the fallback used where the dialog element is not supported.
		details.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				closeDetails();
			}
		} );
		details.addEventListener( 'close', function () {
			$( 'swbm-view-details' ).focus();
		} );

		$( 'swbm-select-page' ).addEventListener( 'click', function () {
			state.rows.forEach( function ( row ) {
				state.selected.set( row.id, true );
			} );
			renderTable();
		} );
		$( 'swbm-select-matching' ).addEventListener( 'click', function () {
			post( 'select_all', state.applied || filters() ).then( function ( data ) {
				data.ids.forEach( function ( id ) {
					state.selected.set( id, true );
				} );
				if ( data.capped ) {
					window.alert( 'That is more products than one selection can hold. The first ' + data.ids.length + ' of ' + data.total + ' were selected.' );
				}
				renderTable();
			} ).catch( showError );
		} );
		$( 'swbm-clear-selection' ).addEventListener( 'click', function () {
			state.selected.clear();
			renderTable();
		} );

		$( 'swbm-run-stock' ).addEventListener( 'click', runStock );
		$( 'swbm-run-attributes' ).addEventListener( 'click', runAttributes );
		$( 'swbm-stop' ).addEventListener( 'click', function () {
			state.stop = true;
			$( 'swbm-progress-text' ).textContent += ' — stopping after this batch…';
		} );
		$( 'swbm-refresh-jobs' ).addEventListener( 'click', loadJobs );

		loadJobs();
	} );
}() );
