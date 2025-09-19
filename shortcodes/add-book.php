<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Register shortcode
add_shortcode(
	'politeia_add_book',
	function () {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'You must be logged in to add books.', 'politeia-reading' ) . '</p>';
		}

		wp_enqueue_style( 'politeia-reading' );

		ob_start();

		// Avisos dentro del buffer del shortcode
		if ( ! empty( $_GET['prs_added'] ) && $_GET['prs_added'] === '1' ) {
			echo '<div class="prs-notice prs-notice--success">' .
			esc_html__( 'Book added to My Library.', 'politeia-reading' ) .
			'</div>';
		}
		if ( ! empty( $_GET['prs_error'] ) && $_GET['prs_error'] === '1' ) {
			echo '<div class="prs-notice prs-notice--error">' .
			esc_html__( 'There was a problem adding the book.', 'politeia-reading' ) .
			'</div>';
		}
		?>
	<div class="prs-add-book">
		<button type="button" class="prs-btn" onclick="document.getElementById('prs-add-book-form').style.display='block'">
			<?php echo esc_html__( 'Add Book', 'politeia-reading' ); ?>
		</button>

		<form id="prs-add-book-form"
				class="prs-form"
				method="post"
				enctype="multipart/form-data"
				action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				style="display:none;">
			<?php wp_nonce_field( 'prs_add_book', 'prs_nonce' ); ?>
			<input type="hidden" name="action" value="prs_add_book_submit" />

			<label><?php _e( 'Title', 'politeia-reading' ); ?>*
				<input type="text" name="prs_title" required />
			</label>

			<label><?php _e( 'Author', 'politeia-reading' ); ?>*
				<input type="text" name="prs_author" required />
			</label>

			<label><?php _e( 'Year', 'politeia-reading' ); ?>
				<input type="number"
						name="prs_year"
						min="1400"
						max="<?php echo esc_attr( (int) date( 'Y' ) + 1 ); ?>" />
			</label>

			<label><?php _e( 'Cover (jpg/png/webp)', 'politeia-reading' ); ?>
				<input type="file" name="prs_cover" accept=".jpg,.jpeg,.png,.webp" />
			</label>

			<button class="prs-btn" type="submit"><?php _e( 'Save to My Library', 'politeia-reading' ); ?></button>
		</form>
	</div>
		<?php
		return ob_get_clean();
	}
);

// Handle submit (front-end safe handler)
add_action( 'admin_post_prs_add_book_submit', 'prs_add_book_submit_handler' );
add_action( 'admin_post_nopriv_prs_add_book_submit', 'prs_add_book_submit_handler' );

function prs_add_book_submit_handler() {
	if ( ! is_user_logged_in() ) {
		wp_die( 'Login required.' );
	}
	if ( ! isset( $_POST['prs_nonce'] ) || ! wp_verify_nonce( $_POST['prs_nonce'], 'prs_add_book' ) ) {
		wp_die( 'Invalid nonce.' );
	}

	$user_id = get_current_user_id();

	// Sanitización
	$title  = isset( $_POST['prs_title'] ) ? sanitize_text_field( wp_unslash( $_POST['prs_title'] ) ) : '';
	$author = isset( $_POST['prs_author'] ) ? sanitize_text_field( wp_unslash( $_POST['prs_author'] ) ) : '';
	$year   = null;
	if ( isset( $_POST['prs_year'] ) && $_POST['prs_year'] !== '' ) {
		$y   = absint( $_POST['prs_year'] );
		$min = 1400;
		$max = (int) date( 'Y' ) + 1;
		if ( $y >= $min && $y <= $max ) {
			$year = $y;
		}
	}

	if ( $title === '' || $author === '' ) {
		wp_safe_redirect( add_query_arg( 'prs_error', 1, wp_get_referer() ?: home_url() ) );
		exit;
	}

	// Upload opcional de portada
	$attachment_id = prs_handle_cover_upload( 'prs_cover' );

	// Crear o encontrar libro canónico
	$book_id = prs_find_or_create_book( $title, $author, $year, $attachment_id );
	if ( is_wp_error( $book_id ) || ! $book_id ) {
		wp_safe_redirect( add_query_arg( 'prs_error', 1, wp_get_referer() ?: home_url() ) );
		exit;
	}

	// Vincular a la biblioteca del usuario (idempotente)
	prs_ensure_user_book( $user_id, (int) $book_id );

	// Redirect back with success flag
	$url = add_query_arg( 'prs_added', 1, wp_get_referer() ?: home_url() );
	wp_safe_redirect( $url );
	exit;
}
