<?php
/**
 * Uploads a product's main + gallery images to an Etsy listing.
 *
 * Order is preserved via Etsy's `rank`. Already-uploaded attachments are
 * skipped (tracked in product meta `_mec_uploaded_images`). Remote files are
 * downloaded to a temp file and removed afterwards.
 *
 * @package MaiaEtsyConnector
 */

defined( 'ABSPATH' ) || exit;

class MEC_Image_Sync {

	const MAX_IMAGES = 10; // Etsy listing image limit.

	/**
	 * @return array{uploaded:int, success:int, failed:int}
	 */
	public static function sync( WC_Product $product, int $listing_id ): array {
		$shop_id   = (int) MEC_Settings::get( 'shop_id' );
		$attach_ids = array_slice( self::image_ids( $product ), 0, self::MAX_IMAGES );
		$done      = (array) $product->get_meta( '_mec_uploaded_images' );
		$stats     = array( 'uploaded' => count( $attach_ids ), 'success' => 0, 'failed' => 0 );

		$rank = 1;
		foreach ( $attach_ids as $attach_id ) {
			if ( isset( $done[ $attach_id ] ) ) {
				++$stats['success']; // already on Etsy, don't re-upload.
				++$rank;
				continue;
			}

			$file = self::load_file( (int) $attach_id );
			if ( null === $file ) {
				++$stats['failed'];
				MEC_Logger::add( $product->get_id(), 'upload_image', 'error', array( 'listing_id' => $listing_id, 'message' => sprintf( 'Görsel dosyası okunamadı (attachment %d).', $attach_id ) ) );
				continue;
			}

			$resp = MEC_Api::upload(
				"/shops/{$shop_id}/listings/{$listing_id}/images",
				array( 'rank' => (string) $rank ),
				array(
					'name'     => 'image',
					'filename' => $file['filename'],
					'content'  => $file['content'],
					'type'     => $file['type'],
				)
			);

			if ( ! empty( $file['tmp'] ) ) {
				@unlink( $file['tmp'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}

			if ( is_wp_error( $resp ) ) {
				++$stats['failed'];
				MEC_Logger::add( $product->get_id(), 'upload_image', 'error', array( 'listing_id' => $listing_id, 'http_code' => MEC_Api::http_code( $resp ), 'message' => $resp->get_error_message() ) );
				continue;
			}

			$done[ $attach_id ] = (int) ( $resp['listing_image_id'] ?? 0 );
			++$stats['success'];
			++$rank;
		}

		$product->update_meta_data( '_mec_uploaded_images', $done );
		$product->update_meta_data( '_mec_image_stats', $stats );
		$product->save();
		return $stats;
	}

	/** Main image first, then gallery, de-duplicated. */
	private static function image_ids( WC_Product $product ): array {
		$ids = array();
		if ( $product->get_image_id() ) {
			$ids[] = (int) $product->get_image_id();
		}
		foreach ( $product->get_gallery_image_ids() as $gid ) {
			$ids[] = (int) $gid;
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Return [filename, content, type, tmp?] or null. Reads the local file if
	 * present, otherwise downloads the URL to a temp file.
	 */
	private static function load_file( int $attach_id ): ?array {
		$path = get_attached_file( $attach_id );
		if ( $path && is_readable( $path ) ) {
			return array(
				'filename' => basename( $path ),
				'content'  => (string) file_get_contents( $path ), // phpcs:ignore WordPress.WP.AlternativeFunctions
				'type'     => (string) ( get_post_mime_type( $attach_id ) ?: 'image/jpeg' ),
			);
		}

		$url = wp_get_attachment_url( $attach_id );
		if ( ! $url ) {
			return null;
		}
		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return null;
		}
		return array(
			'filename' => basename( wp_parse_url( $url, PHP_URL_PATH ) ?: 'image.jpg' ),
			'content'  => (string) file_get_contents( $tmp ), // phpcs:ignore WordPress.WP.AlternativeFunctions
			'type'     => (string) ( get_post_mime_type( $attach_id ) ?: 'image/jpeg' ),
			'tmp'      => $tmp,
		);
	}
}
