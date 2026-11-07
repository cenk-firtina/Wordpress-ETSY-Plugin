<?php
/**
 * WooCommerce category -> Etsy taxonomy mapping.
 *
 * Map stored as option `mec_category_map`: [ woo_term_id => etsy_taxonomy_id ].
 *
 * @package MaiaEtsyConnector
 */

defined( 'ABSPATH' ) || exit;

class MEC_Category_Mapper {

	const OPTION = 'mec_category_map';

	public static function get_map(): array {
		$m = get_option( self::OPTION, array() );
		return is_array( $m ) ? array_map( 'intval', $m ) : array();
	}

	public static function save_map( array $map ): void {
		$clean = array();
		foreach ( $map as $term_id => $taxonomy_id ) {
			$taxonomy_id = (int) $taxonomy_id;
			if ( $taxonomy_id > 0 ) {
				$clean[ (int) $term_id ] = $taxonomy_id;
			}
		}
		update_option( self::OPTION, $clean );
	}

	/**
	 * Resolve the Etsy taxonomy_id for a product: first mapped product category,
	 * else the per-product override, else the global default. 0 if none.
	 */
	public static function taxonomy_for_product( WC_Product $product ): int {
		$override = (int) $product->get_meta( '_mec_etsy_taxonomy_id' );
		if ( $override > 0 ) {
			return $override;
		}
		$map      = self::get_map();
		$term_ids = $product->get_category_ids();
		foreach ( $term_ids as $term_id ) {
			if ( ! empty( $map[ (int) $term_id ] ) ) {
				return (int) $map[ (int) $term_id ];
			}
		}
		return (int) MEC_Settings::get( 'default_taxonomy_id' );
	}

	/**
	 * Etsy seller taxonomy tree, cached 12h. Returns [] on error.
	 */
	public static function etsy_taxonomy_nodes(): array {
		$cached = get_transient( 'mec_taxonomy_nodes' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$resp = MEC_Api::get( '/seller-taxonomy/nodes' );
		if ( is_wp_error( $resp ) ) {
			return array();
		}
		$nodes = $resp['results'] ?? array();
		set_transient( 'mec_taxonomy_nodes', $nodes, 12 * HOUR_IN_SECONDS );
		return $nodes;
	}

	/**
	 * Flatten the taxonomy tree into [ ['id'=>int,'label'=>'A > B > C'], ... ] for a select box.
	 */
	public static function taxonomy_choices(): array {
		$out = array();
		foreach ( self::etsy_taxonomy_nodes() as $node ) {
			self::flatten_node( $node, '', $out );
		}
		return $out;
	}

	private static function flatten_node( array $node, string $prefix, array &$out ): void {
		$label = '' === $prefix ? (string) ( $node['name'] ?? '' ) : $prefix . ' > ' . (string) ( $node['name'] ?? '' );
		$out[] = array(
			'id'    => (int) ( $node['id'] ?? 0 ),
			'label' => $label,
		);
		foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
			self::flatten_node( $child, $label, $out );
		}
	}
}
