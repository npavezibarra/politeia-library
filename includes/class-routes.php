<?php
if ( ! defined('ABSPATH') ) exit;

class Politeia_Reading_Routes {
    public static function init() {
        add_action('init', [__CLASS__, 'register_rules']);
        add_filter('query_vars', [__CLASS__, 'query_vars']);
        add_filter('template_include', [__CLASS__, 'template_router']);
    }

    public static function register_rules() {
        // /my-books  (archive)
        add_rewrite_rule('^my-books/?$', 'index.php?prs_my_books_archive=1', 'top');

        // /my-books/my-book-{slug}  (single)
        add_rewrite_rule('^my-books/my-book-([^/]+)/?$', 'index.php?prs_book_slug=$matches[1]', 'top');

        // flush marcado por el activador
        if ( get_option('politeia_reading_flush_rewrite') ) {
            flush_rewrite_rules(false);
            delete_option('politeia_reading_flush_rewrite');
        }
    }

    public static function query_vars($vars) {
        $vars[] = 'prs_my_books_archive';
        $vars[] = 'prs_book_slug';
        return $vars;
    }

    public static function template_router($template) {
        if ( get_query_var('prs_my_books_archive') ) {
            return POLITEIA_READING_PATH . 'templates/archive-my-books.php';
        }
        if ( get_query_var('prs_book_slug') ) {
            return POLITEIA_READING_PATH . 'templates/my-book-single.php';
        }
        return $template;
    }
}
Politeia_Reading_Routes::init();
