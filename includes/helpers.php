<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Valores por defecto de los ajustes.
 */
function ditms_default_settings() {
	return array(
		'api_key'          => '',
		'model'            => 'claude-opus-5-5',
		'language'         => 'es-CO',
		'business'         => '',
		'sector'           => '',
		'location'         => '',
		'keywords'         => '',
		'instructions'     => '',
		'use_image'        => 1,
		// La descripción no va por defecto: solo se ve en la página de adjunto (desactivada desde WordPress 6.4).
		'fields'           => array( 'alt', 'title', 'caption' ),
		'title_brand'      => '',
		'title_separator'  => 'dash',
		'overwrite'        => 0,
		'auto_on_upload'   => 0,
		'normalize_upload' => 1,
		'replace_refs'     => 1,
		'update_slug'      => 1,
		'redirects'        => 1,
		'sync_alt'         => 1,
	);
}

function ditms_settings() {
	$saved = get_option( DITMS_OPTION, array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), ditms_default_settings() );
}

function ditms_setting( $key ) {
	$s = ditms_settings();
	return isset( $s[ $key ] ) ? $s[ $key ] : null;
}

/**
 * Clave de la API. Primero la constante de wp-config.php; si no existe, la guardada en ajustes.
 */
function ditms_api_key() {
	if ( defined( 'DITMS_ANTHROPIC_API_KEY' ) && DITMS_ANTHROPIC_API_KEY ) {
		return DITMS_ANTHROPIC_API_KEY;
	}
	return (string) ditms_setting( 'api_key' );
}

/**
 * Modelos disponibles en el selector.
 */
/**
 * Precio de la API por millón de tokens (USD), para estimar el gasto. Fuente: tabla de precios de Anthropic, nov-2026.
 */
function ditms_model_prices() {
	return array(
		'claude-opus-5-5'   => array( 'in' => 4.00, 'out' => 20.00 ),
		'claude-sonnet-5-5' => array( 'in' => 2.00, 'out' => 10.00 ),
		'claude-haiku-5-5'  => array( 'in' => 0.10, 'out' => 0.50 ),
	);
}

function ditms_models() {
	return array(
		'claude-opus-5-5'   => 'Claude Opus 5.5 (mejor calidad)',
		'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (equilibrado)',
		'claude-haiku-5-5'  => 'Claude Haiku 5.5 (el más económico)',
	);
}

function ditms_title_separators() {
	return array(
		'dash' => ' - ',
		'pipe' => ' | ',
		'dot'  => ' · ',
	);
}

function ditms_languages() {
	return array(
		'es-CO' => 'Español (Latinoamérica)',
		'es-ES' => 'Español (España)',
		'en-US' => 'English (US)',
	);
}

/**
 * Convierte cualquier texto en un nombre de archivo limpio para SEO:
 * minúsculas, sin tildes ni ñ, palabras separadas por guiones, sin extensión.
 */
function ditms_slugify( $text ) {
	$text = wp_strip_all_tags( (string) $text );
	$text = remove_accents( $text );
	$text = strtolower( $text );
	$text = preg_replace( '/[^a-z0-9]+/', '-', $text );
	$text = preg_replace( '/-+/', '-', $text );
	$text = trim( $text, '-' );
	// Nombres demasiado largos no aportan y algunos servidores los rechazan.
	if ( strlen( $text ) > 80 ) {
		$text = substr( $text, 0, 80 );
		$text = substr( $text, 0, strrpos( $text, '-' ) ?: 80 );
	}
	return $text;
}

/**
 * Quita la extensión si el usuario la escribió y limpia el nombre.
 */
function ditms_clean_basename( $name, $ext ) {
	$name = ditms_strip_extension( $name );
	if ( $ext ) {
		// También la extensión real del archivo, aunque no esté en la lista común (".JPG", "-jpg").
		$name = preg_replace( '/[.\-_\s]' . preg_quote( $ext, '/' ) . '$/i', '', $name );
	}
	return ditms_slugify( $name );
}

/**
 * Quita una extensión de archivo al final del nombre, escrita con punto o con guion
 * (la IA o el usuario a veces escriben "cocina-integral.jpg" o "cocina-integral-jpg").
 */
function ditms_strip_extension( $name ) {
	$name = trim( (string) $name );
	do {
		$before = $name;
		$name   = preg_replace( '/[.\-_\s](jpe?g|png|gif|webp|avif|svg|heic|tiff?|bmp|pdf)$/i', '', $name );
	} while ( $name !== $before && '' !== $name );
	return $name;
}

/**
 * Nombre "legible" a partir del archivo: sin extensión, sin -scaled ni -300x200, guiones por espacios.
 */
function ditms_readable_name( $filename ) {
	$name = pathinfo( $filename, PATHINFO_FILENAME );
	$name = preg_replace( '/-(scaled|rotated|e\d{10,})$/', '', $name );
	$name = preg_replace( '/-\d+x\d+$/', '', $name );
	// Sufijo que agrega WordPress a los duplicados (foto-1, foto-2). Solo 1 o 2 dígitos: "img-2034" es parte del nombre.
	$name = preg_replace( '/-\d{1,2}$/', '', $name );
	$name = str_replace( array( '-', '_', '.' ), ' ', $name );
	return trim( preg_replace( '/\s+/', ' ', $name ) );
}

/**
 * Detecta nombres que no dicen nada: IMG_2034, DSC0001, WhatsApp Image..., Screenshot..., solo números o hashes.
 */
function ditms_is_generic_name( $filename ) {
	$name = strtolower( ditms_readable_name( $filename ) );
	if ( '' === $name ) {
		return true;
	}
	$patterns = array(
		'/^(img|image|dsc|dscn|dcim|pxl|mvimg|photo|foto|imagen|picture|pic|p|gopr|dji)\s?\d/',
		'/^whatsapp\s(image|imagen)/',
		'/^(screenshot|captura|screen shot|pantallazo)/',
		'/^(untitled|sin titulo|unnamed|download|descarga|file|archivo)(\s\d+)?$/',
		'/^[\d\s]+$/',
		'/^[a-f0-9\s]{16,}$/',
	);
	foreach ( $patterns as $p ) {
		if ( preg_match( $p, $name ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Reemplaza cadenas dentro de cualquier valor (string, array u objeto), sin romper datos serializados.
 */
function ditms_deep_replace( $value, array $pairs, &$changed ) {
	if ( is_string( $value ) ) {
		// BeTheme (opción "builder-storage: encode") guarda el constructor como base64 de datos serializados.
		$decoded = ditms_maybe_base64_serialized( $value );
		if ( null !== $decoded ) {
			$inner = false;
			$new   = ditms_deep_replace( $decoded, $pairs, $inner );
			if ( $inner ) {
				$changed = true;
				return base64_encode( serialize( $new ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			}
			return $value;
		}
		$new = strtr( $value, $pairs );
		if ( $new !== $value ) {
			$changed = true;
		}
		return $new;
	}
	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value[ $k ] = ditms_deep_replace( $v, $pairs, $changed );
		}
		return $value;
	}
	if ( is_object( $value ) && ! ( $value instanceof __PHP_Incomplete_Class ) ) {
		foreach ( get_object_vars( $value ) as $k => $v ) {
			$value->$k = ditms_deep_replace( $v, $pairs, $changed );
		}
		return $value;
	}
	return $value;
}

/**
 * Si el texto es base64 de un arreglo serializado, devuelve el arreglo; si no, null.
 */
function ditms_maybe_base64_serialized( $value ) {
	$len = strlen( $value );
	if ( $len < 24 || 0 !== $len % 4 || ! preg_match( '/^[A-Za-z0-9+\/]+={0,2}$/', $value ) ) {
		return null;
	}
	$decoded = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	if ( false === $decoded || ! is_serialized( $decoded ) ) {
		return null;
	}
	$data = @unserialize( $decoded, array( 'allowed_classes' => false ) ); // phpcs:ignore
	return is_array( $data ) ? $data : null;
}
