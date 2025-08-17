<?php
if (!defined('ABSPATH')) exit;
get_header();

if (!is_user_logged_in()) {
  echo '<div class="wrap"><p>You must be logged in.</p></div>';
  get_footer(); exit;
}

global $wpdb;
$user_id = get_current_user_id();
$slug = get_query_var('prs_book_slug');

$tbl_b  = $wpdb->prefix . 'politeia_books';
$tbl_ub = $wpdb->prefix . 'politeia_user_books';
$tbl_rs = $wpdb->prefix . 'politeia_reading_sessions';

$book = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $tbl_b WHERE slug=%s LIMIT 1", $slug) );
if (!$book) { status_header(404); echo '<div class="wrap"><h1>Not found</h1></div>'; get_footer(); exit; }

$ub = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $tbl_ub WHERE user_id=%d AND book_id=%d LIMIT 1", $user_id, $book->id) );
if (!$ub) { status_header(403); echo '<div class="wrap"><h1>No access</h1><p>This book is not in your library.</p></div>'; get_footer(); exit; }

$sessions = $wpdb->get_results( $wpdb->prepare("
  SELECT id, start_time, end_time, start_page, end_page, chapter_name
  FROM $tbl_rs
  WHERE user_id=%d AND book_id=%d
  ORDER BY start_time DESC
", $user_id, $book->id) );

function prs_hms($sec){
  $h = floor($sec/3600); $m = floor(($sec%3600)/60); $s = $sec%60;
  return sprintf('%02d:%02d:%02d', $h, $m, $s);
}
$total_pages = 0; $total_seconds = 0;
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
  #prs-book-meta-1{ grid-column:2; grid-row:2; min-height:140px; }
  #prs-book-meta-2{ grid-column:3; grid-row:2; min-height:140px; }
  #prs-reading-sessions{ grid-column:1 / 4; grid-row:3; min-height:320px; }

  /* Solo para la imagen de portada dentro de la caja */
  .prs-cover-img{ width:100%; height:auto; max-height:100%; object-fit:cover; display:block; }
  .prs-cover-placeholder{ width:100%; height:100%; background:#eee; }
  .prs-box h2{ margin:0 0 8px; }
  .prs-meta{ color:#555; margin-top:6px; }
  .prs-table { width:100%; border-collapse: collapse; background:#fff; }
  .prs-table th, .prs-table td { padding:8px 10px; border-bottom:1px solid #eee; text-align:left; }
</style>

<div class="wrap">
  <p><a href="<?php echo esc_url( home_url('/my-books') ); ?>">&larr; Back to My Books</a></p>

  <div class="prs-single-grid">
    <!-- Columna izquierda: portada -->
    <div id="prs-book-cover" class="prs-box">
      <?php
        if ( ! empty($book->cover_attachment_id) ) {
          echo wp_get_attachment_image( (int)$book->cover_attachment_id, 'large', false, ['class'=>'prs-cover-img','alt'=>$book->title] );
        } else {
          echo '<div class="prs-cover-placeholder"></div>';
        }
      ?>
    </div>

    <!-- Arriba centro: título/autor/ratings -->
    <div id="prs-book-info" class="prs-box">
  <h2 style="margin:0;"><?php echo esc_html( $book->title ); ?></h2>
  <div class="prs-meta">
    <strong><?php echo esc_html( $book->author ); ?></strong>
    <?php echo $book->year ? ' · ' . (int)$book->year : ''; ?>
  </div>
  <div class="prs-meta" aria-hidden="true" style="letter-spacing:2px; margin-top:8px;">★★★★★</div>

  <!-- ========== FIELDS ========== -->
  <div class="prs-fields" style="margin-top:14px; display:grid; gap:10px; max-width:640px;">
    <!-- Pages (int) -->
    <div class="prs-field" id="fld-pages">
      <span class="label" style="font-weight:600;">Pages</span>
      <span id="pages-view" style="margin-left:8px;"><?php echo $ub->pages ? (int)$ub->pages : '—'; ?></span>
      <a href="#" id="pages-edit" style="margin-left:10px;"><?php esc_html_e('edit','politeia-reading'); ?></a>
      <span id="pages-form" style="display:none; margin-left:10px;">
        <input type="number" id="pages-input" min="1" style="width:100px" value="<?php echo $ub->pages ? (int)$ub->pages : ''; ?>" />
        <button type="button" id="pages-save" class="prs-btn" style="padding:4px 10px;">Save</button>
        <button type="button" id="pages-cancel" class="prs-btn" style="padding:4px 10px; background:#777;">Cancel</button>
        <span id="pages-status" style="margin-left:8px; color:#555;"></span>
      </span>
    </div>

    <!-- Purchase Date (date) -->
    <div class="prs-field" id="fld-purchase-date">
      <span class="label" style="font-weight:600;">Purchase Date</span>
      <span id="purchase-date-view" style="margin-left:8px;"><?php echo $ub->purchase_date ? esc_html($ub->purchase_date) : '—'; ?></span>
      <a href="#" id="purchase-date-edit" style="margin-left:10px;"><?php esc_html_e('edit','politeia-reading'); ?></a>
      <span id="purchase-date-form" style="display:none; margin-left:10px;">
        <input type="date" id="purchase-date-input" value="<?php echo $ub->purchase_date ? esc_attr($ub->purchase_date) : ''; ?>" />
        <button type="button" id="purchase-date-save" class="prs-btn" style="padding:4px 10px;">Save</button>
        <button type="button" id="purchase-date-cancel" class="prs-btn" style="padding:4px 10px; background:#777;">Cancel</button>
        <span id="purchase-date-status" style="margin-left:8px; color:#555;"></span>
      </span>
    </div>

    <!-- Purchase Channel + Place -->
    <div class="prs-field" id="fld-purchase-channel">
      <span class="label" style="font-weight:600;">Purchase Channel</span>
      <span id="purchase-channel-view" style="margin-left:8px;">
        <?php
          $label = $ub->purchase_channel ? ucfirst($ub->purchase_channel) : '—';
          if ( $ub->purchase_channel && $ub->purchase_place ) $label .= ' — ' . esc_html($ub->purchase_place);
          echo esc_html( $label );
        ?>
      </span>
      <a href="#" id="purchase-channel-edit" style="margin-left:10px;"><?php esc_html_e('edit','politeia-reading'); ?></a>

      <span id="purchase-channel-form" style="display:none; margin-left:10px;">
        <select id="purchase-channel-select">
          <option value=""><?php esc_html_e('Choose…','politeia-reading'); ?></option>
          <option value="online" <?php selected($ub->purchase_channel, 'online'); ?>>Online</option>
          <option value="store"  <?php selected($ub->purchase_channel, 'store');  ?>>Store</option>
        </select>
        <input type="text" id="purchase-place-input" placeholder="<?php esc_attr_e('Which?', 'politeia-reading'); ?>"
               value="<?php echo $ub->purchase_place ? esc_attr($ub->purchase_place) : ''; ?>"
               style="margin-left:6px; width:220px;" />
        <button type="button" id="purchase-channel-save" class="prs-btn" style="padding:4px 10px;">Save</button>
        <button type="button" id="purchase-channel-cancel" class="prs-btn" style="padding:4px 10px; background:#777;">Cancel</button>
        <span id="purchase-channel-status" style="margin-left:8px; color:#555;"></span>
      </span>
    </div>

    <!-- Reading Status (dropdown) -->
    <div class="prs-field" id="fld-reading-status">
      <span class="label" style="font-weight:600;">Reading Status</span>
      <select id="reading-status-select" style="margin-left:10px;">
        <option value="not_started" <?php selected($ub->reading_status,'not_started'); ?>>Not Started</option>
        <option value="started"     <?php selected($ub->reading_status,'started');     ?>>Started</option>
        <option value="finished"    <?php selected($ub->reading_status,'finished');    ?>>Completed</option>
      </select>
      <span id="reading-status-status" style="margin-left:8px; color:#555;"></span>
    </div>

    <!-- Owning Status (dropdown) -->
    <div class="prs-field" id="fld-owning-status">
      <span class="label" style="font-weight:600;">Owning Status</span>
      <select id="owning-status-select" style="margin-left:10px;">
        <option value="in_shelf"  <?php selected($ub->owning_status,'in_shelf');  ?>>In Shelf</option>
        <option value="borrowed"  <?php selected($ub->owning_status,'borrowed');  ?>>Borrowed</option>
        <option value="borrowing" <?php selected($ub->owning_status,'borrowing'); ?>>Borrowing</option>
        <option value="sold"      <?php selected($ub->owning_status,'sold');      ?>>Sold</option>
        <option value="lost"      <?php selected($ub->owning_status,'lost');      ?>>Lost</option>
      </select>
      <span id="owning-status-status" style="margin-left:8px; color:#555;"></span>

      <?php
        $needs_contact = in_array($ub->owning_status, ['borrowed','borrowing','sold'], true);
      ?>
      <!-- Sub-form contacto -->
      <div id="owning-contact-form" class="prs-contact-form" style="display:block;">
        <label for="owning-contact-name" class="prs-contact-label">Name</label>
        <input type="text" id="owning-contact-name" class="prs-contact-input" />

        <label for="owning-contact-email" class="prs-contact-label">Email</label>
        <input type="email" id="owning-contact-email" class="prs-contact-input" />

        <div class="prs-contact-actions">
          <button type="button" id="owning-contact-save" class="prs-btn">Save</button>
          <span id="owning-contact-status" class="prs-contact-status"></span>
        </div>
      </div>

      <!-- Vista texto del contacto -->
      <div id="owning-contact-view" style="margin:6px 0 0 10px; color:#555;">
        <?php
          $view = '';
          if ( $ub->counterparty_name ) $view .= $ub->counterparty_name;
          if ( $ub->counterparty_email ) $view .= ( $view ? ' · ' : '' ) . $ub->counterparty_email;
          echo esc_html( $view );
        ?>
      </div>
    </div>
  </div>
  <?php
    wp_enqueue_script( 'politeia-my-book' );
    wp_localize_script( 'politeia-my-book', 'PRS_BOOK', [
      'ajax_url'     => admin_url('admin-ajax.php'),
      'nonce'        => wp_create_nonce('prs_update_user_book_meta'),
      'user_book_id' => (int) $ub->id,
    ]);
    ?>
</div>


    <!-- Arriba derecha: session recorder -->
    <div id="prs-session-recorder" class="prs-box">
      <h2>Session recorder</h2>
      <?php echo do_shortcode( '[politeia_start_reading book_id="'. (int)$book->id .'"]' ); ?>
    </div>

    <!-- Abajo centro: Book MetaData 1 -->
    <div id="prs-book-meta-1" class="prs-box">
      <h2>Book MetaData 1</h2>
      <!-- contenido provisional -->
    </div>

    <!-- Abajo derecha: Book MetaData 2 -->
    <div id="prs-book-meta-2" class="prs-box">
      <h2>Book MetaData 2</h2>
      <!-- contenido provisional -->
    </div>

    <!-- Fila completa: Reading Sessions -->
    <div id="prs-reading-sessions" class="prs-box">
      <h2>Reading Sessions</h2>

      <?php if ( $sessions ): ?>
        <table class="prs-table">
          <thead>
          <tr>
            <th>Start</th><th>End</th><th>Duration</th>
            <th>Start Pg</th><th>End Pg</th><th>Pages</th><th>Chapter</th>
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
            <th colspan="2" style="text-align:right">Totals:</th>
            <th><?php echo prs_hms($total_seconds); ?></th>
            <th></th><th></th>
            <th><?php echo (int)$total_pages; ?></th>
            <th></th>
          </tr>
          </tfoot>
        </table>
      <?php else: ?>
        <p>No sessions yet.</p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php get_footer();
