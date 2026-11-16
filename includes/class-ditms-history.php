<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Historial de cambios por adjunto (renombres y textos) y botón de deshacer.
 * Los cambios hechos en una misma acción (p. ej. textos + renombre en el lote) comparten grupo y se deshacen juntos.
 */
class DITMS_History {

	const META = '_ditms_history';
	const MAX  = 20;

	public static function new_group() {
		return wp_generate_password( 8, false );
	}

	public static function add_rename( $id, $from, $to, $group = null ) {
		self::push( $id, array(
			'type' => 'rename',
			'from' => $from,
			'to'   => $to,
		), $group );
	}

	/**
	 * @param array $before Valores anteriores de los campos que cambiaron: alt, title, caption, description.
	 */
	public static function add_texts( $id, array $before, $group = null ) {
		if ( $before ) {
			self::push( $id, array(
				'type'   => 'texts',
				'before' => $before,
			), $group );
		}
	}

	private static function push( $id, array $entry, $group ) {
		$history   = self::all( $id );
		$history[] = array_merge( $entry, array(
			'group' => $group ? $group : self::new_group(),
			'date'  => current_time( 'mysql' ),
			'user'  => get_current_user_id(),
		) );
		update_post_meta( $id, self::META, array_slice( $history, -self::MAX ) );
	}

	public static function all( $id ) {
		$history = get_post_meta( $id, self::META, true );
		return is_array( $history ) ? array_values( $history ) : array();
	}

	/**
	 * Texto del botón de deshacer, o '' si no hay nada que deshacer.
	 */
	public static function undo_label( $id ) {
		$history = self::all( $id );
		if ( ! $history ) {
			return '';
		}
		$last  = end( $history );
		$group = isset( $last['group'] ) ? $last['group'] : '';
		$parts = array();
		foreach ( array_reverse( $history ) as $entry ) {
			if ( ( isset( $entry['group'] ) ? $entry['group'] : '' ) !== $group ) {
				break;
			}
			$parts[] = ( isset( $entry['type'] ) && 'texts' === $entry['type'] ) ? 'restaurar textos' : 'volver a ' . $entry['from'];
		}
		return 'Deshacer: ' . implode( ' y ', array_reverse( $parts ) );
	}

	/**
	 * Deshace el último grupo de cambios.
	 *
	 * @return array|WP_Error Claves: filename, url, texts (valores restaurados), label (siguiente deshacer).
	 */
	public static function undo( $id ) {
		$history = self::all( $id );
		if ( ! $history ) {
			return new WP_Error( 'ditms_nothing', 'No hay cambios para deshacer.' );
		}

		$last   = end( $history );
		$group  = isset( $last['group'] ) ? $last['group'] : '';
		$result = array( 'texts' => array(), 'filename' => '', 'url' => '' );

		while ( $history ) {
			$entry = end( $history );
			if ( ( isset( $entry['group'] ) ? $entry['group'] : '' ) !== $group ) {
				break;
			}

			if ( isset( $entry['type'] ) && 'texts' === $entry['type'] ) {
				$result['texts'] = array_merge( $result['texts'], self::restore_texts( $id, (array) $entry['before'] ) );
			} else {
				$renamed = DITMS_Renamer::rename( $id, pathinfo( $entry['from'], PATHINFO_FILENAME ), array( 'log' => false ) );
				// Si ya tiene ese nombre no hay nada que revertir: se descarta la entrada igual.
				if ( is_wp_error( $renamed ) && 'ditms_same' !== $renamed->get_error_code() ) {
					update_post_meta( $id, self::META, $history );
					return $renamed;
				}
				if ( ! is_wp_error( $renamed ) ) {
					$result['filename'] = $renamed['filename'];
					$result['url']      = $renamed['url'];
				}
			}
			array_pop( $history );
		}

		update_post_meta( $id, self::META, $history );
		$result['label'] = self::undo_label( $id );
		return $result;
	}

	private static function restore_texts( $id, array $before ) {
		$update = array( 'ID' => $id );
		$map    = array( 'title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content' );
		foreach ( $before as $field => $value ) {
			if ( 'alt' === $field ) {
				update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $value ) );
			} elseif ( isset( $map[ $field ] ) ) {
				$update[ $map[ $field ] ] = $value;
			}
		}
		if ( count( $update ) > 1 ) {
			wp_update_post( wp_slash( $update ) );
		}
		return $before;
	}
}
