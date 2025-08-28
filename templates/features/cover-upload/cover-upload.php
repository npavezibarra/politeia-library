<?php
/**
 * Feature: Upload Book Cover (modal + crop/zoom centrado)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class PRS_Cover_Upload_Feature {

    public static function init() {
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
        add_shortcode( 'prs_cover_button', [ __CLASS__, 'shortcode_button' ] );
        add_action( 'wp_ajax_prs_cover_save_crop', [ __CLASS__, 'ajax_save_crop' ] );
    }

    public static function assets() {
        // Solo en la pantalla del libro (usas query var prs_book_slug en tu template).
        if ( ! get_query_var( 'prs_book_slug' ) ) return;

        // CSS + JS de la feature
        wp_register_style(
            'prs-cover-upload',
            plugins_url( 'templates/features/cover-upload/cover-upload.css', dirname( __FILE__, 3 ) ),
            [],
            '0.1.0'
        );
        wp_register_script(
            'prs-cover-upload',
            plugins_url( 'templates/features/cover-upload/cover-upload.js', dirname( __FILE__, 3 ) ),
            [],
            '0.1.0',
            true
        );

        wp_enqueue_style( 'prs-cover-upload' );
        wp_enqueue_script( 'prs-cover-upload' );

        // Datos para AJAX
        global $wpdb;
        // Necesitamos el user_book_id y book_id que ya tienes en PRS_BOOK.
        // Si por alguna razón no están, el JS leerá de window.PRS_BOOK.
        wp_localize_script( 'prs-cover-upload', 'PRS_COVER', [
            'ajax'        => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'prs_cover_save_crop' ),
            'coverWidth'  => 240,
            'coverHeight' => 450,
            'onlyOne'     => 1,
        ] );
    }

    public static function shortcode_button( $atts ) {
        // Botón compacto para insertar sobre la portada
        return '<button type="button" id="prs-cover-open" class="prs-btn prs-cover-btn">Upload Book Cover</button>';
    }

    /**
     * Recibe un dataURL (JPG/PNG) ya recortado a 240x450,
     * lo guarda como attachment, borra portadas anteriores del mismo user_book,
     * y actualiza politeia_user_books.cover_attachment_id_user
     */
    public static function ajax_save_crop() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'auth' ], 401 );
        }
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'prs_cover_save_crop' ) ) {
            wp_send_json_error( [ 'message' => 'bad_nonce' ], 403 );
        }

        $user_id      = get_current_user_id();
        $user_book_id = isset( $_POST['user_book_id'] ) ? absint( $_POST['user_book_id'] ) : 0;
        $book_id      = isset( $_POST['book_id'] )      ? absint( $_POST['book_id'] )      : 0;
        $data_url     = isset( $_POST['image'] )        ? (string) $_POST['image']         : '';

        if ( ! $user_book_id || ! $book_id || ! $data_url ) {
            wp_send_json_error( [ 'message' => 'missing_params' ], 400 );
        }

        // Validar pertenencia del user_book
        global $wpdb;
        $t = $wpdb->prefix . 'politeia_user_books';
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE id=%d AND user_id=%d AND book_id=%d LIMIT 1",
            $user_book_id, $user_id, $book_id
        ) );
        if ( ! $row ) {
            wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
        }

        // Decodificar dataURL
        if ( ! preg_match( '#^data:image/(png|jpeg);base64,#i', $data_url, $m ) ) {
            wp_send_json_error( [ 'message' => 'bad_image' ], 400 );
        }
        $ext  = strtolower( $m[1] ) === 'png' ? 'png' : 'jpg';
        $bin  = base64_decode( preg_replace( '#^data:image/\w+;base64,#i', '', $data_url ) );
        if ( ! $bin ) {
            wp_send_json_error( [ 'message' => 'decode_fail' ], 400 );
        }

        // Guardar archivo en uploads
        $up = wp_upload_dir();
        if ( ! empty( $up['error'] ) ) {
            wp_send_json_error( [ 'message' => 'upload_dir_error' ], 500 );
        }

        $key      = 'u' . $user_id . 'ub' . $user_book_id;
        $filename = 'book-cover-' . $key . '-' . gmdate( 'Ymd-His' ) . '.' . $ext;
        $path     = trailingslashit( $up['path'] ) . $filename;

        if ( ! wp_mkdir_p( $up['path'] ) ) {
            wp_send_json_error( [ 'message' => 'mkdir_fail' ], 500 );
        }
        if ( ! file_put_contents( $path, $bin ) ) {
            wp_send_json_error( [ 'message' => 'write_fail' ], 500 );
        }

        // Insertar attachment
        $filetype = wp_check_filetype( $path, null );
        $att_id = wp_insert_attachment( [
            'post_mime_type' => $filetype['type'],
            'post_title'     => sanitize_file_name( preg_replace( '/\.[^.]+$/', '', $filename ) ),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_author'    => $user_id,
        ], $path );

        if ( ! $att_id ) {
            @unlink( $path );
            wp_send_json_error( [ 'message' => 'attach_fail' ], 500 );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $meta = wp_generate_attachment_metadata( $att_id, $path );
        wp_update_attachment_metadata( $att_id, $meta );

        // Etiquetas para poder limpiar
        update_post_meta( $att_id, '_prs_cover_user_id',      $user_id );
        update_post_meta( $att_id, '_prs_cover_user_book_id', $user_book_id );
        update_post_meta( $att_id, '_prs_cover_key',          $key );

        // Borrar otras portadas del mismo user_book
        $others = get_posts( [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'author'         => $user_id,
            'exclude'        => [ $att_id ],
            'meta_query'     => [
                [ 'key' => '_prs_cover_key', 'value' => $key ],
            ],
        ] );
        foreach ( $others as $oid ) {
            wp_delete_attachment( $oid, true );
        }

        // Persistir en politeia_user_books
        $wpdb->update( $t, [
            'cover_attachment_id_user' => (int) $att_id,
            'updated_at'               => current_time( 'mysql', true ),
        ], [ 'id' => $user_book_id ] );

        // Responder con URL para reemplazar la portada en el front
        $src = wp_get_attachment_image_url( $att_id, 'large' );
        wp_send_json_success( [ 'id' => (int) $att_id, 'src' => $src ?: '' ] );
    }
}
PRS_Cover_Upload_Feature::init();
