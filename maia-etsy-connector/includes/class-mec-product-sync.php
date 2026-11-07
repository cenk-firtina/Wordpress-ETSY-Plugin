<?php
/**
 * Maps a WooCommerce product to an Etsy draft listing and performs
 * create / update operations. Uses WooCommerce CRUD only (HPOS-safe).
 *
 * Draft-only policy: listings are created with state "draft". Nothing is
 * published without the user acting in Etsy. Re-running on an already-mapped
 * product never creates a duplicate — it updates the existing listing.
 *
 * @package MaiaEtsyConnector
 */

defined( 'ABSPATH' ) || exit;

class MEC_Product_Sync {

	/**
	 * Resolve all Etsy fields for a product, applying per-product overrides,
	 * then the Woo value, then the global default. Returns the payload plus a
	 * list of blocking validation errors (Turkish) and a `review` flag.
	 *
	 * @return array{fields: array, errors: string[], review: bool}
	 */
	public static function resolve( WC_Product $product ): array {
		$s      = MEC_Settings::all();
		$errors = array();
		$review = false;

		$title = (string) $product->get_meta( '_mec_etsy_title' );
		if ( '' === $title ) {
			$title = $product->get_name();
		}
		$title = self::clean_title( $title );

		// Prefer the per-product English text; otherwise fall back to the shop-language
		// product description (Etsy auto-translates listings to the buyer's language).
		$desc = (string) $product->get_meta( '_mec_etsy_desc_en' );
		if ( '' === trim( $desc ) ) {
			$desc = (string) $product->get_description();
		}
		// Etsy descriptions are plain text: turn block tags into newlines, strip the rest.
		$desc = preg_replace( '#</(p|div|h[1-6]|li)>|<br\s*/?>#i', "\n", $desc );
		$desc = trim( html_entity_decode( wp_strip_all_tags( $desc ), ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $desc ) {
			$errors[] = __( 'Ürün açıklaması boş.', 'maia-etsy-connector' );
		}

		$taxonomy_id = MEC_Category_Mapper::taxonomy_for_product( $product );
		if ( $taxonomy_id <= 0 ) {
			$errors[] = __( 'Etsy kategorisi (taxonomy_id) eşleştirilmemiş.', 'maia-etsy-connector' );
		}

		$price = self::resolve_price( $product, $s );
		if ( $price <= 0 ) {
			$errors[] = __( 'Geçerli bir Etsy fiyatı hesaplanamadı.', 'maia-etsy-connector' );
		}

		if ( (int) $s['default_shipping_profile_id'] <= 0 ) {
			$errors[] = __( 'Varsayılan Etsy kargo profili seçilmemiş (Ayarlar).', 'maia-etsy-connector' );
		}

		$fields = array(
			'quantity'            => self::resolve_quantity( $product, $s ),
			'title'               => $title,
			'description'         => $desc,
			'price'               => $price,
			'who_made'            => $s['who_made'],
			'when_made'           => $s['when_made'],
			'taxonomy_id'         => $taxonomy_id,
			'shipping_profile_id' => (int) $s['default_shipping_profile_id'],
			'type'                => 'physical',
			'tags'                => self::resolve_tags( $product ),
			'materials'           => self::resolve_materials( $product ),
		);
		if ( (int) $s['default_return_policy_id'] > 0 ) {
			$fields['return_policy_id'] = (int) $s['default_return_policy_id'];
		}
		if ( (int) $s['default_readiness_state_id'] > 0 ) {
			$fields['readiness_state_id'] = (int) $s['default_readiness_state_id'];
		}

		return array(
			'fields' => $fields,
			'errors' => $errors,
			'review' => $review,
		);
	}

	/**
	 * Create a draft listing. Deduplicates on the stored listing id.
	 *
	 * @return array{ok: bool, listing_id: int, message: string}
	 */
	public static function create_draft( int $product_id ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return self::fail( $product_id, 'create', 0, __( 'Ürün bulunamadı.', 'maia-etsy-connector' ) );
		}

		$existing = (int) $product->get_meta( '_mec_listing_id' );
		if ( $existing > 0 ) {
			// Already on Etsy: update instead of creating a duplicate.
			return self::update_listing( $product_id, 'all' );
		}

		$resolved = self::resolve( $product );
		if ( $resolved['errors'] ) {
			$status = $resolved['review'] ? 'review' : 'error';
			$product->update_meta_data( '_mec_status', $status );
			$product->save();
			MEC_Logger::add( $product_id, 'create', 'error', array( 'message' => implode( ' | ', $resolved['errors'] ) ) );
			return array( 'ok' => false, 'listing_id' => 0, 'message' => implode( ' | ', $resolved['errors'] ) );
		}

		$shop_id = (int) MEC_Settings::get( 'shop_id' );
		$body    = $resolved['fields'];
		unset( $body['type'] ); // createDraftListing default type is physical; keep body minimal.

		$resp = MEC_Api::post_form( "/shops/{$shop_id}/listings", $body );
		if ( is_wp_error( $resp ) ) {
			return self::fail( $product_id, 'create', MEC_Api::http_code( $resp ), $resp->get_error_message(), 0, MEC_Api::retry_after( $resp ) );
		}

		$listing_id = (int) ( $resp['listing_id'] ?? 0 );
		if ( ! $listing_id ) {
			return self::fail( $product_id, 'create', 0, __( 'Etsy Listing ID alınamadı.', 'maia-etsy-connector' ) );
		}

		$product->update_meta_data( '_mec_listing_id', $listing_id );
		$product->update_meta_data( '_mec_status', 'done' );
		$product->update_meta_data( '_mec_last_sync', current_time( 'mysql' ) );
		$product->save();
		MEC_Logger::add( $product_id, 'create', 'success', array( 'listing_id' => $listing_id, 'message' => __( 'Taslak oluşturuldu.', 'maia-etsy-connector' ) ) );

		// Upload photos (non-fatal: listing already exists as draft).
		MEC_Image_Sync::sync( $product, $listing_id );

		return array( 'ok' => true, 'listing_id' => $listing_id, 'message' => __( 'Taslak oluşturuldu.', 'maia-etsy-connector' ) );
	}

	/**
	 * Update an existing listing. $mode: price|stock|description|images|all.
	 *
	 * @return array{ok: bool, listing_id: int, message: string}
	 */
	public static function update_listing( int $product_id, string $mode = 'all' ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return self::fail( $product_id, 'update', 0, __( 'Ürün bulunamadı.', 'maia-etsy-connector' ) );
		}
		$listing_id = (int) $product->get_meta( '_mec_listing_id' );
		if ( ! $listing_id ) {
			return self::create_draft( $product_id );
		}

		$shop_id  = (int) MEC_Settings::get( 'shop_id' );
		$resolved = self::resolve( $product );

		// Listing-level text fields (title/description/tags/materials/taxonomy).
		if ( in_array( $mode, array( 'description', 'all' ), true ) ) {
			$f = $resolved['fields'];
			if ( ! $resolved['review'] ) {
				$patch = MEC_Api::patch_form(
					"/shops/{$shop_id}/listings/{$listing_id}",
					array(
						'title'       => $f['title'],
						'description' => $f['description'],
						'tags'        => $f['tags'],
						'materials'   => $f['materials'],
						'taxonomy_id' => $f['taxonomy_id'],
					)
				);
				if ( is_wp_error( $patch ) ) {
					return self::fail( $product_id, 'update_desc', MEC_Api::http_code( $patch ), $patch->get_error_message(), $listing_id, MEC_Api::retry_after( $patch ) );
				}
			}
		}

		// Price and/or stock via the inventory endpoint (get-modify-put).
		if ( in_array( $mode, array( 'price', 'stock', 'all' ), true ) ) {
			$inv = self::update_inventory( $listing_id, $product, $resolved['fields'], $mode );
			if ( is_wp_error( $inv ) ) {
				return self::fail( $product_id, 'update_inventory', MEC_Api::http_code( $inv ), $inv->get_error_message(), $listing_id, MEC_Api::retry_after( $inv ) );
			}
		}

		if ( in_array( $mode, array( 'images', 'all' ), true ) ) {
			MEC_Image_Sync::sync( $product, $listing_id );
		}

		$product->update_meta_data( '_mec_last_sync', current_time( 'mysql' ) );
		$product->update_meta_data( '_mec_status', 'done' );
		$product->save();
		MEC_Logger::add( $product_id, 'update_' . $mode, 'success', array( 'listing_id' => $listing_id ) );

		return array( 'ok' => true, 'listing_id' => $listing_id, 'message' => __( 'Güncellendi.', 'maia-etsy-connector' ) );
	}

	/**
	 * Fetch current inventory, overwrite price/quantity on every offering, PUT back.
	 *
	 * @return array|WP_Error
	 */
	private static function update_inventory( int $listing_id, WC_Product $product, array $fields, string $mode ) {
		$inv = MEC_Api::get( "/listings/{$listing_id}/inventory" );
		if ( is_wp_error( $inv ) ) {
			return $inv;
		}
		$products = $inv['products'] ?? array();
		if ( ! $products ) {
			return new WP_Error( 'mec_inv', __( 'Etsy envanteri okunamadı.', 'maia-etsy-connector' ) );
		}

		$new_price = (float) $fields['price']; // inventory PUT takes a float in major currency units.
		$quantity  = $fields['quantity'];

		$out = array();
		foreach ( $products as $p ) {
			$offerings = array();
			foreach ( (array) ( $p['offerings'] ?? array() ) as $o ) {
				// Keep the existing price when only stock is updated: Money{amount,divisor} -> major units.
				$divisor    = (int) ( $o['price']['divisor'] ?? 100 );
				$keep_price = ( isset( $o['price']['amount'] ) && $divisor ) ? (float) $o['price']['amount'] / $divisor : $new_price;
				$offering   = array(
					'quantity'   => ( 'price' === $mode ) ? (int) ( $o['quantity'] ?? $quantity ) : $quantity,
					'price'      => ( 'stock' === $mode ) ? round( $keep_price, 2 ) : $new_price,
					'is_enabled' => (bool) ( $o['is_enabled'] ?? true ),
				);
				if ( isset( $o['readiness_state_id'] ) ) {
					$offering['readiness_state_id'] = (int) $o['readiness_state_id'];
				}
				$offerings[] = $offering;
			}
			$out[] = array(
				'sku'            => (string) ( $p['sku'] ?? $product->get_sku() ),
				'property_values' => $p['property_values'] ?? array(),
				'offerings'      => $offerings,
			);
		}

		return MEC_Api::put_json( "/listings/{$listing_id}/inventory", array( 'products' => $out ) );
	}

	/* ---------- field resolvers ---------- */

	private static function resolve_price( WC_Product $product, array $s ): float {
		$override = (string) $product->get_meta( '_mec_etsy_price' );
		if ( '' !== $override && (float) $override > 0 ) {
			return round( (float) $override, 2 ); // per-product Etsy price wins, already in Etsy currency.
		}
		$base = (float) wc_get_price_to_display( $product, array( 'price' => $product->get_price() ) );
		if ( $base <= 0 ) {
			$base = (float) $product->get_price();
		}
		$rate     = (float) $s['exchange_rate'] > 0 ? (float) $s['exchange_rate'] : 1.0;
		$etsy_base = $base * $rate;

		$value = match ( $s['price_mode'] ) {
			'percent' => $etsy_base * ( 1 + (float) $s['price_percent'] / 100 ),
			'fixed'   => $etsy_base + (float) $s['price_fixed'],
			default   => $etsy_base, // woo | etsy_custom-without-override
		};
		return round( max( 0, $value ), 2 );
	}

	private static function resolve_quantity( WC_Product $product, array $s ): int {
		if ( $product->managing_stock() ) {
			$qty = (int) $product->get_stock_quantity();
			return $qty > 0 ? $qty : 0;
		}
		return max( 1, (int) $s['default_quantity'] );
	}

	/** Etsy allows max 13 tags, each <= 20 chars, no commas. */
	private static function resolve_tags( WC_Product $product ): array {
		$raw = (string) $product->get_meta( '_mec_etsy_tags' );
		if ( '' === $raw ) {
			$raw = implode( ',', wp_get_product_terms_safe( $product->get_id(), 'product_tag' ) );
		}
		$tags = array();
		foreach ( explode( ',', $raw ) as $t ) {
			$t = trim( preg_replace( '/[^\p{L}\p{N} ]/u', '', $t ) );
			if ( '' !== $t ) {
				$tags[] = mb_substr( $t, 0, 20 );
			}
		}
		return array_slice( array_values( array_unique( $tags ) ), 0, 13 );
	}

	private static function resolve_materials( WC_Product $product ): array {
		$raw = (string) $product->get_meta( '_mec_etsy_materials' );
		if ( '' === $raw ) {
			return array();
		}
		$out = array();
		foreach ( explode( ',', $raw ) as $m ) {
			$m = trim( preg_replace( '/[^\p{L}\p{N} ]/u', '', $m ) );
			if ( '' !== $m ) {
				$out[] = mb_substr( $m, 0, 45 );
			}
		}
		return array_slice( $out, 0, 13 );
	}

	/** Etsy listing titles: max 140 chars, no newlines. */
	private static function clean_title( string $title ): string {
		$title = preg_replace( '/\s+/u', ' ', trim( wp_strip_all_tags( $title ) ) );
		return mb_substr( $title, 0, 140 );
	}

	private static function fail( int $product_id, string $op, int $http, string $message, int $listing_id = 0, int $retry_after = 0 ): array {
		$product = wc_get_product( $product_id );
		if ( $product ) {
			$retries = (int) $product->get_meta( '_mec_retries' );
			$product->update_meta_data( '_mec_status', 'error' );
			$product->save();
		} else {
			$retries = 0;
		}
		MEC_Logger::add( $product_id, $op, 'error', array( 'http_code' => $http, 'message' => $message, 'listing_id' => $listing_id, 'retries' => $retries ) );
		return array( 'ok' => false, 'listing_id' => $listing_id, 'message' => $message, 'http' => $http, 'retry_after' => $retry_after );
	}
}

/**
 * Safe product term-name fetch (avoids notices when WC term helpers vary).
 */
function wp_get_product_terms_safe( int $product_id, string $taxonomy ): array {
	$terms = wp_get_post_terms( $product_id, $taxonomy, array( 'fields' => 'names' ) );
	return is_wp_error( $terms ) ? array() : $terms;
}
