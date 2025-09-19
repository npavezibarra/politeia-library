<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function prs_current_user_id_or_die() {
	if ( ! is_user_logged_in() ) {
		wp_die( __( 'You must be logged in.', 'politeia-reading' ) );
	}
	return get_current_user_id();
}

function prs_find_or_create_book( $title, $author, $year = null, $attachment_id = null ) {
	global $wpdb;
	$table = $wpdb->prefix . 'politeia_books';

	$title  = trim( wp_strip_all_tags( $title ) );
	$author = trim( wp_strip_all_tags( $author ) );

	if ( $title === '' || $author === '' ) {
		return new WP_Error( 'prs_invalid_book', 'Missing title/author' );
	}

	$slug = sanitize_title( $title . '-' . $author . ( $year ? '-' . $year : '' ) );

	// Try to find by slug
	$existing_id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE slug = %s LIMIT 1",
			$slug
		)
	);
	if ( $existing_id ) {
		return (int) $existing_id;
	}

	$wpdb->insert(
		$table,
		array(
			'title'               => $title,
			'author'              => $author,
			'year'                => $year ? (int) $year : null,
			'cover_attachment_id' => $attachment_id ? (int) $attachment_id : null,
			'slug'                => $slug,
			'created_at'          => current_time( 'mysql' ),
			'updated_at'          => current_time( 'mysql' ),
		)
	);
	return (int) $wpdb->insert_id;
}

function prs_ensure_user_book( $user_id, $book_id ) {
	global $wpdb;
	$table = $wpdb->prefix . 'politeia_user_books';

	// Unique (user_id, book_id)
	$id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE user_id = %d AND book_id = %d LIMIT 1",
			$user_id,
			$book_id
		)
	);
	if ( $id ) {
		return (int) $id;
	}

	$wpdb->insert(
		$table,
		array(
			'user_id'        => (int) $user_id,
			'book_id'        => (int) $book_id,
			'reading_status' => 'not_started',
			'owning_status'  => 'in_shelf',
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		)
	);
	return (int) $wpdb->insert_id;
}

function prs_handle_cover_upload( $field_name = 'prs_cover' ) {
	if ( empty( $_FILES[ $field_name ]['name'] ) ) {
		return null;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$file = wp_handle_upload( $_FILES[ $field_name ], array( 'test_form' => false ) );
	if ( isset( $file['error'] ) ) {
		return null;
	}

	$attachment  = array(
		'post_mime_type' => $file['type'],
		'post_title'     => sanitize_file_name( basename( $file['file'] ) ),
		'post_content'   => '',
		'post_status'    => 'inherit',
	);
	$attach_id   = wp_insert_attachment( $attachment, $file['file'] );
	$attach_data = wp_generate_attachment_metadata( $attach_id, $file['file'] );
	wp_update_attachment_metadata( $attach_id, $attach_data );
	return (int) $attach_id;
}

function prs_maybe_alter_user_books() {
	global $wpdb;
	$t = $wpdb->prefix . 'politeia_user_books';

	$cols = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
			DB_NAME,
			$t
		)
	);
	$has  = array_map( 'strtolower', (array) $cols );

	$alters = array();
	if ( ! in_array( 'pages', $has, true ) ) {
		$alters[] = 'ADD COLUMN pages INT UNSIGNED NULL AFTER owning_status';
	}
	if ( ! in_array( 'purchase_date', $has, true ) ) {
		$alters[] = 'ADD COLUMN purchase_date DATE NULL';
	}
	if ( ! in_array( 'purchase_channel', $has, true ) ) {
		$alters[] = "ADD COLUMN purchase_channel ENUM('online','store') NULL";
	}
	if ( ! in_array( 'purchase_place', $has, true ) ) {
		$alters[] = 'ADD COLUMN purchase_place VARCHAR(255) NULL';
	}
	if ( ! in_array( 'counterparty_name', $has, true ) ) {
		$alters[] = 'ADD COLUMN counterparty_name VARCHAR(255) NULL';
	}
	if ( ! in_array( 'counterparty_email', $has, true ) ) {
		$alters[] = 'ADD COLUMN counterparty_email VARCHAR(190) NULL';
	}

	if ( $alters ) {
		$wpdb->query( "ALTER TABLE {$t} " . implode( ', ', $alters ) );
	}
}
add_action( 'plugins_loaded', 'prs_maybe_alter_user_books' );

function prs_maybe_create_loans_table() {
	global $wpdb;
	$table = $wpdb->prefix . 'politeia_loans';

	$exists = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
			DB_NAME,
			$table
		)
	);

	if ( ! $exists ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
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
		dbDelta( $sql );
	}
}
add_action( 'plugins_loaded', 'prs_maybe_create_loans_table' );

// Devuelve el start_date (GMT) del loan activo o null
function prs_get_active_loan_start_date( $user_id, $book_id ) {
	global $wpdb;
	$t = $wpdb->prefix . 'politeia_loans';
	return $wpdb->get_var(
		$wpdb->prepare(
			"SELECT start_date FROM {$t}
         WHERE user_id=%d AND book_id=%d AND end_date IS NULL
         ORDER BY id DESC LIMIT 1",
			$user_id,
			$book_id
		)
	);
}
