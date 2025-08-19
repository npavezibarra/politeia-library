<?php
/**
 * Shortcode: [politeia_start_reading book_id="..."]
 * UI en formato de tabla + estados: IDLE -> RUNNING -> STOPPED
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'politeia_start_reading', function( $atts ){
  if ( ! is_user_logged_in() ) {
    return '<p>You must be logged in.</p>';
  }

  $atts = shortcode_atts( [
    'book_id' => 0,
  ], $atts, 'politeia_start_reading' );

  $book_id = absint( $atts['book_id'] );
  if ( ! $book_id ) return '';

  global $wpdb;
  $user_id   = get_current_user_id();
  $tbl_rs    = $wpdb->prefix . 'politeia_reading_sessions';

  // Última página de la última sesión (si existe)
  $last_end_page = $wpdb->get_var( $wpdb->prepare(
    "SELECT end_page FROM {$tbl_rs}
     WHERE user_id = %d AND book_id = %d AND end_time IS NOT NULL
     ORDER BY end_time DESC LIMIT 1",
    $user_id, $book_id
  ) );

  // Encolar JS/CSS del recorder
  wp_enqueue_script( 'politeia-start-reading' ); // handle ya registrado en tu plugin
  wp_enqueue_style( 'politeia-reading' );

  // Pasar datos al JS
  wp_localize_script( 'politeia-start-reading', 'PRS_SR', [
    'ajax_url'      => admin_url('admin-ajax.php'),
    'nonce'         => wp_create_nonce('prs_reading_nonce'),
    'user_id'       => (int) $user_id,
    'book_id'       => (int) $book_id,
    'last_end_page' => is_null($last_end_page) ? '' : (int) $last_end_page,
    'actions'       => [
      'start' => 'prs_start_reading', // ← align with your backend
      'save'  => 'prs_save_reading',  // ← align with your backend
    ],
  ] );
  

  ob_start();
  ?>
  <style>
  .prs-sr { width:100%; }
  .prs-sr .prs-sr-head { margin:0 0 8px; }
  .prs-sr .prs-sr-last { color:#555; margin:4px 0 10px; }

  .prs-sr-table { width:100%; border-collapse: collapse; background:#fff; }
  .prs-sr-table th,
  .prs-sr-table td { padding:10px; border:1px solid #ddd; text-align:left; vertical-align:middle; }
  .prs-sr-table th { width:40%; background:#f6f6f6; }
  .prs-sr-input { width:100%; box-sizing:border-box; }

  /* Timer en fila completa */
  .prs-sr-row--full td { text-align:left; }
  .prs-sr-timer { font-size:24px; font-weight:600; padding:12px 0; }

  .prs-btn {
    padding:10px 14px;
    background:#111;
    color:#fff;
    border:none;
    cursor:pointer;
    box-shadow:none;
    outline:none;
  }
  .prs-btn[disabled] { opacity:.4; cursor:not-allowed; }
  .prs-btn:focus-visible { outline:2px solid #fff; outline-offset:2px; }

  /* === Botones a la derecha (Start/Stop/Save) === */
  .prs-sr-actions{
    display:flex;
    align-items:center;
    justify-content:flex-end;   /* ← derecha */
    gap:10px;
    width:100%;
  }
  /* El mensaje queda a la izquierda y empuja los botones a la derecha */
  .prs-sr-actions .prs-sr-status{
    order:-1;
    margin-right:auto;
    font-size:12px;
    color:#666;
  }

  .prs-sr-view { display:none; color:#222; }
</style>


  <div class="prs-sr" data-book-id="<?php echo (int) $book_id; ?>">
    <h2 class="prs-sr-head"><?php esc_html_e('Session recorder','politeia-reading'); ?></h2>

    <?php if ( $last_end_page ) : ?>
      <div class="prs-sr-last">
        <?php esc_html_e('Last session page', 'politeia-reading'); ?>:
        <strong><?php echo (int) $last_end_page; ?></strong>
      </div>
    <?php endif; ?>

    <table class="prs-sr-table" role="grid">
  <tbody>
    <!-- Start page -->
    <tr id="prs-sr-row-start">
      <th scope="row"><label for="prs-sr-start-page"><?php esc_html_e('Start page','politeia-reading'); ?>*</label></th>
      <td>
        <input type="number" min="1" id="prs-sr-start-page" class="prs-sr-input" />
        <span id="prs-sr-start-page-view" class="prs-sr-view"></span>
      </td>
    </tr>

    <!-- Capítulo -->
    <tr id="prs-sr-row-chapter">
      <th scope="row"><label for="prs-sr-chapter"><?php esc_html_e('Chapter','politeia-reading'); ?></label></th>
      <td>
        <input type="text" id="prs-sr-chapter" class="prs-sr-input" />
        <span id="prs-sr-chapter-view" class="prs-sr-view"></span>
      </td>
    </tr>

    <!-- Timer -->
    <tr id="prs-sr-row-timer" class="prs-sr-row--full">
      <td colspan="2">
        <div id="prs-sr-timer" class="prs-sr-timer">00:00:00</div>
      </td>
    </tr>

    <!-- Start/Stop Buttons -->
    <tr id="prs-sr-row-actions" class="prs-sr-row--full">
      <td colspan="2">
        <button type="button" id="prs-sr-start" class="prs-btn" disabled>▶ <?php esc_html_e('Start Reading','politeia-reading'); ?></button>
        <button type="button" id="prs-sr-stop" class="prs-btn" style="display:none;">■ <?php esc_html_e('Stop Reading','politeia-reading'); ?></button>
      </td>
    </tr>

    <!-- End Page (aparece tras Stop) -->
    <tr id="prs-sr-row-end" style="display:none;">
      <th scope="row"><label for="prs-sr-end-page"><?php esc_html_e('End Page','politeia-reading'); ?>*</label></th>
      <td>
        <input type="number" min="1" id="prs-sr-end-page" class="prs-sr-input" />
      </td>
    </tr>

    <!-- Guardar sesión (aparece tras Stop) -->
    <tr id="prs-sr-row-save" class="prs-sr-row--full" style="display:none;">
      <td colspan="2">
        <button type="button" id="prs-sr-save" class="prs-btn" disabled><?php esc_html_e('Save Session','politeia-reading'); ?></button>
      </td>
    </tr>
  </tbody>
</table>
  </div>
  <?php
  return ob_get_clean();
});
