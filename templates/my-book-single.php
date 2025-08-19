<?php
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

if ( ! is_user_logged_in() ) {
  echo '<div class="wrap"><p>You must be logged in.</p></div>';
  get_footer(); exit;
}

global $wpdb;
$user_id = get_current_user_id();
$slug    = get_query_var('prs_book_slug');

$tbl_b     = $wpdb->prefix . 'politeia_books';
$tbl_ub    = $wpdb->prefix . 'politeia_user_books';
$tbl_rs    = $wpdb->prefix . 'politeia_reading_sessions';
$tbl_loans = $wpdb->prefix . 'politeia_loans';

$book = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$tbl_b} WHERE slug=%s LIMIT 1", $slug) );
if ( ! $book ) { status_header(404); echo '<div class="wrap"><h1>Not found</h1></div>'; get_footer(); exit; }

$ub = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$tbl_ub} WHERE user_id=%d AND book_id=%d LIMIT 1", $user_id, $book->id) );
if ( ! $ub ) { status_header(403); echo '<div class="wrap"><h1>No access</h1><p>This book is not in your library.</p></div>'; get_footer(); exit; }

/** Contacto ya guardado (definir antes de localize) */
$has_contact = ( ! empty($ub->counterparty_name) ) || ( ! empty($ub->counterparty_email) );

/** Sesiones */
$sessions = $wpdb->get_results( $wpdb->prepare("
  SELECT id, start_time, end_time, start_page, end_page, chapter_name
  FROM {$tbl_rs}
  WHERE user_id=%d AND book_id=%d
  ORDER BY start_time DESC
", $user_id, $book->id) );

/** Préstamo activo (fecha local) */
$active_start_gmt = $wpdb->get_var( $wpdb->prepare(
  "SELECT start_date FROM {$tbl_loans}
   WHERE user_id=%d AND book_id=%d AND end_date IS NULL
   ORDER BY id DESC LIMIT 1",
  $user_id, $book->id
) );
$active_start_local = $active_start_gmt ? get_date_from_gmt( $active_start_gmt, 'Y-m-d' ) : '';

function prs_hms($sec){
  $h = floor($sec/3600); $m = floor(($sec%3600)/60); $s = $sec%60;
  return sprintf('%02d:%02d:%02d', $h, $m, $s);
}
$total_pages = 0; $total_seconds = 0;

/** Assets */
wp_enqueue_script( 'politeia-my-book' );
wp_localize_script( 'politeia-my-book', 'PRS_BOOK', [
  'ajax_url'      => admin_url('admin-ajax.php'),
  'nonce'         => wp_create_nonce('prs_update_user_book_meta'),
  'user_book_id'  => (int) $ub->id,
  'owning_status' => (string) $ub->owning_status, // '' si es NULL
  'has_contact'   => $has_contact ? 1 : 0,
] );
wp_enqueue_style( 'politeia-reading' );
?>
<style>
  /* Maqueta provisional del single */
  .prs-single-grid{
    display:grid;
    grid-template-columns: 280px 1fr 1fr;
    grid-template-rows: auto auto auto;
    gap:24px;
    margin: 16px 0 32px;
  }
  .prs-box{
    border:2px solid #000;
    background:#f9f9f9;
    padding:16px;
    min-height:120px;
  }
  #prs-book-cover{ grid-column:1; grid-row:1 / span 2; min-height:420px; }
  #prs-book-info{ grid-column:2; grid-row:1; min-height:140px; }
  #prs-session-recorder{ grid-column:3; grid-row:1; min-height:140px; }
  #prs-reading-sessions{ grid-column:1 / 4; grid-row:3; min-height:320px; }

  .prs-cover-img{ width:100%; height:auto; max-height:100%; object-fit:cover; display:block; }
  .prs-cover-placeholder{ width:100%; height:100%; background:#eee; }
  .prs-box h2{ margin:0 0 8px; }
  .prs-meta{ color:#555; margin-top:6px; }

  /* Tabla sesiones */
  .prs-table { width:100%; border-collapse: collapse; background:#fff; }
  .prs-table th, .prs-table td { padding:8px 10px; border-bottom:1px solid #eee; text-align:left; }

  /* Fila/inputs dentro de #prs-book-info */
  .prs-field{ margin-top:12px; }
  .prs-field .label{ font-weight:600; display:block; margin-bottom:4px; }
  .prs-inline-actions{ margin-left:8px; }
  .prs-inline-actions a{ margin-left:8px; }
  .prs-help{ color:#666; font-size:12px; }

  /* Contact form (3 filas) */
  .prs-contact-form{
    display: grid;
    grid-template-columns: 120px minmax(240px, 1fr);
    gap: 10px 12px;
    max-width: 600px;
    margin: 0px !important;
  }
  .prs-contact-label{ align-self: center; font-weight: 600; }
  .prs-contact-input{ width: 100%; }
  .prs-contact-actions{ grid-column: 2; display:flex; align-items:center; gap:10px; }
  #owning-contact-view{ margin:6px 0 0 10px; color:#555; }

  @media (max-width: 900px){
    .prs-single-grid{ grid-template-columns: 1fr; grid-template-rows:auto; }
    #prs-book-cover{ grid-row:auto; min-height:260px; }
    #prs-book-info, #prs-session-recorder, #prs-reading-sessions{ grid-column:1; }
    .prs-contact-form{ grid-template-columns: 1fr; margin-left:0; }
    .prs-contact-actions{ grid-column:1; }
  }
</style>

<div class="wrap">
  <p><a href="<?php echo esc_url( home_url('/my-books') ); ?>">&larr; Back to My Books</a></p>

  <div class="prs-single-grid">

    <!-- Columna izquierda: portada -->
    <div id="prs-book-cover" class="prs-box">
      <?php
        if ( ! empty( $book->cover_attachment_id ) ) {
          echo wp_get_attachment_image( (int)$book->cover_attachment_id, 'large', false, ['class'=>'prs-cover-img','alt'=>$book->title] );
        } else {
          echo '<div class="prs-cover-placeholder"></div>';
        }
      ?>
    </div>

    <!-- Arriba centro: título/info y metacampos -->
    <div id="prs-book-info" class="prs-box">
      <h2 style="margin:0;"><?php echo esc_html( $book->title ); ?></h2>
      <div class="prs-meta">
        <strong><?php echo esc_html( $book->author ); ?></strong>
        <?php echo $book->year ? ' · ' . (int)$book->year : ''; ?>
      </div>
      <div class="prs-meta" aria-hidden="true" style="letter-spacing:2px; margin-top:8px;">★★★★★</div>

      <!-- Pages -->
      <div class="prs-field" id="fld-pages">
        <hr style="margin-bottom: 10px">
        <span class="label"><?php esc_html_e('Pages', 'politeia-reading'); ?></span>
        <span id="pages-view"><?php echo $ub->pages ? (int)$ub->pages : '—'; ?></span>
        <a href="#" id="pages-edit" class="prs-inline-actions"><?php esc_html_e('edit', 'politeia-reading'); ?></a>
        <span id="pages-form" style="display:none;" class="prs-inline-actions">
          <input type="number" id="pages-input" min="1" style="width:120px"
                 value="<?php echo $ub->pages ? (int)$ub->pages : ''; ?>" />
          <button type="button" id="pages-save" class="prs-btn" style="padding:4px 10px;">Save</button>
          <button type="button" id="pages-cancel" class="prs-btn" style="padding:4px 10px; background:#777;">Cancel</button>
          <span id="pages-status" class="prs-help"></span>
        </span>
      </div>

      <!-- Purchase Date -->
      <div class="prs-field" id="fld-purchase-date">
      <hr style="margin-bottom: 10px">
        <span class="label"><?php esc_html_e('Purchase Date', 'politeia-reading'); ?></span>
        <span id="purchase-date-view"><?php echo $ub->purchase_date ? esc_html($ub->purchase_date) : '—'; ?></span>
        <a href="#" id="purchase-date-edit" class="prs-inline-actions"><?php esc_html_e('edit', 'politeia-reading'); ?></a>
        <span id="purchase-date-form" style="display:none;" class="prs-inline-actions">
          <input type="date" id="purchase-date-input" value="<?php echo $ub->purchase_date ? esc_attr($ub->purchase_date) : ''; ?>" />
          <button type="button" id="purchase-date-save" class="prs-btn" style="padding:4px 10px;">Save</button>
          <button type="button" id="purchase-date-cancel" class="prs-btn" style="padding:4px 10px; background:#777;">Cancel</button>
          <span id="purchase-date-status" class="prs-help"></span>
        </span>
      </div>

      <!-- Purchase Channel + Which? -->
      <div class="prs-field" id="fld-purchase-channel">
      <hr style="margin-bottom: 10px">
        <span class="label"><?php esc_html_e('Purchase Channel', 'politeia-reading'); ?></span>
        <span id="purchase-channel-view">
          <?php
            $label = '—';
            if ( $ub->purchase_channel ) {
              $label = ucfirst( $ub->purchase_channel );
              if ( $ub->purchase_place ) $label .= ' — ' . $ub->purchase_place;
            }
            echo esc_html( $label );
          ?>
        </span>
        <a href="#" id="purchase-channel-edit" class="prs-inline-actions"><?php esc_html_e('edit', 'politeia-reading'); ?></a>
        <span id="purchase-channel-form" style="display:none;" class="prs-inline-actions">
          <select id="purchase-channel-select">
            <option value=""><?php esc_html_e('Select…','politeia-reading'); ?></option>
            <option value="online" <?php selected( $ub->purchase_channel, 'online' ); ?>><?php esc_html_e('Online','politeia-reading'); ?></option>
            <option value="store"  <?php selected( $ub->purchase_channel, 'store' ); ?>><?php esc_html_e('Store','politeia-reading'); ?></option>
          </select>
          <input type="text" id="purchase-place-input" placeholder="<?php esc_attr_e('Which?','politeia-reading'); ?>"
                 value="<?php echo $ub->purchase_place ? esc_attr($ub->purchase_place) : ''; ?>"
                 style="display: <?php echo $ub->purchase_channel ? 'inline-block' : 'none'; ?>; margin-left:8px; width:220px;" />
          <button type="button" id="purchase-channel-save" class="prs-btn" style="padding:4px 10px;">Save</button>
          <button type="button" id="purchase-channel-cancel" class="prs-btn" style="padding:4px 10px; background:#777;">Cancel</button>
          <span id="purchase-channel-status" class="prs-help"></span>
        </span>
      </div>

      <!-- Reading Status -->
      <div class="prs-field" id="fld-reading-status">
      <hr style="margin-bottom: 10px">
        <label class="label" for="reading-status-select"><?php esc_html_e('Reading Status','politeia-reading'); ?></label>
        <select id="reading-status-select">
          <option value="not_started" <?php selected( $ub->reading_status, 'not_started' ); ?>><?php esc_html_e('Not Started','politeia-reading'); ?></option>
          <option value="started"     <?php selected( $ub->reading_status, 'started' ); ?>><?php esc_html_e('Started','politeia-reading'); ?></option>
          <option value="finished"    <?php selected( $ub->reading_status, 'finished' ); ?>><?php esc_html_e('Finished','politeia-reading'); ?></option>
        </select>
        <span id="reading-status-status" class="prs-help" style="margin-left:8px;"></span>
      </div>

      <!-- Owning Status (editable) + Contact (condicional) -->
      <div class="prs-field" id="fld-owning-status">
      <hr style="margin-bottom: 10px">
        <label class="label" for="owning-status-select"><?php esc_html_e('Owning Status','politeia-reading'); ?></label>
        <select id="owning-status-select">
          <option value="" <?php selected( empty($ub->owning_status) ); ?>><?php esc_html_e('— Select —','politeia-reading'); ?></option>
          <option value="borrowed"  <?php selected( $ub->owning_status, 'borrowed' );  ?>><?php esc_html_e('Borrowed','politeia-reading'); ?></option>
          <option value="borrowing" <?php selected( $ub->owning_status, 'borrowing' ); ?>><?php esc_html_e('Borrowing','politeia-reading'); ?></option>
          <option value="sold"      <?php selected( $ub->owning_status, 'sold' );      ?>><?php esc_html_e('Sold','politeia-reading'); ?></option>
          <option value="lost"      <?php selected( $ub->owning_status, 'lost' );      ?>><?php esc_html_e('Lost','politeia-reading'); ?></option>
        </select>

        <?php $show_return_btn = in_array( $ub->owning_status, ['borrowed','borrowing'], true ); ?>
        <button type="button" id="owning-return-shelf" class="prs-btn" style="margin-left:8px; <?php echo $show_return_btn ? '' : 'display:none;'; ?>">
          <?php esc_html_e('Mark as returned','politeia-reading'); ?>
        </button>

        <span id="owning-status-status" class="prs-help" style="margin-left:8px;"></span>

        <?php
          // In Shelf derivado: '' (NULL) o 'borrowing' => contigo
          $is_in_shelf = ( empty($ub->owning_status) || $ub->owning_status === 'borrowing' );
        ?>
        <div class="prs-help" id="derived-location" style="margin:6px 0;">
          <strong><?php esc_html_e('Location','politeia-reading'); ?>:</strong>
          <span id="derived-location-text"><?php echo $is_in_shelf ? esc_html__('In Shelf','politeia-reading') : esc_html__('Not In Shelf','politeia-reading'); ?></span>
        </div>

        <?php
          $needs_contact = in_array($ub->owning_status, ['borrowed','borrowing','sold'], true) && ! $has_contact;
        ?>
        <div id="owning-contact-form" class="prs-contact-form" style="display: <?php echo $needs_contact ? 'block' : 'none'; ?>;">
          <label for="owning-contact-name" class="prs-contact-label"><?php esc_html_e('Name','politeia-reading'); ?></label>
          <input type="text" id="owning-contact-name" class="prs-contact-input"
                 value="<?php echo $ub->counterparty_name ? esc_attr($ub->counterparty_name) : ''; ?>" />

          <label for="owning-contact-email" class="prs-contact-label"><?php esc_html_e('Email','politeia-reading'); ?></label>
          <input type="email" id="owning-contact-email" class="prs-contact-input"
                 value="<?php echo $ub->counterparty_email ? esc_attr($ub->counterparty_email) : ''; ?>" />

          <div class="prs-contact-actions">
            <button type="button" id="owning-contact-save" class="prs-btn">Save</button>
            <span id="owning-contact-status" class="prs-help"></span>
          </div>
        </div>

        <div id="owning-contact-view">
          <?php
            $view = '';
            if ( $ub->counterparty_name )  $view .= $ub->counterparty_name;
            if ( $ub->counterparty_email ) $view .= ( $view ? ' · ' : '' ) . $ub->counterparty_email;
            if ( $active_start_local )     $view .= ( $view ? ' · ' : '' ) . $active_start_local;
            echo esc_html( $view );
          ?>
        </div>
      </div>
    </div>

    <!-- Arriba derecha: session recorder -->
    <div id="prs-session-recorder" class="prs-box">
      <h2><?php esc_html_e('Session recorder','politeia-reading'); ?></h2>
      <?php echo do_shortcode( '[politeia_start_reading book_id="'. (int)$book->id .'"]' ); ?>
    </div>

    <!-- Fila completa: Reading Sessions -->
    <div id="prs-reading-sessions" class="prs-box">
      <h2><?php esc_html_e('Reading Sessions','politeia-reading'); ?></h2>

      <?php if ( $sessions ): ?>
        <table class="prs-table">
          <thead>
          <tr>
            <th><?php esc_html_e('Start','politeia-reading'); ?></th>
            <th><?php esc_html_e('End','politeia-reading'); ?></th>
            <th><?php esc_html_e('Duration','politeia-reading'); ?></th>
            <th><?php esc_html_e('Start Pg','politeia-reading'); ?></th>
            <th><?php esc_html_e('End Pg','politeia-reading'); ?></th>
            <th><?php esc_html_e('Pages','politeia-reading'); ?></th>
            <th><?php esc_html_e('Chapter','politeia-reading'); ?></th>
          </tr>
          </thead>
          <tbody>
          <?php foreach ( $sessions as $s ):
            $sec   = max(0, strtotime($s->end_time) - strtotime($s->start_time));
            $pages = max(0, (int)$s->end_page - (int)$s->start_page);
            $total_seconds += $sec; $total_pages += $pages;
            ?>
            <tr>
              <td><?php echo esc_html( get_date_from_gmt( $s->start_time, 'Y-m-d H:i' ) ); ?></td>
              <td><?php echo esc_html( get_date_from_gmt( $s->end_time, 'Y-m-d H:i' ) ); ?></td>
              <td><?php echo prs_hms($sec); ?></td>
              <td><?php echo (int)$s->start_page; ?></td>
              <td><?php echo (int)$s->end_page; ?></td>
              <td><?php echo $pages; ?></td>
              <td><?php echo $s->chapter_name ? esc_html($s->chapter_name) : '—'; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
          <tr>
            <th colspan="2" style="text-align:right"><?php esc_html_e('Totals:','politeia-reading'); ?></th>
            <th><?php echo prs_hms($total_seconds); ?></th>
            <th></th><th></th>
            <th><?php echo (int)$total_pages; ?></th>
            <th></th>
          </tr>
          </tfoot>
        </table>
      <?php else: ?>
        <p><?php esc_html_e('No sessions yet.','politeia-reading'); ?></p>
      <?php endif; ?>
    </div>

  </div>
</div>
<?php get_footer();
