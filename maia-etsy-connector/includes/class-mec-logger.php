<?php
/**
 * Transfer history / error log, stored in a custom table.
 *
 * @package MaiaEtsyConnector
 */

defined( 'ABSPATH' ) || exit;

class MEC_Logger {

	const DB_VERSION_OPTION = 'mec_db_version';
	const DB_VERSION        = '1';

	/**
	 * Table name including WP prefix.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mec_sync_log';
	}

	/**
	 * Create/upgrade the log table on activation.
	 */
	public static function install_table(): void {
		global $wpdb;
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			listing_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			operation VARCHAR(40) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT '',
			http_code SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			message TEXT NULL,
			retries SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Ensure the table exists (self-heals if the plugin files were updated without re-activation).
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install_table();
		}
	}

	/**
	 * Write one log row. Secrets must never be passed in here.
	 *
	 * @param int    $product_id WooCommerce product id.
	 * @param string $operation  e.g. create, update_price, upload_image.
	 * @param string $status     success|error|skipped.
	 * @param array  $args       listing_id, http_code, message, retries.
	 */
	public static function add( int $product_id, string $operation, string $status, array $args = array() ): void {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'product_id' => $product_id,
				'listing_id' => (int) ( $args['listing_id'] ?? 0 ),
				'operation'  => substr( $operation, 0, 40 ),
				'status'     => substr( $status, 0, 20 ),
				'http_code'  => (int) ( $args['http_code'] ?? 0 ),
				'message'    => isset( $args['message'] ) ? wp_kses_post( (string) $args['message'] ) : null,
				'retries'    => (int) ( $args['retries'] ?? 0 ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%s' )
		);
	}

	/**
	 * Paginated history rows (newest first).
	 *
	 * @return array{rows: array, total: int}
	 */
	public static function get_page( int $paged = 1, int $per_page = 50, string $status = '' ): array {
		global $wpdb;
		$table    = self::table();
		$per_page = max( 1, $per_page );
		$offset   = ( max( 1, $paged ) - 1 ) * $per_page;

		if ( '' !== $status ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d", $status, $per_page, $offset ) );
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
		}

		return array(
			'rows'  => $rows ?: array(),
			'total' => $total,
		);
	}

	/**
	 * Last successful sync datetime, or empty string.
	 */
	public static function last_sync(): string {
		global $wpdb;
		$table = self::table();
		return (string) $wpdb->get_var( "SELECT created_at FROM {$table} WHERE status = 'success' ORDER BY id DESC LIMIT 1" );
	}

	public static function error_count(): int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT product_id) FROM {$table} WHERE status = 'error'" );
	}
}
