<?php
/**
 * Template: Start/Finish Reading button for regular posts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id   = get_queried_object_id();
$is_logged = is_user_logged_in();

$label         = __( 'Start Reading', 'politeia-reading' );
$extra_cls     = '';
$disabled_attr = '';

if ( $is_logged && class_exists( 'Politeia_Post_Reading_Manager' ) ) {
	$state = Politeia_Post_Reading_Manager::current_status( get_current_user_id(), $post_id );
	if ( isset( $state['status'] ) && $state['status'] === 'started' ) {
		$label     = __( 'Finish Reading', 'politeia-reading' );
		$extra_cls = ' is-finished';
	} elseif ( Politeia_Post_Reading_Manager::has_completed( get_current_user_id(), $post_id ) ) {
			$label = __( 'Start Reading Again', 'politeia-reading' );
	}
} else {
	$label         = __( 'Log in to Start', 'politeia-reading' );
	$disabled_attr = 'disabled';
}

?>
<div class="politeia-post-reading-wrap">
	<div class="politeia-post-reading-inner">
	<button
		type="button"
		class="politeia-post-reading-btn<?php echo esc_attr( $extra_cls ); ?>"
		data-post-id="<?php echo esc_attr( $post_id ); ?>"
		<?php echo $disabled_attr; ?>
	>
		<?php echo esc_html( $label ); ?>
	</button>

	<?php if ( ! $is_logged ) : ?>
		<p class="politeia-post-reading-note">
		<a href="<?php echo esc_url( wp_login_url( get_permalink( $post_id ) ) ); ?>">
			<?php esc_html_e( 'Log in', 'politeia-reading' ); ?>
		</a>
		<?php esc_html_e( 'to track your reading.', 'politeia-reading' ); ?>
		</p>
	<?php endif; ?>
	</div>
</div>

