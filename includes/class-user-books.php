<?php
/**
 * User Books AJAX handlers (incluye Loans)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Politeia_Reading_User_Books {

    public static function init() {
        add_action( 'wp_ajax_prs_update_user_book', [ __CLASS__, 'ajax_update_user_book' ] );
        add_action( 'wp_ajax_prs_update_user_book_meta', [ __CLASS__, 'ajax_update_user_book_meta' ] );
    }

    public static function ajax_update_user_book() {
        if ( ! is_user_logged_in() ) self::json_error( 'auth', 401 );
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
                $effective = current_time( 'mysql', true );
                self::handle_owning_transition( $row, $os, $effective, [
                    'counterparty_name'  => null,
                    'counterparty_email' => null,
                ] );

                if ( ! in_array( $os, [ 'borrowed', 'borrowing', 'sold' ], true ) ) {
                    $update['counterparty_name']  = null;
                    $update['counterparty_email'] = null;
                }
            }
        }

        if ( empty( $update ) ) self::json_error( 'no_fields', 400 );

        $updated = self::update_user_book( $user_book_id, $update );
        self::json_success( $updated );
    }

    public static function ajax_update_user_book_meta() {
        if ( ! is_user_logged_in() ) self::json_error( 'auth', 401 );

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
        if ( empty( $row->book_id ) ) self::json_error( 'missing_book_id', 500 );

        $update = [];

        if ( array_key_exists( 'pages', $_POST ) ) {
            $p = absint( $_POST['pages'] );
            $update['pages'] = $p > 0 ? $p : null;
        }

        if ( array_key_exists( 'purchase_date', $_POST ) ) {
            $d = sanitize_text_field( wp_unslash( $_POST['purchase_date'] ) );
            $ok = ( $d && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) ? $d : null;
            $update['purchase_date'] = $ok;
        }

        if ( array_key_exists( 'purchase_channel', $_POST ) ) {
            $pc = sanitize_key( $_POST['purchase_channel'] );
            $update['purchase_channel'] = in_array( $pc, [ 'online', 'store' ], true ) ? $pc : null;
        }

        if ( array_key_exists( 'purchase_place', $_POST ) ) {
            $update['purchase_place'] = sanitize_text_field( wp_unslash( $_POST['purchase_place'] ) );
        }

        if ( array_key_exists( 'reading_status', $_POST ) ) {
            $rs = sanitize_key( wp_unslash( $_POST['reading_status'] ) );
            if ( in_array( $rs, self::allowed_reading_status(), true ) ) {
                $update['reading_status'] = $rs;
            }
        }

        $cp_name  = array_key_exists( 'counterparty_name',  $_POST ) ? sanitize_text_field( wp_unslash( $_POST['counterparty_name'] ) ) : null;
        $cp_email = array_key_exists( 'counterparty_email', $_POST ) ? sanitize_email( wp_unslash( $_POST['counterparty_email'] ) ) : null;
        if ( array_key_exists( 'counterparty_name', $_POST ) )  $update['counterparty_name']  = $cp_name;
        if ( array_key_exists( 'counterparty_email', $_POST ) ) $update['counterparty_email'] = ( $cp_email && is_email( $cp_email ) ) ? $cp_email : null;

        $effective_at = null;
        if ( ! empty( $_POST['owning_effective_date'] ) ) {
            $raw = sanitize_text_field( wp_unslash( $_POST['owning_effective_date'] ) );
            $ts = strtotime( $raw );
            if ( $ts && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ) {
                // Agrega hora actual del servidor
                $effective_at = gmdate('Y-m-d') . ' ' . gmdate('H:i:s');
            }            
        }
        if ( ! $effective_at ) {
            $effective_at = current_time( 'mysql', true );
        }

        if ( array_key_exists( 'owning_status', $_POST ) ) {
            $os = sanitize_key( wp_unslash( $_POST['owning_status'] ) );
            if ( in_array( $os, self::allowed_owning_status(), true ) ) {
                $update['owning_status'] = $os;

                self::handle_owning_transition( $row, $os, $effective_at, [
                    'counterparty_name'  => $cp_name,
                    'counterparty_email' => ( $cp_email && is_email( $cp_email ) ) ? $cp_email : null,
                ] );

                if ( ! in_array( $os, [ 'borrowed', 'borrowing', 'sold' ], true ) ) {
                    $update['counterparty_name']  = null;
                    $update['counterparty_email'] = null;
                }
            }
        } else {
            // Si no cambió el owning_status, pero sí hay datos de contacto, actualiza el préstamo activo
            if ( $cp_name || $cp_email ) {
                self::handle_owning_transition( $row, $row->owning_status, $effective_at, [
                    'counterparty_name'  => $cp_name,
                    'counterparty_email' => ( $cp_email && is_email( $cp_email ) ) ? $cp_email : null,
                ] );
            }
        }

        if ( empty( $update ) ) self::json_error( 'no_fields', 400 );

        $updated = self::update_user_book( $user_book_id, $update );
        self::json_success( $updated );
    }

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
        error_log("UPDATE user_book #$user_book_id: " . print_r($update, true));
        return $update;
    }

    private static function handle_owning_transition( $user_book_row, $owning_status, $effective_at_gmt, $contact ) {
        $user_id = (int) $user_book_row->user_id;
        $book_id = (int) $user_book_row->book_id;

        if ( in_array( $owning_status, [ 'borrowed', 'borrowing' ], true ) ) {
            $loan_id = self::get_active_loan_id( $user_id, $book_id );

            // Siempre cerrar préstamo previo, si existe
        if ( ! empty( $loan_id ) && $loan_id > 0 ) {
            self::close_active_loan( $user_id, $book_id, $effective_at_gmt );
        }

        // Abrir nuevo préstamo
        self::open_loan( $user_id, $book_id, $contact, $effective_at_gmt );

        } elseif ( in_array( $owning_status, [ 'in_shelf', 'sold', 'lost' ], true ) ) {
            self::close_active_loan( $user_id, $book_id, $effective_at_gmt );
        }
    }

    private static function loans_table() {
        global $wpdb;
        return $wpdb->prefix . 'politeia_loans';
    }

    private static function get_active_loan_id( $user_id, $book_id ) {
        global $wpdb;
        $t = self::loans_table();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$t} WHERE user_id=%d AND book_id=%d AND end_date IS NULL ORDER BY id DESC LIMIT 1",
            $user_id, $book_id
        ) );
    }

    private static function open_loan( $user_id, $book_id, $contact, $start_gmt ) {
        global $wpdb;
        $t = self::loans_table();
        $wpdb->insert( $t, [
            'user_id'           => $user_id,
            'book_id'           => $book_id,
            'counterparty_name' => $contact['counterparty_name'] ?? null,
            'counterparty_email'=> $contact['counterparty_email'] ?? null,
            'start_date'        => $start_gmt,
            'end_date'          => null,
            'created_at'        => current_time( 'mysql' ),
            'updated_at'        => current_time( 'mysql' ),
        ] );
        error_log("✅ OPEN LOAN para user_id=$user_id, book_id=$book_id");
        return (int) $wpdb->insert_id;
    }

    private static function update_loan_contact( $loan_id, $contact ) {
        global $wpdb;
        $t = self::loans_table();
        $wpdb->update( $t, [
            'counterparty_name'  => $contact['counterparty_name']  ?? null,
            'counterparty_email' => $contact['counterparty_email'] ?? null,
            'updated_at'         => current_time( 'mysql' ),
        ], [ 'id' => $loan_id ] );
        error_log("🔁 UPDATE LOAN #$loan_id: " . print_r($contact, true));
    }

    private static function close_active_loan( $user_id, $book_id, $end_gmt ) {
        global $wpdb;
        $t = self::loans_table();
        $loan_id = self::get_active_loan_id( $user_id, $book_id );
        if ( $loan_id ) {
            $wpdb->update( $t, [
                'end_date'   => $end_gmt,
                'updated_at' => current_time( 'mysql' ),
            ], [ 'id' => $loan_id ] );
            error_log("❌ CLOSED LOAN #$loan_id para user_id=$user_id, book_id=$book_id");
        }
    }

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

Politeia_Reading_User_Books::init();
