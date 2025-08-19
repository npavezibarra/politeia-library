<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Politeia_Reading_Sessions {

  public static function init() {
    add_action( 'wp_ajax_prs_start_reading', [ __CLASS__, 'ajax_start' ] );
    add_action( 'wp_ajax_prs_save_reading',  [ __CLASS__, 'ajax_save' ] );
  }

  /** START: valida y devuelve OK (no escribe en DB) */
  public static function ajax_start() {
    if ( ! is_user_logged_in() ) self::err('auth', 401);
    if ( ! self::nonce('prs_reading_nonce') ) self::err('bad_nonce', 403);

    $user_id = get_current_user_id();
    $book_id = isset($_POST['book_id']) ? absint($_POST['book_id']) : 0;
    if ( ! $book_id ) self::err('invalid_book', 400);
    if ( ! self::user_has_book($user_id, $book_id) ) self::err('forbidden', 403);

    $start_page = isset($_POST['start_page']) ? absint($_POST['start_page']) : 0;
    if ( $start_page <= 0 ) self::err('start_page_required', 400);

    // No necesitamos crear un registro aquí; solo confirmamos
    self::ok([ 'session_id' => null ]);
  }

  /** SAVE: inserta la sesión completa en DB */
  public static function ajax_save() {
    if ( ! is_user_logged_in() ) self::err('auth', 401);
    if ( ! self::nonce('prs_reading_nonce') ) self::err('bad_nonce', 403);

    $user_id = get_current_user_id();
    $book_id = isset($_POST['book_id']) ? absint($_POST['book_id']) : 0;
    if ( ! $book_id ) self::err('invalid_book', 400);
    if ( ! self::user_has_book($user_id, $book_id) ) self::err('forbidden', 403);

    $start_page = isset($_POST['start_page']) ? absint($_POST['start_page']) : 0;
    $end_page   = isset($_POST['end_page'])   ? absint($_POST['end_page'])   : 0;
    $chapter    = isset($_POST['chapter_name']) ? sanitize_text_field( wp_unslash($_POST['chapter_name']) ) : null;
    $dur        = isset($_POST['duration_sec']) ? max(0, absint($_POST['duration_sec'])) : 0;

    if ( $start_page <= 0 ) self::err('start_page_required', 400);
    if ( $end_page   <= 0 || $end_page < $start_page ) self::err('end_page_invalid', 400);

    global $wpdb;
    $tbl = $wpdb->prefix . 'politeia_reading_sessions';

    // Tiempos en GMT (como usas en otros módulos)
    $now_ts   = current_time( 'timestamp', true );           // GMT unix
    $start_ts = max(0, $now_ts - $dur);
    $start_gm = gmdate('Y-m-d H:i:s', $start_ts);
    $end_gm   = gmdate('Y-m-d H:i:s', $now_ts);

    $wpdb->insert( $tbl, [
      'user_id'      => (int) $user_id,
      'book_id'      => (int) $book_id,
      'start_time'   => $start_gm,
      'end_time'     => $end_gm,
      'start_page'   => (int) $start_page,
      'end_page'     => (int) $end_page,
      'chapter_name' => $chapter ? $chapter : null,
      'created_at'   => current_time( 'mysql', true ),
    ], [ '%d','%d','%s','%s','%d','%d','%s','%s' ] );

    if ( $wpdb->last_error ) {
      self::err( 'db_error: ' . $wpdb->last_error, 500 );
    }

    self::ok([ 'id' => (int) $wpdb->insert_id ]);
  }

  // ---------- helpers ----------
  private static function user_has_book( $user_id, $book_id ) {
    global $wpdb;
    $t = $wpdb->prefix . 'politeia_user_books';
    return (int) $wpdb->get_var( $wpdb->prepare(
      "SELECT id FROM {$t} WHERE user_id=%d AND book_id=%d LIMIT 1",
      $user_id, $book_id
    ) );
  }

  private static function nonce( $action, $keys = ['nonce','_ajax_nonce','security'] ) {
    foreach ( $keys as $k ) {
      if ( isset($_REQUEST[$k]) && wp_verify_nonce( $_REQUEST[$k], $action ) ) return true;
    }
    return false;
  }

  private static function err( $msg, $code = 400 ){ wp_send_json_error( [ 'message' => $msg ], $code ); }
  private static function ok ( $data = [] ){ wp_send_json_success( $data ); }
}

Politeia_Reading_Sessions::init();
