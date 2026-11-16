<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Genera con Claude los textos SEO de un adjunto a partir de su nombre, el contexto del sitio
 * y, si está activado, la propia imagen.
 *
 * Usa la API HTTP de Anthropic con wp_remote_post: un plugin de WordPress no puede depender de Composer
 * sin arriesgar conflictos con otros plugins, y la petición es una sola llamada.
 */
class DITMS_AI {

	const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	/** Formatos de imagen que acepta la API. */
	const IMAGE_MIMES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/** Límite de la API por imagen (5 MB) con margen. */
	const MAX_IMAGE_BYTES = 4500000;

	/** Modelos que aceptan fallbacks del lado del servidor ante un rechazo. */
	const FALLBACK_MODELS = array( 'claude-opus-5-5', 'claude-sonnet-5-5' );

	/**
	 * @param int    $id   ID del adjunto.
	 * @param string $hint Nombre o palabras clave que escribió el usuario (opcional; si no, se usa el nombre del archivo).
	 * @return array|WP_Error Claves: filename, alt, title, caption, description.
	 */
	public static function generate( $id, $hint = '' ) {
		$key = ditms_api_key();
		if ( ! $key ) {
			return new WP_Error( 'ditms_no_key', 'Falta la clave de la API de Anthropic. Configúrala en Ajustes > Media SEO.' );
		}

		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'ditms_not_found', 'El adjunto no existe.' );
		}

		$settings = ditms_settings();
		$file     = get_attached_file( $id );
		$filename = $file ? basename( $file ) : '';
		$meta     = wp_get_attachment_metadata( $id );
		if ( is_array( $meta ) && ! empty( $meta['original_image'] ) ) {
			$filename = $meta['original_image'];
		}

		$content = array();
		$image   = ! empty( $settings['use_image'] ) ? self::image_block( $id ) : null;
		if ( $image ) {
			$content[] = $image;
		}
		$content[] = array(
			'type' => 'text',
			'text' => self::user_prompt( $post, $filename, $hint, (bool) $image ),
		);

		$model = array_key_exists( $settings['model'], ditms_models() ) ? $settings['model'] : 'claude-opus-5-5';

		$body = array(
			'model'         => $model,
			'max_tokens'    => 8000,
			'system'        => self::system_prompt( $settings ),
			'messages'      => array(
				array(
					'role'    => 'user',
					'content' => $content,
				),
			),
			'output_config' => array(
				// Tarea corta y bien definida: esfuerzo bajo basta y reduce costo y tiempo.
				'effort' => 'low',
				'format' => array(
					'type'   => 'json_schema',
					'schema' => self::schema(),
				),
			),
		);

		$headers = array(
			'content-type'      => 'application/json',
			'x-api-key'         => $key,
			'anthropic-version' => '2023-06-01',
		);

		// Si el filtro de seguridad rechaza la petición, la API la reintenta con otro modelo en la misma llamada.
		if ( in_array( $model, self::FALLBACK_MODELS, true ) ) {
			$body['fallbacks']         = 'default';
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
		}

		$response = wp_remote_post( self::ENDPOINT, array(
			'timeout' => 90,
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ditms_http', 'No hubo conexión con la API: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return new WP_Error( self::error_code( $code ), self::error_message( $code, $data ) );
		}

		if ( isset( $data['stop_reason'] ) && 'refusal' === $data['stop_reason'] ) {
			return new WP_Error( 'ditms_refusal', 'El modelo no quiso describir esta imagen. Escribe los textos a mano.' );
		}
		if ( isset( $data['stop_reason'] ) && 'max_tokens' === $data['stop_reason'] ) {
			return new WP_Error( 'ditms_truncated', 'La respuesta quedó incompleta. Intenta de nuevo.' );
		}

		$text = '';
		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
				$text .= $block['text'];
			}
		}

		$out = json_decode( $text, true );
		if ( ! is_array( $out ) ) {
			return new WP_Error( 'ditms_parse', 'La respuesta de la IA no se pudo leer. Intenta de nuevo.' );
		}

		return self::clean_output( $out );
	}

	/**
	 * Guarda en el adjunto los campos generados y deja el estado anterior en el historial para poder deshacer.
	 * Respeta los campos activados y, si no se pide sobrescribir, solo llena los vacíos.
	 *
	 * @param string|null $group Grupo de deshacer (para unir textos y renombre de una misma acción).
	 * @return string[] Campos que se guardaron.
	 */
	public static function save_fields( $id, array $data, $overwrite = null, $group = null ) {
		$settings  = ditms_settings();
		$enabled   = (array) $settings['fields'];
		$overwrite = null === $overwrite ? ! empty( $settings['overwrite'] ) : (bool) $overwrite;
		$post      = get_post( $id );
		$before    = array();
		$update    = array( 'ID' => $id );

		if ( in_array( 'alt', $enabled, true ) && '' !== $data['alt'] ) {
			$current = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
			if ( ( $overwrite || '' === trim( $current ) ) && $current !== $data['alt'] ) {
				update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $data['alt'] ) );
				$before['alt'] = $current;
			}
		}

		// El título por defecto de WordPress es el nombre del archivo: se considera "vacío".
		$title_is_default = '' === trim( $post->post_title ) || ditms_readable_name( $post->post_title ) === ditms_readable_name( basename( (string) get_attached_file( $id ) ) ) || ditms_is_generic_name( $post->post_title );

		$map = array(
			'title'       => array( 'post_title', $title_is_default ),
			'caption'     => array( 'post_excerpt', '' === trim( $post->post_excerpt ) ),
			'description' => array( 'post_content', '' === trim( $post->post_content ) ),
		);
		foreach ( $map as $field => $conf ) {
			list( $column, $is_empty ) = $conf;
			if ( in_array( $field, $enabled, true ) && '' !== $data[ $field ] && ( $overwrite || $is_empty ) && $post->$column !== $data[ $field ] ) {
				$update[ $column ] = $data[ $field ];
				$before[ $field ]  = $post->$column;
			}
		}

		if ( count( $update ) > 1 ) {
			wp_update_post( wp_slash( $update ) );
		}
		if ( $before ) {
			DITMS_History::add_texts( $id, $before, $group );
			update_post_meta( $id, '_ditms_generated', current_time( 'mysql' ) );
		}
		return array_keys( $before );
	}

	private static function schema() {
		$string = array( 'type' => 'string' );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'filename'    => $string,
				'alt'         => $string,
				'title'       => $string,
				'caption'     => $string,
				'description' => $string,
			),
			'required'             => array( 'filename', 'alt', 'title', 'caption', 'description' ),
			'additionalProperties' => false,
		);
	}

	private static function system_prompt( array $s ) {
		$languages = ditms_languages();
		$language  = isset( $languages[ $s['language'] ] ) ? $languages[ $s['language'] ] : 'Español (Latinoamérica)';

		$context = array();
		$site    = get_bloginfo( 'name' );
		if ( $s['business'] ) {
			$context[] = 'Negocio: ' . $s['business'];
		} elseif ( $site ) {
			$context[] = 'Sitio web: ' . $site;
		}
		if ( $s['sector'] ) {
			$context[] = 'Sector: ' . $s['sector'];
		}
		if ( $s['location'] ) {
			$context[] = 'Ciudad o zona que atiende: ' . $s['location'];
		}
		if ( $s['keywords'] ) {
			$context[] = 'Palabras clave prioritarias del sitio (úsalas solo si corresponden a la imagen): ' . $s['keywords'];
		}

		$prompt = "Redactas los textos SEO de imágenes para la biblioteca de medios de un sitio web en WordPress. Escribe todo en {$language}.\n\n";

		if ( $context ) {
			$prompt .= "Contexto del sitio:\n- " . implode( "\n- ", $context ) . "\n\n";
		}

		$prompt .= "Campos que debes entregar:\n"
			. "- filename: nombre de archivo descriptivo, de 3 a 6 palabras, en minúsculas, separadas por guiones, sin tildes, sin ñ, sin extensión y sin palabras vacías (de, la, el, y, para, the, of). Pon primero la palabra clave principal. Ej.: cocina-integral-blanca-isla-bogota.\n"
			. "- alt: texto alternativo para lectores de pantalla y Google Imágenes. Una sola frase completa que describa lo que se ve, con la palabra clave principal una vez y de forma natural. Idealmente menos de 125 caracteres; si no cabe, prioriza que la frase quede completa. No empieces con \"imagen de\", \"foto de\" ni similares.\n"
			. "- title: título corto de 3 a 8 palabras, en formato de frase (solo la primera letra en mayúscula, salvo nombres propios)." . ( trim( (string) $s['title_brand'] ) ? ' No incluyas la marca ni el nombre del negocio: se agregan después automáticamente.' : '' ) . "\n"
			. "- caption: leyenda visible bajo la imagen. Una frase de máximo 20 palabras que aporte algo al lector, no que repita el alt.\n"
			. "- description: descripción de 1 a 3 frases, máximo 300 caracteres, con detalles útiles (material, uso, lugar, servicio relacionado) y palabras clave secundarias si encajan.\n\n"
			. "Reglas:\n"
			. "- El nombre del archivo indica la intención de búsqueda: respeta esa palabra clave aunque la reescribas mejor.\n"
			. "- Si tienes la imagen y contradice el nombre, describe lo que de verdad muestra la imagen.\n"
			. "- Si no tienes la imagen, no inventes detalles visuales (colores, personas, objetos) que no se deduzcan del nombre o del contexto.\n"
			. "- Nada de relleno de palabras clave: cada campo debe leerse natural.\n"
			. "- Menciona la ciudad o zona solo cuando sea relevante para la imagen, como máximo en dos campos.\n"
			. "- No uses la raya larga (em dash). No uses comillas, emojis ni signos de exclamación.\n"
			. "- No prometas resultados ni uses superlativos sin respaldo (\"el mejor\", \"número 1\", \"garantizado\").\n";

		if ( $s['instructions'] ) {
			$prompt .= "\nInstrucciones adicionales del sitio:\n" . $s['instructions'] . "\n";
		}

		return $prompt;
	}

	private static function user_prompt( $post, $filename, $hint, $has_image ) {
		$lines = array();
		$lines[] = 'Nombre actual del archivo: ' . $filename;
		$lines[] = 'Lectura del nombre: ' . ditms_readable_name( $filename );
		if ( ditms_is_generic_name( $filename ) ) {
			$lines[] = 'Ese nombre es genérico (de cámara, celular o captura) y no describe nada: básate en la imagen y en el contexto.';
		}
		if ( $hint ) {
			$lines[] = 'Palabras clave o nombre sugerido por el editor (tienen prioridad): ' . sanitize_text_field( $hint );
		}
		if ( $post->post_parent ) {
			$parent = get_post( $post->post_parent );
			if ( $parent ) {
				$lines[] = 'La imagen se subió en la página o entrada: "' . wp_strip_all_tags( $parent->post_title ) . '"';
			}
		}
		$lines[] = $has_image ? 'Arriba está la imagen.' : 'No se adjunta la imagen: trabaja solo con el nombre y el contexto.';
		return implode( "\n", $lines );
	}

	/**
	 * Bloque de imagen en base64. Usa un tamaño intermedio para gastar menos tokens.
	 */
	private static function image_block( $id ) {
		if ( ! wp_attachment_is_image( $id ) ) {
			return null;
		}
		$path = '';
		$mime = '';
		foreach ( array( 'large', 'medium_large', 'full' ) as $size ) {
			$candidate = self::size_path( $id, $size );
			if ( $candidate && file_exists( $candidate ) && filesize( $candidate ) <= self::MAX_IMAGE_BYTES ) {
				$type = wp_check_filetype( $candidate );
				if ( in_array( $type['type'], self::IMAGE_MIMES, true ) ) {
					$path = $candidate;
					$mime = $type['type'];
					break;
				}
			}
		}
		if ( ! $path ) {
			return null;
		}
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $bytes ) {
			return null;
		}
		return array(
			'type'   => 'image',
			'source' => array(
				'type'       => 'base64',
				'media_type' => $mime,
				'data'       => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			),
		);
	}

	private static function size_path( $id, $size ) {
		$file = get_attached_file( $id );
		if ( 'full' === $size ) {
			return $file;
		}
		$meta = wp_get_attachment_metadata( $id );
		if ( empty( $meta['sizes'][ $size ]['file'] ) ) {
			return '';
		}
		return dirname( $file ) . '/' . $meta['sizes'][ $size ]['file'];
	}

	private static function clean_output( array $out ) {
		$clean = array();
		foreach ( array( 'filename', 'alt', 'title', 'caption', 'description' ) as $k ) {
			$v = isset( $out[ $k ] ) ? (string) $out[ $k ] : '';
			// Red de seguridad para las reglas de estilo, por si el modelo se salta alguna.
			$v = str_replace( array( "\u{2014}", "\u{2013}" ), array( ', ', '-' ), $v );
			$v = trim( sanitize_text_field( $v ), " \t\n\r\0\x0B\"'" );
			$clean[ $k ] = $v;
		}
		$clean['filename'] = ditms_slugify( ditms_strip_extension( $clean['filename'] ) );
		$clean['alt']   = self::first_sentences( $clean['alt'], 125 );
		$clean['title'] = self::brand_title( $clean['title'] );
		return $clean;
	}

	/**
	 * Si el alt es largo, conserva solo frases completas hasta el largo sugerido. Nunca corta a mitad de frase:
	 * si la primera frase ya es larga se deja entera (125 caracteres es una pauta, no un límite de Google).
	 */
	private static function first_sentences( $text, $max ) {
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$sentences = preg_split( '/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$out       = array_shift( $sentences );
		foreach ( $sentences as $sentence ) {
			if ( mb_strlen( $out . ' ' . $sentence ) > $max ) {
				break;
			}
			$out .= ' ' . $sentence;
		}
		return $out;
	}

	/**
	 * Agrega la cadena de marca configurada al título, si no la tiene ya. Ej.: "Auditoría SEO técnica - Leonardo Báez".
	 */
	public static function brand_title( $title ) {
		$brand = trim( (string) ditms_setting( 'title_brand' ) );
		if ( '' === $brand || '' === $title || false !== mb_stripos( $title, $brand ) ) {
			return $title;
		}
		$separators = ditms_title_separators();
		$key        = (string) ditms_setting( 'title_separator' );
		$sep        = isset( $separators[ $key ] ) ? $separators[ $key ] : ' - ';
		return rtrim( $title, ' .,;:-|' ) . $sep . $brand;
	}

	private static function error_code( $code ) {
		if ( in_array( $code, array( 401, 403 ), true ) ) {
			return 'ditms_auth';
		}
		if ( in_array( $code, array( 429, 503, 529 ), true ) ) {
			return 'ditms_rate';
		}
		return 'ditms_api';
	}

	private static function error_message( $code, $data ) {
		$detail = isset( $data['error']['message'] ) ? $data['error']['message'] : '';
		switch ( $code ) {
			case 401:
				return 'La clave de la API no es válida. Revísala en Ajustes > Media SEO.';
			case 403:
				return 'La clave no tiene permiso para usar este modelo.';
			case 429:
				return 'Se alcanzó el límite de uso de la API. Espera un momento y vuelve a intentar.';
			case 529:
			case 503:
				return 'La API está saturada en este momento. Intenta de nuevo en unos minutos.';
			default:
				return 'Error de la API (' . $code . ')' . ( $detail ? ': ' . $detail : '.' );
		}
	}
}
