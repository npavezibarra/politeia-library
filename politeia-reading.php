<?php
/**
 * Plugin Name: Politeia Reading
 * Description: Manage "My Library" and Reading Sessions with custom tables and shortcodes.
 * Version: 0.2.1
 * Author: Politeia
 * Text Domain: politeia-reading
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// ===== Constants =====
if ( ! defined( 'POLITEIA_READING_VERSION' ) ) {
	// ⬆️ Incrementa esta versión cuando cambies estructuras/flujo global del plugin
	define( 'POLITEIA_READING_VERSION', '0.2.1' );
}
if ( ! defined( 'POLITEIA_READING_PATH' ) ) {
	define( 'POLITEIA_READING_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'POLITEIA_READING_URL' ) ) {
	define( 'POLITEIA_READING_URL', plugin_dir_url( __FILE__ ) );
}

// ===== i18n =====
add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'politeia-reading', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

// ===== Includes núcleo =====
require_once POLITEIA_READING_PATH . 'includes/class-activator.php';
require_once POLITEIA_READING_PATH . 'includes/class-rest.php';
require_once POLITEIA_READING_PATH . 'includes/class-books.php';
require_once POLITEIA_READING_PATH . 'includes/class-user-books.php';
require_once POLITEIA_READING_PATH . 'includes/class-reading-sessions.php';
require_once POLITEIA_READING_PATH . 'includes/helpers.php';
require_once POLITEIA_READING_PATH . 'templates/features/cover-upload/cover-upload.php';
require_once POLITEIA_READING_PATH . 'includes/class-routes.php';

// ===== Módulos (carga modular) =====
// Módulo: Post Reading (botón Start/Finish para posts regulares + tabla wp_politeia_post_reading)
require_once POLITEIA_READING_PATH . 'modules/post-reading/init.php';

// ===== Activation Hooks =====
register_activation_hook( __FILE__, [ 'Politeia_Reading_Activator', 'activate' ] );

// Asegura la migración del módulo post-reading al activar el plugin
register_activation_hook( __FILE__, function () {
	if ( class_exists( 'Politeia_Post_Reading_Schema' ) ) {
		Politeia_Post_Reading_Schema::migrate();
	}
	// Opcional: si tu plugin registra rewrites en class-routes, marca para flush una vez
	if ( ! get_option( 'politeia_reading_flush_rewrite' ) ) {
		update_option( 'politeia_reading_flush_rewrite', 1 );
	}
} );

// ===== Upgrade / Migrations on load =====
// Ejecuta migraciones idempotentes cuando cambies POLITEIA_READING_VERSION (core)
add_action( 'plugins_loaded', [ 'Politeia_Reading_Activator', 'maybe_upgrade' ] );
// Nota: el módulo post-reading ya registra su propio maybe_upgrade en su init.php.
// No lo repetimos aquí para evitar dobles llamadas.

// ===== Flush rewrites (una sola vez post-activación) =====
add_action( 'admin_init', function () {
	if ( get_option( 'politeia_reading_flush_rewrite' ) ) {
		// Si tu plugin registra reglas (endpoints/slugs), aquí ya están cargadas
		flush_rewrite_rules( false );
		delete_option( 'politeia_reading_flush_rewrite' );
	}
} );

// ===== Asset Registration / Enqueue (core existente) =====
add_action( 'wp_enqueue_scripts', function() {

	// Estilos base del plugin
	wp_register_style(
		'politeia-reading',
		POLITEIA_READING_URL . 'assets/css/politeia.css',
		[],
		POLITEIA_READING_VERSION
	);

	// Scripts varios del plugin
	wp_register_script(
		'politeia-add-book',
		POLITEIA_READING_URL . 'assets/js/add-book.js',
		[ 'jquery' ],
		POLITEIA_READING_VERSION,
		true
	);

	wp_register_script(
		'politeia-start-reading',
		POLITEIA_READING_URL . 'assets/js/start-reading.js',
		[ 'jquery' ],
		POLITEIA_READING_VERSION,
		true
	);

	// Script de la página “Mi Libro”
	wp_register_script(
		'politeia-my-book',
		POLITEIA_READING_URL . 'assets/js/my-book.js',
		[ 'jquery' ],
		POLITEIA_READING_VERSION,
		true
	);

	// Carga condicional en la vista de un libro individual (manteniendo tu lógica)
	if ( get_query_var( 'prs_book_slug' ) ) {
		wp_enqueue_style( 'politeia-reading' );
		wp_enqueue_script( 'politeia-my-book' );
	}

	// Importante: los assets del módulo Post Reading (post-reading.css/js)
	// los encola automáticamente Politeia_Post_Reading_Render solo en single posts.
} );

// ===== Shortcodes =====
require_once POLITEIA_READING_PATH . 'shortcodes/add-book.php';
require_once POLITEIA_READING_PATH . 'shortcodes/start-reading.php';
require_once POLITEIA_READING_PATH . 'shortcodes/my-books.php';
