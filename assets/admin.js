/* Media SEO by Designs and IT. Sin dependencias. */
( function () {
	'use strict';

	if ( typeof window.DITMS === 'undefined' ) {
		return;
	}
	var cfg = window.DITMS;

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', 'ditms_' + action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		return fetch( cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json().catch( function () {
					throw new Error( 'Respuesta inválida del servidor (' + r.status + ').' );
				} );
			} )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					var err = new Error( res && res.data && res.data.message ? res.data.message : 'No se pudo completar.' );
					err.stop = !! ( res && res.data && res.data.stop );
					throw err;
				}
				return res.data;
			} );
	}

	function isEditScreen() {
		return document.body.classList.contains( 'post-type-attachment' ) && !! document.getElementById( 'post' );
	}

	function refreshModel( id ) {
		if ( window.wp && wp.media && wp.media.attachment ) {
			wp.media.attachment( id ).fetch();
		}
	}

	/**
	 * Campos nativos del adjunto según el contexto (pantalla Editar medio o ventana de medios).
	 */
	function nativeFields( box ) {
		if ( isEditScreen() ) {
			return {
				alt: document.getElementById( 'attachment_alt' ),
				title: document.getElementById( 'title' ),
				caption: document.getElementById( 'attachment_caption' ),
				description: document.getElementById( 'attachment_content' )
			};
		}
		var scope = box.closest( '.media-sidebar' ) || box.closest( '.attachment-details' ) || box.closest( '.media-modal-content' ) || document;
		function q( sel ) {
			return scope.querySelector( sel );
		}
		return {
			alt: q( '[data-setting="alt"] textarea' ) || q( '[data-setting="alt"] input' ),
			title: q( '[data-setting="title"] input' ),
			caption: q( '[data-setting="caption"] textarea' ),
			description: q( '[data-setting="description"] textarea' )
		};
	}

	function fillFields( box, values ) {
		var fields = nativeFields( box );
		Object.keys( values ).forEach( function ( key ) {
			if ( fields[ key ] && typeof values[ key ] === 'string' ) {
				fields[ key ].value = values[ key ];
			}
		} );
	}

	/* ---------------- Caja en la ficha del archivo ---------------- */

	function status( box, text, type ) {
		var el = box.querySelector( '.ditms__status' );
		el.textContent = text || '';
		el.dataset.type = type || '';
	}

	function setUndo( box, label ) {
		var btn = box.querySelector( '[data-action="undo"]' );
		if ( ! btn ) {
			return;
		}
		btn.hidden = ! label;
		btn.title = label || '';
	}

	function setCurrent( box, filename ) {
		var name = box.querySelector( '.ditms__name' );
		name.textContent = filename;
		name.title = filename;
		var tag = box.querySelector( '.ditms__tag' );
		if ( tag ) {
			tag.remove();
		}
	}

	function busy( box, on ) {
		box.classList.toggle( 'is-busy', on );
		box.querySelectorAll( 'button' ).forEach( function ( b ) {
			b.disabled = on;
		} );
	}

	var actions = {
		generate: function ( box, id ) {
			if ( ! cfg.hasKey ) {
				return Promise.reject( new Error( 'Falta la clave de la API en Ajustes > Media SEO.' ) );
			}
			status( box, 'Analizando la imagen…', 'busy' );
			return post( 'generate', { id: id, hint: box.querySelector( '.ditms__filename' ).value } ).then( function ( d ) {
				box.querySelector( '.ditms__filename' ).value = d.filename || '';
				var values = {};
				( d.saved || [] ).forEach( function ( k ) {
					values[ k ] = d[ k ];
				} );
				fillFields( box, values );
				refreshModel( id );
				setUndo( box, d.undo );
				status( box, ( d.saved && d.saved.length ? 'Textos guardados' + pagesNote( d.pages ) + '. Revisa el nombre propuesto y pulsa Renombrar.' : 'Sin cambios en los textos. Revisa el nombre propuesto.' ), 'ok' );
			} );
		},

		rename: function ( box, id ) {
			var input = box.querySelector( '.ditms__filename' );
			if ( ! input.value.trim() ) {
				input.focus();
				return Promise.reject( new Error( 'Escribe el nombre nuevo o usa Generar con IA.' ) );
			}
			status( box, 'Renombrando y actualizando enlaces…', 'busy' );
			return post( 'rename', { id: id, filename: input.value } ).then( function ( d ) {
				input.value = '';
				setCurrent( box, d.filename );
				setUndo( box, d.undo );
				status( box, 'Listo: ' + d.filename + ( d.refs ? ' · ' + d.refs + ' enlace(s) actualizados' : '' ), 'ok' );
				afterFileChange( id, d, box );
			} );
		},

		undo: function ( box, id ) {
			status( box, 'Deshaciendo…', 'busy' );
			return post( 'undo', { id: id } ).then( function ( d ) {
				if ( d.texts ) {
					fillFields( box, d.texts );
				}
				setCurrent( box, d.current );
				setUndo( box, d.undo );
				status( box, 'Cambio deshecho' + pagesNote( d.pages ) + '.', 'ok' );
				if ( d.url ) {
					afterFileChange( id, d, box );
				} else {
					refreshModel( id );
				}
			} );
		}
	};

	// La ventana de medios no vuelve a pintar el nombre ni la URL del archivo: se actualizan a mano.
	function updateModalDetails( box, d ) {
		if ( ! box || ! d.url ) {
			return;
		}
		var scope = box.closest( '.media-modal-content' ) || box.closest( '.media-sidebar' ) || document;
		var filename = d.filename || d.current || '';
		scope.querySelectorAll( '.details .filename' ).forEach( function ( el ) {
			var label = el.querySelector( 'strong' );
			el.textContent = '';
			if ( label ) {
				el.appendChild( label );
				el.appendChild( document.createTextNode( ' ' ) );
			}
			el.appendChild( document.createTextNode( filename ) );
		} );
		scope.querySelectorAll( '.attachment-details-copy-link, input[id^="attachment-details"][id$="copy-link"]' ).forEach( function ( input ) {
			input.value = d.url;
		} );
	}

	// Tras renombrar: en Editar medio se actualiza la pantalla sin recargar (para no perder lo que no se haya guardado).
	function afterFileChange( id, d, box ) {
		if ( ! isEditScreen() ) {
			refreshModel( id );
			updateModalDetails( box, d );
			return;
		}
		var url = document.getElementById( 'attachment_url' );
		if ( url && d.url ) {
			url.value = d.url;
		}
		document.querySelectorAll( '.wp_attachment_image img' ).forEach( function ( img ) {
			if ( d.url ) {
				img.src = d.url + '?v=' + Date.now();
			}
		} );
		var slug = document.getElementById( 'post_name' );
		if ( slug && d.slug ) {
			slug.value = d.slug;
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.ditms [data-action]' );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		var box = btn.closest( '.ditms' );
		var action = actions[ btn.dataset.action ];
		if ( ! action || box.classList.contains( 'is-busy' ) ) {
			return;
		}
		busy( box, true );
		action( box, box.dataset.id )
			.catch( function ( err ) {
				status( box, err.message, 'error' );
			} )
			.then( function () {
				busy( box, false );
			} );
	} );

	// Enter en el campo de nombre = renombrar (y no envía el formulario de la página).
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' && e.target.classList && e.target.classList.contains( 'ditms__filename' ) ) {
			e.preventDefault();
			var btn = e.target.closest( '.ditms' ).querySelector( '[data-action="rename"]' );
			if ( btn && ! btn.disabled ) {
				btn.click();
			}
		}
	} );

	function pagesNote( pages ) {
		return pages ? ' · alt actualizado en ' + pages + ' página(s) publicada(s)' : '';
	}

	/* ---------------- Ajustes: probar conexión ---------------- */

	function initTest() {
		var btn = document.getElementById( 'ditms-test' );
		if ( ! btn ) {
			return;
		}
		var out = document.querySelector( '.ditms-test__result' );
		btn.addEventListener( 'click', function () {
			var key = document.getElementById( 'ditms-key' );
			if ( key && key.value.trim() ) {
				out.textContent = 'Guarda los cambios primero: la prueba usa la clave guardada.';
				out.dataset.type = 'error';
				return;
			}
			btn.disabled = true;
			out.textContent = 'Probando…';
			out.dataset.type = 'busy';
			post( 'test', {} )
				.then( function ( d ) {
					out.textContent = d.message;
					out.dataset.type = 'ok';
				} )
				.catch( function ( err ) {
					out.textContent = err.message;
					out.dataset.type = 'error';
				} )
				.then( function () {
					btn.disabled = false;
				} );
		} );
	}

	/* ---------------- Lote ---------------- */

	function initBulk( page ) {
		var startBtn = document.getElementById( 'ditms-start' );
		var stopBtn = document.getElementById( 'ditms-stop' );
		var text = page.querySelector( '.ditms-run__text' );
		var bar = page.querySelector( '.ditms-progress' );
		var log = page.querySelector( '.ditms-log' );
		var stopped = false;

		function progress( done, total ) {
			bar.hidden = false;
			bar.firstElementChild.style.width = ( total ? Math.round( done / total * 100 ) : 0 ) + '%';
		}

		function line( id, message, type, undo ) {
			var li = document.createElement( 'li' );
			li.dataset.type = type;
			var span = document.createElement( 'span' );
			span.textContent = '#' + id + ' · ' + message;
			li.appendChild( span );
			if ( undo ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'ditms__btn ditms__btn--ghost ditms__btn--sm';
				b.textContent = 'Deshacer';
				b.title = undo;
				b.addEventListener( 'click', function () {
					b.disabled = true;
					post( 'undo', { id: id } )
						.then( function ( d ) {
							span.textContent = '#' + id + ' · cambio deshecho';
							li.dataset.type = '';
							if ( d.undo ) {
								b.title = d.undo;
								b.disabled = false;
							} else {
								b.remove();
							}
						} )
						.catch( function ( err ) {
							span.textContent = '#' + id + ' · ' + err.message;
							b.disabled = false;
						} );
				} );
				li.appendChild( b );
			}
			log.insertBefore( li, log.firstChild );
		}

		function finish( message ) {
			text.textContent = message;
			startBtn.disabled = false;
			stopBtn.disabled = true;
		}

		function run( ids ) {
			var total = ids.length;
			var done = 0;
			var errors = 0;
			var rename = document.getElementById( 'ditms-rename' ).checked ? 1 : 0;
			var overwrite = document.getElementById( 'ditms-overwrite' ).checked ? 1 : 0;

			( function next() {
				if ( stopped ) {
					return finish( 'Detenido: ' + done + ' de ' + total + '.' );
				}
				if ( ! ids.length ) {
					return finish( 'Terminado: ' + done + ' procesadas, ' + errors + ' con error.' );
				}
				var id = ids.shift();
				text.textContent = ( done + 1 ) + ' de ' + total;
				post( 'process', { id: id, rename: rename, overwrite: overwrite } )
					.then( function ( d ) {
						line( id, d.message, 'ok', d.undo );
					} )
					.catch( function ( err ) {
						errors++;
						line( id, err.message, 'error' );
						// Sin clave, clave inválida o límite de uso: se detiene para no gastar intentos.
						if ( err.stop ) {
							stopped = true;
						}
					} )
					.then( function () {
						done++;
						progress( done, total );
						next();
					} );
			}() );
		}

		startBtn.addEventListener( 'click', function () {
			if ( ! cfg.hasKey ) {
				return finish( 'Falta la clave de la API en Ajustes > Media SEO.' );
			}
			var checked = page.querySelector( 'input[name="ditms-mode"]:checked' );
			var mode = checked ? checked.value : 'missing_alt';
			stopped = false;
			log.innerHTML = '';
			progress( 0, 1 );
			startBtn.disabled = true;
			stopBtn.disabled = false;

			if ( mode === 'preset' ) {
				return run( String( page.dataset.preset ).split( ',' ).filter( Boolean ) );
			}
			text.textContent = 'Buscando imágenes…';
			post( 'list', { mode: mode } )
				.then( function ( d ) {
					if ( ! d.ids.length ) {
						bar.hidden = true;
						return finish( 'No hay imágenes para procesar.' );
					}
					run( d.ids );
				} )
				.catch( function ( err ) {
					finish( err.message );
				} );
		} );

		stopBtn.addEventListener( 'click', function () {
			stopped = true;
			stopBtn.disabled = true;
		} );
	}

	/* ---------------- Archivos sin uso ---------------- */

	function formatBytes( n ) {
		if ( n >= 1048576 ) {
			return ( n / 1048576 ).toFixed( 1 ) + ' MB';
		}
		return Math.max( 1, Math.round( n / 1024 ) ) + ' KB';
	}

	function initOrphans( page ) {
		var scanBtn = document.getElementById( 'ditms-orphans-scan' );
		var status = document.getElementById( 'ditms-orphans-status' );
		var bar = page.querySelector( '.ditms-progress' );
		var results = document.getElementById( 'ditms-orphans-results' );
		var unusedBody = page.querySelector( '#ditms-orphans-unused tbody' );
		var usedBody = page.querySelector( '#ditms-orphans-used tbody' );
		var all = document.getElementById( 'ditms-orphans-all' );
		var moveBtn = document.getElementById( 'ditms-orphans-move' );
		var moveStatus = document.getElementById( 'ditms-orphans-move-status' );
		var qCount = document.getElementById( 'ditms-q-count' );
		var qSize = document.getElementById( 'ditms-q-size' );
		var restoreBtn = document.getElementById( 'ditms-q-restore' );
		var purgeBtn = document.getElementById( 'ditms-q-purge' );
		var purgeConfirm = document.getElementById( 'ditms-q-purge-confirm' );
		var qStatus = document.getElementById( 'ditms-q-status' );

		function setQuarantine( count, bytes ) {
			qCount.textContent = count;
			qSize.dataset.bytes = bytes;
			qSize.textContent = count ? count + ' archivo(s), ' + formatBytes( bytes ) : '';
			restoreBtn.disabled = ! count;
			purgeBtn.disabled = ! count;
			purgeConfirm.hidden = true;
		}
		setQuarantine( parseInt( qCount.textContent, 10 ) || 0, parseInt( qSize.dataset.bytes, 10 ) || 0 );

		function row( body, cells ) {
			var tr = document.createElement( 'tr' );
			cells.forEach( function ( c ) {
				var td = document.createElement( 'td' );
				if ( c instanceof Node ) {
					td.appendChild( c );
				} else {
					td.textContent = c;
				}
				tr.appendChild( td );
			} );
			body.appendChild( tr );
			return tr;
		}

		function code( text ) {
			var c = document.createElement( 'code' );
			c.textContent = text;
			return c;
		}

		function counts() {
			document.getElementById( 'ditms-orphans-unused-count' ).textContent = unusedBody.rows.length;
			document.getElementById( 'ditms-orphans-used-count' ).textContent = usedBody.rows.length;
			moveBtn.disabled = ! unusedBody.querySelector( 'input:checked' );
		}

		scanBtn.addEventListener( 'click', function () {
			scanBtn.disabled = true;
			unusedBody.innerHTML = '';
			usedBody.innerHTML = '';
			moveStatus.textContent = '';
			results.hidden = true;
			status.textContent = 'Buscando carpetas…';
			post( 'orphans_dirs', {} )
				.then( function ( d ) {
					var dirs = d.dirs;
					var done = 0;
					var unusedBytes = 0;
					bar.hidden = false;
					return dirs.reduce( function ( chain, dir ) {
						return chain.then( function () {
							status.textContent = 'Revisando ' + ( dir || 'raíz de uploads' ) + ' (' + ( done + 1 ) + ' de ' + dirs.length + ')';
							return post( 'orphans_scan', { dir: dir } ).then( function ( r ) {
								r.unused.forEach( function ( f ) {
									var cb = document.createElement( 'input' );
									cb.type = 'checkbox';
									cb.checked = true;
									cb.value = f.rel;
									cb.setAttribute( 'aria-label', 'Seleccionar ' + f.rel );
									cb.addEventListener( 'change', counts );
									row( unusedBody, [ cb, code( f.rel ), formatBytes( f.size ) ] );
									unusedBytes += f.size;
								} );
								r.used.forEach( function ( f ) {
									row( usedBody, [ code( f.rel ), f.where ] );
								} );
								done++;
								bar.firstElementChild.style.width = Math.round( done / dirs.length * 100 ) + '%';
							} );
						} );
					}, Promise.resolve() ).then( function () {
						results.hidden = false;
						counts();
						status.textContent = unusedBody.rows.length
							? 'Listo: ' + unusedBody.rows.length + ' archivo(s) sin uso (' + formatBytes( unusedBytes ) + ').'
							: 'Listo: no hay archivos sin uso.';
					} );
				} )
				.catch( function ( err ) {
					status.textContent = err.message;
				} )
				.then( function () {
					scanBtn.disabled = false;
				} );
		} );

		all.addEventListener( 'change', function () {
			unusedBody.querySelectorAll( 'input[type="checkbox"]' ).forEach( function ( cb ) {
				cb.checked = all.checked;
			} );
			counts();
		} );

		moveBtn.addEventListener( 'click', function () {
			var files = Array.prototype.map.call( unusedBody.querySelectorAll( 'input:checked' ), function ( cb ) {
				return cb.value;
			} );
			if ( ! files.length ) {
				return;
			}
			moveBtn.disabled = true;
			moveStatus.textContent = 'Moviendo ' + files.length + ' archivo(s)…';
			post( 'orphans_move', { files: JSON.stringify( files ) } )
				.then( function ( d ) {
					d.moved.forEach( function ( rel ) {
						var cb = unusedBody.querySelector( 'input[value="' + CSS.escape( rel ) + '"]' );
						if ( cb ) {
							cb.closest( 'tr' ).remove();
						}
					} );
					var skipped = Object.keys( d.skipped || {} );
					moveStatus.textContent = d.moved.length + ' movido(s) a cuarentena' + ( skipped.length ? ' · ' + skipped.length + ' no se movieron (' + d.skipped[ skipped[ 0 ] ] + ')' : '' ) + '.';
					var bytes = parseInt( qSize.dataset.bytes, 10 ) || 0;
					setQuarantine( ( parseInt( qCount.textContent, 10 ) || 0 ) + d.moved.length, bytes );
					qStatus.textContent = 'Recarga la página para ver el tamaño total de la cuarentena.';
					counts();
				} )
				.catch( function ( err ) {
					moveStatus.textContent = err.message;
				} )
				.then( function () {
					counts();
				} );
		} );

		restoreBtn.addEventListener( 'click', function () {
			restoreBtn.disabled = true;
			qStatus.textContent = 'Restaurando…';
			post( 'orphans_restore', { files: '[]' } )
				.then( function ( d ) {
					var skipped = Object.keys( d.skipped || {} ).length;
					qStatus.textContent = d.restored.length + ' restaurado(s)' + ( skipped ? ' · ' + skipped + ' no se pudieron restaurar' : '' ) + '. Vuelve a analizar para ver el estado actual.';
					setQuarantine( skipped, skipped ? parseInt( qSize.dataset.bytes, 10 ) || 0 : 0 );
				} )
				.catch( function ( err ) {
					qStatus.textContent = err.message;
					restoreBtn.disabled = false;
				} );
		} );

		// Vaciar pide un segundo clic: el navegador del panel no muestra confirm().
		purgeBtn.addEventListener( 'click', function () {
			purgeConfirm.hidden = false;
			qStatus.textContent = 'Los archivos se borran para siempre y no se pueden recuperar.';
			purgeConfirm.focus();
		} );
		purgeConfirm.addEventListener( 'click', function () {
			purgeConfirm.disabled = true;
			post( 'orphans_purge', { confirm: 1 } )
				.then( function ( d ) {
					qStatus.textContent = d.deleted + ' archivo(s) borrado(s).';
					setQuarantine( 0, 0 );
				} )
				.catch( function ( err ) {
					qStatus.textContent = err.message;
				} )
				.then( function () {
					purgeConfirm.disabled = false;
				} );
		} );
	}

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		initTest();
		var orphans = document.querySelector( '.ditms-orphans' );
		if ( orphans ) {
			initOrphans( orphans );
		}
		var page = document.querySelector( '.ditms-bulk' );
		if ( page ) {
			initBulk( page );
		}
	} );
}() );
