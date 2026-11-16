<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interfaz: ajustes, campo en la ficha del adjunto, procesamiento en lote, columna en la biblioteca,
 * normalización de nombres al subir y generación automática.
 */
class DITMS_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menus' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DITMS_FILE ), array( __CLASS__, 'action_links' ) );

		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'field' ), 20, 2 );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		// La ventana de medios también se abre en Elementor, en BeBuilder (BeTheme) y en la ficha de productos de WooCommerce.
		add_action( 'wp_enqueue_media', array( __CLASS__, 'enqueue' ) );

		foreach ( array( 'generate', 'rename', 'undo', 'process', 'list' ) as $action ) {
			add_action( 'wp_ajax_ditms_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}

		add_filter( 'bulk_actions-upload', array( __CLASS__, 'bulk_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( __CLASS__, 'handle_bulk' ), 10, 3 );

		add_filter( 'manage_media_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );

		add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'normalize_upload' ) );
		add_filter( 'wp_handle_sideload_prefilter', array( __CLASS__, 'normalize_upload' ) );

		add_action( 'add_attachment', array( __CLASS__, 'schedule_auto' ) );
		add_action( 'ditms_auto_generate', array( __CLASS__, 'run_auto' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Menús y ajustes                                                     */
	/* ------------------------------------------------------------------ */

	public static function menus() {
		add_options_page( 'Media SEO', 'Media SEO', 'manage_options', 'ditms-settings', array( __CLASS__, 'settings_page' ) );
		add_media_page( 'Media SEO en lote', 'Media SEO en lote', 'upload_files', 'ditms-bulk', array( __CLASS__, 'bulk_page' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=ditms-settings' ) ) . '">Ajustes</a>' );
		return $links;
	}

	public static function register_settings() {
		register_setting( 'ditms', DITMS_OPTION, array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize' ),
		) );
	}

	public static function sanitize( $input ) {
		$old   = ditms_settings();
		$input = is_array( $input ) ? $input : array();
		$out   = ditms_default_settings();

		// La clave no se vuelve a mostrar: si el campo llega vacío se conserva la guardada.
		$key = isset( $input['api_key'] ) ? trim( sanitize_text_field( $input['api_key'] ) ) : '';
		if ( ! empty( $input['api_key_delete'] ) ) {
			$out['api_key'] = '';
		} else {
			$out['api_key'] = '' !== $key ? $key : $old['api_key'];
		}

		$out['model']    = isset( $input['model'] ) && array_key_exists( $input['model'], ditms_models() ) ? $input['model'] : $old['model'];
		$out['language'] = isset( $input['language'] ) && array_key_exists( $input['language'], ditms_languages() ) ? $input['language'] : $old['language'];

		foreach ( array( 'business', 'sector', 'location', 'keywords' ) as $k ) {
			$out[ $k ] = isset( $input[ $k ] ) ? sanitize_text_field( $input[ $k ] ) : '';
		}
		$out['instructions'] = isset( $input['instructions'] ) ? sanitize_textarea_field( $input['instructions'] ) : '';

		// Sin rayas largas en la marca: se cambian por guion normal.
		$out['title_brand']     = isset( $input['title_brand'] ) ? str_replace( array( "\u{2014}", "\u{2013}" ), '-', sanitize_text_field( $input['title_brand'] ) ) : '';
		$out['title_separator'] = isset( $input['title_separator'] ) && array_key_exists( $input['title_separator'], ditms_title_separators() ) ? $input['title_separator'] : 'dash';

		$out['fields'] = isset( $input['fields'] ) ? array_values( array_intersect( (array) $input['fields'], array( 'alt', 'title', 'caption', 'description' ) ) ) : array();

		foreach ( array( 'use_image', 'overwrite', 'auto_on_upload', 'normalize_upload', 'replace_refs', 'update_slug', 'redirects' ) as $k ) {
			$out[ $k ] = empty( $input[ $k ] ) ? 0 : 1;
		}
		return $out;
	}

	/** Interruptor (checkbox con estilo). */
	private static function toggle( $key, $label, $help = '' ) {
		$s = ditms_settings();
		printf(
			'<label class="ditms-toggle"><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s><span class="ditms-toggle__ui" aria-hidden="true"></span><span class="ditms-toggle__text">%4$s%5$s</span></label>',
			esc_attr( DITMS_OPTION ),
			esc_attr( $key ),
			checked( ! empty( $s[ $key ] ), true, false ),
			esc_html( $label ),
			$help ? '<small>' . esc_html( $help ) . '</small>' : ''
		);
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = ditms_settings();
		$n = DITMS_OPTION;
		?>
		<div class="wrap ditms-page">
			<header class="ditms-page__head">
				<h1>Media SEO</h1>
				<p>Renombrado seguro y textos SEO con IA para la biblioteca de medios.</p>
			</header>

			<form method="post" action="options.php">
				<?php settings_fields( 'ditms' ); ?>

				<section class="ditms-card">
					<h2>Conexión con la IA</h2>
					<div class="ditms-field">
						<label for="ditms-key">Clave de la API de Anthropic</label>
						<?php if ( defined( 'DITMS_ANTHROPIC_API_KEY' ) && DITMS_ANTHROPIC_API_KEY ) : ?>
							<p class="ditms-pill ditms-pill--ok">Se usa la clave definida en wp-config.php</p>
						<?php else : ?>
							<input type="password" id="ditms-key" name="<?php echo esc_attr( $n ); ?>[api_key]" value="" autocomplete="new-password" placeholder="<?php echo $s['api_key'] ? esc_attr( 'Guardada (termina en ' . substr( $s['api_key'], -4 ) . ')' ) : 'sk-ant-...'; ?>">
							<small>Más seguro: <code>define( 'DITMS_ANTHROPIC_API_KEY', '...' );</code> en wp-config.php. Se crea en console.anthropic.com.</small>
							<?php if ( $s['api_key'] ) : ?>
								<label class="ditms-inline"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[api_key_delete]" value="1"> Borrar la clave guardada</label>
							<?php endif; ?>
						<?php endif; ?>
					</div>
					<div class="ditms-grid">
						<div class="ditms-field">
							<label for="ditms-model">Modelo</label>
							<select id="ditms-model" name="<?php echo esc_attr( $n ); ?>[model]">
								<?php foreach ( ditms_models() as $id => $label ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['model'], $id ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<small>Costo estimado por imagen: Opus aprox. USD 0,02 · Sonnet aprox. USD 0,01 · Haiku aprox. USD 0,001.</small>
						</div>
						<div class="ditms-field">
							<label for="ditms-language">Idioma de los textos</label>
							<select id="ditms-language" name="<?php echo esc_attr( $n ); ?>[language]">
								<?php foreach ( ditms_languages() as $id => $label ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['language'], $id ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
					<?php self::toggle( 'use_image', 'Analizar la imagen', 'Los textos describen lo que de verdad se ve y funciona con nombres como IMG_2034.jpg.' ); ?>
				</section>

				<section class="ditms-card">
					<h2>Contexto del negocio</h2>
					<div class="ditms-grid">
						<?php
						$text_fields = array(
							'business' => array( 'Nombre del negocio', get_bloginfo( 'name' ) ),
							'sector'   => array( 'Sector o servicio principal', 'Cocinas integrales a medida' ),
							'location' => array( 'Ciudad o zona', 'Bogotá' ),
							'keywords' => array( 'Palabras clave prioritarias', 'Separadas por comas' ),
						);
						foreach ( $text_fields as $k => $conf ) :
							?>
							<div class="ditms-field">
								<label for="ditms-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $conf[0] ); ?></label>
								<input type="text" id="ditms-<?php echo esc_attr( $k ); ?>" name="<?php echo esc_attr( $n . '[' . $k . ']' ); ?>" value="<?php echo esc_attr( $s[ $k ] ); ?>" placeholder="<?php echo esc_attr( $conf[1] ); ?>">
							</div>
						<?php endforeach; ?>
					</div>
					<div class="ditms-field">
						<label for="ditms-instructions">Instrucciones adicionales</label>
						<textarea id="ditms-instructions" name="<?php echo esc_attr( $n ); ?>[instructions]" rows="3" placeholder="Tratar al lector de usted; no mencionar precios."><?php echo esc_textarea( $s['instructions'] ); ?></textarea>
					</div>
				</section>

				<section class="ditms-card">
					<h2>Campos que llena la IA</h2>
					<div class="ditms-chips">
						<?php
						$labels = array(
							'alt'         => 'Texto alternativo',
							'title'       => 'Título',
							'caption'     => 'Leyenda',
							'description' => 'Descripción',
						);
						foreach ( $labels as $k => $label ) :
							?>
							<label class="ditms-chip"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[fields][]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, (array) $s['fields'], true ) ); ?>><span><?php echo esc_html( $label ); ?></span></label>
						<?php endforeach; ?>
					</div>
					<small class="ditms-note">El texto alternativo es el campo que más pesa para Google. La leyenda solo aparece si la insertas con la imagen. La descripción solo se ve en la página de adjunto, que WordPress desactiva desde la versión 6.4: casi no aporta.</small>
					<div class="ditms-grid ditms-grid--brand">
						<div class="ditms-field">
							<label for="ditms-title-brand">Marca al final del título</label>
							<input type="text" id="ditms-title-brand" name="<?php echo esc_attr( $n ); ?>[title_brand]" value="<?php echo esc_attr( $s['title_brand'] ); ?>" placeholder="Leonardo Báez - Consultor SEO">
							<small>Opcional. Se agrega a cada título generado si no la tiene ya.</small>
						</div>
						<div class="ditms-field">
							<label for="ditms-title-separator">Separador</label>
							<select id="ditms-title-separator" name="<?php echo esc_attr( $n ); ?>[title_separator]">
								<?php foreach ( ditms_title_separators() as $key => $sep ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['title_separator'], $key ); ?>><?php echo esc_html( 'Título' . $sep . 'Marca' ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
					<?php
					self::toggle( 'overwrite', 'Sobrescribir textos existentes en lote y automático', 'Apagado, solo llena campos vacíos. El título por defecto (el nombre del archivo) cuenta como vacío.' );
					self::toggle( 'normalize_upload', 'Limpiar el nombre al subir', 'Minúsculas, sin tildes, ñ ni espacios.' );
					self::toggle( 'auto_on_upload', 'Generar textos al subir', 'En segundo plano, unos segundos después de subir la imagen.' );
					?>
				</section>

				<section class="ditms-card">
					<h2>Renombrado</h2>
					<?php
					self::toggle( 'replace_refs', 'Actualizar enlaces en todo el sitio', 'Páginas, productos, Elementor, BeTheme, campos personalizados, categorías y widgets.' );
					self::toggle( 'redirects', 'Redirigir con 301 las URLs viejas' );
					self::toggle( 'update_slug', 'Actualizar el slug del adjunto' );
					?>
					<small class="ditms-note">Antes de renombrar en lote en producción, haz una copia de seguridad de la base de datos. Cada cambio se puede deshacer desde la ficha del archivo.</small>
				</section>

				<section class="ditms-card">
					<h2>Redirecciones guardadas <span class="ditms-count"><?php echo (int) DITMS_Redirects::count(); ?></span></h2>
					<?php $recent = DITMS_Redirects::recent( 20 ); ?>
					<?php if ( $recent ) : ?>
						<ul class="ditms-redirects">
							<?php foreach ( $recent as $row ) : ?>
								<li><code><?php echo esc_html( $row[0] ); ?></code><span aria-hidden="true">→</span><?php echo $row[1] ? '<a href="' . esc_url( $row[1] ) . '" target="_blank" rel="noopener">' . esc_html( basename( wp_parse_url( $row[1], PHP_URL_PATH ) ) ) . '</a>' : '<em>adjunto borrado</em>'; ?></li>
							<?php endforeach; ?>
						</ul>
						<small class="ditms-note">Últimas 20. Los archivos renombrados que no estén aquí se redirigen igual si tienen historial en su ficha.</small>
					<?php else : ?>
						<small class="ditms-note">Todavía no hay redirecciones guardadas.</small>
					<?php endif; ?>
				</section>

				<?php submit_button( 'Guardar cambios', 'primary ditms-save' ); ?>
			</form>

			<p class="ditms-credit">
				Media SEO <?php echo esc_html( DITMS_VERSION ); ?> es una herramienta de
				<a href="https://www.designsandit.com/?utm_source=media-seo&amp;utm_medium=plugin&amp;utm_campaign=ajustes" target="_blank" rel="noopener">Designs and IT</a>,
				agencia SEO en Colombia y Estados Unidos.
				<a href="<?php echo esc_url( DITMS_REPO ); ?>" target="_blank" rel="noopener">Novedades y código</a>
			</p>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Recursos (solo donde se usan; sin jQuery ni librerías)              */
	/* ------------------------------------------------------------------ */

	public static function admin_assets( $hook ) {
		$screen = get_current_screen();
		if ( ( $screen && 'attachment' === $screen->id ) || in_array( $hook, array( 'upload.php', 'media_page_ditms-bulk', 'settings_page_ditms-settings' ), true ) ) {
			self::enqueue();
		}
	}

	public static function enqueue() {
		if ( ! current_user_can( 'upload_files' ) || wp_script_is( 'ditms-admin', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style( 'ditms-admin', DITMS_URL . 'assets/admin.css', array(), DITMS_VERSION );
		wp_enqueue_script( 'ditms-admin', DITMS_URL . 'assets/admin.js', array(), DITMS_VERSION, true );
		wp_localize_script( 'ditms-admin', 'DITMS', array(
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'nonce'  => wp_create_nonce( 'ditms' ),
			'fields' => array_values( (array) ditms_setting( 'fields' ) ),
			'hasKey' => (bool) ditms_api_key(),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Campo en la ficha del adjunto                                       */
	/* ------------------------------------------------------------------ */

	public static function field( $form_fields, $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $form_fields;
		}
		$file = get_attached_file( $post->ID );
		if ( ! $file ) {
			return $form_fields;
		}
		$meta     = wp_get_attachment_metadata( $post->ID );
		$current  = ! empty( $meta['original_image'] ) ? $meta['original_image'] : basename( $file );
		$ext      = strtolower( pathinfo( $current, PATHINFO_EXTENSION ) );
		$is_image = wp_attachment_is_image( $post->ID );
		$undo     = DITMS_History::undo_label( $post->ID );

		ob_start();
		?>
		<div class="ditms" data-id="<?php echo (int) $post->ID; ?>">
			<div class="ditms__file">
				<span class="ditms__name" title="<?php echo esc_attr( $current ); ?>"><?php echo esc_html( $current ); ?></span>
				<?php if ( ditms_is_generic_name( $current ) ) : ?>
					<span class="ditms__tag">genérico</span>
				<?php endif; ?>
			</div>
			<div class="ditms__input">
				<input type="text" class="ditms__filename" placeholder="<?php echo $is_image ? 'nombre nuevo o palabras clave' : 'nombre nuevo'; ?>" aria-label="Nombre nuevo del archivo" autocomplete="off" spellcheck="false">
				<span class="ditms__ext">.<?php echo esc_html( $ext ); ?></span>
			</div>
			<div class="ditms__actions">
				<?php if ( $is_image ) : ?>
					<button type="button" class="ditms__btn ditms__btn--ai" data-action="generate">
						<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path fill="currentColor" d="M8 0l1.6 4.4L14 6 9.6 7.6 8 12 6.4 7.6 2 6l4.4-1.6zM13 10l.8 2.2L16 13l-2.2.8L13 16l-.8-2.2L10 13l2.2-.8z"/></svg>
						Generar con IA
					</button>
				<?php endif; ?>
				<button type="button" class="ditms__btn ditms__btn--primary" data-action="rename">Renombrar</button>
				<button type="button" class="ditms__btn ditms__btn--ghost" data-action="undo" title="<?php echo esc_attr( $undo ); ?>" <?php echo $undo ? '' : 'hidden'; ?>>
					<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path fill="currentColor" d="M5.8 3.2V0L0 5l5.8 5V6.6c4 0 6.7 1.3 8.2 4.4C13.4 6.6 10.6 3.8 5.8 3.2z"/></svg>
					Deshacer
				</button>
			</div>
			<p class="ditms__status" aria-live="polite"></p>
		</div>
		<?php
		$form_fields['ditms'] = array(
			'label' => 'Media SEO',
			'input' => 'html',
			'html'  => ob_get_clean(),
		);
		return $form_fields;
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                                */
	/* ------------------------------------------------------------------ */

	private static function check( $id ) {
		check_ajax_referer( 'ditms', 'nonce' );
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => 'No tienes permiso para editar este archivo.' ), 403 );
		}
	}

	private static function request_id() {
		return isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Genera y guarda los textos (se pueden deshacer). Devuelve también el nombre propuesto, que no se aplica hasta pulsar Renombrar.
	 */
	public static function ajax_generate() {
		$id = self::request_id();
		self::check( $id );
		$hint = isset( $_POST['hint'] ) ? sanitize_text_field( wp_unslash( $_POST['hint'] ) ) : '';
		$data = DITMS_AI::generate( $id, $hint );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}
		$data['saved'] = DITMS_AI::save_fields( $id, $data, true );
		$data['undo']  = DITMS_History::undo_label( $id );
		wp_send_json_success( $data );
	}

	public static function ajax_rename() {
		$id = self::request_id();
		self::check( $id );
		$name   = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
		$result = DITMS_Renamer::rename( $id, $name );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$result['slug'] = get_post_field( 'post_name', $id );
		$result['undo'] = DITMS_History::undo_label( $id );
		wp_send_json_success( $result );
	}

	public static function ajax_undo() {
		$id = self::request_id();
		self::check( $id );
		$result = DITMS_History::undo( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$result['current'] = basename( (string) get_attached_file( $id ) );
		$meta              = wp_get_attachment_metadata( $id );
		if ( ! empty( $meta['original_image'] ) ) {
			$result['current'] = $meta['original_image'];
		}
		$result['undo'] = $result['label'];
		wp_send_json_success( $result );
	}

	/**
	 * Lote: genera, guarda y (si se pide) renombra los archivos con nombre genérico. Todo queda en un solo paso de deshacer.
	 */
	public static function ajax_process() {
		$id = self::request_id();
		self::check( $id );
		$rename    = ! empty( $_POST['rename'] );
		$overwrite = ! empty( $_POST['overwrite'] );

		$data = DITMS_AI::generate( $id );
		if ( is_wp_error( $data ) ) {
			// Sin clave, clave inválida o límite de uso: no tiene sentido seguir con el resto del lote.
			$stop = in_array( $data->get_error_code(), array( 'ditms_no_key', 'ditms_auth', 'ditms_rate' ), true );
			wp_send_json_error( array( 'message' => $data->get_error_message(), 'stop' => $stop ) );
		}

		$group = DITMS_History::new_group();
		$saved = DITMS_AI::save_fields( $id, $data, $overwrite, $group );
		$notes = array();
		if ( $saved ) {
			$notes[] = 'textos: ' . implode( ', ', $saved );
		}

		if ( $rename && $data['filename'] ) {
			$meta    = wp_get_attachment_metadata( $id );
			$current = ! empty( $meta['original_image'] ) ? $meta['original_image'] : basename( (string) get_attached_file( $id ) );
			if ( ditms_is_generic_name( $current ) ) {
				$r       = DITMS_Renamer::rename( $id, $data['filename'], array( 'group' => $group ) );
				$notes[] = is_wp_error( $r ) ? 'no se renombró: ' . $r->get_error_message() : 'renombrado a ' . $r['filename'];
			}
		}

		wp_send_json_success( array(
			'message' => $notes ? implode( ' · ', $notes ) : 'sin cambios (ya tenía textos)',
			'undo'    => ( $notes ? DITMS_History::undo_label( $id ) : '' ),
		) );
	}

	public static function ajax_list() {
		check_ajax_referer( 'ditms', 'nonce' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => 'Sin permiso.' ), 403 );
		}
		global $wpdb;
		$mode = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'missing_alt';

		if ( 'missing_alt' === $mode ) {
			$ids = $wpdb->get_col(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
				 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'
				 AND ( m.meta_value IS NULL OR TRIM( m.meta_value ) = '' )
				 ORDER BY p.ID DESC LIMIT 2000"
			);
		} else {
			$rows = $wpdb->get_results(
				"SELECT p.ID, f.meta_value AS file FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
				 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'
				 ORDER BY p.ID DESC LIMIT 5000"
			);
			$ids = array();
			foreach ( $rows as $row ) {
				if ( 'all' === $mode || ( 'generic' === $mode && ditms_is_generic_name( basename( (string) $row->file ) ) ) ) {
					$ids[] = $row->ID;
				}
			}
			$ids = array_slice( $ids, 0, 2000 );
		}

		$ids = array_values( array_filter( array_map( 'absint', $ids ), function ( $id ) {
			return current_user_can( 'edit_post', $id );
		} ) );
		wp_send_json_success( array( 'ids' => $ids ) );
	}

	/* ------------------------------------------------------------------ */
	/* Lote                                                                */
	/* ------------------------------------------------------------------ */

	public static function bulk_action( $actions ) {
		$actions['ditms_bulk'] = 'Generar textos SEO con IA';
		return $actions;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 'ditms_bulk' !== $action ) {
			return $redirect;
		}
		return add_query_arg( array(
			'page' => 'ditms-bulk',
			'ids'  => implode( ',', array_map( 'absint', (array) $ids ) ),
		), admin_url( 'upload.php' ) );
	}

	public static function bulk_page() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$preset = isset( $_GET['ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['ids'] ) ) ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap ditms-page ditms-bulk" data-preset="<?php echo esc_attr( implode( ',', $preset ) ); ?>">
			<header class="ditms-page__head">
				<h1>Media SEO en lote</h1>
				<p>Se procesan de una en una. Deja esta pestaña abierta hasta que termine.</p>
			</header>

			<?php if ( ! ditms_api_key() ) : ?>
				<p class="ditms-pill ditms-pill--error">Falta la clave de la API. <a href="<?php echo esc_url( admin_url( 'options-general.php?page=ditms-settings' ) ); ?>">Configurarla</a></p>
			<?php endif; ?>

			<section class="ditms-card">
				<h2>Qué imágenes</h2>
				<div class="ditms-options">
					<?php if ( $preset ) : ?>
						<label class="ditms-option"><input type="radio" name="ditms-mode" value="preset" checked><span>Las <?php echo count( $preset ); ?> seleccionadas</span></label>
					<?php endif; ?>
					<label class="ditms-option"><input type="radio" name="ditms-mode" value="missing_alt" <?php checked( ! $preset ); ?>><span>Sin texto alternativo</span></label>
					<label class="ditms-option"><input type="radio" name="ditms-mode" value="generic"><span>Con nombre genérico</span></label>
					<label class="ditms-option"><input type="radio" name="ditms-mode" value="all"><span>Todas</span></label>
				</div>
				<label class="ditms-toggle"><input type="checkbox" id="ditms-overwrite" <?php checked( ditms_setting( 'overwrite' ) ); ?>><span class="ditms-toggle__ui" aria-hidden="true"></span><span class="ditms-toggle__text">Sobrescribir textos existentes</span></label>
				<label class="ditms-toggle"><input type="checkbox" id="ditms-rename"><span class="ditms-toggle__ui" aria-hidden="true"></span><span class="ditms-toggle__text">Renombrar los archivos con nombre genérico<small>IMG_2034, DSC, WhatsApp Image, capturas. Cada imagen se puede deshacer desde la lista de abajo.</small></span></label>

				<div class="ditms-run">
					<button type="button" class="ditms__btn ditms__btn--primary" id="ditms-start">Empezar</button>
					<button type="button" class="ditms__btn ditms__btn--ghost" id="ditms-stop" disabled>Detener</button>
					<span class="ditms-run__text" aria-live="polite"></span>
				</div>
				<div class="ditms-progress" hidden><span></span></div>
			</section>

			<ol class="ditms-log"></ol>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Columna en la biblioteca (vista de lista)                           */
	/* ------------------------------------------------------------------ */

	public static function column( $columns ) {
		$columns['ditms'] = 'Texto alternativo';
		return $columns;
	}

	public static function column_value( $column, $id ) {
		if ( 'ditms' !== $column || ! wp_attachment_is_image( $id ) ) {
			return;
		}
		$alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		if ( '' === $alt ) {
			echo '<span class="ditms-dot ditms-dot--missing">Falta</span>';
		} else {
			echo '<span class="ditms-dot ditms-dot--ok" title="' . esc_attr( $alt ) . '">' . esc_html( wp_trim_words( $alt, 8 ) ) . '</span>';
		}
	}

	/* ------------------------------------------------------------------ */
	/* Al subir                                                            */
	/* ------------------------------------------------------------------ */

	public static function normalize_upload( $file ) {
		if ( empty( $file['name'] ) || ! ditms_setting( 'normalize_upload' ) ) {
			return $file;
		}
		$ext  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		$base = ditms_slugify( pathinfo( $file['name'], PATHINFO_FILENAME ) );
		if ( '' !== $base && '' !== $ext ) {
			$file['name'] = $base . '.' . $ext;
		}
		return $file;
	}

	public static function schedule_auto( $id ) {
		if ( ! ditms_setting( 'auto_on_upload' ) || ! ditms_api_key() || ! wp_attachment_is_image( $id ) ) {
			return;
		}
		// En segundo plano para no hacer esperar la subida; para entonces ya existen los tamaños intermedios.
		wp_schedule_single_event( time() + 15, 'ditms_auto_generate', array( (int) $id ) );
	}

	public static function run_auto( $id ) {
		$data = DITMS_AI::generate( $id );
		if ( ! is_wp_error( $data ) ) {
			DITMS_AI::save_fields( $id, $data, false );
		}
	}
}
