<?php
/**
 * REST Controller: Post Reading
 * Rutas:
 *  - POST /politeia/v1/post-reading/toggle
 *  - POST /politeia/v1/post-reading/start
 *  - POST /politeia/v1/post-reading/finish
 *  - GET  /politeia/v1/post-reading/status?post_id=123
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Politeia_Post_Reading_REST {

	const NS   = 'politeia/v1';
	const BASE = 'post-reading';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		// POST /toggle
		register_rest_route(
			self::NS,
			'/' . self::BASE . '/toggle',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_toggle' ),
				'permission_callback' => array( __CLASS__, 'require_logged_in' ),
				'args'                => self::post_args_schema(),
			)
		);

		// POST /start
		register_rest_route(
			self::NS,
			'/' . self::BASE . '/start',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_start' ),
				'permission_callback' => array( __CLASS__, 'require_logged_in' ),
				'args'                => self::post_args_schema(),
			)
		);

		// POST /finish
		register_rest_route(
			self::NS,
			'/' . self::BASE . '/finish',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_finish' ),
				'permission_callback' => array( __CLASS__, 'require_logged_in' ),
				'args'                => self::post_args_schema(),
			)
		);

		// GET /status
		register_rest_route(
			self::NS,
			'/' . self::BASE . '/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_status' ),
				'permission_callback' => array( __CLASS__, 'require_logged_in' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => array( __CLASS__, 'validate_post_exists' ),
						'description'       => 'Post ID para consultar estado.',
					),
				),
			)
		);
	}

	/* ---------- Common ---------- */

	public static function require_logged_in( $request ) {
		return is_user_logged_in() ? true : new WP_Error( 'not_logged_in', 'Authentication required.', array( 'status' => 401 ) );
	}

	protected static function post_args_schema() {
		return array(
			'post_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => array( __CLASS__, 'validate_post_exists' ),
				'description'       => 'Post ID objetivo.',
			),
		);
	}

	public static function validate_post_exists( $value, $request, $param ) {
		$post_id = absint( $value );
		if ( $post_id <= 0 ) {
			return new WP_Error( 'invalid_post', 'Invalid post_id.' );
		}
		// Debe existir y ser público o al menos accesible
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'invalid_post', 'Post not found.' );
		}
		return true;
	}

	protected static function respond( $data, $status = 200 ) {
		return new WP_REST_Response( $data, $status );
	}

	protected static function error_response( $error ) {
		if ( is_wp_error( $error ) ) {
			$code   = $error->get_error_code();
			$msg    = $error->get_error_message();
			$data   = $error->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			return self::respond(
				array(
					'error'   => $code,
					'message' => $msg,
				),
				$status
			);
		}
		return self::respond(
			array(
				'error'   => 'unknown_error',
				'message' => 'Unknown error',
			),
			500
		);
	}

	/* ---------- Handlers ---------- */

	public static function handle_toggle( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$post_id = absint( $request->get_param( 'post_id' ) );

		if ( ! class_exists( 'Politeia_Post_Reading_Manager' ) ) {
			return self::respond(
				array(
					'error'   => 'missing_manager',
					'message' => 'Manager class not loaded.',
				),
				500
			);
		}

		$result = Politeia_Post_Reading_Manager::toggle( $user_id, $post_id );
		if ( is_wp_error( $result ) ) {
			return self::error_response( $result );
		}

		return self::respond(
			array(
				'action'  => 'toggle',
				'status'  => isset( $result['status'] ) ? $result['status'] : null, // started|finished|already_*
				'row'     => isset( $result['row'] ) ? $result['row'] : null,
				'post_id' => $post_id,
				'user_id' => $user_id,
			),
			200
		);
	}

	public static function handle_start( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$post_id = absint( $request->get_param( 'post_id' ) );

		if ( ! class_exists( 'Politeia_Post_Reading_Manager' ) ) {
			return self::respond(
				array(
					'error'   => 'missing_manager',
					'message' => 'Manager class not loaded.',
				),
				500
			);
		}

		$result = Politeia_Post_Reading_Manager::start( $user_id, $post_id );
		if ( is_wp_error( $result ) ) {
			return self::error_response( $result );
		}

		return self::respond(
			array(
				'action'  => 'start',
				'status'  => isset( $result['status'] ) ? $result['status'] : null, // started|already_started
				'row'     => isset( $result['row'] ) ? $result['row'] : null,
				'post_id' => $post_id,
				'user_id' => $user_id,
			),
			200
		);
	}

	public static function handle_finish( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$post_id = absint( $request->get_param( 'post_id' ) );

		if ( ! class_exists( 'Politeia_Post_Reading_Manager' ) ) {
			return self::respond(
				array(
					'error'   => 'missing_manager',
					'message' => 'Manager class not loaded.',
				),
				500
			);
		}

		$result = Politeia_Post_Reading_Manager::finish( $user_id, $post_id );
		if ( is_wp_error( $result ) ) {
			return self::error_response( $result );
		}

		return self::respond(
			array(
				'action'  => 'finish',
				'status'  => isset( $result['status'] ) ? $result['status'] : null, // finished|already_finished
				'row'     => isset( $result['row'] ) ? $result['row'] : null,
				'post_id' => $post_id,
				'user_id' => $user_id,
			),
			200
		);
	}

	public static function handle_status( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$post_id = absint( $request->get_param( 'post_id' ) );

		if ( ! class_exists( 'Politeia_Post_Reading_Manager' ) ) {
			return self::respond(
				array(
					'error'   => 'missing_manager',
					'message' => 'Manager class not loaded.',
				),
				500
			);
		}

		$result = Politeia_Post_Reading_Manager::current_status( $user_id, $post_id );
		if ( is_wp_error( $result ) ) {
			return self::error_response( $result );
		}

		return self::respond(
			array(
				'action'  => 'status',
				'status'  => isset( $result['status'] ) ? $result['status'] : null, // started|finished
				'row'     => isset( $result['row'] ) ? $result['row'] : null,
				'post_id' => $post_id,
				'user_id' => $user_id,
			),
			200
		);
	}
}

add_action( 'init', array( 'Politeia_Post_Reading_REST', 'init' ) );
