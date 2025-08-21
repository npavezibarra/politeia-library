<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PRS_Cover_Upload_Feature {
	public static function init() {
		add_shortcode( 'prs_cover_button', [ __CLASS__, 'shortcode_button' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'wp_ajax_prs_cover_save_crop', [ __CLASS__, 'ajax_save_crop' ] );
	}

	public static function enqueue() {
		// Cárgalo solo en la página del libro (si usas esa var); comenta esta línea si prefieres cargarlo siempre.
		if ( ! get_query_var( 'prs_book_slug' ) ) return;

		// ✅ FIX: usar la carpeta del propio archivo como base
		$base = trailingslashit( plugin_dir_url( __FILE__ ) );

		wp_enqueue_style(  'prs-cover-upload',  $base . 'cover-upload.css', [], '0.1.2' );
		wp_enqueue_script( 'prs-cover-upload',  $base . 'cover-upload.js',  [], '0.1.2', true );

		wp_localize_script( 'prs-cover-upload', 'PRS_COVER', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'prs_cover_save_crop' ),
		] );
	}

	public static function shortcode_button( $atts = [] ) : string {
		$label = isset( $atts['label'] ) ? sanitize_text_field( $atts['label'] ) : __( 'Upload Book Cover', 'politeia-reading' );
		ob_start(); ?>
		<button type="button" id="prs-cover-upload-btn" class="prs-btn prs-cover-btn"><?php echo esc_html( $label ); ?></button>

		<div id="prs-cu-modal" class="prs-cu-modal" hidden>
			<div class="prs-cu-dialog" role="dialog" aria-modal="true" aria-labelledby="prs-cu-title">
				<h2 id="prs-cu-title" class="prs-cu-title"><?php esc_html_e('Upload Book Cover','politeia-reading'); ?></h2>
				<div class="prs-cu-body">
					<input id="prs-cu-file" type="file" accept="image/*" />
					<div id="prs-cu-stage" class="prs-cu-stage" aria-label="<?php esc_attr_e('Crop area 240x450','politeia-reading'); ?>">
						<img id="prs-cu-img" class="prs-cu-img" alt="" />
					</div>
					<div class="prs-cu-zoom">
						<label for="prs-cu-zoom"><?php esc_html_e('Zoom','politeia-reading'); ?></label>
						<input id="prs-cu-zoom" type="range" min="1" max="3" step="0.01" value="1" />
					</div>
				</div>
				<div class="prs-cu-actions">
					<button type="button" class="prs-btn prs-cu-cancel" data-close><?php esc_html_e('Cancel','politeia-reading'); ?></button>
					<button type="button" class="prs-btn prs-cu-save" id="prs-cu-save"><?php esc_html_e('Save','politeia-reading'); ?></button>
				</div>
			</div>
		</div>
		<?php return ob_get_clean();
	}

	public static function ajax_save_crop() {
		if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'auth' ], 401 );
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'prs_cover_save_crop' ) ) {
			wp_send_json_error( [ 'message' => 'bad_nonce' ], 403 );
		}

		$user_id      = get_current_user_id();
		$user_book_id = isset( $_POST['user_book_id'] ) ? absint( $_POST['user_book_id'] ) : 0;
		if ( ! $user_book_id ) wp_send_json_error( [ 'message' => 'invalid_id' ], 400 );

		global $wpdb;
		$tub = $wpdb->prefix . 'politeia_user_books';
		$ok  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tub} WHERE id=%d AND user_id=%d", $user_book_id, $user_id
		) );
		if ( ! $ok ) wp_send_json_error( [ 'message' => 'forbidden' ], 403 );

		if ( empty( $_FILES['file'] ) || ! empty( $_FILES['file']['error'] ) ) {
			wp_send_json_error( [ 'message' => 'no_file' ], 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$movefile = wp_handle_sideload( $_FILES['file'], [ 'test_form' => false ] );
		if ( ! $movefile || isset( $movefile['error'] ) ) {
			wp_send_json_error( [ 'message' => 'upload_error', 'detail' => $movefile['error'] ?? '' ], 500 );
		}

		$filetype   = wp_check_filetype( $movefile['file'], null );
		$attachment = [
			'post_mime_type' => $filetype['type'],
			'post_title'     => sanitize_file_name( preg_replace( '/\.[^.]+$/', '', basename( $movefile['file'] ) ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_author'    => $user_id,
		];
		$attach_id = wp_insert_attachment( $attachment, $movefile['file'] );
		if ( ! $attach_id ) wp_send_json_error( [ 'message' => 'attach_fail' ], 500 );

		$meta = wp_generate_attachment_metadata( $attach_id, $movefile['file'] );
		wp_update_attachment_metadata( $attach_id, $meta );

		update_post_meta( $attach_id, '_prs_cover_user_id',      $user_id );
		update_post_meta( $attach_id, '_prs_cover_user_book_id', $user_book_id );

		$old = get_posts( [
			'post_type' => 'attachment', 'fields' => 'ids', 'posts_per_page' => -1,
			'author' => $user_id, 'exclude' => [ $attach_id ],
			'meta_query' => [
				[ 'key' => '_prs_cover_user_id',      'value' => $user_id ],
				[ 'key' => '_prs_cover_user_book_id', 'value' => $user_book_id ],
			],
		] );
		foreach ( $old as $oid ) wp_delete_attachment( $oid, true );

		$wpdb->update( $tub, [
			'cover_attachment_id_user' => $attach_id,
			'updated_at'               => current_time( 'mysql', true ),
		], [ 'id' => $user_book_id ] );

		wp_send_json_success( [
			'attachment_id' => $attach_id,
			'src' => wp_get_attachment_image_url( $attach_id, 'full' ),
		] );
	}
}
PRS_Cover_Upload_Feature::init();
