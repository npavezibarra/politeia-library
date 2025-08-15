<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode('politeia_my_books', function () {
    if ( ! is_user_logged_in() ) {
        return '<p>' . esc_html__('You must be logged in to view your library.', 'politeia-reading') . '</p>';
    }

    wp_enqueue_style('politeia-reading');
    wp_enqueue_script('politeia-my-book');

    $user_id = get_current_user_id();
    global $wpdb;
    $ub = $wpdb->prefix . 'politeia_user_books';
    $b  = $wpdb->prefix . 'politeia_books';

    // ⬇️ Traer también el slug
    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT ub.id AS user_book_id,
               ub.reading_status,
               ub.owning_status,
               b.id   AS book_id,
               b.title,
               b.author,
               b.year,
               b.cover_attachment_id,
               b.slug
        FROM $ub ub
        JOIN $b b ON b.id = ub.book_id
        WHERE ub.user_id = %d
        ORDER BY b.title ASC
    ", $user_id));

    if ( ! $rows ) {
        return '<p>' . esc_html__('Your library is empty. Add a book first.', 'politeia-reading') . '</p>';
    }

    ob_start(); ?>
    <div class="prs-library">
      <table class="prs-table">
        <thead>
          <tr>
            <th><?php esc_html_e('Cover', 'politeia-reading'); ?></th>
            <th><?php esc_html_e('Title', 'politeia-reading'); ?></th>
            <th><?php esc_html_e('Author', 'politeia-reading'); ?></th>
            <th><?php esc_html_e('Year', 'politeia-reading'); ?></th>
            <th><?php esc_html_e('Reading Status', 'politeia-reading'); ?></th>
            <th><?php esc_html_e('Owning Status', 'politeia-reading'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ( $rows as $r ): ?>
            <tr data-user-book-id="<?php echo (int) $r->user_book_id; ?>">
              <td style="width:60px">
                <?php
                if ( $r->cover_attachment_id ) {
                    echo wp_get_attachment_image(
                        (int) $r->cover_attachment_id,
                        'thumbnail',
                        false,
                        [ 'style' => 'max-width:50px;height:auto;border-radius:4px', 'alt' => $r->title ]
                    );
                } else {
                    echo '<div style="width:50px;height:70px;background:#eee;border-radius:4px;"></div>';
                }
                ?>
              </td>
              <td>
                <?php
                  // ⬇️ Fallback si no hay slug almacenado
                  $slug = $r->slug ?: sanitize_title( $r->title . '-' . $r->author . ( $r->year ? '-' . $r->year : '' ) );
                  $url  = home_url( '/my-books/my-book-' . $slug );
                ?>
                <a href="<?php echo esc_url( $url ); ?>">
                  <?php echo esc_html( $r->title ); ?>
                </a>
              </td>
              <td><?php echo esc_html( $r->author ); ?></td>
              <td><?php echo $r->year ? (int) $r->year : '—'; ?></td>
              <td>
                <select class="prs-reading-status">
                  <?php
                    $reading = [
                      'not_started' => __( 'Not Started', 'politeia-reading' ),
                      'started'     => __( 'Started', 'politeia-reading' ),
                      'finished'    => __( 'Finished', 'politeia-reading' ),
                    ];
                    foreach ( $reading as $val => $label ) {
                        printf(
                            '<option value="%s"%s>%s</option>',
                            esc_attr( $val ),
                            selected( $r->reading_status, $val, false ),
                            esc_html( $label )
                        );
                    }
                  ?>
                </select>
              </td>
              <td>
                <select class="prs-owning-status">
                  <?php
                    $owning = [
                      'in_shelf'  => __( 'In Shelf', 'politeia-reading' ),
                      'lost'      => __( 'Lost', 'politeia-reading' ),
                      'borrowed'  => __( 'Borrowed', 'politeia-reading' ),
                      'borrowing' => __( 'Borrowing', 'politeia-reading' ),
                      'sold'      => __( 'Sold', 'politeia-reading' ),
                    ];
                    foreach ( $owning as $val => $label ) {
                        printf(
                            '<option value="%s"%s>%s</option>',
                            esc_attr( $val ),
                            selected( $r->owning_status, $val, false ),
                            esc_html( $label )
                        );
                    }
                  ?>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php wp_nonce_field( 'prs_update_user_book', 'prs_update_user_book_nonce' ); ?>
    </div>
    <?php
    return ob_get_clean();
});
