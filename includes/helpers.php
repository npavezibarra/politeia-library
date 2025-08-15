<?php
if ( ! defined( 'ABSPATH' ) ) exit;

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

    if ( $title === '' || $author === '' ) return new WP_Error('prs_invalid_book', 'Missing title/author');

    $slug = sanitize_title( $title . '-' . $author . ( $year ? '-' . $year : '' ) );

    // Try to find by slug
    $existing_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $slug
    ) );
    if ( $existing_id ) return (int) $existing_id;

    $wpdb->insert( $table, [
        'title' => $title,
        'author'=> $author,
        'year'  => $year ? (int) $year : null,
        'cover_attachment_id' => $attachment_id ? (int) $attachment_id : null,
        'slug'  => $slug,
        'created_at' => current_time( 'mysql' ),
        'updated_at' => current_time( 'mysql' ),
    ] );
    return (int) $wpdb->insert_id;
}

function prs_ensure_user_book( $user_id, $book_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'politeia_user_books';

    // Unique (user_id, book_id)
    $id = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE user_id = %d AND book_id = %d LIMIT 1", $user_id, $book_id
    ) );
    if ( $id ) return (int) $id;

    $wpdb->insert( $table, [
        'user_id' => (int) $user_id,
        'book_id' => (int) $book_id,
        'reading_status' => 'not_started',
        'owning_status'  => 'in_shelf',
        'created_at' => current_time( 'mysql' ),
        'updated_at' => current_time( 'mysql' ),
    ] );
    return (int) $wpdb->insert_id;
}

function prs_handle_cover_upload( $field_name = 'prs_cover' ) {
    if ( empty( $_FILES[ $field_name ]['name'] ) ) return null;

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $file = wp_handle_upload( $_FILES[ $field_name ], [ 'test_form' => false ] );
    if ( isset( $file['error'] ) ) return null;

    $attachment = [
        'post_mime_type' => $file['type'],
        'post_title'     => sanitize_file_name( basename( $file['file'] ) ),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];
    $attach_id = wp_insert_attachment( $attachment, $file['file'] );
    $attach_data = wp_generate_attachment_metadata( $attach_id, $file['file'] );
    wp_update_attachment_metadata( $attach_id, $attach_data );
    return (int) $attach_id;
}
