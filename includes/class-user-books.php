<?php
/**
 * User Books AJAX handlers (Loans, estados y metadatos)
 * - "In Shelf" es estado DERIVADO: owning_status NULL/'' => In Shelf
 * - owning_status válido (persistido): borrowed, borrowing, sold, lost
 * - Loans idempotentes (a lo más 1 abierto por (user, book))
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Politeia_Reading_User_Books {

    public static function init() {
        add_action( 'wp_ajax_prs_update_user_book',       [ __CLASS__, 'ajax_update_user_book' ] );
        add_action( 'wp_ajax_prs_update_user_book_meta',  [ __CLASS__, 'ajax_update_user_book_meta' ] );
        // (Sin acciones de portada/imagen)
    }

    /* ============================================================
     * AJAX: update simple (reading_status / owning_status derivado)
     * ============================================================ */
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

        // reading_status (opcional)
        if ( isset( $_POST['reading_status'] ) ) {
            $rs = sanitize_key( wp_unslash( $_POST['reading_status'] ) );
            if ( in_array( $rs, self::allowed_reading_status(), true ) ) {
                $update['reading_status'] = $rs;
            }
        }

        // owning_status (DERIVADO: vacío => volver a In Shelf)
        if ( array_key_exists( 'owning_status', $_POST ) ) {
            $raw = wp_unslash( $_POST['owning_status'] );
            $os  = sanitize_key( $raw );
            $now = current_time( 'mysql', true );

            if ( $raw === '' || $raw === null ) {
                // Volver a "In Shelf"
                $update['owning_status']      = null;
                $update['counterparty_name']  = null;
                $update['counterparty_email'] = null;
                self::close_open_loan( (int)$row->user_id, (int)$row->book_id, $now );
            } elseif ( in_array( $os, self::allowed_owning_status(), true ) ) {
                $update['owning_status'] = $os;

                if ( $os === 'borrowed' || $os === 'borrowing' ) {
                    // Solo asegura loan si hay contacto (la ensure_open_loan no crea si falta)
                    self::ensure_open_loan( (int)$row->user_id, (int)$row->book_id, [], $now );
                } else { // sold / lost
                    self::close_open_loan( (int)$row->user_id, (int)$row->book_id, $now );
                }

                if ( $os === 'lost' ) {
                    $update['counterparty_name']  = null;
                    $update['counterparty_email'] = null;
                }
            }
        }

        if ( empty( $update ) ) self::json_error( 'no_fields', 400 );

        $updated = self::update_user_book( $user_book_id, $update );
        self::json_success( $updated );
    }

    /* ==================================================================================
     * AJAX: update meta granular (pages, purchase_*, contact, reading_status, rating)
     * ================================================================================== */
    public static function ajax_update_user_book_meta() {
        if ( ! is_user_logged_in() ) self::json_error( 'auth', 401 );

        // Acepta cualquiera de los dos nonces
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

        // ====== METADATOS ======
        if ( array_key_exists( 'pages', $_POST ) ) {
            $p = absint( $_POST['pages'] );
            $update['pages'] = $p > 0 ? $p : null;
        }
        if ( array_key_exists( 'purchase_date', $_POST ) ) {
            $d = sanitize_text_field( wp_unslash( $_POST['purchase_date'] ) );
            $update['purchase_date'] = ( $d && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) ? $d : null;
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

        // ====== RATING ======
        if ( array_key_exists( 'rating', $_POST ) ) {
            $r = is_numeric( $_POST['rating'] ) ? (int) $_POST['rating'] : null;
            if ( is_int( $r ) ) {
                if ( $r < 0 ) $r = 0;
                if ( $r > 5 ) $r = 5;
                $update['rating'] = $r;
            } else {
                $update['rating'] = null; // permitir limpiar
            }
        }

        // ====== CONTACTO ======
        $cp_name_raw  = array_key_exists( 'counterparty_name',  $_POST ) ? wp_unslash( $_POST['counterparty_name'] )  : null;
        $cp_email_raw = array_key_exists( 'counterparty_email', $_POST ) ? wp_unslash( $_POST['counterparty_email'] ) : null;
        $cp_name      = isset( $cp_name_raw )  ? sanitize_text_field( $cp_name_raw ) : null;
        $cp_email     = isset( $cp_email_raw ) ? sanitize_email( $cp_email_raw )     : null;

        $both_empty           = ( '' === trim( (string) $cp_name ) ) && ( '' === trim( (string) $cp_email ) );
        $requires_contact_now = in_array( $row->owning_status, [ 'borrowed', 'borrowing', 'sold' ], true );

        if ( ( $both_empty ) && ( $requires_contact_now )
             && ( array_key_exists( 'counterparty_name', $_POST ) || array_key_exists( 'counterparty_email', $_POST ) ) ) {
            self::json_error( 'contact_required', 400 );
        }

        if ( array_key_exists( 'counterparty_name', $_POST ) )  $update['counterparty_name']  = $cp_name;
        if ( array_key_exists( 'counterparty_email', $_POST ) ) $update['counterparty_email'] = ( $cp_email && is_email( $cp_email ) ) ? $cp_email : null;

        // ====== FECHA EFECTIVA (UTC) ======
        $effective_at = null;
        if ( ! empty( $_POST['owning_effective_date'] ) ) {
            $raw = sanitize_text_field( wp_unslash( $_POST['owning_effective_date'] ) );
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
                $effective_at = $raw . ' ' . gmdate( 'H:i:s' );
            }
        }
        if ( ! $effective_at ) $effective_at = current_time( 'mysql', true );

        // ====== OWNING STATUS (DERIVADO) ======
        if ( array_key_exists( 'owning_status', $_POST ) ) {
            $raw = wp_unslash( $_POST['owning_status'] );
            $os  = sanitize_key( $raw );

            if ( $raw === '' || $raw === null ) {
                $update['owning_status']     = null;
                $update['counterparty_name']  = null;
                $update['counterparty_email'] = null;
                self::close_open_loan( (int) $row->user_id, (int) $row->book_id, $effective_at );
            } elseif ( in_array( $os, self::allowed_owning_status(), true ) ) {
                $update['owning_status'] = $os;

                if ( $os === 'borrowed' || $os === 'borrowing' ) {
                    // Asegura loan abierto; actualiza contacto si vino en el mismo POST
                    self::ensure_open_loan( (int) $row->user_id, (int) $row->book_id, [
                        'counterparty_name'  => $cp_name,
                        'counterparty_email' => ( $cp_email && is_email( $cp_email ) ) ? $cp_email : null,
                    ], $effective_at );
                } else { // sold / lost
                    self::close_open_loan( (int) $row->user_id, (int) $row->book_id, $effective_at );
                }

                // LOST: limpia contacto
                if ( $os === 'lost' ) {
                    $update['counterparty_name']  = null;
                    $update['counterparty_email'] = null;
                }
            }
        } else {
            // No cambió owning_status: si llega contacto y el estado actual requiere,
            // actualiza el loan abierto (no crear uno nuevo si no corresponde)
            if ( ( $cp_name || $cp_email ) && in_array( $row->owning_status, [ 'borrowed', 'borrowing' ], true ) ) {
                self::ensure_open_loan( (int) $row->user_id, (int) $row->book_id, [
                    'counterparty_name'  => $cp_name,
                    'counterparty_email' => ( $cp_email && is_email( $cp_email ) ) ? $cp_email : null,
                ], $effective_at );
            }
        }

        if ( empty( $update ) ) self::json_error( 'no_fields', 400 );

        $updated = self::update_user_book( $user_book_id, $update );
        self::json_success( $updated );
    }

    /* =========================
     * Validaciones permitidas
     * ========================= */
    private static function allowed_reading_status() {
        return [ 'not_started', 'started', 'finished' ];
    }
    private static function allowed_owning_status() {
        // In Shelf se representa con NULL/'' (derivado)
        return [ 'borrowed', 'borrowing', 'sold', 'lost' ];
    }

    /* =========================
     * DB helpers
     * ========================= */
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
        $update['updated_at'] = current_time( 'mysql', true ); // UTC
        $wpdb->update( $t, $update, [ 'id' => $user_book_id ] );
        return $update;
    }

    /* ==============================================
     * LOANS: idempotentes (evitan duplicados)
     * ============================================== */

    private static function loans_table() {
        global $wpdb;
        return $wpdb->prefix . 'politeia_loans';
    }

    private static function get_active_loan_id( $user_id, $book_id ) {
        global $wpdb;
        $t = self::loans_table();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$t}
             WHERE user_id=%d AND book_id=%d AND end_date IS NULL
             ORDER BY id DESC LIMIT 1",
            $user_id, $book_id
        ) );
    }

    /**
     * Asegura un único loan abierto por (user, book):
     * - Si existe, actualiza (contacto/updated_at).
     * - Si no existe y hay contacto, inserta con start_date = $start_gmt.
     *   (Si NO hay contacto, no crea nada).
     */
    private static function ensure_open_loan( $user_id, $book_id, $data = [], $start_gmt = null ) {
        global $wpdb;
        $t   = self::loans_table();
        $now = current_time( 'mysql', true );

        $open_id = self::get_active_loan_id( $user_id, $book_id );
        if ( $open_id ) {
            $row = [ 'updated_at' => $now ];
            if ( array_key_exists( 'counterparty_name', $data ) )  $row['counterparty_name']  = $data['counterparty_name'];
            if ( array_key_exists( 'counterparty_email', $data ) ) $row['counterparty_email'] = $data['counterparty_email'];
            $wpdb->update( $t, $row, [ 'id' => $open_id ] );
            return $open_id;
        }

        // Si NO hay contacto, NO insertes un loan vacío
        $has_contact = ! empty( $data['counterparty_name'] ) || ! empty( $data['counterparty_email'] );
        if ( ! $has_contact ) {
            return 0;
        }

        // Insertar nuevo
        $start = $start_gmt ?: $now;
        $wpdb->insert( $t, [
            'user_id'           => (int) $user_id,
            'book_id'           => (int) $book_id,
            'counterparty_name' => $data['counterparty_name']  ?? null,
            'counterparty_email'=> $data['counterparty_email'] ?? null,
            'start_date'        => $start,
            'end_date'          => null,
            'created_at'        => $now,
            'updated_at'        => $now,
        ], [ '%d','%d','%s','%s','%s','%s','%s','%s' ] );
        return (int) $wpdb->insert_id;
    }

    /** Cierra cualquier loan abierto del par (user, book). */
    private static function close_open_loan( $user_id, $book_id, $end_gmt ) {
        global $wpdb;
        $t   = self::loans_table();
        $now = current_time( 'mysql', true );
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$t}
             SET end_date=%s, updated_at=%s
             WHERE user_id=%d AND book_id=%d AND end_date IS NULL",
            $end_gmt, $now, $user_id, $book_id
        ) );
    }

    /* =========================
     * Nonces & JSON helpers
     * ========================= */
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

    /* ===== (Sin métodos de portada/imagen) ===== */
}

Politeia_Reading_User_Books::init();
