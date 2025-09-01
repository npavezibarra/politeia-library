<?php
/**
 * Post Reading Manager
 * - Capa de negocio: start/finish/toggle por usuario y post
 * - Mantiene a lo sumo un registro “abierto” (sin end_time) por (user_id, post_id)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Politeia_Post_Reading_Manager {

	/** Nombre de la tabla (con prefijo) */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'politeia_post_reading';
	}

	/** Obtiene el registro abierto (end_time NULL) para user+post */
	public static function get_open_row( $user_id, $post_id ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND post_id = %d AND end_time IS NULL ORDER BY id DESC LIMIT 1",
				$user_id,
				$post_id
			)
		);
	}

	/** Obtiene el último registro (abierto o cerrado) para user+post */
	public static function get_last_row( $user_id, $post_id ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND post_id = %d ORDER BY id DESC LIMIT 1",
				$user_id,
				$post_id
			)
		);
	}

	/** Inicia lectura: crea registro con start_time ahora (si no hay uno abierto) */
	public static function start( $user_id, $post_id ) {
		if ( ! $user_id ) {
			return new WP_Error( 'not_logged_in', 'User not logged in.' );
		}
		if ( ! $post_id ) {
			return new WP_Error( 'invalid_post', 'Invalid post.' );
		}

		global $wpdb;
		$table = self::table();

		// Si ya hay uno abierto, reusar
		$open = self::get_open_row( $user_id, $post_id );
		if ( $open ) {
			return array(
				'status' => 'already_started',
				'row'    => $open,
			);
		}

		$now = current_time( 'mysql' ); // ya lo tienes

		$inserted = $wpdb->insert(
			$table,
			array(
				'user_id'    => $user_id,
				'post_id'    => $post_id,
				'start_time' => $now,
				'end_time'   => null,
				'created_at' => $now,   // ⬅️ nuevo
				'updated_at' => $now,   // ⬅️ nuevo
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', 'Could not start reading.' );
		}

		$row_id = (int) $wpdb->insert_id;
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $row_id ) );

		return array(
			'status' => 'started',
			'row'    => $row,
		);
	}

	/** Finaliza lectura: pone end_time ahora en el registro abierto */
	public static function finish( $user_id, $post_id ) {
		if ( ! $user_id ) {
			return new WP_Error( 'not_logged_in', 'User not logged in.' );
		}
		if ( ! $post_id ) {
			return new WP_Error( 'invalid_post', 'Invalid post.' );
		}

		global $wpdb;
		$table = self::table();

		$open = self::get_open_row( $user_id, $post_id );
		if ( ! $open ) {
			// No hay sesión abierta; devolver estado idempotente
			$last = self::get_last_row( $user_id, $post_id );
			return array(
				'status' => 'already_finished',
				'row'    => $last,
			);
		}

		$now   = current_time( 'mysql' );
		$ok    = $wpdb->update(
			$table,
			array( 'end_time' => $now ),
			array( 'id' => (int) $open->id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $ok ) {
			return new WP_Error( 'db_update_failed', 'Could not finish reading.' );
		}

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $open->id ) );

		return array(
			'status' => 'finished',
			'row'    => $row,
		);
	}

	/** Toggle: si hay registro abierto → finish; si no → start */
	public static function toggle( $user_id, $post_id ) {
		$open = self::get_open_row( $user_id, $post_id );
		if ( $open ) {
			return self::finish( $user_id, $post_id );
		}
		return self::start( $user_id, $post_id );
	}

	/** Estado actual para UI: started|finished y última fila */
	public static function current_status( $user_id, $post_id ) {
		$open = self::get_open_row( $user_id, $post_id );
		if ( $open ) {
			return array(
				'status' => 'started',
				'row'    => $open,
			);
		}
		$last = self::get_last_row( $user_id, $post_id );
		return array(
			'status' => 'finished',
			'row'    => $last,
		);
	}

	public static function has_completed( $user_id, $post_id ) {
		global $wpdb;
		$table = self::table();
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$table} 
				 WHERE user_id = %d AND post_id = %d AND end_time IS NOT NULL 
				 LIMIT 1",
				$user_id, $post_id
			)
		);
		return (bool) $exists;
	}
	
}
