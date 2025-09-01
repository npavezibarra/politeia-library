<?php
/**
 * Schema: wp_politeia_post_reading
 * - id (PK, autoincrement)
 * - user_id (BIGINT, required)
 * - post_id (BIGINT, required)
 * - start_time (datetime, nullable)
 * - end_time (datetime, nullable)
 * - created_at (datetime)
 * - updated_at (datetime)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Politeia_Post_Reading_Schema {

	/** @var string */
	protected static $version_option = 'politeia_post_reading_schema_version';
    protected static $version = '1.0.2';


	/** Nombre de la tabla */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'politeia_post_reading';
	}

	/** Ejecutar migración */
	public static function migrate() {
		global $wpdb;

		$table_name = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			start_time DATETIME DEFAULT NULL,
			end_time DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY post_id (post_id),
			KEY user_post (user_id, post_id)
		) {$charset_collate};";

        dbDelta( $sql );

        // Refuerzos porque dbDelta a veces ignora defaults/ON UPDATE en DATETIME
        $wpdb->query( "ALTER TABLE {$table_name}
        MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP" );
        $wpdb->query( "ALTER TABLE {$table_name}
        MODIFY updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP" );

        update_option( self::$version_option, self::$version );

	}

	/** Hook para actualizar en upgrades del plugin */
	public static function maybe_upgrade() {
		$installed = get_option( self::$version_option );
		if ( $installed !== self::$version ) {
			self::migrate();
		}
	}
}
