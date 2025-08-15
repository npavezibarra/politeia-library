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
<div class="wrap">
  <p><a href="<?php echo esc_url( home_url('/my-books') ); ?>">&larr; Back to My Books</a></p>
  <h1><?php echo esc_html($book->title); ?></h1>
  <p><strong><?php echo esc_html($book->author); ?></strong><?php echo $book->year ? ' · '.(int)$book->year : ''; ?></p>

  <?php if ($sessions): ?>
    <h2>Reading Sessions</h2>
    <table class="prs-table">
      <thead>
        <tr>
          <th>Start</th><th>End</th><th>Duration</th>
          <th>Start Pg</th><th>End Pg</th><th>Pages</th><th>Chapter</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sessions as $s):
          $sec = max(0, strtotime($s->end_time) - strtotime($s->start_time));
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

  <div id="start-reading" style="margin-top:24px;">
    <?php echo do_shortcode( '[politeia_start_reading book_id="'. (int)$book->id .'"]' ); ?>
  </div>
</div>
<?php get_footer();
