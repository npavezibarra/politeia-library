<?php
/**
 * Shortcode: [politeia_start_reading book_id="..."]
 * UI en formato de tabla + estados: IDLE -> RUNNING -> STOPPED
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode(
	'politeia_start_reading',
	function ( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p>You must be logged in.</p>';
		}

		$atts = shortcode_atts(
			array(
				'book_id' => 0,
			),
			$atts,
			'politeia_start_reading'
		);

		$book_id = absint( $atts['book_id'] );
		if ( ! $book_id ) {
			return '';
		}

		global $wpdb;
		$user_id = get_current_user_id();

		$tbl_rs = $wpdb->prefix . 'politeia_reading_sessions';
		$tbl_ub = $wpdb->prefix . 'politeia_user_books';

		// Última página de la última sesión (si existe)
		$last_end_page = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT end_page FROM {$tbl_rs}
     WHERE user_id = %d AND book_id = %d AND end_time IS NOT NULL
     ORDER BY end_time DESC LIMIT 1",
				$user_id,
				$book_id
			)
		);

		// Owning status y pages actuales del usuario para este libro
		$row_ub        = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT owning_status, pages FROM {$tbl_ub} WHERE user_id=%d AND book_id=%d LIMIT 1",
				$user_id,
				$book_id
			)
		);
		$owning_status = $row_ub && $row_ub->owning_status ? (string) $row_ub->owning_status : 'in_shelf';
		$total_pages   = $row_ub && $row_ub->pages ? (int) $row_ub->pages : 0;

		// No se puede iniciar si está prestado a otro, perdido o vendido
		$can_start = ! in_array( $owning_status, array( 'borrowed', 'lost', 'sold' ), true );

		// Encolar JS/CSS del recorder
		wp_enqueue_script( 'politeia-start-reading' );
		wp_enqueue_style( 'politeia-reading' );

		// Pasar datos al JS
		wp_localize_script(
			'politeia-start-reading',
			'PRS_SR',
			array(
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'prs_reading_nonce' ),
				'user_id'       => (int) $user_id,
				'book_id'       => (int) $book_id,
				'last_end_page' => is_null( $last_end_page ) ? '' : (int) $last_end_page,
				'owning_status' => (string) $owning_status,
				'total_pages'   => (int) $total_pages, // ← NUEVO
				'can_start'     => $can_start ? 1 : 0,
				'actions'       => array(
					'start' => 'prs_start_reading',
					'save'  => 'prs_save_reading',
				),
			)
		);

		ob_start();
		?>
	<style>
	/* Estilos mínimos (la alineación derecha de botones la manejas con tu CSS externo) */
	.prs-sr { width:100%; }
	.prs-sr .prs-sr-head { margin:0 0 8px; }
	.prs-sr .prs-sr-last { color:#555; margin:4px 0 10px; }

	.prs-sr-table { width:100%; border-collapse: collapse; background:#fff; }
	.prs-sr-table th,
	.prs-sr-table td { padding:10px; border:1px solid #ddd; vertical-align:middle; }
	.prs-sr-table th { width:40%; background:#f6f6f6; text-align:left; }
	.prs-sr-input { width:100%; box-sizing:border-box; }
	.prs-sr-timer { font-size:28px; font-weight:600; padding:12px 0; text-align:center; }

	/* Bloque de éxito (HTML) */
	.prs-sr-flash-block {
		display:none;
		width:100%;
		background:#ffe680;     /* amarillo */
		color:#111;             /* texto negro */
		border:1px solid #ddd;
		border-radius:10px;
		padding:24px;
	}
	.prs-sr-flash-inner{
		min-height:140px;       /* se ajusta a la altura del form via JS */
		display:flex;
		flex-direction:column;
		align-items:center;
		justify-content:center;
		text-align:center;
		gap:6px;
	}
	.prs-sr-flash-inner h2{
		margin:0; font-size:22px; font-weight:800;
	}
	.prs-sr-flash-inner h3{
		margin:0; font-size:18px; font-weight:600;
	}
	.prs-sr-flash-sub{
		margin-top:6px; font-size:14px; opacity:.9;
	}

	.prs-btn {
		padding:10px 14px;
		background:#111;
		color:#fff;
		border:none;
		cursor:pointer;
		box-shadow:none;
		outline:none;
		border-radius:10px;
	}
	.prs-btn[disabled] { opacity:.4; cursor:not-allowed; }
	.prs-btn:focus-visible { outline:2px solid #fff; outline-offset:2px; }

	/* Nota cuando faltan páginas */
	.prs-sr-note {
		font-size:13px;
		color:#444;
	}
	</style>

	<div class="prs-sr" data-book-id="<?php echo (int) $book_id; ?>">
	<h2 class="prs-sr-head"><?php esc_html_e( 'Session recorder', 'politeia-reading' ); ?></h2>

		<?php if ( $last_end_page ) : ?>
		<div class="prs-sr-last">
			<?php esc_html_e( 'Last session page', 'politeia-reading' ); ?>:
		<strong><?php echo (int) $last_end_page; ?></strong>
		</div>
	<?php endif; ?>

	<!-- Bloque HTML de éxito (centrado, amarillo, h2/h3) -->
	<div id="prs-sr-flash" class="prs-sr-flash-block" role="status" aria-live="polite">
		<div class="prs-sr-flash-inner">
		<h2>¡Muy bien!</h2>
		<h3>Leíste <span id="prs-sr-flash-pages">—</span> en <span id="prs-sr-flash-time">—</span>.</h3>
		<div class="prs-sr-flash-sub">Te espero pronto para seguir con este libro.</div>
		</div>
	</div>

	<!-- Wrapper del formulario (se oculta mientras se muestra el flash) -->
	<div id="prs-sr-formwrap">
		<table class="prs-sr-table" role="grid">
		<tbody>
			<!-- Start page -->
			<tr id="prs-sr-row-start">
			<th scope="row"><label for="prs-sr-start-page"><?php esc_html_e( 'Start page', 'politeia-reading' ); ?>*</label></th>
			<td>
				<input type="number" min="1" id="prs-sr-start-page" class="prs-sr-input" />
				<span id="prs-sr-start-page-view" class="prs-sr-view" style="display:none;"></span>
			</td>
			</tr>

			<!-- Capítulo -->
			<tr id="prs-sr-row-chapter">
			<th scope="row"><label for="prs-sr-chapter"><?php esc_html_e( 'Chapter', 'politeia-reading' ); ?></label></th>
			<td>
				<input type="text" id="prs-sr-chapter" class="prs-sr-input" />
				<span id="prs-sr-chapter-view" class="prs-sr-view" style="display:none;"></span>
			</td>
			</tr>

			<!-- Timer -->
			<tr id="prs-sr-row-timer" class="prs-sr-row--full">
			<td colspan="2">
				<div id="prs-sr-timer" class="prs-sr-timer">00:00:00</div>
			</td>
			</tr>

			<!-- AVISO cuando no hay pages -->
			<tr id="prs-sr-row-needs-pages" class="prs-sr-row--full" style="display:none;">
			<td colspan="2">
				<div class="prs-sr-note">To start a session, set the total <strong>Pages</strong> for this book in the info panel.</div>
			</td>
			</tr>

			<!-- Start/Stop Buttons (tu layout exacto; alineación derecha en tu CSS externo) -->
			<tr id="prs-sr-row-actions" class="prs-sr-row--full">
			<td colspan="2">
				<button
				type="button"
				id="prs-sr-start"
				class="prs-btn"
				disabled
				>
				▶ <?php esc_html_e( 'Start Reading', 'politeia-reading' ); ?>
				</button>
				<button type="button" id="prs-sr-stop" class="prs-btn" style="display:none;">
				■ <?php esc_html_e( 'Stop Reading', 'politeia-reading' ); ?>
				</button>
			</td>
			</tr>

			<!-- End Page (aparece tras Stop) -->
			<tr id="prs-sr-row-end" style="display:none;">
			<th scope="row"><label for="prs-sr-end-page"><?php esc_html_e( 'End Page', 'politeia-reading' ); ?>*</label></th>
			<td>
				<input type="number" min="1" id="prs-sr-end-page" class="prs-sr-input" />
			</td>
			</tr>

			<!-- Guardar sesión (aparece tras Stop) -->
			<tr id="prs-sr-row-save" class="prs-sr-row--full" style="display:none;">
			<td colspan="2">
				<button type="button" id="prs-sr-save" class="prs-btn" disabled>
				<?php esc_html_e( 'Save Session', 'politeia-reading' ); ?>
				</button>
			</td>
			</tr>
		</tbody>
		</table>
	</div>
	</div>
		<?php
		return ob_get_clean();
	}
);
