<?php
/**
 * "Etsy Bilgileri" tab on the WooCommerce product edit screen.
 *
 * Stores Etsy-specific overrides as product meta. WooCommerce (Turkish)
 * description fields are never touched here.
 *
 * Mapping / status meta keys (also written by the sync):
 *   _mec_listing_id, _mec_status (pending|done|error), _mec_last_sync
 * Override meta keys (user-editable here):
 *   _mec_etsy_title, _mec_etsy_desc_en, _mec_etsy_tags, _mec_etsy_price,
 *   _mec_etsy_taxonomy_id, _mec_etsy_materials
 *
 * @package MaiaEtsyConnector
 */

defined( 'ABSPATH' ) || exit;

class MEC_Product_Meta {

	public static function init(): void {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ) );
	}

	public static function add_tab( array $tabs ): array {
		$tabs['mec_etsy'] = array(
			'label'    => __( 'Etsy Bilgileri', 'maia-etsy-connector' ),
			'target'   => 'mec_etsy_product_data',
			'class'    => array(),
			'priority' => 75,
		);
		return $tabs;
	}

	public static function render_panel(): void {
		global $post;
		$pid        = (int) $post->ID;
		$listing_id = (string) get_post_meta( $pid, '_mec_listing_id', true );
		$last_sync  = (string) get_post_meta( $pid, '_mec_last_sync', true );
		echo '<div id="mec_etsy_product_data" class="panel woocommerce_options_panel hidden">';

		woocommerce_wp_text_input(
			array(
				'id'          => '_mec_etsy_title',
				'label'       => __( 'Etsy Ürün Başlığı', 'maia-etsy-connector' ),
				'desc_tip'    => true,
				'description' => __( 'Boş bırakılırsa WooCommerce ürün adı kullanılır.', 'maia-etsy-connector' ),
			)
		);
		woocommerce_wp_textarea_input(
			array(
				'id'          => '_mec_etsy_desc_en',
				'label'       => __( 'Etsy İngilizce Açıklama', 'maia-etsy-connector' ),
				'desc_tip'    => true,
				'description' => __( 'Boşsa ürün, kontrol gerektiren kayıt olarak işaretlenir (otomatik çeviri yapılmaz).', 'maia-etsy-connector' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_mec_etsy_tags',
				'label'       => __( 'Etsy Etiketleri', 'maia-etsy-connector' ),
				'desc_tip'    => true,
				'description' => __( 'Virgülle ayırın. Etsy en fazla 13 etiket kabul eder.', 'maia-etsy-connector' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_mec_etsy_price',
				'label'             => __( 'Etsy Fiyatı', 'maia-etsy-connector' ),
				'type'              => 'number',
				'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
				'desc_tip'          => true,
				'description'       => __( 'Etsy para biriminde. Boşsa Ayarlar’daki fiyat kuralı uygulanır.', 'maia-etsy-connector' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_mec_etsy_taxonomy_id',
				'label'       => __( 'Etsy Kategorisi (taxonomy_id)', 'maia-etsy-connector' ),
				'type'        => 'number',
				'desc_tip'    => true,
				'description' => __( 'Boşsa Kategori Eşleştirme kullanılır.', 'maia-etsy-connector' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_mec_etsy_materials',
				'label'       => __( 'Etsy Materyalleri', 'maia-etsy-connector' ),
				'desc_tip'    => true,
				'description' => __( 'Virgülle ayırın.', 'maia-etsy-connector' ),
			)
		);

		echo '<div class="options_group">';
		echo '<p class="form-field"><label>' . esc_html__( 'Etsy Listing ID', 'maia-etsy-connector' ) . '</label><span>' . ( $listing_id ? esc_html( $listing_id ) : '—' ) . '</span></p>';
		echo '<p class="form-field"><label>' . esc_html__( 'Son Aktarım Tarihi', 'maia-etsy-connector' ) . '</label><span>' . ( $last_sync ? esc_html( $last_sync ) : '—' ) . '</span></p>';
		echo '</div>';

		echo '</div>';
	}

	public static function save( int $post_id ): void {
		// WooCommerce verifies the product nonce before firing this hook.
		$text_fields = array( '_mec_etsy_title', '_mec_etsy_tags', '_mec_etsy_materials' );
		foreach ( $text_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}
		if ( isset( $_POST['_mec_etsy_desc_en'] ) ) {
			update_post_meta( $post_id, '_mec_etsy_desc_en', sanitize_textarea_field( wp_unslash( $_POST['_mec_etsy_desc_en'] ) ) );
		}
		if ( isset( $_POST['_mec_etsy_price'] ) ) {
			$price = wc_format_decimal( wp_unslash( $_POST['_mec_etsy_price'] ) );
			update_post_meta( $post_id, '_mec_etsy_price', $price );
		}
		if ( isset( $_POST['_mec_etsy_taxonomy_id'] ) ) {
			update_post_meta( $post_id, '_mec_etsy_taxonomy_id', (int) $_POST['_mec_etsy_taxonomy_id'] );
		}
	}
}
