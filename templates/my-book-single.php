<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

if ( ! is_user_logged_in() ) {
	echo '<div class="wrap"><p>You must be logged in.</p></div>';
	get_footer();
	exit;
}

global $wpdb;
$user_id = get_current_user_id();
$slug    = get_query_var( 'prs_book_slug' );

$tbl_b     = $wpdb->prefix . 'politeia_books';
$tbl_ub    = $wpdb->prefix . 'politeia_user_books';
$tbl_loans = $wpdb->prefix . 'politeia_loans';

$book = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl_b} WHERE slug=%s LIMIT 1", $slug ) );
if ( ! $book ) {
	status_header( 404 );
	echo '<div class="wrap"><h1>Not found</h1></div>';
	get_footer();
	exit; }

$ub = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl_ub} WHERE user_id=%d AND book_id=%d LIMIT 1", $user_id, $book->id ) );
if ( ! $ub ) {
	status_header( 403 );
	echo '<div class="wrap"><h1>No access</h1><p>This book is not in your library.</p></div>';
	get_footer();
	exit; }

/** Contacto ya guardado (definir antes de localize) */
$has_contact = ( ! empty( $ub->counterparty_name ) ) || ( ! empty( $ub->counterparty_email ) );

/** Préstamo activo (fecha local) */
$active_start_gmt   = $wpdb->get_var(
	$wpdb->prepare(
		"SELECT start_date FROM {$tbl_loans}
   WHERE user_id=%d AND book_id=%d AND end_date IS NULL
   ORDER BY id DESC LIMIT 1",
		$user_id,
		$book->id
	)
);
$active_start_local = $active_start_gmt ? get_date_from_gmt( $active_start_gmt, 'Y-m-d' ) : '';

/** Encolar assets */
wp_enqueue_style( 'politeia-reading' );
wp_enqueue_script( 'politeia-my-book' ); // asegúrate de registrar este JS en tu plugin/tema

/** Datos al JS principal */
wp_localize_script(
	'politeia-my-book',
	'PRS_BOOK',
	array(
		'ajax_url'      => admin_url( 'admin-ajax.php' ),
		'nonce'         => wp_create_nonce( 'prs_update_user_book_meta' ),
		'user_book_id'  => (int) $ub->id,
		'book_id'       => (int) $book->id,
		'owning_status' => (string) $ub->owning_status,
		'has_contact'   => $has_contact ? 1 : 0,
		'rating'        => isset( $ub->rating ) && $ub->rating !== null ? (int) $ub->rating : 0,
	)
);
?>
<style>
	/* Maqueta general */
	.prs-single-grid{
	display:grid;
	grid-template-columns: 280px 1fr 1fr;
	grid-template-rows: auto auto auto;
	gap:24px;
	margin: 16px 0 32px;
	}
	.prs-box{ background:#f9f9f9; padding:16px; min-height:120px; }
	#prs-book-cover{ grid-column:1; grid-row:1 / span 2; }
	#prs-book-info{ grid-column:2; grid-row:1; min-height:140px; }
	#prs-session-recorder{ grid-column:3; grid-row:1; min-height:140px; background:#ffffff;
	padding: 16px; border: 1px solid #dddddd; }
	#prs-reading-sessions{ grid-column:1 / 4; grid-row:3; min-height:320px; }

	/* Frame portada */
	.prs-cover-frame{
	position:relative; width:100%; height:auto; overflow:hidden;
	background:#eee; border-radius:12px;
	}
	.prs-cover-img{ width:100%; height:100%; object-fit:cover; display:block; }
	.prs-cover-placeholder{ width:100%; height:100%; background:#ddd; }

	/* Tipos y tablas */
	.prs-box h2{ margin:0 0 8px; }
	.prs-meta{ color:#555; margin-top:6px; }
	.prs-table{ width:100%; border-collapse:collapse; background:#fff; }
	.prs-table th, .prs-table td{ padding:8px 10px; border-bottom:1px solid #eee; text-align:left; }

	.prs-field{ margin-top:12px; }
	.prs-field .label{ font-weight:600; display:block; margin-bottom:4px; }
	.prs-inline-actions{ margin-left:8px; }
	.prs-inline-actions a{ margin-left:8px; }
	.prs-help{ color:#666; font-size:12px; }

	/* Contact form (3 filas) */
	.prs-contact-form{
	display:grid; grid-template-columns:120px minmax(240px,1fr);
	gap:10px 12px; max-width:600px; margin:0 !important;
	}
	.prs-contact-label{ align-self:center; font-weight:600; }
	.prs-contact-input{ width:100%; }
	.prs-contact-actions{ grid-column:2; display:flex; align-items:center; gap:10px; }
	#owning-contact-view{ margin:6px 0 0 10px; color:#555; }

	/* Paginación (parcial AJAX) */
	.prs-pagination ul.page-numbers{ display:flex; gap:6px; list-style:none; justify-content: center; }
	.prs-pagination .page-numbers{ padding:6px 10px; background:#fff; border:1px solid #ddd; border-radius:6px; text-decoration:none; width: fit-content; margin: auto; padding: 10px !important }
	.prs-pagination .current{ font-weight:700; }
	@media (max-width: 900px){
	.prs-single-grid{ grid-template-columns: 1fr; grid-template-rows:auto; }
	#prs-book-cover{ grid-row:auto; }
	#prs-book-info, #prs-session-recorder, #prs-reading-sessions{ grid-column:1; }
	.prs-contact-form{ grid-template-columns:1fr; margin-left:0; }
	.prs-contact-actions{ grid-column:1; }
	}
</style>

<div class="wrap">
	<p><a href="<?php echo esc_url( home_url( '/my-books' ) ); ?>">&larr; Back to My Books</a></p>

	<div class="prs-single-grid">

	<!-- Columna izquierda: portada -->
	<div id="prs-book-cover" class="prs-box">
		<?php
		$user_cover_id  = isset( $ub->cover_attachment_id_user ) ? (int) $ub->cover_attachment_id_user : 0;
		$canon_cover_id = isset( $book->cover_attachment_id ) ? (int) $book->cover_attachment_id : 0;
		$final_cover_id = $user_cover_id ?: $canon_cover_id;
		$has_image      = $final_cover_id > 0;
		?>
		<div id="prs-cover-frame" class="prs-cover-frame <?php echo $has_image ? 'has-image' : ''; ?>">
		<?php if ( $has_image ) : ?>
			<?php
			echo wp_get_attachment_image(
				$final_cover_id,
				'large',
				false,
				array(
					'class' => 'prs-cover-img',
					'alt'   => esc_attr( $book->title ),
					'id'    => 'prs-cover-img',
				)
			);
			?>
		<?php else : ?>
			<div id="prs-cover-placeholder" class="prs-cover-placeholder"></div>
		<?php endif; ?>
		<div class="prs-cover-overlay">
			<?php echo do_shortcode( '[prs_cover_button]' ); ?>
		</div>
		</div>
	</div>

	<!-- Arriba centro: título/info y metacampos -->
	<div id="prs-book-info" class="prs-box">
		<h2 style="margin:0;"><?php echo esc_html( $book->title ); ?></h2>
		<div class="prs-meta">
		<strong><?php echo esc_html( $book->author ); ?></strong>
		<?php echo $book->year ? ' · ' . (int) $book->year : ''; ?>
		</div>

		<?php $current_rating = isset( $ub->rating ) && $ub->rating !== null ? (int) $ub->rating : 0; ?>
		<div class="prs-field" id="fld-user-rating">
		<div id="prs-user-rating" class="prs-stars" role="radiogroup" aria-label="<?php esc_attr_e( 'Your rating', 'politeia-reading' ); ?>">
			<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
			<button type="button"
				class="prs-star<?php echo ( $i <= $current_rating ) ? ' is-active' : ''; ?>"
				data-value="<?php echo $i; ?>"
				role="radio"
				aria-checked="<?php echo ( $i === $current_rating ) ? 'true' : 'false'; ?>">
				★
			</button>
			<?php endfor; ?>
		</div>
		<span id="rating-status" class="prs-help" style="margin-left:8px;"></span>
		</div>

		<!-- Pages -->
		<div class="prs-field" id="fld-pages">
		<hr style="margin-bottom:10px">
		<span class="label"><?php esc_html_e( 'Pages', 'politeia-reading' ); ?></span>
		<span id="pages-view"><?php echo $ub->pages ? (int) $ub->pages : '—'; ?></span>
		<a href="#" id="pages-edit" class="prs-inline-actions"><?php esc_html_e( 'edit', 'politeia-reading' ); ?></a>
		<span id="pages-form" style="display:none;" class="prs-inline-actions">
			<input type="number" id="pages-input" min="1" style="width:120px" value="<?php echo $ub->pages ? (int) $ub->pages : ''; ?>" />
			<button type="button" id="pages-save" class="prs-btn" style="padding:4px 10px;">Save</button>
			<button type="button" id="pages-cancel" class="prs-btn" style="padding:4px 10px;background:#777;">Cancel</button>
			<span id="pages-status" class="prs-help"></span>
		</span>
		</div>

		<!-- Purchase Date -->
		<div class="prs-field" id="fld-purchase-date">
		<hr style="margin-bottom:10px">
		<span class="label"><?php esc_html_e( 'Purchase Date', 'politeia-reading' ); ?></span>
		<span id="purchase-date-view"><?php echo $ub->purchase_date ? esc_html( $ub->purchase_date ) : '—'; ?></span>
		<a href="#" id="purchase-date-edit" class="prs-inline-actions"><?php esc_html_e( 'edit', 'politeia-reading' ); ?></a>
		<span id="purchase-date-form" style="display:none;" class="prs-inline-actions">
			<input type="date" id="purchase-date-input" value="<?php echo $ub->purchase_date ? esc_attr( $ub->purchase_date ) : ''; ?>" />
			<button type="button" id="purchase-date-save" class="prs-btn" style="padding:4px 10px;">Save</button>
			<button type="button" id="purchase-date-cancel" class="prs-btn" style="padding:4px 10px;background:#777;">Cancel</button>
			<span id="purchase-date-status" class="prs-help"></span>
		</span>
		</div>

		<!-- Purchase Channel + Which? -->
		<div class="prs-field" id="fld-purchase-channel">
		<hr style="margin-bottom:10px">
		<span class="label"><?php esc_html_e( 'Purchase Channel', 'politeia-reading' ); ?></span>
		<span id="purchase-channel-view">
			<?php
			$label = '—';
			if ( $ub->purchase_channel ) {
				$label = ucfirst( $ub->purchase_channel );
				if ( $ub->purchase_place ) {
					$label .= ' — ' . $ub->purchase_place;
				}
			}
			echo esc_html( $label );
			?>
		</span>
		<a href="#" id="purchase-channel-edit" class="prs-inline-actions"><?php esc_html_e( 'edit', 'politeia-reading' ); ?></a>
		<span id="purchase-channel-form" style="display:none;" class="prs-inline-actions">
			<div>
			<select id="purchase-channel-select">
				<option value=""><?php esc_html_e( 'Select…', 'politeia-reading' ); ?></option>
				<option value="online" <?php selected( $ub->purchase_channel, 'online' ); ?>><?php esc_html_e( 'Online', 'politeia-reading' ); ?></option>
				<option value="store"  <?php selected( $ub->purchase_channel, 'store' ); ?>><?php esc_html_e( 'Store', 'politeia-reading' ); ?></option>
			</select>
			<input type="text" id="purchase-place-input" placeholder="<?php esc_attr_e( 'Which?', 'politeia-reading' ); ?>"
					value="<?php echo $ub->purchase_place ? esc_attr( $ub->purchase_place ) : ''; ?>"
					style="display: <?php echo $ub->purchase_channel ? 'inline-block' : 'none'; ?>; margin-left:8px; width:220px;" />
			</div>
			<button type="button" id="purchase-channel-save" class="prs-btn" style="padding:4px 10px;">Save</button>
			<button type="button" id="purchase-channel-cancel" class="prs-btn" style="padding:4px 10px;background:#777;">Cancel</button>
			<span id="purchase-channel-status" class="prs-help"></span>
		</span>
		</div>

		<!-- Reading Status -->
		<div class="prs-field" id="fld-reading-status">
		<hr style="margin-bottom:10px">
		<label class="label" for="reading-status-select"><?php esc_html_e( 'Reading Status', 'politeia-reading' ); ?></label>
		<select id="reading-status-select">
			<option value="not_started" <?php selected( $ub->reading_status, 'not_started' ); ?>><?php esc_html_e( 'Not Started', 'politeia-reading' ); ?></option>
			<option value="started"     <?php selected( $ub->reading_status, 'started' ); ?>><?php esc_html_e( 'Started', 'politeia-reading' ); ?></option>
			<option value="finished"    <?php selected( $ub->reading_status, 'finished' ); ?>><?php esc_html_e( 'Finished', 'politeia-reading' ); ?></option>
		</select>
		<span id="reading-status-status" class="prs-help" style="margin-left:8px;"></span>
		</div>

		<!-- Owning Status (editable) + Contact (condicional) -->
		<div class="prs-field" id="fld-owning-status">
		<hr style="margin-bottom:10px">
		<label class="label" for="owning-status-select"><?php esc_html_e( 'Owning Status', 'politeia-reading' ); ?></label>
		<select id="owning-status-select">
			<option value="" <?php selected( empty( $ub->owning_status ) ); ?>><?php esc_html_e( '— Select —', 'politeia-reading' ); ?></option>
			<option value="borrowed"  <?php selected( $ub->owning_status, 'borrowed' ); ?>><?php esc_html_e( 'Borrowed', 'politeia-reading' ); ?></option>
			<option value="borrowing" <?php selected( $ub->owning_status, 'borrowing' ); ?>><?php esc_html_e( 'Borrowing', 'politeia-reading' ); ?></option>
			<option value="sold"      <?php selected( $ub->owning_status, 'sold' ); ?>><?php esc_html_e( 'Sold', 'politeia-reading' ); ?></option>
			<option value="lost"      <?php selected( $ub->owning_status, 'lost' ); ?>><?php esc_html_e( 'Lost', 'politeia-reading' ); ?></option>
		</select>

		<?php $show_return_btn = in_array( $ub->owning_status, array( 'borrowed', 'borrowing' ), true ); ?>
		<button type="button" id="owning-return-shelf" class="prs-btn" style="margin-left:8px; <?php echo $show_return_btn ? '' : 'display:none;'; ?>">
			<?php esc_html_e( 'Mark as returned', 'politeia-reading' ); ?>
		</button>

		<span id="owning-status-status" class="prs-help" style="margin-left:8px;"></span>

		<?php
			// "In Shelf" es derivado solo cuando owning_status es NULL/''
			$is_in_shelf = empty( $ub->owning_status );
		?>
		<div class="prs-help" id="derived-location" style="margin:6px 0;">
			<strong><?php esc_html_e( 'Location', 'politeia-reading' ); ?>:</strong>
			<span id="derived-location-text"><?php echo $is_in_shelf ? esc_html__( 'In Shelf', 'politeia-reading' ) : esc_html__( 'Not In Shelf', 'politeia-reading' ); ?></span>
		</div>

		<?php
			$needs_contact = in_array( $ub->owning_status, array( 'borrowed', 'borrowing', 'sold' ), true ) && ! $has_contact;
		?>
		<div id="owning-contact-form" class="prs-contact-form" style="display: <?php echo $needs_contact ? 'block' : 'none'; ?>;">
			<label for="owning-contact-name" class="prs-contact-label"><?php esc_html_e( 'Name', 'politeia-reading' ); ?></label>
			<input type="text" id="owning-contact-name" class="prs-contact-input"
				value="<?php echo $ub->counterparty_name ? esc_attr( $ub->counterparty_name ) : ''; ?>" />

			<label for="owning-contact-email" class="prs-contact-label"><?php esc_html_e( 'Email', 'politeia-reading' ); ?></label>
			<input type="email" id="owning-contact-email" class="prs-contact-input"
				value="<?php echo $ub->counterparty_email ? esc_attr( $ub->counterparty_email ) : ''; ?>" />

			<div class="prs-contact-actions">
			<button type="button" id="owning-contact-save" class="prs-btn">Save</button>
			<span id="owning-contact-status" class="prs-help"></span>
			</div>
		</div>

		<div id="owning-contact-view">
			<?php
			$view = '';
			if ( $ub->counterparty_name ) {
				$view .= $ub->counterparty_name;
			}
			if ( $ub->counterparty_email ) {
				$view .= ( $view ? ' · ' : '' ) . $ub->counterparty_email;
			}
			if ( $active_start_local ) {
				$view .= ( $view ? ' · ' : '' ) . $active_start_local;
			}
			echo esc_html( $view );
			?>
		</div>
		</div>
	</div>

	<!-- Arriba derecha: session recorder -->
	<div id="prs-session-recorder" class="prs-box">
		<?php echo do_shortcode( '[politeia_start_reading book_id="' . (int) $book->id . '"]' ); ?>
	</div>

	<!-- Fila completa: Reading Sessions (AJAX) -->
	<div id="prs-reading-sessions" class="prs-box">
		<h2><?php esc_html_e( 'Reading Sessions', 'politeia-reading' ); ?></h2>
		<?php
		$prs_sess_nonce = wp_create_nonce( 'prs_sessions_nonce' );
		// Página inicial desde la URL (se respetará con replaceState)
		$initial_paged = isset( $_GET['prs_sess'] ) ? max( 1, absint( $_GET['prs_sess'] ) ) : 1;
		?>
		<div id="prs-sessions-table" data-initial-paged="<?php echo (int) $initial_paged; ?>"></div>
		<script>
		// Config para el loader AJAX de sesiones (lo usa assets/js/my-book.js)
		window.PRS_SESS = {
			ajax_url: "<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>",
			nonce:    "<?php echo esc_js( $prs_sess_nonce ); ?>",
			book_id:  <?php echo (int) $book->id; ?>,
			param:    "prs_sess"
		};
		</script>
	</div>

	</div>
</div>
<?php
get_footer();
