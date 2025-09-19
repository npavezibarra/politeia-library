<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Politeia_Reading_Activator {

	public static function activate() {
		self::create_or_update_tables();
		self::run_migrations();

		// ⬇️ NUEVO: asegura la tabla wp_politeia_post_reading del módulo Post Reading
		if ( class_exists( 'Politeia_Post_Reading_Schema' ) ) {
			Politeia_Post_Reading_Schema::migrate();
		}

		if ( get_option( 'politeia_reading_db_version' ) === false ) {
			add_option( 'politeia_reading_db_version', POLITEIA_READING_VERSION );
		} else {
			update_option( 'politeia_reading_db_version', POLITEIA_READING_VERSION );
		}

		add_option( 'politeia_reading_flush_rewrite', 1 );
	}

	/**
	 * Llamar en plugins_loaded para aplicar migraciones cuando subas la versión.
	 * Asegúrate en el main plugin:
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

		// 1) Canonical books table (con hash único)
		$sql_books = "CREATE TABLE {$books_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            author VARCHAR(255) NOT NULL,
            year SMALLINT UNSIGNED NULL,
            cover_attachment_id BIGINT UNSIGNED NULL,
            cover_url VARCHAR(800) NULL,
            slug VARCHAR(255) NULL,
            -- nuevos/compatibles:
            normalized_title  VARCHAR(255) NULL,
            normalized_author VARCHAR(255) NULL,
            title_author_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_title (title),
            KEY idx_author (author),
            UNIQUE KEY uniq_slug (slug),
            UNIQUE KEY uniq_title_author_hash (title_author_hash)
        ) {$charset_collate};";

		// 2) User books (ya incluye UNIQUE (user_id,book_id))
		$sql_user_books = "CREATE TABLE {$user_books_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            book_id BIGINT UNSIGNED NOT NULL,
            rating TINYINT UNSIGNED NULL DEFAULT NULL,
            reading_status ENUM('not_started','started','finished') NOT NULL DEFAULT 'not_started',
            owning_status  ENUM('in_shelf','lost','borrowed','borrowing','sold') NOT NULL DEFAULT 'in_shelf',
            pages INT UNSIGNED NULL,
            purchase_date DATE NULL,
            purchase_channel ENUM('online','store') NULL,
            purchase_place VARCHAR(255) NULL,
            counterparty_name  VARCHAR(255) NULL,
            counterparty_email VARCHAR(190) NULL,
            cover_attachment_id_user BIGINT UNSIGNED NULL,
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
		self::maybe_add_rating_column();      // legado
		self::maybe_migrate_owning_status();  // legado

		// NUEVO: asegurar cover_url en books
		global $wpdb;
		$books = $wpdb->prefix . 'politeia_books';
		self::maybe_add_column( $books, 'cover_url', 'VARCHAR(800) NULL' );

		self::migrate_books_hash_and_unique(); // <-- mantiene tu migración de hash/unique
		self::ensure_unique_user_book();       // robustez por si faltara el UNIQUE
	}

	/* ======================== MIGRACIONES NUEVAS ======================== */

	/**
	 * Garantiza columna title_author_hash poblada, sin duplicados y UNIQUE.
	 */
	private static function migrate_books_hash_and_unique() {
		global $wpdb;
		$books = $wpdb->prefix . 'politeia_books';

		// 1) Añadir columnas nuevas si faltan (opcionales)
		self::maybe_add_column( $books, 'normalized_title', 'VARCHAR(255) NULL' );
		self::maybe_add_column( $books, 'normalized_author', 'VARCHAR(255) NULL' );

		// 2) Añadir title_author_hash si falta (NULL al crear)
		self::maybe_add_column( $books, 'title_author_hash', 'CHAR(64) NULL' );

		// 3) Rellenar hashes vacíos (usa normalizados si existen; si no, raw)
		// con normalizados
		$wpdb->query(
			"
            UPDATE {$books}
            SET title_author_hash = LOWER(SHA2(CONCAT_WS('|', normalized_title, normalized_author), 256))
            WHERE (title_author_hash IS NULL OR title_author_hash = '')
              AND normalized_title  IS NOT NULL AND normalized_title  <> ''
              AND normalized_author IS NOT NULL AND normalized_author <> ''
        "
		);
		// con raw title/author
		$wpdb->query(
			"
            UPDATE {$books}
            SET title_author_hash = LOWER(SHA2(CONCAT_WS('|', LOWER(TRIM(title)), LOWER(TRIM(author))), 256))
            WHERE (title_author_hash IS NULL OR title_author_hash = '')
        "
		);

		// 4) Deduplicar por hash y corregir enlaces en user_books
		self::dedupe_books_and_fix_links( $books, $wpdb->prefix . 'politeia_user_books' );

		// 5) Asegurar NOT NULL y UNIQUE(hash)
		// (hacer NOT NULL después de rellenar)
		if ( self::column_exists( $books, 'title_author_hash' ) ) {
			$wpdb->query( "ALTER TABLE {$books} MODIFY title_author_hash CHAR(64) NOT NULL" );
		}
		self::maybe_add_unique( $books, 'uniq_title_author_hash', array( 'title_author_hash' ) );
	}

	/**
	 * Garantiza UNIQUE(user_id, book_id) en user_books y limpia duplicados si existen.
	 */
	private static function ensure_unique_user_book() {
		global $wpdb;
		$ub = $wpdb->prefix . 'politeia_user_books';

		// Deduplicar filas duplicadas conservando menor id
		$wpdb->query(
			"
            DELETE t1
            FROM {$ub} t1
            JOIN {$ub} t2
              ON t1.user_id = t2.user_id AND t1.book_id = t2.book_id AND t1.id > t2.id
        "
		);

		self::maybe_add_unique( $ub, 'uniq_user_book', array( 'user_id', 'book_id' ) );
		self::maybe_add_index( $ub, 'idx_user', array( 'user_id' ) );
		self::maybe_add_index( $ub, 'idx_book', array( 'book_id' ) );
	}

	/**
	 * Reapunta vínculos de user_books al "keeper" y elimina duplicados de books por hash.
	 */
	private static function dedupe_books_and_fix_links( $table_books, $table_user_books ) {
		global $wpdb;

		// ¿Hay duplicados?
		$dupes = (int) $wpdb->get_var(
			"
            SELECT COUNT(*) FROM (
              SELECT title_author_hash, COUNT(*) c
              FROM {$table_books}
              WHERE title_author_hash IS NOT NULL AND title_author_hash <> ''
              GROUP BY title_author_hash HAVING c > 1
            ) x
        "
		);
		if ( $dupes === 0 ) {
			return;
		}

		// Tabla temporal de keepers
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS _pol_keep' );
		$wpdb->query(
			"
            CREATE TEMPORARY TABLE _pol_keep
            SELECT MIN(id) AS keep_id, title_author_hash
            FROM {$table_books}
            WHERE title_author_hash IS NOT NULL AND title_author_hash <> ''
            GROUP BY title_author_hash
        "
		);

		// Reapuntar user_books
		$wpdb->query(
			"
            UPDATE {$table_user_books} ub
            JOIN {$table_books} b  ON ub.book_id = b.id
            JOIN _pol_keep k       ON b.title_author_hash = k.title_author_hash
            SET ub.book_id = k.keep_id
        "
		);

		// Borrar duplicados (los que no son keeper)
		$wpdb->query(
			"
            DELETE b
            FROM {$table_books} b
            LEFT JOIN _pol_keep k ON b.id = k.keep_id
            WHERE k.keep_id IS NULL
              AND b.title_author_hash IS NOT NULL AND b.title_author_hash <> ''
        "
		);
	}

	/* =========================== LEGADO =========================== */

	private static function maybe_add_rating_column() {
		global $wpdb;
		$tbl    = $wpdb->prefix . 'politeia_user_books';
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$tbl} LIKE %s",
				'rating'
			)
		);
		if ( ! $exists ) {
			$wpdb->query( "ALTER TABLE {$tbl} ADD COLUMN rating TINYINT UNSIGNED NULL DEFAULT NULL AFTER book_id" );
		}
	}

	private static function maybe_migrate_owning_status() {
		global $wpdb;
		$tbl = $wpdb->prefix . 'politeia_user_books';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$tbl} WHERE Field=%s",
				'owning_status'
			)
		);
		if ( ! $row ) {
			return;
		}

		$type         = isset( $row->Type ) ? strtolower( $row->Type ) : '';
		$has_in_shelf = ( strpos( $type, "'in_shelf'" ) !== false );

		if ( $has_in_shelf ) {
			$wpdb->query( "UPDATE {$tbl} SET owning_status = NULL WHERE owning_status = 'in_shelf'" );
			$wpdb->query(
				"ALTER TABLE {$tbl}
                 MODIFY owning_status ENUM('borrowed','borrowing','sold','lost') NULL DEFAULT NULL"
			);
		} elseif ( strpos( $type, 'default null' ) === false ) {
				$wpdb->query(
					"ALTER TABLE {$tbl}
                     MODIFY owning_status ENUM('borrowed','borrowing','sold','lost') NULL DEFAULT NULL"
				);
		}
	}

	/* ===================== HELPERS DE SCHEMA ===================== */

	private static function column_exists( $table, $column ) {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1',
				$table,
				$column
			)
		);
	}

	private static function index_exists( $table, $index_name ) {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s LIMIT 1',
				$table,
				$index_name
			)
		);
	}

	private static function maybe_add_column( $table, $column, $definition ) {
		global $wpdb;
		if ( ! self::column_exists( $table, $column ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" );
		}
	}

	private static function maybe_add_unique( $table, $name, array $cols ) {
		global $wpdb;
		if ( ! self::index_exists( $table, $name ) ) {
			$cols_sql = implode( ',', array_map( fn( $c )=>"`{$c}`", $cols ) );
			$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY `{$name}` ({$cols_sql})" );
		}
	}

	private static function maybe_add_index( $table, $name, array $cols ) {
		global $wpdb;
		if ( ! self::index_exists( $table, $name ) ) {
			$cols_sql = implode( ',', array_map( fn( $c )=>"`{$c}`", $cols ) );
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX `{$name}` ({$cols_sql})" );
		}
	}
}


// o mejor:
add_action( 'plugins_loaded', array( 'Politeia_Post_Reading_Schema', 'maybe_upgrade' ) );
