<?php
/**
 * AJAX (enqueue + progress) and admin-post form handlers (settings, category).
 * Every entry point checks capability + nonce.
 *
 * @package MaiaEtsyConnector
 */

defined( 'ABSPATH' ) || exit;

class MEC_Ajax {

	const CAP = 'manage_woocommerce';

	public static function init(): void {
		add_action( 'wp_ajax_mec_enqueue', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_mec_progress', array( __CLASS__, 'progress' ) );
		add_action( 'admin_post_mec_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_mec_save_category', array( __CLASS__, 'save_category' ) );
	}

	private static function verify_ajax(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Yetkiniz yok.', 'maia-etsy-connector' ) ), 403 );
		}
		check_ajax_referer( 'mec_ajax', 'nonce' );
	}

	/**
	 * Queue one or more products. POST: ids[] (ints), op (string).
	 */
	public static function enqueue(): void {
		self::verify_ajax();
		if ( ! MEC_Settings::is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Etsy bağlantısı yok.', 'maia-etsy-connector' ) ) );
		}
		$ids = array_map( 'absint', (array) ( $_POST['ids'] ?? array() ) );
		$ids = array_filter( array_unique( $ids ) );
		$op  = sanitize_key( $_POST['op'] ?? 'create' );
		if ( ! in_array( $op, array( 'create', 'price', 'stock', 'description', 'images', 'all' ), true ) ) {
			$op = 'create';
		}
		if ( ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'Ürün seçilmedi.', 'maia-etsy-connector' ) ) );
		}

		$queued = 0;
		$skipped = 0;
		foreach ( $ids as $pid ) {
			if ( MEC_Queue::enqueue( (int) $pid, $op ) ) {
				++$queued;
			} else {
				++$skipped;
			}
		}
		wp_send_json_success(
			array(
				'queued'  => $queued,
				'skipped' => $skipped,
				'message' => sprintf( /* translators: 1: queued, 2: skipped */ __( '%1$d ürün kuyruğa eklendi, %2$d atlandı.', 'maia-etsy-connector' ), $queued, $skipped ),
			)
		);
	}

	/**
	 * Progress for a set of product ids. POST: ids[].
	 */
	public static function progress(): void {
		self::verify_ajax();
		$ids = array_filter( array_map( 'absint', (array) ( $_POST['ids'] ?? array() ) ) );
		$out = array( 'success' => 0, 'pending' => 0, 'error' => 0, 'total' => count( $ids ), 'rows' => array() );
		foreach ( $ids as $pid ) {
			$product = wc_get_product( (int) $pid );
			if ( ! $product ) {
				continue;
			}
			$listing_id = (int) $product->get_meta( '_mec_listing_id' );
			$status     = (string) $product->get_meta( '_mec_status' );
			if ( $listing_id && in_array( $status, array( 'done', '' ), true ) ) {
				$state = 'success';
			} elseif ( 'error' === $status || 'review' === $status ) {
				$state = 'error';
			} else {
				$state = 'pending';
			}
			++$out[ $state ];
			$out['rows'][ (int) $pid ] = array( 'state' => $state, 'listing_id' => $listing_id );
		}
		$out['percent'] = $out['total'] ? (int) round( ( $out['success'] / $out['total'] ) * 100 ) : 0;
		wp_send_json_success( $out );
	}

	/* ---------- admin-post form handlers ---------- */

	public static function save_settings(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'maia-etsy-connector' ) );
		}
		check_admin_referer( 'mec_save_settings' );

		$fields = array( 'api_keystring', 'default_shipping_profile_id', 'default_readiness_state_id', 'default_return_policy_id', 'default_taxonomy_id', 'who_made', 'when_made', 'default_quantity', 'price_mode', 'price_percent', 'price_fixed', 'etsy_currency', 'exchange_rate' );
		$input  = array();
		foreach ( $fields as $f ) {
			if ( isset( $_POST[ $f ] ) ) {
				$input[ $f ] = wp_unslash( $_POST[ $f ] );
			}
		}
		// Secret: only overwrite when a new non-empty value is submitted.
		$secret = isset( $_POST['shared_secret'] ) ? trim( (string) wp_unslash( $_POST['shared_secret'] ) ) : '';
		if ( '' !== $secret ) {
			$input['shared_secret'] = $secret;
		}

		MEC_Settings::save( $input );
		self::redirect( 'mec-settings', __( 'Ayarlar kaydedildi.', 'maia-etsy-connector' ), 'success' );
	}

	public static function save_category(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'maia-etsy-connector' ) );
		}
		check_admin_referer( 'mec_save_category' );
		$map = array();
		foreach ( (array) ( $_POST['mec_map'] ?? array() ) as $term_id => $tax_id ) {
			$map[ (int) $term_id ] = (int) $tax_id;
		}
		MEC_Category_Mapper::save_map( $map );
		self::redirect( 'mec-category', __( 'Kategori eşleştirmeleri kaydedildi.', 'maia-etsy-connector' ), 'success' );
	}

	private static function redirect( string $page, string $message, string $type ): void {
		wp_safe_redirect( add_query_arg( array( 'page' => $page, 'mec_notice' => rawurlencode( $message ), 'mec_type' => $type ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
