<?php
/**
 * Background transfer queue built on WooCommerce Action Scheduler.
 *
 * One async action per product. Duplicate pending actions for the same
 * product+operation are refused (no concurrent duplicate work). 429 and 5xx
 * are rescheduled (Retry-After / bounded exponential backoff); 4xx validation
 * errors are permanent and never retried.
 *
 * @package MaiaEtsyConnector
 */

defined( 'ABSPATH' ) || exit;

class MEC_Queue {

	const HOOK  = 'mec_sync_product';
	const GROUP = 'mec-sync';

	const MAX_TRANSIENT_RETRIES = 5;
	const MAX_BACKOFF           = 300; // seconds

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'handle' ), 10, 2 );
	}

	/**
	 * Queue one product. $operation: create|price|stock|description|images|all.
	 * Returns false if a duplicate is already pending.
	 */
	public static function enqueue( int $product_id, string $operation = 'create' ): bool {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}
		$args = array( $product_id, $operation );
		if ( as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			return false; // already queued for this exact op.
		}
		$product = wc_get_product( $product_id );
		if ( $product ) {
			$product->update_meta_data( '_mec_status', 'pending' );
			$product->delete_meta_data( '_mec_retries' );
			$product->save();
		}
		as_enqueue_async_action( self::HOOK, $args, self::GROUP );
		return true;
	}

	/**
	 * Action Scheduler callback.
	 */
	public static function handle( int $product_id, string $operation = 'create' ): void {
		if ( ! MEC_Settings::is_connected() ) {
			MEC_Logger::add( $product_id, $operation, 'error', array( 'message' => __( 'Etsy bağlantısı yok; görev durduruldu.', 'maia-etsy-connector' ) ) );
			return;
		}

		$result = ( 'create' === $operation )
			? MEC_Product_Sync::create_draft( $product_id )
			: MEC_Product_Sync::update_listing( $product_id, $operation );

		if ( ! empty( $result['ok'] ) ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$product->delete_meta_data( '_mec_retries' );
				$product->save();
			}
			return;
		}

		$code        = (int) ( $result['http'] ?? 0 );
		$retry_after = (int) ( $result['retry_after'] ?? 0 );
		$transient   = 429 === $code || ( $code >= 500 && $code <= 599 );

		if ( ! $transient ) {
			return; // permanent (validation/auth); status already 'error'. No retry.
		}

		$product = wc_get_product( $product_id );
		$retries = $product ? (int) $product->get_meta( '_mec_retries' ) : self::MAX_TRANSIENT_RETRIES;
		if ( $retries >= self::MAX_TRANSIENT_RETRIES ) {
			MEC_Logger::add( $product_id, $operation, 'error', array( 'http_code' => $code, 'retries' => $retries, 'message' => __( 'Tekrar deneme sınırına ulaşıldı.', 'maia-etsy-connector' ) ) );
			return;
		}

		$delay = 429 === $code
			? max( 1, $retry_after )
			: min( self::MAX_BACKOFF, 2 ** $retries );

		if ( $product ) {
			$product->update_meta_data( '_mec_retries', $retries + 1 );
			$product->update_meta_data( '_mec_status', 'pending' );
			$product->save();
		}
		as_schedule_single_action( time() + $delay, self::HOOK, array( $product_id, $operation ), self::GROUP );
	}

	/**
	 * Count of pending/in-progress actions in our group.
	 */
	public static function pending_count(): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}
		return (int) count(
			as_get_scheduled_actions(
				array(
					'hook'     => self::HOOK,
					'group'    => self::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 0,
				),
				'ids'
			)
		);
	}
}
