<?php
/**
 * Reading Sessions AJAX (start/save)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Politeia_Reading_Sessions {

    public static function init() {
        add_action( 'wp_ajax_prs_start_reading', [ __CLASS__, 'ajax_start' ] );
        add_action( 'wp_ajax_prs_save_reading',  [ __CLASS__, 'ajax_save' ] );
    }

    /* =========================
     * AJAX: START
     * ========================= */
    public static function ajax_start() {
        if ( ! is_user_logged_in() ) self::err( 'auth', 401 );
        if ( ! self::verify_nonce( 'prs_reading_nonce', [ 'nonce' ] ) ) self::err( 'bad_nonce', 403 );

        $user_id = get_current_user_id();
        $book_id = isset($_POST['book_id']) ? absint($_POST['book_id']) : 0;
        if ( ! $book_id ) self::err( 'invalid_book', 400 );

        // Verifica que el libro esté en la librería del usuario
        $ub_row = self::get_user_book_row( $user_id, $book_id );
        if ( ! $ub_row ) self::err( 'forbidden', 403 );

        // Bloqueo por estado de posesión
        $owning_status = (string) $ub_row->owning_status;
        if ( self::blocked_by_status( $owning_status ) ) {
            self::err( 'not_in_possession', 403 );
        }

        $start_page = isset($_POST['start_page']) ? absint($_POST['start_page']) : 0;
        if ( $start_page < 1 ) self::err( 'invalid_start_page', 400 );

        $chapter = isset($_POST['chapter_name']) ? sanitize_text_field( wp_unslash($_POST['chapter_name']) ) : '';
        if ( strlen( $chapter ) > 255 ) $chapter = substr( $chapter, 0, 255 );

        global $wpdb;
        $t = $wpdb->prefix . 'politeia_reading_sessions';

        $now_gmt = current_time( 'mysql', true ); // GMT
        // La tabla define end_time NOT NULL, así que inicializamos con start_time
        $ins = [
            'user_id'     => $user_id,
            'book_id'     => $book_id,
            'start_time'  => $now_gmt,
            'end_time'    => $now_gmt,         // placeholder hasta el SAVE
            'start_page'  => $start_page,
            'end_page'    => $start_page,      // placeholder
            'chapter_name'=> $chapter ?: null,
        ];

        $ok = $wpdb->insert( $t, $ins, [
            '%d','%d','%s','%s','%d','%d','%s'
        ] );
        if ( ! $ok ) self::err( 'db_insert_failed', 500 );

        $session_id = (int) $wpdb->insert_id;

        self::ok( [
            'session_id' => $session_id,
            'started_at' => $now_gmt,
        ] );
    }

    /* =========================
     * AJAX: SAVE
     * ========================= */
    public static function ajax_save() {
        if ( ! is_user_logged_in() ) self::err( 'auth', 401 );
        if ( ! self::verify_nonce( 'prs_reading_nonce', [ 'nonce' ] ) ) self::err( 'bad_nonce', 403 );

        $user_id = get_current_user_id();
        $book_id = isset($_POST['book_id']) ? absint($_POST['book_id']) : 0;
        if ( ! $book_id ) self::err( 'invalid_book', 400 );

        // Verifica que el libro esté en la librería del usuario
        $ub_row = self::get_user_book_row( $user_id, $book_id );
        if ( ! $ub_row ) self::err( 'forbidden', 403 );

        // Bloqueo por estado de posesión
        $owning_status = (string) $ub_row->owning_status;
        if ( self::blocked_by_status( $owning_status ) ) {
            self::err( 'not_in_possession', 403 );
        }

        $start_page = isset($_POST['start_page']) ? absint($_POST['start_page']) : 0;
        $end_page   = isset($_POST['end_page'])   ? absint($_POST['end_page'])   : 0;
        if ( $start_page < 1 || $end_page < 1 || $end_page < $start_page ) {
            self::err( 'invalid_pages', 400 );
        }

        $chapter = isset($_POST['chapter_name']) ? sanitize_text_field( wp_unslash($_POST['chapter_name']) ) : '';
        if ( strlen( $chapter ) > 255 ) $chapter = substr( $chapter, 0, 255 );

        $duration_sec = isset($_POST['duration_sec']) ? absint($_POST['duration_sec']) : 0;

        global $wpdb;
        $t = $wpdb->prefix . 'politeia_reading_sessions';

        $now_gmt = current_time( 'mysql', true ); // GMT (end_time)
        // start_time calculado desde duration (si provisto); si no, igual a end_time
        $end_ts   = strtotime( $now_gmt . ' +0 seconds' );
        $start_ts = $duration_sec > 0 ? max(0, $end_ts - $duration_sec) : $end_ts;
        $start_gmt = gmdate( 'Y-m-d H:i:s', $start_ts );

        // Si vino session_id, intentamos actualizar esa fila (propiedad + libro)
        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
        if ( $session_id > 0 ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id,user_id,book_id FROM {$t} WHERE id=%d LIMIT 1",
                $session_id
            ) );
            // Debe existir y pertenecer al mismo user/book
            if ( $row && (int)$row->user_id === $user_id && (int)$row->book_id === $book_id ) {
                $wpdb->update( $t, [
                    'start_time'  => $start_gmt,
                    'end_time'    => $now_gmt,
                    'start_page'  => $start_page,
                    'end_page'    => $end_page,
                    'chapter_name'=> $chapter ?: null,
                ], [ 'id' => $session_id ], [
                    '%s','%s','%d','%d','%s'
                ], [ '%d' ] );

                if ( $wpdb->last_error ) self::err( 'db_update_failed', 500 );

                self::ok( [
                    'session_id' => (int)$session_id,
                    'start_time' => $start_gmt,
                    'end_time'   => $now_gmt,
                ] );
            }
            // Si el session_id no es válido, caemos a crear una sesión completa nueva
        }

        // Inserta una fila completa (sin session_id)
        $ok = $wpdb->insert( $t, [
            'user_id'     => $user_id,
            'book_id'     => $book_id,
            'start_time'  => $start_gmt,
            'end_time'    => $now_gmt,
            'start_page'  => $start_page,
            'end_page'    => $end_page,
            'chapter_name'=> $chapter ?: null,
        ], [ '%d','%d','%s','%s','%d','%d','%s' ] );

        if ( ! $ok ) self::err( 'db_insert_failed', 500 );

        self::ok( [
            'session_id' => (int) $wpdb->insert_id,
            'start_time' => $start_gmt,
            'end_time'   => $now_gmt,
        ] );
    }

    /* =========================
     * Helpers de dominio
     * ========================= */
    private static function get_user_book_row( $user_id, $book_id ) {
        global $wpdb;
        $t = $wpdb->prefix . 'politeia_user_books';
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE user_id=%d AND book_id=%d LIMIT 1",
            $user_id, $book_id
        ) );
    }

    private static function blocked_by_status( $status ) {
        return in_array( (string)$status, [ 'borrowed', 'lost', 'sold' ], true );
    }

    /* =========================
     * Utils
     * ========================= */
    private static function verify_nonce( $action, $keys = [ '_ajax_nonce', 'security', 'nonce' ] ) {
        foreach ( (array) $keys as $k ) {
            if ( isset( $_REQUEST[ $k ] ) ) {
                return (bool) wp_verify_nonce( $_REQUEST[ $k ], $action );
            }
        }
        return false;
    }

    private static function err( $message, $code = 400 ) {
        wp_send_json_error( [ 'message' => $message ], $code );
    }

    private static function ok( $data ) {
        wp_send_json_success( $data );
    }
}

Politeia_Reading_Sessions::init();
