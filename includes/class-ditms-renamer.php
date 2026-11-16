<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renombra el archivo de un adjunto de forma completa:
 * mueve el original, regenera los tamaños, borra los archivos viejos,
 * actualiza enlaces (contenido, Elementor, BeTheme, WooCommerce, términos y opciones) y registra la redirección 301.
 */
class DITMS_Renamer {

	/** Filas por consulta al buscar enlaces: mantiene bajo el uso de memoria en sitios grandes. */
	const CHUNK = 200;

	/** Metadatos de BeTheme que pueden ir en base64 (no se encuentran con LIKE). */
	const BETHEME_KEYS = array( 'mfn-page-items', 'mfn-builder-preview', 'mfn-page-items-backup' );

	/**
	 * @param int    $id       ID del adjunto.
	 * @param string $new_name Nombre nuevo (con o sin extensión; se limpia).
	 * @param array  $args     log (bool, registrar en el historial) y group (grupo de deshacer).
	 * @return array|WP_Error
	 */
	public static function rename( $id, $new_name, array $args = array() ) {
		// exact: usar el nombre tal cual (solo para Deshacer, que vuelve al nombre original con mayúsculas o guiones bajos).
		$args = wp_parse_args( $args, array( 'log' => true, 'group' => null, 'exact' => false ) );
		$id   = (int) $id;
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'ditms_not_found', 'El adjunto no existe.' );
		}

		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'ditms_missing', 'No se encuentra el archivo en el servidor.' );
		}

		$lock = 'ditms_lock_' . $id;
		if ( get_transient( $lock ) ) {
			return new WP_Error( 'ditms_locked', 'Este archivo se está renombrando en otra pestaña. Intenta de nuevo en un minuto.' );
		}
		set_transient( $lock, 1, MINUTE_IN_SECONDS );

		$result = self::do_rename( $id, $post, $file, $new_name, $args );

		delete_transient( $lock );
		return $result;
	}

	private static function do_rename( $id, $post, $file, $new_name, array $args ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$dir      = dirname( $file );
		$meta     = wp_get_attachment_metadata( $id );
		$meta     = is_array( $meta ) ? $meta : array();
		$is_image = wp_attachment_is_image( $id );

		// En WP 5.3+ el archivo adjunto puede ser la copia "-scaled" o "-rotated": se renombra el original real.
		$source = $file;
		if ( ! empty( $meta['original_image'] ) && file_exists( $dir . '/' . $meta['original_image'] ) ) {
			$source = $dir . '/' . $meta['original_image'];
		}

		$ext  = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );
		if ( $args['exact'] ) {
			// sanitize_file_name quita rutas y caracteres peligrosos, pero conserva mayúsculas y guiones bajos.
			$base     = sanitize_file_name( pathinfo( $new_name, PATHINFO_FILENAME ) );
			$orig_ext = pathinfo( $new_name, PATHINFO_EXTENSION );
			if ( '' !== $orig_ext && strtolower( $orig_ext ) === $ext ) {
				$ext = $orig_ext;
			}
		} else {
			$base = ditms_clean_basename( $new_name, $ext );
		}
		if ( '' === $base ) {
			return new WP_Error( 'ditms_empty', 'El nombre nuevo queda vacío después de limpiarlo. Usa letras o números.' );
		}
		if ( pathinfo( $source, PATHINFO_FILENAME ) === $base ) {
			return new WP_Error( 'ditms_same', 'El archivo ya tiene ese nombre.' );
		}

		$old_files    = self::collect_files( $file, $meta );
		$old_urls     = self::collect_urls( $id, $meta );
		$old_stem     = self::upload_stem( $source );
		$old_filename = basename( $source );

		$new_filename = wp_unique_filename( $dir, $base . '.' . $ext );
		$new_path     = $dir . '/' . $new_filename;

		if ( ! @rename( $source, $new_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'ditms_move', 'No se pudo renombrar el archivo. Revisa los permisos de la carpeta de uploads.' );
		}

		update_attached_file( $id, $new_path );

		// Regenera los tamaños desde el original renombrado (crea de nuevo el -scaled si hace falta).
		$new_meta = wp_generate_attachment_metadata( $id, $new_path );
		if ( is_wp_error( $new_meta ) || ( $is_image && empty( $new_meta['file'] ) ) ) {
			// Deshacer: devolver el archivo a su nombre y metadatos originales.
			@rename( $new_path, $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			update_attached_file( $id, $file );
			wp_update_attachment_metadata( $id, $meta );
			return new WP_Error( 'ditms_regen', 'No se pudieron regenerar los tamaños de la imagen. Se dejó el nombre original.' );
		}
		wp_update_attachment_metadata( $id, $new_meta );

		// Borra los archivos viejos (tamaños, -scaled, formatos alternos) que ya no usa el adjunto.
		$new_files = self::collect_files( get_attached_file( $id ), $new_meta );
		foreach ( array_diff( $old_files, $new_files, array( wp_normalize_path( $new_path ) ) ) as $old ) {
			if ( file_exists( $old ) ) {
				wp_delete_file( $old );
			}
		}

		$new_urls = self::collect_urls( $id, $new_meta );
		$pairs    = self::url_pairs( $old_urls, $new_urls );

		global $wpdb;

		$settings = ditms_settings();
		$fields   = array( 'guid' => $new_urls['full'] );
		if ( ! empty( $settings['update_slug'] ) ) {
			$fields['post_name'] = wp_unique_post_slug( sanitize_title( $base ), $id, $post->post_status, 'attachment', $post->post_parent );
		}
		$wpdb->update( $wpdb->posts, $fields, array( 'ID' => $id ) );
		clean_post_cache( $id );

		$counts = array();
		if ( ! empty( $settings['replace_refs'] ) && $pairs ) {
			$counts = self::replace_references( $id, $old_stem, $pairs );
		}

		if ( ! empty( $settings['redirects'] ) ) {
			DITMS_Redirects::add( $id, $old_urls, $new_urls );
		}

		if ( $args['log'] ) {
			DITMS_History::add_rename( $id, $old_filename, $new_filename, $args['group'] );
		}

		do_action( 'ditms_renamed', $id, $old_urls, $new_urls );

		return array(
			'id'       => $id,
			'filename' => $new_filename,
			'url'      => $new_urls['full'],
			'counts'   => $counts,
			'refs'     => array_sum( $counts ),
		);
	}

	/**
	 * Rutas absolutas de todos los archivos físicos del adjunto.
	 */
	private static function collect_files( $file, array $meta ) {
		$dir   = dirname( $file );
		$files = array( $file );

		if ( ! empty( $meta['original_image'] ) ) {
			$files[] = $dir . '/' . $meta['original_image'];
		}
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $dir . '/' . $size['file'];
				}
				$files = array_merge( $files, self::source_files( $dir, $size ) );
			}
		}
		// Formatos alternos (WebP/AVIF) que guardan algunos plugins de rendimiento.
		$files = array_merge( $files, self::source_files( $dir, $meta ) );

		return array_values( array_unique( array_map( 'wp_normalize_path', $files ) ) );
	}

	private static function source_files( $dir, $node ) {
		$out = array();
		if ( ! empty( $node['sources'] ) && is_array( $node['sources'] ) ) {
			foreach ( $node['sources'] as $src ) {
				if ( ! empty( $src['file'] ) ) {
					$out[] = $dir . '/' . basename( $src['file'] );
				}
			}
		}
		return $out;
	}

	/**
	 * URLs del adjunto por tamaño: full, original y cada tamaño intermedio.
	 */
	private static function collect_urls( $id, array $meta ) {
		$full = wp_get_attachment_url( $id );
		$base = trailingslashit( dirname( $full ) );
		$urls = array( 'full' => $full );

		if ( ! empty( $meta['original_image'] ) ) {
			$urls['original'] = $base . $meta['original_image'];
		}
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $name => $size ) {
				if ( ! empty( $size['file'] ) ) {
					$urls[ 'size:' . $name ] = $base . $size['file'];
				}
			}
		}
		return $urls;
	}

	/**
	 * Pares viejo => nuevo, en forma de ruta (sirve para http, https, dominio de staging o CDN)
	 * y con las barras escapadas que usan Elementor, BeTheme y otros constructores en JSON.
	 */
	private static function url_pairs( array $old, array $new ) {
		$pairs = array();
		foreach ( $old as $key => $old_url ) {
			$target = isset( $new[ $key ] ) ? $new[ $key ] : $new['full'];
			$from   = wp_make_link_relative( $old_url );
			$to     = wp_make_link_relative( $target );
			if ( $from && $from !== $to ) {
				$pairs[ $from ]                          = $to;
				$pairs[ str_replace( '/', '\/', $from ) ] = str_replace( '/', '\/', $to );
			}
		}
		return $pairs;
	}

	/**
	 * Parte común de todas las variantes del archivo, relativa a uploads. Ej.: 2024/05/cocina-integral
	 */
	private static function upload_stem( $path ) {
		$uploads = wp_get_upload_dir();
		$rel     = ltrim( str_replace( wp_normalize_path( $uploads['basedir'] ), '', wp_normalize_path( $path ) ), '/' );
		$dir     = dirname( $rel );
		$stem    = pathinfo( $rel, PATHINFO_FILENAME );
		return ( '.' === $dir || '' === $dir ) ? $stem : $dir . '/' . $stem;
	}

	/**
	 * Recorre una consulta por bloques (paginando por ID) para no cargar miles de filas a la vez.
	 * $sql debe terminar en una condición WHERE; se le agrega "AND $id_col > %d ORDER BY ... LIMIT".
	 */
	private static function each_row( $sql, array $args, $id_col, callable $callback ) {
		global $wpdb;
		$last = 0;
		do {
			$query = $wpdb->prepare( "{$sql} AND {$id_col} > %d ORDER BY {$id_col} ASC LIMIT " . self::CHUNK, array_merge( $args, array( $last ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			// Si la consulta falla, get_results devuelve null: se trata como "sin filas" para no detener el renombrado.
			$rows = $query ? (array) $wpdb->get_results( $query ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $rows as $row ) {
				$callback( $row );
				$last = (int) $row->$id_col;
			}
		} while ( count( $rows ) === self::CHUNK );
	}

	/**
	 * Reemplaza las URLs viejas en todo el sitio.
	 */
	private static function replace_references( $id, $stem, array $pairs ) {
		global $wpdb;

		$plain    = '%' . $wpdb->esc_like( $stem ) . '%';
		$json     = '%' . $wpdb->esc_like( str_replace( '/', '\/', $stem ) ) . '%';
		$counts   = array( 'posts' => 0, 'meta' => 0, 'terms' => 0, 'options' => 0, 'files' => 0 );
		$touched  = array();
		$builders = array( 'elementor' => false, 'betheme' => false );

		// 1. Contenido y extracto (descripción corta de WooCommerce).
		self::each_row(
			"SELECT ID, post_content, post_excerpt FROM {$wpdb->posts}
			 WHERE post_type <> 'revision' AND ( post_content LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s )",
			array( $plain, $json, $plain ),
			'ID',
			function ( $row ) use ( $wpdb, $pairs, &$counts, &$touched ) {
				$content = strtr( $row->post_content, $pairs );
				$excerpt = strtr( $row->post_excerpt, $pairs );
				if ( $content !== $row->post_content || $excerpt !== $row->post_excerpt ) {
					$wpdb->update( $wpdb->posts, array( 'post_content' => $content, 'post_excerpt' => $excerpt ), array( 'ID' => $row->ID ) );
					clean_post_cache( $row->ID );
					$touched[ $row->ID ] = true;
					$counts['posts']++;
				}
			}
		);

		// 2. Campos personalizados: Elementor, ACF, BeTheme, descargas de WooCommerce... (serializado, JSON o base64).
		$meta_callback = function ( $row ) use ( $wpdb, $pairs, &$counts, &$touched, &$builders ) {
			$changed = false;
			$value   = ditms_deep_replace( maybe_unserialize( $row->meta_value ), $pairs, $changed );
			if ( ! $changed ) {
				return;
			}
			$wpdb->update( $wpdb->postmeta, array( 'meta_value' => maybe_serialize( $value ) ), array( 'meta_id' => $row->meta_id ) );
			wp_cache_delete( $row->post_id, 'post_meta' );
			$touched[ $row->post_id ] = true;
			$counts['meta']++;
			if ( 0 === strpos( $row->meta_key, '_elementor' ) ) {
				$builders['elementor'] = true;
			} elseif ( 0 === strpos( $row->meta_key, 'mfn-' ) ) {
				$builders['betheme'] = true;
			}
		};

		self::each_row(
			"SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta}
			 WHERE ( meta_value LIKE %s OR meta_value LIKE %s )
			 AND NOT ( post_id = %d AND meta_key IN ( '_wp_attached_file', '_wp_attachment_metadata', '_ditms_history' ) )",
			array( $plain, $json, $id ),
			'meta_id',
			$meta_callback
		);

		// 2b. BeTheme en base64: no se puede filtrar con LIKE, se revisan solo sus claves (y solo si el tema está activo).
		$keys = "'" . implode( "','", array_map( 'esc_sql', self::BETHEME_KEYS ) ) . "'";
		if ( self::betheme_active() ) {
			self::each_row(
				"SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta}
				 WHERE ( meta_key IN ( {$keys} ) OR meta_key LIKE %s )
				 AND meta_value NOT LIKE %s AND meta_value NOT LIKE %s",
				array( $wpdb->esc_like( 'mfn-builder-revision-' ) . '%', $plain, $json ),
				'meta_id',
				$meta_callback
			);
		}

		// 3. Términos: descripciones y metadatos (categorías de WooCommerce, etiquetas, taxonomías del tema).
		self::each_row(
			"SELECT term_taxonomy_id, term_id, taxonomy, description FROM {$wpdb->term_taxonomy} WHERE ( description LIKE %s OR description LIKE %s )",
			array( $plain, $json ),
			'term_taxonomy_id',
			function ( $row ) use ( $wpdb, $pairs, &$counts ) {
				$new = strtr( $row->description, $pairs );
				if ( $new !== $row->description ) {
					$wpdb->update( $wpdb->term_taxonomy, array( 'description' => $new ), array( 'term_taxonomy_id' => $row->term_taxonomy_id ) );
					clean_term_cache( $row->term_id, $row->taxonomy );
					$counts['terms']++;
				}
			}
		);
		self::each_row(
			"SELECT meta_id, term_id, meta_value FROM {$wpdb->termmeta} WHERE ( meta_value LIKE %s OR meta_value LIKE %s )",
			array( $plain, $json ),
			'meta_id',
			function ( $row ) use ( $wpdb, $pairs, &$counts ) {
				$changed = false;
				$value   = ditms_deep_replace( maybe_unserialize( $row->meta_value ), $pairs, $changed );
				if ( $changed ) {
					$wpdb->update( $wpdb->termmeta, array( 'meta_value' => maybe_serialize( $value ) ), array( 'meta_id' => $row->meta_id ) );
					wp_cache_delete( $row->term_id, 'term_meta' );
					$counts['terms']++;
				}
			}
		);

		// 4. Opciones: widgets, personalizador, opciones de BeTheme y de otros temas.
		self::each_row(
			"SELECT option_id, option_name, option_value FROM {$wpdb->options}
			 WHERE ( option_value LIKE %s OR option_value LIKE %s )
			 AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT IN ( %s, %s )",
			array( $plain, $json, $wpdb->esc_like( '_transient' ) . '%', $wpdb->esc_like( '_site_transient' ) . '%', DITMS_OPTION, DITMS_REDIRECTS ),
			'option_id',
			function ( $row ) use ( $wpdb, $pairs, &$counts ) {
				$changed = false;
				$value   = ditms_deep_replace( maybe_unserialize( $row->option_value ), $pairs, $changed );
				if ( $changed ) {
					$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $value ) ), array( 'option_id' => $row->option_id ) );
					wp_cache_delete( $row->option_name, 'options' );
					$counts['options']++;
				}
			}
		);
		if ( $counts['options'] ) {
			wp_cache_delete( 'alloptions', 'options' );
		}

		// 5. CSS estático que generan los constructores.
		$counts['files'] += self::replace_in_css_files( 'betheme/css', $pairs );
		if ( $builders['elementor'] ) {
			self::clear_elementor();
		}

		// 6. WooCommerce: caché de productos afectados.
		if ( $touched && function_exists( 'wc_delete_product_transients' ) ) {
			foreach ( array_keys( $touched ) as $post_id ) {
				if ( in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true ) ) {
					wc_delete_product_transients( $post_id );
				}
			}
		}

		if ( array_sum( $counts ) ) {
			self::purge_page_caches();
		}

		return $counts;
	}

	/**
	 * Reemplaza las URLs en los .css de una carpeta dentro de uploads (BeTheme guarda ahí los estilos de cada página).
	 */
	private static function replace_in_css_files( $subdir, array $pairs ) {
		$uploads = wp_get_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . $subdir;
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$needles = array_keys( $pairs );
		$count   = 0;
		foreach ( (array) glob( $dir . '/*.css' ) as $css_file ) {
			$css = file_get_contents( $css_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $css ) {
				continue;
			}
			$found = false;
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $css, $needle ) ) {
					$found = true;
					break;
				}
			}
			if ( $found && false !== file_put_contents( $css_file, strtr( $css, $pairs ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				$count++;
			}
		}
		return $count;
	}

	private static function betheme_active() {
		return 'betheme' === get_template() || function_exists( 'mfn_opts_get' );
	}

	private static function clear_elementor() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}
		try {
			// Borra el CSS generado y la caché de elementos; Elementor los regenera en la siguiente visita.
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
		}
	}

	/**
	 * Vacía la caché de página de los plugins más comunes para que no sirvan HTML con las URLs viejas.
	 */
	private static function purge_page_caches() {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		do_action( 'litespeed_purge_all' );
	}
}
