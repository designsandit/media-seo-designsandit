<?php
/**
 * Plugin Name:       Media SEO by Designs and IT
 * Plugin URI:        https://github.com/designsandit/media-seo-designsandit
 * Description:       Renombra archivos de la biblioteca de medios de forma segura (borra los archivos viejos, regenera tamaños, actualiza enlaces y deja redirección 301) y genera con IA el texto alternativo, el título y la leyenda orientados a SEO.
 * Version:           1.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Designs and IT
 * Author URI:        https://www.designsandit.com/?utm_source=media-seo&utm_medium=plugin&utm_campaign=lista-plugins
 * License:           GPL-2.0-or-later
 * Text Domain:       media-seo-designsandit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DITMS_VERSION', '1.3.0' );
define( 'DITMS_FILE', __FILE__ );
define( 'DITMS_DIR', plugin_dir_path( __FILE__ ) );
define( 'DITMS_URL', plugin_dir_url( __FILE__ ) );
define( 'DITMS_OPTION', 'ditms_settings' );
define( 'DITMS_REDIRECTS', 'ditms_redirects' );
define( 'DITMS_REPO', 'https://github.com/designsandit/media-seo-designsandit' );

require_once DITMS_DIR . 'includes/helpers.php';
require_once DITMS_DIR . 'includes/class-ditms-history.php';
require_once DITMS_DIR . 'includes/class-ditms-renamer.php';
require_once DITMS_DIR . 'includes/class-ditms-ai.php';
require_once DITMS_DIR . 'includes/class-ditms-redirects.php';
require_once DITMS_DIR . 'includes/class-ditms-admin.php';

// Actualizaciones desde los releases de GitHub: cada sitio ve la versión nueva en Plugins y se actualiza con un clic.
require_once DITMS_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
	$ditms_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker( DITMS_REPO . '/', __FILE__, 'media-seo-designsandit' );
	$ditms_updater->setBranch( 'main' );
	// Se instala el .zip adjunto al release (trae la carpeta con el nombre correcto), no el código fuente del repositorio.
	$ditms_updater->getVcsApi()->enableReleaseAssets( '/media-seo-designsandit[^\/]*\.zip($|[?&#])/i' );
}

add_action( 'plugins_loaded', function () {
	DITMS_Redirects::init();
	DITMS_Admin::init();
} );

register_activation_hook( __FILE__, function () {
	if ( false === get_option( DITMS_OPTION ) ) {
		add_option( DITMS_OPTION, ditms_default_settings() );
	}
	if ( false === get_option( DITMS_REDIRECTS ) ) {
		// Sin autoload: solo se lee en las peticiones 404.
		add_option( DITMS_REDIRECTS, array(), '', 'no' );
	}
} );
