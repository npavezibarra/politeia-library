<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Politeia_Reading_Activator {

    public static function activate() {
        self::create_or_update_tables();
        self::run_migrations();

        // Guardar versión DB
        if ( get_option( 'politeia_reading_db_version' ) === false ) {
            add_option( 'politeia_reading_db_version', POLITEIA_READING_VERSION );
        } else {
            update_option( 'politeia_reading_db_version', POLITEIA_READING_VERSION );
        }

        // Pedir flush rewrites
        add_option( 'politeia_reading_flush_rewrite', 1 );
    }

    /**
     * Llamar en plugins_loaded para aplicar migraciones cuando subas la versión.
     * Añade en politeia-reading.php:
     * add_action('plugins_loaded', ['Politeia_Reading_Activator','maybe_upgrade']);
     */
    public static function maybe_upgrade() {
        $stored = get_option( 'politeia_reading_db_version' );
        if ( $stored !== POLITEIA_READING_VERSION ) {
            self::create_or_update_tables();
            self::run_migrations();
            update_option( 'politeia_reading_db_version', POLITEIA_READING_VERSION );
        }
    }

    private static function create_or_update_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $books_table      = $wpdb->prefix . 'politeia_books';
        $user_books_table = $wpdb->prefix . 'politeia_user_books';
        $sessions_table   = $wpdb->prefix . 'politeia_reading_sessions';
        $loans_table      = $wpdb->prefix . 'politeia_loans';

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

        // 2) User books (relación + campos por usuario)
        // Nota: owning_status SIN 'in_shelf' y permite NULL (In Shelf derivado).
        $sql_user_books = "CREATE TABLE {$user_books_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            book_id BIGINT UNSIGNED NOT NULL,
            rating TINYINT UNSIGNED NULL DEFAULT NULL,
            reading_status ENUM('not_started','started','finished') NOT NULL DEFAULT 'not_started',
            owning_status  ENUM('borrowed','borrowing','sold','lost') NULL DEFAULT NULL,
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

        // 3) Loans
        $sql_loans = "CREATE TABLE {$loans_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            book_id BIGINT UNSIGNED NOT NULL,
            counterparty_name  VARCHAR(255) NULL,
            counterparty_email VARCHAR(190) NULL,
            start_date DATETIME NOT NULL,
            end_date   DATETIME NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_book (user_id, book_id),
            KEY idx_active (user_id, book_id, end_date)
        ) {$charset_collate};";

        // 4) Reading sessions
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

        dbDelta( $sql_books );
        dbDelta( $sql_user_books );
        dbDelta( $sql_sessions );
        dbDelta( $sql_loans );
    }

    /**
     * Migraciones idempotentes para instalaciones existentes.
     */
    private static function run_migrations() {
        self::maybe_add_rating_column();
        self::maybe_migrate_owning_status();
    }

    /**
     * Añade 'rating' si no existe.
     */
    private static function maybe_add_rating_column() {
        global $wpdb;
        $tbl = $wpdb->prefix . 'politeia_user_books';
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$tbl} LIKE %s", 'rating'
        ) );
        if ( ! $exists ) {
            $wpdb->query( "ALTER TABLE {$tbl} ADD COLUMN rating TINYINT UNSIGNED NULL DEFAULT NULL AFTER book_id" );
        }
    }

    /**
     * Migra owning_status:
     *  - reemplaza 'in_shelf' por NULL
     *  - cambia el ENUM para permitir NULL y eliminar 'in_shelf'
     */
    private static function maybe_migrate_owning_status() {
        global $wpdb;
        $tbl = $wpdb->prefix . 'politeia_user_books';

        // ¿La columna contiene 'in_shelf' en su definición?
        $row = $wpdb->get_row( $wpdb->prepare(
            "SHOW COLUMNS FROM {$tbl} WHERE Field=%s", 'owning_status'
        ) );
        if ( ! $row ) return;

        $type = isset( $row->Type ) ? strtolower( $row->Type ) : '';
        $has_in_shelf = ( strpos( $type, "'in_shelf'" ) !== false );

        // Si existen filas con valor 'in_shelf', pásalas a NULL (derivado)
        if ( $has_in_shelf ) {
            $wpdb->query( "UPDATE {$tbl} SET owning_status = NULL WHERE owning_status = 'in_shelf'" );
            // Cambiar definición de la columna (ENUM sin 'in_shelf' y NULL por defecto)
            $wpdb->query(
                "ALTER TABLE {$tbl}
                 MODIFY owning_status ENUM('borrowed','borrowing','sold','lost') NULL DEFAULT NULL"
            );
        } else {
            // Si ya no tiene 'in_shelf' pero la columna no permite NULL, asegúralo
            if ( strpos( $type, 'default null' ) === false ) {
                $wpdb->query(
                    "ALTER TABLE {$tbl}
                     MODIFY owning_status ENUM('borrowed','borrowing','sold','lost') NULL DEFAULT NULL"
                );
            }
        }
    }
}
