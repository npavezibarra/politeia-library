<?php
/**
 * Módulo: Post Reading (posts regulares)
 * Carga de clases y hook de migración de tabla.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Versión propia del módulo (si quieres llevar control separado)
if ( ! defined( 'POLITEIA_POST_READING_VERSION' ) ) {
	define( 'POLITEIA_POST_READING_VERSION', '1.0.0' );
}

// Rutas base del módulo
define( 'POLITEIA_POST_READING_DIR', __DIR__ . '/' );

// Requerir clases del módulo
require_once POLITEIA_POST_READING_DIR . 'class-post-reading-schema.php';
require_once POLITEIA_POST_READING_DIR . 'class-post-reading-manager.php';
require_once POLITEIA_POST_READING_DIR . 'class-post-reading-render.php';
require_once POLITEIA_POST_READING_DIR . 'class-post-reading-rest.php';

// Asegurar migración/upgrade del schema al cargar plugins
add_action(
	'plugins_loaded',
	function () {
		if ( class_exists( 'Politeia_Post_Reading_Schema' ) ) {
			Politeia_Post_Reading_Schema::maybe_upgrade();
		}
	}
);

// Nota: si quieres migrar explícitamente al activar el plugin principal,
// añade en politeia-reading.php (archivo raíz del plugin):
//
// register_activation_hook( __FILE__, function() {
// if ( class_exists( 'Politeia_Post_Reading_Schema' ) ) {
// Politeia_Post_Reading_Schema::migrate();
// }
// });
//
// Esto no puede ir aquí porque este archivo no es el archivo principal del plugin.
