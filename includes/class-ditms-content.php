<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lleva el texto alternativo de la biblioteca a las páginas ya publicadas.
 *
 * El editor de bloques y el clásico copian el alt dentro del HTML de la página al insertar la imagen,
 * así que cambiarlo después en la biblioteca no cambia lo publicado. Elementor y BeTheme no lo necesitan:
 * leen el alt de la biblioteca al mostrar la página.
 *
 * Solo toca imágenes con la clase wp-image-{ID} (bloque Imagen, galerías y editor clásico) y solo cuando su alt
 * está vacío o es igual al anterior de la biblioteca: un alt escrito a mano en la página se respeta.
 */
class DITMS_Content {

	const CHUNK = 100;

	/** Alt anterior de cada adjunto, capturado antes de que WordPress lo cambie. */
	private static $previous = array();

	/** Páginas actualizadas en la última sincronización (para el mensaje en pantalla). */
	public static $last_count = 0;

	public static function init() {
		add_action( 'update_post_meta', array( __CLASS__, 'before_update' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'after_update' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'after_update' ), 10, 4 );
	}

	public static function before_update( $meta_id, $post_id, $meta_key ) {
		if ( '_wp_attachment_image_alt' === $meta_key ) {
			self::$previous[ $post_id ] = (string) get_post_meta( $post_id, '_wp_attachment_image_alt', true );
		}
	}

	public static function after_update( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( '_wp_attachment_image_alt' !== $meta_key || ! ditms_setting( 'sync_alt' ) ) {
			return;
		}
		$old = isset( self::$previous[ $post_id ] ) ? self::$previous[ $post_id ] : '';
		unset( self::$previous[ $post_id ] );
		$new = (string) $meta_value;
		if ( $old !== $new ) {
			self::$last_count = self::sync_alt( (int) $post_id, $old, $new );
		}
	}

	/**
	 * @return int Número de páginas actualizadas.
	 */
	public static function sync_alt( $attachment_id, $old_alt, $new_alt ) {
		global $wpdb;

		// WP_HTML_Tag_Processor existe desde WordPress 6.2 y edita atributos sin romper el HTML.
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return 0;
		}

		$class   = 'wp-image-' . (int) $attachment_id;
		$like    = '%' . $wpdb->esc_like( $class ) . '%';
		$user_id = get_current_user_id();
		$count   = 0;
		$last    = 0;

		do {
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts}
				 WHERE post_type NOT IN ( 'revision', 'attachment' ) AND post_content LIKE %s AND ID > %d
				 ORDER BY ID ASC LIMIT " . self::CHUNK,
				$like,
				$last
			) );

			foreach ( $rows as $row ) {
				$last = (int) $row->ID;

				// Con un usuario conectado, solo se tocan las páginas que ese usuario puede editar.
				if ( $user_id && ! current_user_can( 'edit_post', $row->ID ) ) {
					continue;
				}

				$html = self::replace_in_html( $row->post_content, $class, $old_alt, $new_alt );
				if ( null !== $html ) {
					$wpdb->update( $wpdb->posts, array( 'post_content' => $html ), array( 'ID' => $row->ID ) );
					clean_post_cache( $row->ID );
					$count++;
				}
			}
		} while ( count( $rows ) === self::CHUNK );

		return $count;
	}

	/**
	 * @return string|null HTML nuevo, o null si no cambió nada.
	 */
	public static function replace_in_html( $html, $class, $old_alt, $new_alt ) {
		$processor = new WP_HTML_Tag_Processor( $html );
		$changed   = false;
		$old_alt   = trim( $old_alt );

		while ( $processor->next_tag( array( 'tag_name' => 'img', 'class_name' => $class ) ) ) {
			$current = $processor->get_attribute( 'alt' );
			$current = is_string( $current ) ? trim( $current ) : '';
			if ( '' === $current || $current === $old_alt ) {
				if ( $current !== $new_alt ) {
					$processor->set_attribute( 'alt', $new_alt );
					$changed = true;
				}
			}
		}

		return $changed ? $processor->get_updated_html() : null;
	}
}
