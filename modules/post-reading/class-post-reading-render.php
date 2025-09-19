<?php
/**
 * Render del botón Start/Finish Reading para posts regulares
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Politeia_Post_Reading_Render {

	const SCRIPT_HANDLE = 'politeia-post-reading';
	const STYLE_HANDLE  = 'politeia-post-reading-css';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_if_needed' ) );
		add_filter( 'the_content', array( __CLASS__, 'inject_button_into_content' ) );
	}

	/** ¿Debemos renderizar en esta vista? */
	protected static function should_render() {
		return ( ! is_admin() && is_singular( 'post' ) );
	}

	/** Encola assets y pasa datos al JS cuando corresponde */
	public static function enqueue_if_needed() {
		if ( ! self::should_render() ) {
			return;
		}

		// URLs de assets (usa la constante del plugin; fallback defensivo si no existe)
		$base_url   = defined( 'POLITEIA_READING_URL' )
			? POLITEIA_READING_URL
			: trailingslashit( plugins_url( '', dirname( __DIR__, 1 ) ) );
		$assets_url = $base_url . 'assets/';

		// CSS
		wp_enqueue_style(
			self::STYLE_HANDLE,
			$assets_url . 'css/post-reading.css',
			array(),
			defined( 'POLITEIA_READING_VERSION' ) ? POLITEIA_READING_VERSION : '1.0.0'
		);

		// JS (sin dependencias; usa fetch nativo)
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			$assets_url . 'js/post-reading.js',
			array(),
			defined( 'POLITEIA_READING_VERSION' ) ? POLITEIA_READING_VERSION : '1.0.0',
			true
		);

		// Datos para el front
		$post_id  = get_queried_object_id();
		$user_id  = get_current_user_id();
		$rest_url = rest_url( 'politeia/v1/post-reading/toggle' );
		$nonce    = wp_create_nonce( 'wp_rest' );

		// Estado inicial y "ya completó" (si está logueado)
		$initial       = array(
			'status' => 'finished',
			'row'    => null,
		);
		$has_completed = false;

		if ( $user_id && class_exists( 'Politeia_Post_Reading_Manager' ) && $post_id ) {
			$initial       = Politeia_Post_Reading_Manager::current_status( $user_id, $post_id );
			$has_completed = Politeia_Post_Reading_Manager::has_completed( $user_id, $post_id );
		}

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'politeiaPostReading',
			array(
				'postId'       => (int) $post_id,
				'nonce'        => $nonce,
				'restUrl'      => $rest_url,
				'isLoggedIn'   => (bool) $user_id,
				'initial'      => $initial,          // { status: started|finished, row: {...}|null }
				'hasCompleted' => (bool) $has_completed, // true si el usuario ya tiene alguna sesión cerrada
				'loginUrl'     => wp_login_url( get_permalink( $post_id ) ),
			)
		);
	}

	/** Inyecta el botón ANTES del contenido del post */
	public static function inject_button_into_content( $content ) {
		if ( ! self::should_render() ) {
			return $content;
		}

		$html = self::get_button_markup();

		// Antes del contenido
		return $html . "\n" . $content;
	}

	/**
	 * Genera el HTML del botón.
	 * Si existe un template en templates/post-reading/button.php lo usa; si no, usa fallback.
	 */
	protected static function get_button_markup() {
		$template = trailingslashit( defined( 'POLITEIA_READING_PATH' ) ? POLITEIA_READING_PATH : plugin_dir_path( dirname( __DIR__, 1 ) ) ) . 'templates/post-reading/button.php';

		// Variables útiles por si el template las quiere leer
		$post_id   = get_queried_object_id();
		$is_logged = is_user_logged_in();

		// Fallback simple si no hay template
		if ( ! file_exists( $template ) ) {
			// Estado inicial para pintar la clase/label sin esperar al JS
			$label     = __( 'Start Reading', 'politeia-reading' );
			$extra_cls = '';

			if ( $is_logged && class_exists( 'Politeia_Post_Reading_Manager' ) ) {
				$state = Politeia_Post_Reading_Manager::current_status( get_current_user_id(), $post_id );
				if ( isset( $state['status'] ) && $state['status'] === 'started' ) {
					$label     = __( 'Finish Reading', 'politeia-reading' );
					$extra_cls = ' is-finished';
				} else {
					// Si ya lo leyó antes (tiene alguna sesión cerrada), mostrar "Again"
					if ( Politeia_Post_Reading_Manager::has_completed( get_current_user_id(), $post_id ) ) {
						$label = __( 'Start Reading Again', 'politeia-reading' );
					}
				}
			} else {
				$label = __( 'Log in to Start', 'politeia-reading' );
			}

			ob_start(); ?>
			<div class="politeia-post-reading-wrap">
				<div class="politeia-post-reading-inner">
					<button
					type="button"
					class="politeia-post-reading-btn<?php echo esc_attr( $extra_cls ); ?>"
					<?php echo $is_logged ? '' : 'disabled'; ?>
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
			<?php
			return ob_get_clean();
		}

		// Template override interno del plugin (mantiene modularidad)
		ob_start();
		include $template;
		return ob_get_clean();
	}
}

// Auto-init del render al cargar el módulo
add_action( 'init', array( 'Politeia_Post_Reading_Render', 'init' ) );
