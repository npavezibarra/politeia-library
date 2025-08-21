<?php
/**
 * Plugin Name: Politeia Reading
 * Description: Manage "My Library" and Reading Sessions with custom tables and shortcodes.
 * Version: 0.1.0
 * Author: Politeia
 * Text Domain: politeia-reading
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// ===== Constants =====
define( 'POLITEIA_READING_VERSION', '0.1.0' );
define( 'POLITEIA_READING_PATH', plugin_dir_path( __FILE__ ) );
define( 'POLITEIA_READING_URL',  plugin_dir_url( __FILE__ ) );

// ===== Includes =====
require_once POLITEIA_READING_PATH . 'includes/class-activator.php';
require_once POLITEIA_READING_PATH . 'includes/class-rest.php';
require_once POLITEIA_READING_PATH . 'includes/class-books.php';
require_once POLITEIA_READING_PATH . 'includes/class-user-books.php';
require_once POLITEIA_READING_PATH . 'includes/class-reading-sessions.php';
require_once POLITEIA_READING_PATH . 'includes/helpers.php';
require_once POLITEIA_READING_PATH . 'templates/features/cover-upload/cover-upload.php';

// ===== Activation Hook =====
register_activation_hook( __FILE__, [ 'Politeia_Reading_Activator', 'activate' ] );

// ===== Asset Registration / Enqueue =====
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

    // Script de la página de “mi libro”
    // (SIN dependencias de media ni nada relacionado con uploads)
    wp_register_script(
        'politeia-my-book',
        plugins_url( 'assets/js/my-book.js', __FILE__ ),
        [ 'jquery' ],
        POLITEIA_READING_VERSION,
        true
    );

    // Carga condicional en la vista de un libro individual
    if ( get_query_var( 'prs_book_slug' ) ) {
        wp_enqueue_style( 'politeia-reading' );
        wp_enqueue_script( 'politeia-my-book' );
    }
} );

// ===== Shortcodes =====
require_once POLITEIA_READING_PATH . 'shortcodes/add-book.php';
require_once POLITEIA_READING_PATH . 'shortcodes/start-reading.php';
require_once POLITEIA_READING_PATH . 'shortcodes/my-books.php';
require_once POLITEIA_READING_PATH . 'includes/class-routes.php';
