<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registro mensual del uso de la API (peticiones, tokens y costo estimado).
 * Se guarda en una opción sin autoload: solo se lee en la página de ajustes.
 */
class DITMS_Usage {

	const MONTHS = 12;

	/**
	 * @param string $model Modelo que respondió (puede ser el de respaldo si hubo un rechazo).
	 * @param array  $usage Bloque "usage" de la respuesta de la API.
	 */
	public static function record( $model, $usage ) {
		if ( ! is_array( $usage ) ) {
			return;
		}
		$input  = (int) ( $usage['input_tokens'] ?? 0 );
		$output = (int) ( $usage['output_tokens'] ?? 0 );
		// Sin caché de prompts en este plugin, pero si la API reporta tokens de caché se cuentan a su tarifa.
		$cache_write = (int) ( $usage['cache_creation_input_tokens'] ?? 0 );
		$cache_read  = (int) ( $usage['cache_read_input_tokens'] ?? 0 );

		$prices = ditms_model_prices();
		$price  = isset( $prices[ $model ] ) ? $prices[ $model ] : null;
		if ( ! $price ) {
			$configured = (string) ditms_setting( 'model' );
			$price      = isset( $prices[ $configured ] ) ? $prices[ $configured ] : $prices['claude-opus-5-5'];
		}
		$cost = ( $input + $cache_write * 1.25 + $cache_read * 0.1 ) * $price['in'] / 1000000
			+ $output * $price['out'] / 1000000;

		$data  = self::all();
		$month = current_time( 'Y-m' );
		$row   = isset( $data[ $month ] ) ? $data[ $month ] : array( 'requests' => 0, 'input' => 0, 'output' => 0, 'cost' => 0.0 );

		$row['requests']++;
		$row['input']  += $input + $cache_write + $cache_read;
		$row['output'] += $output;
		$row['cost']   += $cost;
		$data[ $month ] = $row;

		krsort( $data );
		update_option( DITMS_USAGE, array_slice( $data, 0, self::MONTHS, true ), false );
	}

	/**
	 * @return array [ 'YYYY-MM' => [ requests, input, output, cost ] ], del mes más reciente al más antiguo.
	 */
	public static function all() {
		$data = get_option( DITMS_USAGE, array() );
		return is_array( $data ) ? $data : array();
	}
}
