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

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		initTest();
		var page = document.querySelector( '.ditms-bulk' );
		if ( page ) {
			initBulk( page );
		}
	} );
}() );
