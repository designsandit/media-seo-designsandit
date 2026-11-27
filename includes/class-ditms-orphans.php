<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Archivos sin uso en uploads: los que no pertenecen a ninguna imagen de la biblioteca
 * (por ejemplo, las copias que dejaba Rename Media Files al renombrar).
 *
 * Nunca borra directamente: mueve a una carpeta de cuarentena, de donde se puede restaurar.
 * Antes de mover vuelve a comprobar que el archivo no pertenezca a la biblioteca ni se use en el sitio.
 */
class DITMS_Orphans {

	const DIR    = 'media-seo-cuarentena';
	const OPTION = 'ditms_quarantine';

	/** Archivos más nuevos que esto se ignoran: pueden ser subidas en curso. */
	const MIN_AGE = HOUR_IN_SECONDS;

	/** Formatos que los plugins de optimización generan junto al original y sirven sin pasar por la base de datos. */
	const ALT_FORMATS = array( 'webp', 'avif' );

	/** Archivos del sistema que nunca se tocan. */
	const IGNORE = array( 'index.php', 'index.html', '.htaccess', 'web.config' );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		foreach ( array( 'dirs', 'scan', 'move', 'restore', 'purge' ) as $action ) {
			add_action( 'wp_ajax_ditms_orphans_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
	}

	public static function menu() {
		add_media_page( 'Archivos sin uso', 'Archivos sin uso', 'manage_options', 'ditms-orphans', array( __CLASS__, 'page' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Rutas                                                               */
	/* ------------------------------------------------------------------ */

	private static function base() {
		$uploads = wp_get_upload_dir();
		return wp_normalize_path( untrailingslashit( $uploads['basedir'] ) );
	}

	private static function quarantine_base() {
		return self::base() . '/' . self::DIR;
	}

	/**
	 * Valida una ruta relativa de uploads ("2024/05/foto.jpg" o "foto.jpg") y devuelve la absoluta, o '' si no es válida.
	 */
	private static function resolve( $rel, $root ) {
		$rel = wp_normalize_path( (string) $rel );
		if ( ! preg_match( '#^(\d{4}/\d{2}/)?[^/\\\\]+$#', $rel ) || false !== strpos( $rel, '..' ) ) {
			return '';
		}
		$file = in_array( basename( $rel ), self::IGNORE, true ) ? '' : $root . '/' . $rel;
		return $file;
	}

	/**
	 * Carpetas a revisar: las de año/mes y la raíz de uploads (solo sus archivos sueltos).
	 */
	public static function dirs() {
		$base = self::base();
		$dirs = array();
		foreach ( (array) glob( $base . '/[0-9][0-9][0-9][0-9]/[0-9][0-9]', GLOB_ONLYDIR ) as $dir ) {
			if ( $dir ) {
				$dirs[] = substr( wp_normalize_path( $dir ), strlen( $base ) + 1 );
			}
		}
		sort( $dirs );
		$dirs[] = '';
		return $dirs;
	}

	/**
	 * Nombres de archivo que pertenecen a la biblioteca en una carpeta ('' = raíz de uploads).
	 */
	private static function known_files( $dir ) {
		global $wpdb;
		if ( '' === $dir ) {
			$ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value NOT LIKE '%/%'" );
		} else {
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
				$wpdb->esc_like( $dir . '/' ) . '%'
			) );
		}
		$ids = array_map( 'intval', (array) $ids );
		if ( $ids ) {
			update_meta_cache( 'post', $ids );
		}

		$known = array();
		foreach ( $ids as $id ) {
			$attached = (string) get_post_meta( $id, '_wp_attached_file', true );
			if ( wp_normalize_path( dirname( $attached ) ) !== ( '' === $dir ? '.' : $dir ) ) {
				continue;
			}
			$known[ basename( $attached ) ] = true;
			// Originales que guarda el editor de imágenes de WordPress al recortar o rotar ("Restaurar imagen original").
			$backups = get_post_meta( $id, '_wp_attachment_backup_sizes', true );
			if ( is_array( $backups ) ) {
				foreach ( $backups as $backup ) {
					if ( ! empty( $backup['file'] ) ) {
						$known[ basename( $backup['file'] ) ] = true;
					}
				}
			}
			$meta = wp_get_attachment_metadata( $id );
			if ( ! is_array( $meta ) ) {
				continue;
			}
			if ( ! empty( $meta['original_image'] ) ) {
				$known[ basename( $meta['original_image'] ) ] = true;
			}
			$nodes = array( $meta );
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( ! empty( $size['file'] ) ) {
						$known[ basename( $size['file'] ) ] = true;
					}
					$nodes[] = $size;
				}
			}
			foreach ( $nodes as $node ) {
				if ( ! empty( $node['sources'] ) && is_array( $node['sources'] ) ) {
					foreach ( $node['sources'] as $src ) {
						if ( ! empty( $src['file'] ) ) {
							$known[ basename( $src['file'] ) ] = true;
						}
					}
				}
			}
		}
		return $known;
	}

	/**
	 * ¿Es un formato alterno (WebP/AVIF) de un archivo de la biblioteca? Ej.: foto.jpg.webp o foto.webp junto a foto.jpg.
	 */
	private static function is_alt_format( $file, array $known ) {
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::ALT_FORMATS, true ) ) {
			return false;
		}
		$without = substr( $file, 0, -( strlen( $ext ) + 1 ) );
		if ( isset( $known[ $without ] ) ) {
			return true;
		}
		$stem = pathinfo( $file, PATHINFO_FILENAME );
		foreach ( array_keys( $known ) as $name ) {
			if ( pathinfo( $name, PATHINFO_FILENAME ) === $stem ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* Uso en el sitio                                                     */
	/* ------------------------------------------------------------------ */

	/** Texto de los constructores que no se puede buscar con LIKE (BeTheme en base64) y CSS generado. Una vez por petición. */
	private static $haystack = null;

	private static function haystack() {
		if ( null !== self::$haystack ) {
			return self::$haystack;
		}
		global $wpdb;
		$parts = array();

		if ( 'betheme' === get_template() || function_exists( 'mfn_opts_get' ) ) {
			$rows = $wpdb->get_col( $wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ( 'mfn-page-items', 'mfn-builder-preview', 'mfn-page-items-backup' ) OR meta_key LIKE %s",
				$wpdb->esc_like( 'mfn-builder-revision-' ) . '%'
			) );
			foreach ( (array) $rows as $raw ) {
				$parts[] = self::flatten( maybe_unserialize( $raw ) );
			}
		}
		foreach ( array( 'betheme/css', 'elementor/css' ) as $sub ) {
			foreach ( (array) glob( self::base() . '/' . $sub . '/*.css' ) as $css ) {
				if ( $css ) {
					$parts[] = (string) file_get_contents( $css ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
			}
		}
		self::$haystack = implode( "\n", $parts );
		return self::$haystack;
	}

	/** Texto de los archivos del tema activo y su tema padre (CSS, JS, PHP, plantillas). Una vez por petición. */
	private static $theme_hay = null;

	/** Límites para no cargar temas enormes en memoria. */
	const THEME_FILE_MAX  = 2097152;  // 2 MB por archivo
	const THEME_TOTAL_MAX = 33554432; // 32 MB en total

	private static function theme_haystack() {
		if ( null !== self::$theme_hay ) {
			return self::$theme_hay;
		}
		$parts = array();
		$total = 0;
		$dirs  = array_unique( array( get_stylesheet_directory(), get_template_directory() ) );
		foreach ( $dirs as $dir ) {
			if ( ! $dir || ! is_dir( $dir ) ) {
				continue;
			}
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				$ext = strtolower( $file->getExtension() );
				if ( ! in_array( $ext, array( 'css', 'scss', 'js', 'php', 'html', 'json', 'twig' ), true ) ) {
					continue;
				}
				$size = (int) $file->getSize();
				if ( $size > self::THEME_FILE_MAX || $total + $size > self::THEME_TOTAL_MAX ) {
					continue;
				}
				$total  += $size;
				$parts[] = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		self::$theme_hay = implode( "\n", $parts );
		return self::$theme_hay;
	}

	private static function flatten( $value ) {
		if ( is_string( $value ) ) {
			$decoded = ditms_maybe_base64_serialized( $value );
			return null !== $decoded ? self::flatten( $decoded ) : $value;
		}
		if ( is_array( $value ) ) {
			return implode( "\n", array_map( array( __CLASS__, 'flatten' ), $value ) );
		}
		return '';
	}

	/**
	 * Todo el texto del sitio que menciona una carpeta ("2024/05/"), agrupado por dónde está.
	 * Una consulta por tabla y carpeta, en vez de una por archivo. La raíz de uploads usa la búsqueda por archivo.
	 *
	 * @return array|null [ etiqueta => texto ], o null para la raíz.
	 */
	private static function dir_haystacks( $dir ) {
		global $wpdb;
		if ( '' === $dir ) {
			return null;
		}
		$plain = '%' . $wpdb->esc_like( $dir . '/' ) . '%';
		$json  = '%' . $wpdb->esc_like( str_replace( '/', '\/', $dir . '/' ) ) . '%';
		$out   = array();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_content, post_excerpt FROM {$wpdb->posts} WHERE post_type <> 'revision' AND ( post_content LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s )",
			$plain, $json, $plain
		), ARRAY_N );
		$out['páginas y entradas'] = implode( "\n", array_map( function ( $r ) {
			return $r[0] . "\n" . $r[1];
		}, (array) $rows ) );

		$queries = array(
			'campos personalizados'  => "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key NOT IN ( '_wp_attached_file', '_wp_attachment_metadata' ) AND ( meta_value LIKE %s OR meta_value LIKE %s )",
			'opciones y widgets'     => "SELECT option_value FROM {$wpdb->options} WHERE option_value LIKE %s OR option_value LIKE %s",
			'categorías y etiquetas' => "SELECT meta_value FROM {$wpdb->termmeta} WHERE meta_value LIKE %s OR meta_value LIKE %s",
		);
		foreach ( $queries as $label => $sql ) {
			$out[ $label ] = implode( "\n", (array) $wpdb->get_col( $wpdb->prepare( $sql, $plain, $json ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$out['categorías y etiquetas'] .= "\n" . implode( "\n", (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT description FROM {$wpdb->term_taxonomy} WHERE description LIKE %s OR description LIKE %s",
			$plain, $json
		) ) );
		$out['constructor o CSS'] = self::haystack();
		$out['archivos del tema'] = self::theme_haystack();
		return $out;
	}

	/**
	 * Dónde se usa un archivo, o '' si no aparece en ningún lado. $rel: "2024/05/foto.jpg".
	 * Con $hays (de dir_haystacks) compara en memoria; sin él, consulta la base de datos.
	 */
	public static function where_used( $rel, $hays = null ) {
		global $wpdb;
		if ( is_array( $hays ) ) {
			$escaped = str_replace( '/', '\/', $rel );
			foreach ( $hays as $label => $text ) {
				if ( '' !== $text && ( false !== strpos( $text, $rel ) || false !== strpos( $text, $escaped ) ) ) {
					return $label;
				}
			}
			return '';
		}
		$needles = array( $rel, str_replace( '/', '\/', $rel ) );
		if ( false === strpos( $rel, '/' ) ) {
			// Archivos en la raíz: se busca con la barra de uploads delante para no confundir nombres cortos.
			$needles = array( 'uploads/' . $rel, 'uploads\/' . $rel );
		}
		$checks = array(
			'páginas y entradas'     => "SELECT 1 FROM {$wpdb->posts} WHERE post_type <> 'revision' AND ( post_content LIKE %s OR post_excerpt LIKE %s ) LIMIT 1",
			'campos personalizados'  => "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_value LIKE %s OR meta_value LIKE %s LIMIT 1",
			'opciones y widgets'     => "SELECT 1 FROM {$wpdb->options} WHERE option_value LIKE %s OR option_value LIKE %s LIMIT 1",
			'categorías y etiquetas' => "SELECT 1 FROM {$wpdb->termmeta} WHERE meta_value LIKE %s OR meta_value LIKE %s LIMIT 1",
		);
		foreach ( $checks as $label => $sql ) {
			$found = $wpdb->get_var( $wpdb->prepare( $sql, '%' . $wpdb->esc_like( $needles[0] ) . '%', '%' . $wpdb->esc_like( $needles[1] ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $found ) {
				return $label;
			}
		}
		$found = $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->term_taxonomy} WHERE description LIKE %s LIMIT 1",
			'%' . $wpdb->esc_like( $needles[0] ) . '%'
		) );
		if ( $found ) {
			return 'categorías y etiquetas';
		}
		$hay = self::haystack();
		if ( '' !== $hay && ( false !== strpos( $hay, $needles[0] ) || false !== strpos( $hay, $needles[1] ) ) ) {
			return 'constructor o CSS';
		}
		$theme = self::theme_haystack();
		if ( '' !== $theme && ( false !== strpos( $theme, $needles[0] ) || false !== strpos( $theme, $needles[1] ) ) ) {
			return 'archivos del tema';
		}
		return '';
	}

	/**
	 * Revisa una carpeta. @return array [ 'unused' => [...], 'used' => [...] ] con rel, size y where.
	 */
	public static function scan( $dir ) {
		$base  = self::base();
		$path  = '' === $dir ? $base : $base . '/' . $dir;
		$out   = array( 'unused' => array(), 'used' => array() );
		$known = self::known_files( $dir );
		$hays  = self::dir_haystacks( $dir );
		$now   = time();

		foreach ( (array) scandir( $path ) as $file ) {
			if ( ! $file || '.' === $file[0] || in_array( $file, self::IGNORE, true ) ) {
				continue;
			}
			$abs = $path . '/' . $file;
			if ( ! is_file( $abs ) || isset( $known[ $file ] ) || self::is_alt_format( $file, $known ) ) {
				continue;
			}
			if ( $now - (int) filemtime( $abs ) < self::MIN_AGE ) {
				continue;
			}
			$rel   = '' === $dir ? $file : $dir . '/' . $file;
			$where = self::where_used( $rel, $hays );
			$row   = array( 'rel' => $rel, 'size' => (int) filesize( $abs ), 'where' => $where );
			$out[ $where ? 'used' : 'unused' ][] = $row;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Cuarentena                                                          */
	/* ------------------------------------------------------------------ */

	public static function quarantined() {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	private static function protect_quarantine() {
		$q = self::quarantine_base();
		wp_mkdir_p( $q );
		if ( ! file_exists( $q . '/index.php' ) ) {
			file_put_contents( $q . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $q . '/.htaccess' ) ) {
			file_put_contents( $q . '/.htaccess', "Options -Indexes\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Mueve archivos a cuarentena, comprobando de nuevo cada uno.
	 *
	 * @return array [ 'moved' => [rel...], 'skipped' => [ rel => motivo ] ]
	 */
	public static function move( array $rels ) {
		$base   = self::base();
		$qbase  = self::quarantine_base();
		$list   = self::quarantined();
		$result = array( 'moved' => array(), 'skipped' => array() );
		$known  = array();
		$hays   = array();

		self::protect_quarantine();

		foreach ( array_unique( $rels ) as $rel ) {
			$src = self::resolve( $rel, $base );
			if ( ! $src || ! is_file( $src ) ) {
				$result['skipped'][ $rel ] = 'no existe o la ruta no es válida';
				continue;
			}
			$dir  = false === strpos( $rel, '/' ) ? '' : dirname( $rel );
			$file = basename( $rel );
			if ( ! isset( $known[ $dir ] ) ) {
				$known[ $dir ] = self::known_files( $dir );
				$hays[ $dir ]  = self::dir_haystacks( $dir );
			}
			if ( isset( $known[ $dir ][ $file ] ) || self::is_alt_format( $file, $known[ $dir ] ) ) {
				$result['skipped'][ $rel ] = 'pertenece a la biblioteca';
				continue;
			}
			$where = self::where_used( $rel, $hays[ $dir ] );
			if ( $where ) {
				$result['skipped'][ $rel ] = 'se usa en ' . $where;
				continue;
			}
			$dest = $qbase . '/' . $rel;
			wp_mkdir_p( dirname( $dest ) );
			if ( file_exists( $dest ) || ! @rename( $src, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$result['skipped'][ $rel ] = 'no se pudo mover';
				continue;
			}
			$list[ $rel ] = array(
				'size' => (int) filesize( $dest ),
				'date' => current_time( 'mysql' ),
				'user' => get_current_user_id(),
			);
			$result['moved'][] = $rel;
		}
		update_option( self::OPTION, $list, false );
		return $result;
	}

	/**
	 * Devuelve archivos de la cuarentena a su lugar. Sin lista, restaura todos.
	 */
	public static function restore( array $rels = array() ) {
		$base   = self::base();
		$qbase  = self::quarantine_base();
		$list   = self::quarantined();
		$rels   = $rels ? $rels : array_keys( $list );
		$result = array( 'restored' => array(), 'skipped' => array() );

		foreach ( $rels as $rel ) {
			if ( ! isset( $list[ $rel ] ) ) {
				continue;
			}
			$src  = self::resolve( $rel, $qbase );
			$dest = self::resolve( $rel, $base );
			if ( ! $src || ! $dest || ! is_file( $src ) ) {
				unset( $list[ $rel ] );
				$result['skipped'][ $rel ] = 'ya no está en la cuarentena';
				continue;
			}
			if ( file_exists( $dest ) ) {
				$result['skipped'][ $rel ] = 'ya existe un archivo con ese nombre';
				continue;
			}
			wp_mkdir_p( dirname( $dest ) );
			if ( @rename( $src, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				unset( $list[ $rel ] );
				$result['restored'][] = $rel;
			} else {
				$result['skipped'][ $rel ] = 'no se pudo mover';
			}
		}
		update_option( self::OPTION, $list, false );
		return $result;
	}

	/**
	 * Borra para siempre todo lo que está en cuarentena.
	 */
	public static function purge() {
		$qbase   = self::quarantine_base();
		$deleted = 0;
		foreach ( array_keys( self::quarantined() ) as $rel ) {
			$file = self::resolve( $rel, $qbase );
			if ( $file && is_file( $file ) ) {
				wp_delete_file( $file );
				$deleted++;
			}
		}
		update_option( self::OPTION, array(), false );
		return $deleted;
	}

	/* ------------------------------------------------------------------ */
	/* AJAX (solo administradores)                                         */
	/* ------------------------------------------------------------------ */

	private static function check() {
		check_ajax_referer( 'ditms', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Solo un administrador puede revisar los archivos sin uso.' ), 403 );
		}
	}

	private static function posted_list() {
		$raw = isset( $_POST['files'] ) ? wp_unslash( $_POST['files'] ) : '[]'; // phpcs:ignore WordPress.Security
		$list = json_decode( (string) $raw, true );
		return is_array( $list ) ? array_values( array_filter( array_map( 'strval', $list ) ) ) : array();
	}

	public static function ajax_dirs() {
		self::check();
		wp_send_json_success( array( 'dirs' => self::dirs() ) );
	}

	public static function ajax_scan() {
		self::check();
		$dir = isset( $_POST['dir'] ) ? sanitize_text_field( wp_unslash( $_POST['dir'] ) ) : '';
		if ( '' !== $dir && ! preg_match( '#^\d{4}/\d{2}$#', $dir ) ) {
			wp_send_json_error( array( 'message' => 'Carpeta no válida.' ) );
		}
		wp_send_json_success( self::scan( $dir ) );
	}

	public static function ajax_move() {
		self::check();
		wp_send_json_success( self::move( self::posted_list() ) );
	}

	public static function ajax_restore() {
		self::check();
		wp_send_json_success( self::restore( self::posted_list() ) );
	}

	public static function ajax_purge() {
		self::check();
		if ( empty( $_POST['confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_send_json_error( array( 'message' => 'Falta la confirmación.' ) );
		}
		wp_send_json_success( array( 'deleted' => self::purge() ) );
	}

	/* ------------------------------------------------------------------ */
	/* Página                                                              */
	/* ------------------------------------------------------------------ */

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$q     = self::quarantined();
		$total = array_sum( wp_list_pluck( $q, 'size' ) );
		?>
		<div class="wrap ditms-page ditms-orphans">
			<header class="ditms-page__head">
				<h1>Archivos sin uso</h1>
				<p>Busca en uploads los archivos que no pertenecen a ninguna imagen de la biblioteca, como las copias que dejaba Rename Media Files. Los que nada usa se pueden mover a cuarentena y restaurar si hace falta.</p>
			</header>

			<section class="ditms-card">
				<h2>Analizar</h2>
				<small class="ditms-note">Revisa una carpeta de mes a la vez. No mueve nada hasta que lo pidas. Los formatos WebP y AVIF de imágenes existentes y los archivos subidos en la última hora no se tocan.</small>
				<div class="ditms-run">
					<button type="button" class="ditms__btn ditms__btn--primary" id="ditms-orphans-scan">Analizar archivos</button>
					<span class="ditms-run__text" id="ditms-orphans-status" aria-live="polite"></span>
				</div>
				<div class="ditms-progress" hidden><span></span></div>
			</section>

			<section class="ditms-card" id="ditms-orphans-results" hidden>
				<h2>Sin uso <span class="ditms-count" id="ditms-orphans-unused-count">0</span></h2>
				<small class="ditms-note">No pertenecen a la biblioteca y no aparecen en páginas, campos personalizados, opciones, widgets, categorías, constructores, CSS ni archivos del tema. Antes de mover cada uno se vuelve a comprobar.</small>
				<div class="ditms-table-wrap"><table class="ditms-usage ditms-files" id="ditms-orphans-unused"><thead><tr><th scope="col"><input type="checkbox" id="ditms-orphans-all" checked aria-label="Seleccionar todos"></th><th scope="col">Archivo</th><th scope="col">Tamaño</th></tr></thead><tbody></tbody></table></div>
				<div class="ditms-run">
					<button type="button" class="ditms__btn ditms__btn--primary" id="ditms-orphans-move">Mover a cuarentena</button>
					<span class="ditms-run__text" id="ditms-orphans-move-status" aria-live="polite"></span>
				</div>

				<h2>En uso: no tocar <span class="ditms-count" id="ditms-orphans-used-count">0</span></h2>
				<small class="ditms-note">No pertenecen a la biblioteca, pero algo del sitio todavía los usa. Se quedan donde están.</small>
				<div class="ditms-table-wrap"><table class="ditms-usage ditms-files" id="ditms-orphans-used"><thead><tr><th scope="col">Archivo</th><th scope="col">Dónde se usa</th></tr></thead><tbody></tbody></table></div>
			</section>

			<section class="ditms-card" id="ditms-quarantine">
				<h2>Cuarentena <span class="ditms-count" id="ditms-q-count"><?php echo (int) count( $q ); ?></span></h2>
				<small class="ditms-note">Carpeta <code>uploads/<?php echo esc_html( self::DIR ); ?></code>. Revisa el sitio unos días y, si todo se ve bien, vacíala. Al desinstalar el plugin estos archivos no se borran.</small>
				<p class="ditms-q-size" id="ditms-q-size" data-bytes="<?php echo (int) $total; ?>"></p>
				<div class="ditms-run">
					<button type="button" class="ditms__btn" id="ditms-q-restore" <?php disabled( ! $q ); ?>>Restaurar todo</button>
					<button type="button" class="ditms__btn ditms__btn--ghost" id="ditms-q-purge" <?php disabled( ! $q ); ?>>Vaciar cuarentena</button>
					<button type="button" class="ditms__btn ditms__btn--danger" id="ditms-q-purge-confirm" hidden>Sí, borrar para siempre</button>
					<span class="ditms-run__text" id="ditms-q-status" aria-live="polite"></span>
				</div>
			</section>
		</div>
		<?php
	}
}
