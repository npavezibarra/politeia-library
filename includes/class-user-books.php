<?php
/**
 * User Books AJAX handlers
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Politeia_Reading_User_Books {

    public static function init() {
        // Listado "My Library" (usa nonce prs_update_user_book_nonce)
        add_action( 'wp_ajax_prs_update_user_book', [ __CLASS__, 'ajax_update_user_book' ] );

        // Vista "my-book-single" y otras (usa nonce prs_update_user_book_meta)
        add_action( 'wp_ajax_prs_update_user_book_meta', [ __CLASS__, 'ajax_update_user_book_meta' ] );
    }

    /* ------------------------- Public AJAX handlers ------------------------- */

    /**
     * Endpoint simple para el listado [politeia_my_books]
     * Espera: user_book_id, reading_status?, owning_status?
     * Nonce: prs_update_user_book_nonce (action: prs_update_user_book)
     */
    public static function ajax_update_user_book() {
        if ( ! is_user_logged_in() ) self::json_error( 'auth', 401 );

        // Verifica nonce del listado
        if ( ! self::verify_nonce( 'prs_update_user_book', [ 'prs_update_user_book_nonce', 'nonce' ] ) ) {
            self::json_error( 'bad_nonce', 403 );
        }

        $user_id      = get_current_user_id();
        $user_book_id = isset( $_POST['user_book_id'] ) ? absint( $_POST['user_book_id'] ) : 0;
        if ( ! $user_book_id ) self::json_error( 'invalid_id', 400 );

        $row = self::get_user_book_row( $user_book_id, $user_id );
        if ( ! $row ) self::json_error( 'forbidden', 403 );

        $update = [];

        if ( isset( $_POST['reading_status'] ) ) {
            $rs = sanitize_key( wp_unslash( $_POST['reading_status'] ) );
            if ( in_array( $rs, self::allowed_reading_status(), true ) ) {
                $update['reading_status'] = $rs;
            }
        }

        if ( isset( $_POST['owning_status'] ) ) {
            $os = sanitize_key( wp_unslash( $_POST['owning_status'] ) );
            if ( in_array( $os, self::allowed_owning_status(), true ) ) {
                $update['owning_status'] = $os;
                if ( ! in_array( $os, [ 'borrowed', 'borrowing', 'sold' ], true ) ) {
                    // si el estado ya no requiere contacto, limpiar
                    $update['counterparty_name']  = null;
                    $update['counterparty_email'] = null;
                }
            }
        }

        if ( empty( $update ) ) self::json_error( 'no_fields', 400 );

        $updated = self::update_user_book( $user_book_id, $update );
        self::json_success( $updated );
    }

    /**
     * Endpoint genérico para actualizar metadatos desde my-book-single
     * Espera: user_book_id y 1+ de: pages, purchase_date, purchase_channel, purchase_place,
     *         reading_status, owning_status, counterparty_name, counterparty_email
     * Nonce: prs_update_user_book_meta (key: nonce) — ver wp_localize_script('PRS_BOOK')
     */
    public static function ajax_update_user_book_meta() {
        if ( ! is_user_logged_in() ) self::json_error( 'auth', 401 );

        // Acepta nonce 'nonce' (PRS_BOOK.nonce) o el del listado por compatibilidad
        if ( ! self::verify_nonce_multi( [
            [ 'action' => 'prs_update_user_book_meta', 'keys' => [ 'nonce' ] ],
            [ 'action' => 'prs_update_user_book',      'keys' => [ 'prs_update_user_book_nonce' ] ],
        ] ) ) {
            self::json_error( 'bad_nonce', 403 );
        }

        $user_id      = get_current_user_id();
        $user_book_id = isset( $_POST['user_book_id'] ) ? absint( $_POST['user_book_id'] ) : 0;
        if ( ! $user_book_id ) self::json_error( 'invalid_id', 400 );

        $row = self::get_user_book_row( $user_book_id, $user_id );
        if ( ! $row ) self::json_error( 'forbidden', 403 );

        $update = [];

        // pages
        if ( array_key_exists( 'pages', $_POST ) ) {
            $p = absint( $_POST['pages'] );
            $update['pages'] = $p > 0 ? $p : null;
        }

        // purchase_date (YYYY-MM-DD)
        if ( array_key_exists( 'purchase_date', $_POST ) ) {
            $d = sanitize_text_field( wp_unslash( $_POST['purchase_date'] ) );
            $ok = ( $d && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) ? $d : null;
            $update['purchase_date'] = $ok;
        }

        // purchase_channel
        if ( array_key_exists( 'purchase_channel', $_POST ) ) {
            $pc = sanitize_key( $_POST['purchase_channel'] );
            $update['purchase_channel'] = in_array( $pc, [ 'online', 'store' ], true ) ? $pc : null;
        }

        // purchase_place
        if ( array_key_exists( 'purchase_place', $_POST ) ) {
            $update['purchase_place'] = sanitize_text_field( wp_unslash( $_POST['purchase_place'] ) );
        }

        // reading_status
        if ( array_key_exists( 'reading_status', $_POST ) ) {
            $rs = sanitize_key( wp_unslash( $_POST['reading_status'] ) );
            if ( in_array( $rs, self::allowed_reading_status(), true ) ) {
                $update['reading_status'] = $rs;
            }
        }

        // owning_status (+ limpia contacto si no aplica)
        if ( array_key_exists( 'owning_status', $_POST ) ) {
            $os = sanitize_key( wp_unslash( $_POST['owning_status'] ) );
            if ( in_array( $os, self::allowed_owning_status(), true ) ) {
                $update['owning_status'] = $os;
                if ( ! in_array( $os, [ 'borrowed', 'borrowing', 'sold' ], true ) ) {
                    $update['counterparty_name']  = null;
                    $update['counterparty_email'] = null;
                }
            }
        }

        // counterparty_name / email
        if ( array_key_exists( 'counterparty_name', $_POST ) ) {
            $update['counterparty_name'] = sanitize_text_field( wp_unslash( $_POST['counterparty_name'] ) );
        }
        if ( array_key_exists( 'counterparty_email', $_POST ) ) {
            $email = sanitize_email( wp_unslash( $_POST['counterparty_email'] ) );
            $update['counterparty_email'] = $email && is_email( $email ) ? $email : null;
        }

        if ( empty( $update ) ) self::json_error( 'no_fields', 400 );

        $updated = self::update_user_book( $user_book_id, $update );
        self::json_success( $updated );
    }

    /* ------------------------------ Internals ------------------------------- */

    private static function allowed_reading_status() {
        return [ 'not_started', 'started', 'finished' ];
    }
    private static function allowed_owning_status() {
        return [ 'in_shelf', 'borrowed', 'borrowing', 'sold', 'lost' ];
    }

    private static function get_user_book_row( $user_book_id, $user_id ) {
        global $wpdb;
        $t = $wpdb->prefix . 'politeia_user_books';
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE id=%d AND user_id=%d LIMIT 1",
            $user_book_id, $user_id
        ) );
    }

    private static function update_user_book( $user_book_id, $update ) {
        global $wpdb;
        $t = $wpdb->prefix . 'politeia_user_books';
        $update['updated_at'] = current_time( 'mysql' );
        $wpdb->update( $t, $update, [ 'id' => $user_book_id ] );
        return $update;
    }

    /* ------------------------------ Utilities ------------------------------- */

    private static function verify_nonce( $action, $keys = [ '_ajax_nonce', 'security', 'nonce' ] ) {
        foreach ( (array) $keys as $k ) {
            if ( isset( $_REQUEST[ $k ] ) ) {
                $nonce = $_REQUEST[ $k ];
                return (bool) wp_verify_nonce( $nonce, $action );
            }
        }
        return false;
    }

    private static function verify_nonce_multi( $pairs ) {
        // $pairs = [ ['action'=>'x','keys'=>['a','b']], ... ]
        foreach ( (array) $pairs as $p ) {
            $action = isset( $p['action'] ) ? $p['action'] : '';
            $keys   = isset( $p['keys'] )   ? (array) $p['keys']   : [];
            if ( $action && $keys && self::verify_nonce( $action, $keys ) ) return true;
        }
        return false;
    }

    private static function json_error( $message, $code = 400 ) {
        wp_send_json_error( [ 'message' => $message ], $code );
    }
    private static function json_success( $data ) {
        wp_send_json_success( $data );
    }
}

// Bootstrap
Politeia_Reading_User_Books::init();
