<?php
/**
 * Reading Sessions AJAX (start/save) con auto-started y auto-finished
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Politeia_Reading_Sessions {

    public static function init() {
        add_action( 'wp_ajax_prs_start_reading',   [ __CLASS__, 'ajax_start' ] );
        add_action( 'wp_ajax_prs_save_reading',    [ __CLASS__, 'ajax_save' ] );

        // Render parcial (tabla de sesiones + paginación) para AJAX en my-book-single.php
        add_action( 'wp_ajax_prs_render_sessions', [ __CLASS__, 'ajax_render_sessions' ] );
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

        $ub_row = self::get_user_book_row( $user_id, $book_id );
        if ( ! $ub_row ) self::err( 'forbidden', 403 );

        // Debe tener pages definidos
        $total_pages = (int) ($ub_row->pages ?? 0);
        if ( $total_pages <= 0 ) self::err( 'pages_required', 400 );

        // Bloqueo por estado de posesión
        $owning_status = (string) ($ub_row->owning_status ?? 'in_shelf');
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
        // Guardamos placeholder (end_time=end_time=start_time) por restricción NOT NULL
        $ins = [
            'user_id'     => (int) $user_id,
            'book_id'     => (int) $book_id,
            'start_time'  => $now_gmt,
            'end_time'    => $now_gmt,
            'start_page'  => max(1, min($start_page, $total_pages)),
            'end_page'    => max(1, min($start_page, $total_pages)),
            'chapter_name'=> $chapter ?: null,
        ];

        $ok = $wpdb->insert( $t, $ins, [ '%d','%d','%s','%s','%d','%d','%s' ] );
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

        $ub_row = self::get_user_book_row( $user_id, $book_id );
        if ( ! $ub_row ) self::err( 'forbidden', 403 );

        $total_pages = (int) ($ub_row->pages ?? 0);
        if ( $total_pages <= 0 ) self::err( 'pages_required', 400 );

        $owning_status = (string) ($ub_row->owning_status ?? 'in_shelf');
        if ( self::blocked_by_status( $owning_status ) ) {
            self::err( 'not_in_possession', 403 );
        }

        $start_page = isset($_POST['start_page']) ? absint($_POST['start_page']) : 0;
        $end_page   = isset($_POST['end_page'])   ? absint($_POST['end_page'])   : 0;

        // clamp a [1..pages]
        $start_page = max(1, min($start_page, $total_pages));
        $end_page   = max(1, min($end_page,   $total_pages));

        if ( $start_page < 1 || $end_page < 1 || $end_page < $start_page ) {
            self::err( 'invalid_pages', 400 );
        }

        $chapter = isset($_POST['chapter_name']) ? sanitize_text_field( wp_unslash($_POST['chapter_name']) ) : '';
        if ( strlen( $chapter ) > 255 ) $chapter = substr( $chapter, 0, 255 );

        $duration_sec = isset($_POST['duration_sec']) ? absint($_POST['duration_sec']) : 0;

        global $wpdb;
        $t = $wpdb->prefix . 'politeia_reading_sessions';

        $now_gmt = current_time( 'mysql', true ); // end_time GMT
        $end_ts   = strtotime( $now_gmt . ' +0 seconds' );
        $start_ts = $duration_sec > 0 ? max(0, $end_ts - $duration_sec) : $end_ts;
        $start_gmt = gmdate( 'Y-m-d H:i:s', $start_ts );

        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
        if ( $session_id > 0 ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id,user_id,book_id FROM {$t} WHERE id=%d LIMIT 1",
                $session_id
            ) );
            if ( $row && (int)$row->user_id === $user_id && (int)$row->book_id === $book_id ) {
                $wpdb->update( $t, [
                    'start_time'  => $start_gmt,
                    'end_time'    => $now_gmt,
                    'start_page'  => $start_page,
                    'end_page'    => $end_page,
                    'chapter_name'=> $chapter ?: null,
                ], [ 'id' => $session_id ], [ '%s','%s','%d','%d','%s' ], [ '%d' ] );
                if ( $wpdb->last_error ) self::err( 'db_update_failed', 500 );
            } else {
                $session_id = 0; // caer a inserción
            }
        }
        if ( $session_id === 0 ) {
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
            $session_id = (int) $wpdb->insert_id;
        }

        // 1) Auto-pasar a STARTED si estaba NOT_STARTED
        if ( (string)$ub_row->reading_status === 'not_started' ) {
            self::update_user_book_fields( (int)$ub_row->id, [
                'reading_status' => 'started',
            ] );
            // refresca $ub_row para decisiones siguientes
            $ub_row = self::get_user_book_row( $user_id, $book_id );
        }

        // 2) Calcular cobertura y auto-finished
        $coverage = self::coverage_stats( $user_id, $book_id, $total_pages );
        $has_full = $coverage['full'] ?? false;

        if ( $has_full ) {
            // si no es finished o es finished auto, lo ponemos finished auto
            $do_finish = false;
            $update = [ 'reading_status' => 'finished' ];
            if ( self::table_has_columns( 'politeia_user_books', [ 'finish_mode', 'finished_at' ] ) ) {
                $update['finish_mode'] = 'auto';
                $update['finished_at'] = $now_gmt;
            }
            if ( (string)$ub_row->reading_status !== 'finished' ) {
                $do_finish = true;
            } else {
                // está finished: solo lo tocamos si es auto o nulo
                if ( property_exists($ub_row,'finish_mode') ) {
                    $fm = (string)($ub_row->finish_mode ?? '');
                    if ( $fm === '' || $fm === 'auto' ) $do_finish = true;
                } else {
                    // si no existe la col, asumimos que podemos setear finished
                    $do_finish = true;
                }
            }
            if ( $do_finish ) {
                self::update_user_book_fields( (int)$ub_row->id, $update );
            }
        } else {
            // si estaba finished auto, revertir a started
            $was_finished_auto = false;
            if ( (string)$ub_row->reading_status === 'finished' ) {
                if ( property_exists($ub_row,'finish_mode') ) {
                    $was_finished_auto = ((string)$ub_row->finish_mode === 'auto');
                } else {
                    // sin columna, no sabríamos: no tocamos
                    $was_finished_auto = false;
                }
            }
            if ( $was_finished_auto ) {
                $update = [ 'reading_status' => 'started' ];
                // limpiar finished_at/finish_mode si existen
                if ( self::table_has_columns( 'politeia_user_books', [ 'finish_mode', 'finished_at' ] ) ) {
                    $update['finish_mode'] = null;
                    $update['finished_at'] = null;
                }
                self::update_user_book_fields( (int)$ub_row->id, $update );
            }
        }

        self::ok( [
            'session_id' => (int) $session_id,
            'start_time' => $start_gmt,
            'end_time'   => $now_gmt,
            'coverage'   => $coverage, // { covered, total, full }
        ] );
    }

    /* =========================
     * Cobertura: unión de intervalos
     * ========================= */
    private static function coverage_stats( $user_id, $book_id, $total_pages ) {
        $total_pages = (int) $total_pages;
        if ( $total_pages <= 0 ) {
            return [ 'covered' => 0, 'total' => 0, 'full' => false ];
        }
        $intervals = self::fetch_intervals( $user_id, $book_id );

        // normalizar y clamp
        $norm = [];
        foreach ( $intervals as $iv ) {
            $a = max(1, (int)$iv['s']);
            $b = min($total_pages, (int)$iv['e']);
            if ( $b < $a ) continue;
            $norm[] = [ $a, $b ];
        }
        if ( ! $norm ) return [ 'covered' => 0, 'total' => $total_pages, 'full' => false ];

        // unir
        usort( $norm, function($x,$y){ return $x[0] <=> $y[0]; } );
        $merged = [];
        $cur = $norm[0];
        for ( $i=1; $i<count($norm); $i++ ) {
            $iv = $norm[$i];
            if ( $iv[0] <= $cur[1] + 1 ) {
                // solapa o adyacente → unir
                $cur[1] = max($cur[1], $iv[1]);
            } else {
                $merged[] = $cur;
                $cur = $iv;
            }
        }
        $merged[] = $cur;

        // suma de longitudes (inclusivo)
        $covered = 0;
        foreach ( $merged as $m ) {
            $covered += ($m[1] - $m[0] + 1);
        }
        $covered = max(0, min($covered, $total_pages));

        return [
            'covered' => $covered,
            'total'   => $total_pages,
            'full'    => ($covered >= $total_pages),
        ];
    }

    private static function fetch_intervals( $user_id, $book_id ) {
        global $wpdb;
        $t = $wpdb->prefix . 'politeia_reading_sessions';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT start_page, end_page FROM {$t}
             WHERE user_id=%d AND book_id=%d AND end_time IS NOT NULL",
            $user_id, $book_id
        ), ARRAY_A );
        $out = [];
        if ( $rows ) {
            foreach ( $rows as $r ) {
                $s = (int)$r['start_page'];
                $e = (int)$r['end_page'];
                if ( $e < $s ) continue;
                $out[] = [ 's' => $s, 'e' => $e ];
            }
        }
        return $out;
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

    private static function update_user_book_fields( $user_book_id, $data ) {
        global $wpdb;
        $t = $wpdb->prefix . 'politeia_user_books';

        // Si las columnas extra no existen, no las mandamos
        if ( isset($data['finish_mode']) || isset($data['finished_at']) ) {
            if ( ! self::table_has_columns( 'politeia_user_books', [ 'finish_mode', 'finished_at' ] ) ) {
                unset( $data['finish_mode'], $data['finished_at'] );
            }
        }
        $data['updated_at'] = current_time( 'mysql' );

        $wpdb->update( $t, $data, [ 'id' => (int)$user_book_id ] );
    }

    private static function table_has_columns( $basename, $cols ) {
        global $wpdb;
        $t = $wpdb->prefix . $basename;
        foreach ( (array)$cols as $c ) {
            $found = $wpdb->get_var( $wpdb->prepare(
                "SHOW COLUMNS FROM {$t} LIKE %s", $c
            ) );
            if ( ! $found ) return false;
        }
        return true;
    }

    /* =========================
     * Paginated sessions
     * ========================= */

    /**
     * Devuelve sesiones paginadas para (user, book).
     * @return array { rows:[], total:int, max_pages:int, paged:int, per_page:int }
     */
    public static function get_sessions_page( $user_id, $book_id, $per_page = 15, $paged = 1, $orderby = 'start_time', $order = 'desc', $only_finished = true ) {
        global $wpdb;
        $t = $wpdb->prefix . 'politeia_reading_sessions';

        $user_id   = (int) $user_id;
        $book_id   = (int) $book_id;
        $per_page  = max(1, (int) $per_page);
        $paged     = max(1, (int) $paged);
        $offset    = ($paged - 1) * $per_page;

        $where = "WHERE user_id=%d AND book_id=%d";
        $args  = [ $user_id, $book_id ];

        if ( $only_finished ) {
            $where .= " AND end_time IS NOT NULL";
        }

        // Total para paginación
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} {$where}",
            ...$args
        ) );

        if ( $total === 0 ) {
            return [
                'rows'     => [],
                'total'    => 0,
                'max_pages'=> 0,
                'paged'    => $paged,
                'per_page' => $per_page,
            ];
        }

        $max_pages = (int) ceil( $total / $per_page );
        if ( $paged > $max_pages ) {
            $paged  = $max_pages;
            $offset = ($paged - 1) * $per_page;
        }
        
        // --- NEW: Dynamic and safe ORDER BY clause ---
        $order_clause = '';
        $order_dir = strtolower($order) === 'asc' ? 'ASC' : 'DESC'; // Sanitize direction

        switch ( $orderby ) {
            case 'duration':
                $order_clause = "ORDER BY (UNIX_TIMESTAMP(end_time) - UNIX_TIMESTAMP(start_time)) {$order_dir}, id DESC";
                break;
            case 'pages':
                $order_clause = "ORDER BY (end_page - start_page + 1) {$order_dir}, id DESC";
                break;
            case 'start_time':
            default:
                $order_clause = "ORDER BY start_time {$order_dir}, id DESC";
                break;
        }

        // Traer la página actual
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, start_time, end_time, start_page, end_page, chapter_name
             FROM {$t}
             {$where}
             {$order_clause}
             LIMIT %d OFFSET %d",
            ...array_merge( $args, [ $per_page, $offset ] )
        ) );

        return [
            'rows'      => $rows ?: [],
            'total'     => $total,
            'max_pages' => $max_pages,
            'paged'     => $paged,
            'per_page'  => $per_page,
        ];
    }

    /* =========================
     * AJAX: render parcial de sesiones
     * ========================= */
    public static function ajax_render_sessions() {
        if ( ! is_user_logged_in() ) self::err( 'auth', 401 );
        if ( ! self::verify_nonce( 'prs_sessions_nonce', [ 'nonce' ] ) ) self::err( 'bad_nonce', 403 );

        $user_id  = get_current_user_id();
        $book_id  = isset($_POST['book_id']) ? absint($_POST['book_id']) : 0;
        $paged    = isset($_POST['paged'])   ? max(1, absint($_POST['paged'])) : 1;
        $per_page = (int) apply_filters( 'politeia_reading_sessions_per_page', 15 );
        
        // --- NEW: Read sorting parameters ---
        $orderby = isset($_POST['orderby']) ? sanitize_key($_POST['orderby']) : 'start_time';
        $order   = isset($_POST['order'])   ? sanitize_key($_POST['order'])   : 'desc';


        if ( ! $book_id ) self::err('invalid_book', 400);

        // Pass sorting parameters to the get_sessions_page function
        $data = self::get_sessions_page( $user_id, $book_id, $per_page, $paged, $orderby, $order, true );
        
        // --- NEW: Helper function to generate header attributes ---
        $sort_attrs = function( $key ) use ( $orderby, $order ) {
            $class = 'prs-sortable';
            if ( $key === $orderby ) {
                $class .= ' ' . ($order === 'asc' ? 'asc' : 'desc');
            }
            return 'class="' . esc_attr($class) . '" data-sort="' . esc_attr($key) . '"';
        };

        // --- UPDATED: HTML table with sortable headers ---
        $html  = '<table class="prs-table"><thead><tr>';
        $html .= '<th ' . $sort_attrs('start_time') . '>' . esc_html__('Start','politeia-reading') . '</th>';
        $html .= '<th>' . esc_html__('End','politeia-reading') . '</th>';
        $html .= '<th ' . $sort_attrs('duration') . '>' . esc_html__('Duration','politeia-reading') . '</th>';
        $html .= '<th>' . esc_html__('Start Pg','politeia-reading') . '</th>';
        $html .= '<th>' . esc_html__('End Pg','politeia-reading') . '</th>';
        $html .= '<th ' . $sort_attrs('pages') . '>' . esc_html__('Pages','politeia-reading') . '</th>';
        $html .= '<th>' . esc_html__('Chapter','politeia-reading') . '</th>';
        $html .= '</tr></thead><tbody>';

        $total_seconds = 0; $total_pages_read = 0;

        foreach ( (array) $data['rows'] as $s ) {
            $start_local = $s->start_time ? get_date_from_gmt( $s->start_time, 'Y-m-d H:i' ) : '—';
            $end_local   = $s->end_time   ? get_date_from_gmt( $s->end_time,   'Y-m-d H:i' ) : '—';

            $sec = 0;
            if ( $s->start_time && $s->end_time ) {
                $sec = max(0, strtotime($s->end_time.' +0 seconds') - strtotime($s->start_time.' +0 seconds'));
            }
            $p_start = (int) $s->start_page;
            $p_end   = (int) $s->end_page;
            $pages_read = ($p_end >= $p_start) ? ($p_end - $p_start + 1) : 0;

            $total_seconds += $sec;
            $total_pages_read += $pages_read;

            $html .= '<tr>';
            $html .= '<td>' . esc_html($start_local) . '</td>';
            $html .= '<td>' . esc_html($end_local)   . '</td>';
            $html .= '<td>' . esc_html(self::hms($sec)) . '</td>';
            $html .= '<td>' . (int)$p_start . '</td>';
            $html .= '<td>' . (int)$p_end   . '</td>';
            $html .= '<td>' . (int)$pages_read . '</td>';
            $html .= '<td>' . ( $s->chapter_name ? esc_html($s->chapter_name) : '—' ) . '</td>';
            $html .= '</tr>';
        }

        if ( empty($data['rows']) ) {
            $html .= '<tr><td colspan="7">' . esc_html__('No sessions yet.','politeia-reading') . '</td></tr>';
        }

        $html .= '</tbody><tfoot><tr>';
        $html .= '<th colspan="2" style="text-align:right">' . esc_html__('Totals (this page):','politeia-reading') . '</th>';
        $html .= '<th>' . esc_html(self::hms($total_seconds)) . '</th>';
        $html .= '<th></th><th></th>';
        $html .= '<th>' . (int)$total_pages_read . '</th>';
        $html .= '<th></th>';
        $html .= '</tr></tfoot></table>';

        // Paginación con enlaces AJAX (data-page)
        if ( (int) $data['max_pages'] > 1 ) {
            $html .= '<nav class="prs-pagination" aria-label="' . esc_attr__('Sessions pagination','politeia-reading') . '"><ul class="page-numbers">';
            for ( $i = 1; $i <= (int)$data['max_pages']; $i++ ) {
                if ( $i === (int)$data['paged'] ) {
                    $html .= '<li><span class="page-numbers current">'.$i.'</span></li>';
                } else {
                    $html .= '<li><a href="#" class="page-numbers prs-sess-link" data-page="'.$i.'">'.$i.'</a></li>';
                }
            }
            $html .= '</ul></nav>';
        }

        self::ok( [
            'html'      => $html,
            'paged'     => (int) $data['paged'],
            'max_pages' => (int) $data['max_pages'],
        ] );
    }

    /** Helper: HH:MM:SS */
    private static function hms( $sec ){
        $sec = max(0, (int)$sec);
        $h = floor($sec/3600); $m = floor(($sec%3600)/60); $s = $sec%60;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
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