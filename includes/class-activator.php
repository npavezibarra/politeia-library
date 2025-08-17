<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Politeia_Reading_Activator {

    public static function activate() {
        global $wpdb;

        // Ensure dbDelta is available
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $books_table      = $wpdb->prefix . 'politeia_books';
        $user_books_table = $wpdb->prefix . 'politeia_user_books';
        $sessions_table   = $wpdb->prefix . 'politeia_reading_sessions';

        // 1) Canonical books table
        $sql_books = "CREATE TABLE {$books_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            author VARCHAR(255) NOT NULL,
            year SMALLINT UNSIGNED NULL,
            cover_attachment_id BIGINT UNSIGNED NULL,
            slug VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_title (title),
            KEY idx_author (author),
            UNIQUE KEY uniq_slug (slug)
        ) {$charset_collate};";

        // 2) User books (relationship + per-user fields)
        $sql_user_books = "CREATE TABLE {$user_books_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            book_id BIGINT UNSIGNED NOT NULL,
            reading_status ENUM('not_started','started','finished') NOT NULL DEFAULT 'not_started',
            owning_status  ENUM('in_shelf','lost','borrowed','borrowing','sold') NOT NULL DEFAULT 'in_shelf',
            pages INT UNSIGNED NULL,
            purchase_date DATE NULL,
            purchase_channel ENUM('online','store') NULL,
            purchase_place VARCHAR(255) NULL,
            counterparty_name  VARCHAR(255) NULL,
            counterparty_email VARCHAR(190) NULL,
            language VARCHAR(50) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_user_book (user_id, book_id),
            KEY idx_user (user_id),
            KEY idx_book (book_id)
        ) {$charset_collate};";             

        // 3) Reading sessions (events)
        $sql_sessions = "CREATE TABLE {$sessions_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            book_id BIGINT UNSIGNED NOT NULL,
            start_time DATETIME NOT NULL,
            end_time DATETIME NOT NULL,
            start_page INT UNSIGNED NOT NULL,
            end_page INT UNSIGNED NOT NULL,
            chapter_name VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_user_book_time (user_id, book_id, start_time),
            KEY idx_book (book_id)
        ) {$charset_collate};";

        // Run creators
        dbDelta( $sql_books );
        dbDelta( $sql_user_books );
        dbDelta( $sql_sessions );

        // Store/advance DB version
        if ( get_option( 'politeia_reading_db_version' ) === false ) {
            add_option( 'politeia_reading_db_version', POLITEIA_READING_VERSION );
        } else {
            update_option( 'politeia_reading_db_version', POLITEIA_READING_VERSION );
        }

        // Flag to flush rewrites once on next load
        add_option( 'politeia_reading_flush_rewrite', 1 );
    }
}
