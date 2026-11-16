<?php
/**
 * Al borrar el plugin se eliminan sus ajustes (incluida la clave guardada) y el mapa de redirecciones.
 * Los textos y nombres que generó quedan en las imágenes.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ditms_settings' );
delete_option( 'ditms_redirects' );
delete_option( 'ditms_usage' );
delete_post_meta_by_key( '_ditms_history' );
delete_post_meta_by_key( '_ditms_generated' );
wp_clear_scheduled_hook( 'ditms_auto_generate' );
