<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'politeia_start_reading', function( $atts = [] ) {
    if ( ! is_user_logged_in() ) {
        return '<p>' . esc_html__( 'You must be logged in to start a reading session.', 'politeia-reading' ) . '</p>';
    }

    // 1) Atributos del shortcode
    $atts = shortcode_atts( ['book_id' => 0], $atts, 'politeia_start_reading' );
    $selected_id = absint( $atts['book_id'] );

    wp_enqueue_style( 'politeia-reading' );
    wp_enqueue_script( 'politeia-start-reading' );

    $user_id = get_current_user_id();
    global $wpdb;
    $table_user_books = $wpdb->prefix . 'politeia_user_books';
    $table_books      = $wpdb->prefix . 'politeia_books';

    // 2) Libros del usuario
    $my_books = $wpdb->get_results( $wpdb->prepare("
        SELECT ub.book_id, b.title, b.author
        FROM {$table_user_books} ub
        JOIN {$table_books} b ON ub.book_id = b.id
        WHERE ub.user_id = %d
        ORDER BY b.title ASC
    ", $user_id) );

    // 3) Si viene book_id, validar que pertenezca a la biblioteca del usuario
    if ( $selected_id ) {
        $owned_ids = array_map( 'intval', wp_list_pluck( (array) $my_books, 'book_id' ) );
        if ( ! in_array( $selected_id, $owned_ids, true ) ) {
            $selected_id = 0;
        }
    }

    // Etiqueta legible del libro seleccionado (si ocultamos el select)
    $selected_label = '';
    if ( $selected_id ) {
        foreach ( (array) $my_books as $row ) {
            if ( (int) $row->book_id === $selected_id ) {
                $selected_label = "{$row->title} — {$row->author}";
                break;
            }
        }
    }

    ob_start();

    // Aviso de éxito (dentro del buffer del shortcode)
    if ( ! empty($_GET['prs_session_saved']) && $_GET['prs_session_saved'] === '1' ) {
        echo '<div class="prs-notice prs-notice--success">' .
             esc_html__( 'Reading session saved.', 'politeia-reading' ) .
             '</div>';
    }
    ?>
    <div class="prs-start-reading">
        <form id="prs-start-reading-form"
              class="prs-form"
              method="post"
              action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'prs_start_reading', 'prs_nonce' ); ?>
            <input type="hidden" name="action" value="prs_save_session" />
            <input type="hidden" name="prs_start_time" id="prs_start_time" value="" />
            <input type="hidden" name="prs_end_time" id="prs_end_time" value="" />
            <input type="hidden" name="prs_elapsed" id="prs_elapsed" value="" />

            <?php if ( $selected_id ): ?>
                <!-- Solo texto + hidden cuando hay book_id -->
                <div class="prs-book-fixed">
                    <span class="prs-label"><?php _e('Book', 'politeia-reading'); ?>*</span>
                    <div class="prs-value"><?php echo esc_html( $selected_label ); ?></div>
                </div>
                <input type="hidden" name="prs_book_id" value="<?php echo (int) $selected_id; ?>" />
            <?php else: ?>
                <!-- Selector normal cuando NO hay book_id -->
                <label><?php _e('Book','politeia-reading'); ?>*
                    <select name="prs_book_id" required>
                        <option value=""><?php _e('Choose a book…','politeia-reading'); ?></option>
                        <?php foreach ( (array) $my_books as $row ): ?>
                            <option value="<?php echo (int) $row->book_id; ?>">
                                <?php echo esc_html( "{$row->title} — {$row->author}" ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>

            <label><?php _e('Start Page','politeia-reading'); ?>*
                <input type="number" name="prs_start_page" id="prs_start_page" min="1" required />
            </label>

            <label><?php _e('Chapter (optional)','politeia-reading'); ?>
                <input type="text" name="prs_chapter_name" />
            </label>

            <div class="prs-timer">
                <div id="prs-timer-display">00:00:00</div>
                <button type="button" id="prs-start-btn" class="prs-btn"><?php _e('Start Reading','politeia-reading'); ?></button>
                <button type="button" id="prs-stop-btn" class="prs-btn" disabled><?php _e('Stop Reading','politeia-reading'); ?></button>
            </div>

            <div id="prs-end-fields" style="display:none;">
                <label><?php _e('End Page','politeia-reading'); ?>*
                    <input type="number" name="prs_end_page" id="prs_end_page" min="1" />
                </label>
                <button class="prs-btn" type="submit"><?php _e('Save Session','politeia-reading'); ?></button>
            </div>
        </form>
    </div>
    <?php
    return ob_get_clean();
});

// Save session
add_action( 'admin_post_prs_save_session', 'prs_save_session_handler' );
add_action( 'admin_post_nopriv_prs_save_session', 'prs_save_session_handler' );

function prs_save_session_handler() {
    if ( ! is_user_logged_in() ) wp_die( 'Login required.' );
    if ( ! isset( $_POST['prs_nonce'] ) || ! wp_verify_nonce( $_POST['prs_nonce'], 'prs_start_reading' ) ) wp_die( 'Invalid nonce.' );

    $user_id      = get_current_user_id();
    $book_id      = absint( $_POST['prs_book_id'] ?? 0 );
    $start_page   = absint( $_POST['prs_start_page'] ?? 0 );
    $end_page     = absint( $_POST['prs_end_page'] ?? 0 );
    $chapter_name = sanitize_text_field( $_POST['prs_chapter_name'] ?? '' );

    $start_time = sanitize_text_field( $_POST['prs_start_time'] ?? '' );
    $end_time   = sanitize_text_field( $_POST['prs_end_time'] ?? '' );

    // 1) Validaciones básicas
    if ( ! $book_id || $start_page < 1 || $end_page < $start_page || ! $start_time || ! $end_time ) {
        wp_safe_redirect( wp_get_referer() ?: home_url() );
        exit;
    }

    // 2) Seguridad: verificar que el book_id pertenezca al usuario
    global $wpdb;
    $table_user_books = $wpdb->prefix . 'politeia_user_books';
    $owned = $wpdb->get_var( $wpdb->prepare("
        SELECT id FROM {$table_user_books} WHERE user_id=%d AND book_id=%d LIMIT 1
    ", $user_id, $book_id ) );

    if ( ! $owned ) {
        wp_die( 'This book is not in your library.' );
    }

    // 3) Insertar sesión
    $table = $wpdb->prefix . 'politeia_reading_sessions';

    $wpdb->insert( $table, [
        'user_id'     => $user_id,
        'book_id'     => $book_id,
        'start_time'  => gmdate( 'Y-m-d H:i:s', strtotime( $start_time ) ),
        'end_time'    => gmdate( 'Y-m-d H:i:s', strtotime( $end_time ) ),
        'start_page'  => $start_page,
        'end_page'    => $end_page,
        'chapter_name'=> $chapter_name ?: null,
        'created_at'  => current_time( 'mysql' ),
    ] );

    $url = add_query_arg( 'prs_session_saved', 1, wp_get_referer() ?: home_url() );
    wp_safe_redirect( $url );
    exit;
}
