<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Politeia_Reading_REST {
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_rest_route(
			'politeia/v1',
			'/user-books/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'update_user_book' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);
	}

	public static function update_user_book( WP_REST_Request $req ) {
		$user_id = get_current_user_id();
		$id      = (int) $req['id'];

		// Nonce check (sent as X-WP-Nonce typical, but we’ll also accept our field)
		if ( ! wp_verify_nonce( $req->get_header( 'X-WP-Nonce' ) ?: ( $req['nonce'] ?? '' ), 'wp_rest' )
			&& ! wp_verify_nonce( $req['prs_update_user_book_nonce'] ?? '', 'prs_update_user_book' ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_nonce' ), 403 );
		}

		$reading = sanitize_text_field( $req['reading_status'] ?? '' );
		$owning  = sanitize_text_field( $req['owning_status'] ?? '' );

		$valid_reading = array( 'not_started', 'started', 'finished' );
		$valid_owning  = array( 'in_shelf', 'lost', 'borrowed', 'borrowing', 'sold' );
		if ( $reading && ! in_array( $reading, $valid_reading, true ) ) {
			$reading = '';
		}
		if ( $owning && ! in_array( $owning, $valid_owning, true ) ) {
			$owning = '';
		}

		global $wpdb;
		$table = $wpdb->prefix . 'politeia_user_books';

		// Ownership check
		$owner = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM $table WHERE id=%d", $id ) );
		if ( (int) $owner !== (int) $user_id ) {
			return new WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
		}

		$data = array();
		if ( $reading ) {
			$data['reading_status'] = $reading;
		}
		if ( $owning ) {
			$data['owning_status'] = $owning;
		}
		if ( ! $data ) {
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->update( $table, $data, array( 'id' => $id ) );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
Politeia_Reading_REST::init();
