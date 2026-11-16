<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirección 301 de las URLs viejas de un archivo renombrado a las nuevas.
 * Conserva el valor de los enlaces externos y de Google Imágenes que apuntaban al nombre anterior.
 *
 * Funciona cuando el servidor pasa a WordPress las peticiones de archivos que no existen
 * (lo normal en Apache con el .htaccess de WordPress y en la mayoría de configuraciones de Nginx).
 */
class DITMS_Redirects {

	const MAX_ENTRIES = 5000;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
		add_action( 'delete_attachment', array( __CLASS__, 'forget' ) );
	}

	/**
	 * Registra las rutas viejas (relativas a uploads) con el adjunto y el tamaño al que corresponden.
	 */
	public static function add( $id, array $old_urls, array $new_urls ) {
		$map = get_option( DITMS_REDIRECTS, array() );
		$map = is_array( $map ) ? $map : array();

		foreach ( $old_urls as $key => $url ) {
			$rel = self::relative( $url );
			if ( $rel ) {
				unset( $map[ $rel ] );
				$map[ $rel ] = array( (int) $id, $key );
			}
		}
		// Si un archivo vuelve a tener un nombre que ya usó, esa ruta deja de redirigir.
		foreach ( $new_urls as $url ) {
			$rel = self::relative( $url );
			if ( $rel ) {
				unset( $map[ $rel ] );
			}
		}

		if ( count( $map ) > self::MAX_ENTRIES ) {
			$map = array_slice( $map, -self::MAX_ENTRIES, null, true );
		}
		update_option( DITMS_REDIRECTS, $map, false );
	}

	public static function forget( $id ) {
		$map = get_option( DITMS_REDIRECTS, array() );
		if ( ! is_array( $map ) || ! $map ) {
			return;
		}
		$before = count( $map );
		$map    = array_filter( $map, function ( $entry ) use ( $id ) {
			return (int) $entry[0] !== (int) $id;
		} );
		if ( count( $map ) !== $before ) {
			update_option( DITMS_REDIRECTS, $map, false );
		}
	}

	public static function maybe_redirect() {
		if ( ! is_404() || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$rel  = $path ? self::relative( rawurldecode( $path ) ) : '';
		if ( ! $rel ) {
			return;
		}

		// 1. Mapa de redirecciones. 2. Respaldo: historial de renombres del plugin.
		$found = self::from_map( $rel );
		$via   = 'map';
		if ( ! $found ) {
			$found = self::from_history( $rel );
			$via   = 'history';
		}

		if ( ! $found ) {
			return;
		}
		// Diagnóstico: cómo se resolvió la redirección (lista o historial). Solo en redirecciones, no en cada 404.
		if ( ! headers_sent() ) {
			header( 'X-Media-SEO: ' . $via );
		}

		$target = self::target_url( $found[0], $found[1] );
		if ( $target && self::relative( $target ) !== $rel ) {
			// wp_redirect y no wp_safe_redirect: la URL sale de WordPress y puede estar en un CDN con otro dominio.
			wp_redirect( $target, 301, 'Media SEO by Designs and IT' ); // phpcs:ignore WordPress.Security.SafeRedirect
			exit;
		}
	}

	/**
	 * @return array|null [ id, clave de tamaño ]
	 */
	private static function from_map( $rel ) {
		$map = get_option( DITMS_REDIRECTS, array() );
		if ( empty( $map[ $rel ] ) || ! get_post( $map[ $rel ][0] ) ) {
			return null;
		}
		return $map[ $rel ];
	}

	/**
	 * Busca el archivo en el historial de renombres. Reconoce también las miniaturas (-300x200) y la copia -scaled.
	 *
	 * @return array|null [ id, clave de tamaño ]
	 */
	private static function from_history( $rel ) {
		global $wpdb;

		$dir  = dirname( $rel );
		$file = basename( $rel );
		$ext  = pathinfo( $file, PATHINFO_EXTENSION );
		$stem = pathinfo( $file, PATHINFO_FILENAME );
		$size = null;

		if ( preg_match( '/^(.+)-(\d+)x(\d+)$/', $stem, $m ) ) {
			$stem = $m[1];
			$size = array( (int) $m[2], (int) $m[3] );
		} elseif ( preg_match( '/^(.+)-(scaled|rotated)$/', $stem, $m ) ) {
			$stem = $m[1];
		}
		$original = $stem . '.' . $ext;

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ditms_history' AND meta_value LIKE %s ORDER BY post_id DESC LIMIT 10",
			'%' . $wpdb->esc_like( '"' . $original . '"' ) . '%'
		) );

		foreach ( $ids as $id ) {
			$attached = get_post_meta( $id, '_wp_attached_file', true );
			// Debe ser la misma carpeta (2024/04): evita confundir archivos con el mismo nombre en meses distintos.
			if ( ! $attached || dirname( $attached ) !== $dir ) {
				continue;
			}
			$from_names = wp_list_pluck( DITMS_History::all( $id ), 'from' );
			if ( ! in_array( $original, $from_names, true ) ) {
				continue;
			}
			return array( (int) $id, $size ? self::size_key( $id, $size ) : 'full' );
		}
		return null;
	}

	/**
	 * Tamaño registrado con esas dimensiones, o la imagen completa si ya no existe.
	 */
	private static function size_key( $id, array $dims ) {
		$meta = wp_get_attachment_metadata( $id );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $name => $data ) {
				if ( (int) $data['width'] === $dims[0] && (int) $data['height'] === $dims[1] ) {
					return 'size:' . $name;
				}
			}
		}
		return 'full';
	}

	private static function target_url( $id, $key ) {
		$target = '';
		if ( 'original' === $key ) {
			$target = wp_get_original_image_url( $id );
		} elseif ( 0 === strpos( $key, 'size:' ) ) {
			$src    = wp_get_attachment_image_src( $id, substr( $key, 5 ) );
			$target = $src ? $src[0] : '';
		}
		return $target ? $target : wp_get_attachment_url( $id );
	}

	/**
	 * Últimas redirecciones guardadas, para la página de ajustes.
	 *
	 * @return array Lista de [ ruta vieja, URL actual ].
	 */
	public static function recent( $limit = 20 ) {
		$map = get_option( DITMS_REDIRECTS, array() );
		$out = array();
		if ( ! is_array( $map ) ) {
			return $out;
		}
		foreach ( array_slice( array_reverse( $map, true ), 0, $limit, true ) as $rel => $entry ) {
			$out[] = array( $rel, get_post( $entry[0] ) ? self::target_url( $entry[0], $entry[1] ) : '' );
		}
		return $out;
	}

	public static function count() {
		$map = get_option( DITMS_REDIRECTS, array() );
		return is_array( $map ) ? count( $map ) : 0;
	}

	/**
	 * Ruta relativa a la carpeta de uploads. Ej.: 2024/05/cocina-integral-300x200.jpg
	 */
	private static function relative( $url ) {
		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( wp_make_link_relative( $uploads['baseurl'] ) );
		$path    = wp_make_link_relative( $url );
		if ( 0 !== strpos( $path, $base ) ) {
			return '';
		}
		return substr( $path, strlen( $base ) );
	}
}
