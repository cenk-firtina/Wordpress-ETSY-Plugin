<?php
/**
 * Renders the six admin pages. All output is escaped; all reads are capability-gated
 * by the menu registration (manage_woocommerce).
 *
 * @package MaiaEtsyConnector
 */

defined( 'ABSPATH' ) || exit;

class MEC_Admin {

	/* ================= Dashboard ================= */

	public function render_dashboard(): void {
		$this->open( __( 'Dashboard', 'maia-etsy-connector' ) );

		$stats     = $this->stats();
		$connected = MEC_Settings::is_connected();
		$shop      = MEC_Settings::get( 'shop_name' );

		echo '<div class="mec-cards">';
		$this->card( __( 'Toplam WooCommerce Ürünü', 'maia-etsy-connector' ), $stats['total'] );
		$this->card( __( 'Etsy’ye Aktarılan', 'maia-etsy-connector' ), $stats['transferred'] );
		$this->card( __( 'Aktarım Bekleyen', 'maia-etsy-connector' ), $stats['pending'] );
		$this->card( __( 'Hatalı Ürün', 'maia-etsy-connector' ), $stats['error'] );
		echo '</div>';

		echo '<table class="widefat mec-kv"><tbody>';
		echo '<tr><th>' . esc_html__( 'Son Senkronizasyon', 'maia-etsy-connector' ) . '</th><td>' . esc_html( $stats['last_sync'] ?: '—' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Etsy Bağlantı Durumu', 'maia-etsy-connector' ) . '</th><td>';
		echo $connected
			? '<span class="mec-badge mec-ok">' . esc_html__( 'Bağlı', 'maia-etsy-connector' ) . ( $shop ? ' · ' . esc_html( $shop ) : '' ) . '</span>'
			: '<span class="mec-badge mec-bad">' . esc_html__( 'Bağlı değil', 'maia-etsy-connector' ) . '</span>';
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Kuyruktaki Görev', 'maia-etsy-connector' ) . '</th><td>' . esc_html( MEC_Queue::pending_count() ) . '</td></tr>';
		echo '</tbody></table>';

		echo '<p class="mec-actions">';
		if ( ! $connected ) {
			$this->oauth_button();
		} else {
			echo '<a class="button button-primary button-hero" href="' . esc_url( admin_url( 'admin.php?page=mec-products' ) ) . '">' . esc_html__( 'ÜRÜNLERİ SENKRONİZE ET', 'maia-etsy-connector' ) . '</a> ';
			$this->disconnect_button();
		}
		echo '</p>';
		$this->close();
	}

	/* ================= Ürün Aktarımı ================= */

	public function render_products(): void {
		$this->open( __( 'Ürün Aktarımı', 'maia-etsy-connector' ) );

		if ( ! MEC_Settings::is_connected() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Etsy’ye bağlı değilsiniz. Aktarım için önce Ayarlar’dan bağlanın.', 'maia-etsy-connector' ) . '</p></div>';
		}

		$paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$cat    = isset( $_GET['product_cat'] ) ? (int) $_GET['product_cat'] : 0;
		$filter = isset( $_GET['mec_filter'] ) ? sanitize_key( $_GET['mec_filter'] ) : 'all';

		// Filter / search form.
		echo '<form method="get" class="mec-filters">';
		echo '<input type="hidden" name="page" value="mec-products" />';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Ürün adı ara…', 'maia-etsy-connector' ) . '" />';
		wp_dropdown_categories(
			array(
				'taxonomy'         => 'product_cat',
				'name'             => 'product_cat',
				'selected'         => $cat,
				'show_option_all'  => __( 'Tüm kategoriler', 'maia-etsy-connector' ),
				'hierarchical'     => true,
				'hide_empty'       => false,
				'value_field'      => 'term_id',
			)
		);
		echo '<select name="mec_filter">';
		foreach ( array(
			'all'         => __( 'Tümü', 'maia-etsy-connector' ),
			'transferred' => __( 'Aktarılmış', 'maia-etsy-connector' ),
			'not'         => __( 'Aktarılmamış', 'maia-etsy-connector' ),
			'error'       => __( 'Hatalı', 'maia-etsy-connector' ),
		) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $filter, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<button class="button">' . esc_html__( 'Filtrele', 'maia-etsy-connector' ) . '</button>';
		echo '</form>';

		$query = $this->product_query( $paged, $search, $cat, $filter );

		echo '<div class="mec-bulkbar"><label><input type="checkbox" id="mec-select-all" /> ' . esc_html__( 'Tümünü seç', 'maia-etsy-connector' ) . '</label> ';
		echo '<button class="button button-primary" id="mec-bulk-transfer">' . esc_html__( 'Seçilenleri Etsy’ye Aktar (Taslak)', 'maia-etsy-connector' ) . '</button>';
		echo '<span class="mec-bulk-status"></span></div>';

		echo '<table class="widefat striped mec-products"><thead><tr>';
		echo '<td class="check-column"></td>';
		foreach ( array( __( 'Görsel', 'maia-etsy-connector' ), __( 'ID', 'maia-etsy-connector' ), __( 'Ürün Adı', 'maia-etsy-connector' ), __( 'Kategori', 'maia-etsy-connector' ), __( 'Fiyat', 'maia-etsy-connector' ), __( 'Stok', 'maia-etsy-connector' ), __( 'Etsy Durumu', 'maia-etsy-connector' ), __( 'İşlem', 'maia-etsy-connector' ) ) as $col ) {
			echo '<th>' . esc_html( $col ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $pid ) {
				$this->product_row( (int) $pid );
			}
		} else {
			echo '<tr><td colspan="9">' . esc_html__( 'Ürün bulunamadı.', 'maia-etsy-connector' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$this->pagination( $paged, (int) $query->max_num_pages, array( 's' => $search, 'product_cat' => $cat, 'mec_filter' => $filter ) );
		$this->close();
	}

	private function product_row( int $pid ): void {
		$product = wc_get_product( $pid );
		if ( ! $product ) {
			return;
		}
		$status     = (string) $product->get_meta( '_mec_status' );
		$listing_id = (int) $product->get_meta( '_mec_listing_id' );
		$cats       = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'names' ) );

		echo '<tr data-product="' . esc_attr( $pid ) . '">';
		echo '<td class="check-column"><input type="checkbox" class="mec-cb" value="' . esc_attr( $pid ) . '" /></td>';
		echo '<td>' . wp_kses_post( $product->get_image( array( 48, 48 ) ) ) . '</td>';
		echo '<td>' . esc_html( $pid ) . '</td>';
		echo '<td>' . esc_html( $product->get_name() ) . '</td>';
		echo '<td>' . esc_html( is_wp_error( $cats ) ? '' : implode( ', ', $cats ) ) . '</td>';
		echo '<td>' . wp_kses_post( wc_price( (float) $product->get_price() ) ) . '</td>';
		echo '<td>' . esc_html( $product->managing_stock() ? (string) $product->get_stock_quantity() : '—' ) . '</td>';
		echo '<td class="mec-status">' . $this->status_badge( $status, $listing_id ) . '</td>';
		echo '<td class="mec-row-actions">';
		if ( $listing_id ) {
			echo '<button class="button mec-action" data-op="all">' . esc_html__( 'Güncelle', 'maia-etsy-connector' ) . '</button> ';
		} else {
			echo '<button class="button button-primary mec-action" data-op="create">' . esc_html__( 'Aktar', 'maia-etsy-connector' ) . '</button> ';
		}
		echo '<a class="button" href="' . esc_url( get_edit_post_link( $pid ) ) . '#mec_etsy_product_data">' . esc_html__( 'Detay', 'maia-etsy-connector' ) . '</a>';
		echo '</td></tr>';
	}

	/* ================= Etsy Ürünleri ================= */

	public function render_listings(): void {
		$this->open( __( 'Etsy Ürünleri', 'maia-etsy-connector' ) );
		$shop_id = (int) MEC_Settings::get( 'shop_id' );

		$q = new WP_Query(
			array(
				'post_type'      => array( 'product' ),
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_query'     => array( array( 'key' => '_mec_listing_id', 'compare' => 'EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Ürün', 'maia-etsy-connector' ), __( 'Listing ID', 'maia-etsy-connector' ), __( 'Son Aktarım', 'maia-etsy-connector' ), __( 'İşlem', 'maia-etsy-connector' ) ) as $c ) {
			echo '<th>' . esc_html( $c ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( $q->have_posts() ) {
			foreach ( $q->posts as $pid ) {
				$product    = wc_get_product( (int) $pid );
				$listing_id = (int) $product->get_meta( '_mec_listing_id' );
				if ( ! $listing_id ) {
					continue;
				}
				$url = 'https://www.etsy.com/your/shops/me/tools/listings/' . $listing_id;
				echo '<tr data-product="' . esc_attr( $pid ) . '">';
				echo '<td>' . esc_html( $product->get_name() ) . '</td>';
				echo '<td><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $listing_id ) . '</a></td>';
				echo '<td>' . esc_html( (string) $product->get_meta( '_mec_last_sync' ) ?: '—' ) . '</td>';
				echo '<td class="mec-row-actions">';
				foreach ( array( 'price' => __( 'Fiyat', 'maia-etsy-connector' ), 'stock' => __( 'Stok', 'maia-etsy-connector' ), 'description' => __( 'Açıklama', 'maia-etsy-connector' ), 'images' => __( 'Fotoğraf', 'maia-etsy-connector' ), 'all' => __( 'Tümü', 'maia-etsy-connector' ) ) as $op => $label ) {
					echo '<button class="button mec-action" data-op="' . esc_attr( $op ) . '">' . esc_html( $label ) . '</button> ';
				}
				echo '</td></tr>';
			}
		} else {
			echo '<tr><td colspan="4">' . esc_html__( 'Henüz Etsy’ye aktarılmış ürün yok.', 'maia-etsy-connector' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Güncelleme tipini seçin; işlem arka planda kuyruğa alınır.', 'maia-etsy-connector' ) . '</p>';
		$this->close();
	}

	/* ================= Kategori Eşleştirme ================= */

	public function render_category(): void {
		$this->open( __( 'Kategori Eşleştirme', 'maia-etsy-connector' ) );

		$choices = MEC_Settings::is_connected() ? MEC_Category_Mapper::taxonomy_choices() : array();
		if ( ! $choices ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Etsy kategori ağacı alınamadı. Bağlantıyı kontrol edin; taxonomy_id’yi elle de girebilirsiniz.', 'maia-etsy-connector' ) . '</p></div>';
		}

		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$map   = MEC_Category_Mapper::get_map();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mec_save_category' );
		echo '<input type="hidden" name="action" value="mec_save_category" />';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'WooCommerce Kategorisi', 'maia-etsy-connector' ) . '</th><th>' . esc_html__( 'Etsy Kategorisi (taxonomy_id)', 'maia-etsy-connector' ) . '</th></tr></thead><tbody>';
		foreach ( (array) $terms as $term ) {
			$current = (int) ( $map[ $term->term_id ] ?? 0 );
			echo '<tr><td>' . esc_html( $term->name ) . '</td><td>';
			if ( $choices ) {
				echo '<select name="mec_map[' . esc_attr( $term->term_id ) . ']" class="mec-tax-select">';
				echo '<option value="0">' . esc_html__( '— seçiniz —', 'maia-etsy-connector' ) . '</option>';
				foreach ( $choices as $c ) {
					echo '<option value="' . esc_attr( $c['id'] ) . '"' . selected( $current, $c['id'], false ) . '>' . esc_html( $c['label'] ) . ' (' . esc_html( $c['id'] ) . ')</option>';
				}
				echo '</select>';
			} else {
				echo '<input type="number" name="mec_map[' . esc_attr( $term->term_id ) . ']" value="' . esc_attr( $current ?: '' ) . '" />';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Eşleştirmeleri Kaydet', 'maia-etsy-connector' ) );
		echo '</form>';
		$this->close();
	}

	/* ================= Aktarım Geçmişi ================= */

	public function render_history(): void {
		$this->open( __( 'Aktarım Geçmişi', 'maia-etsy-connector' ) );
		$paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$data   = MEC_Logger::get_page( $paged, 50, $status );

		echo '<form method="get" class="mec-filters"><input type="hidden" name="page" value="mec-history" /><select name="status">';
		foreach ( array( '' => __( 'Tümü', 'maia-etsy-connector' ), 'success' => __( 'Başarılı', 'maia-etsy-connector' ), 'error' => __( 'Hatalı', 'maia-etsy-connector' ) ) as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $status, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select><button class="button">' . esc_html__( 'Filtrele', 'maia-etsy-connector' ) . '</button></form>';

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Tarih', 'maia-etsy-connector' ), __( 'Ürün ID', 'maia-etsy-connector' ), __( 'Listing ID', 'maia-etsy-connector' ), __( 'İşlem', 'maia-etsy-connector' ), __( 'Durum', 'maia-etsy-connector' ), __( 'HTTP', 'maia-etsy-connector' ), __( 'Deneme', 'maia-etsy-connector' ), __( 'Mesaj', 'maia-etsy-connector' ) ) as $c ) {
			echo '<th>' . esc_html( $c ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $data['rows'] as $r ) {
			echo '<tr>';
			echo '<td>' . esc_html( $r->created_at ) . '</td>';
			echo '<td>' . esc_html( $r->product_id ) . '</td>';
			echo '<td>' . esc_html( $r->listing_id ?: '—' ) . '</td>';
			echo '<td>' . esc_html( $r->operation ) . '</td>';
			echo '<td>' . ( 'success' === $r->status ? '<span class="mec-badge mec-ok">' . esc_html__( 'Başarılı', 'maia-etsy-connector' ) . '</span>' : '<span class="mec-badge mec-bad">' . esc_html( $r->status ) . '</span>' ) . '</td>';
			echo '<td>' . esc_html( $r->http_code ?: '—' ) . '</td>';
			echo '<td>' . esc_html( $r->retries ) . '</td>';
			echo '<td>' . esc_html( (string) $r->message ) . '</td>';
			echo '</tr>';
		}
		if ( ! $data['rows'] ) {
			echo '<tr><td colspan="8">' . esc_html__( 'Kayıt yok.', 'maia-etsy-connector' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$this->pagination( $paged, (int) ceil( $data['total'] / 50 ), array( 'status' => $status ) );
		$this->close();
	}

	/* ================= Ayarlar ================= */

	public function render_settings(): void {
		$this->open( __( 'Ayarlar', 'maia-etsy-connector' ) );
		$s         = MEC_Settings::all();
		$connected = MEC_Settings::is_connected();

		// Connection box.
		echo '<h2>' . esc_html__( 'Etsy Bağlantısı', 'maia-etsy-connector' ) . '</h2>';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Durum', 'maia-etsy-connector' ) . '</th><td>';
		echo $connected ? '<span class="mec-badge mec-ok">' . esc_html__( 'Bağlı', 'maia-etsy-connector' ) . ' · ' . esc_html( $s['shop_name'] ) . ' (Shop ID: ' . esc_html( $s['shop_id'] ) . ')</span>' : '<span class="mec-badge mec-bad">' . esc_html__( 'Bağlı değil', 'maia-etsy-connector' ) . '</span>';
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Redirect URI', 'maia-etsy-connector' ) . '</th><td><code>' . esc_html( MEC_Auth::redirect_uri() ) . '</code><p class="description">' . esc_html__( 'Bu adresi Etsy uygulamanızın Callback URL alanına birebir ekleyin (https zorunlu).', 'maia-etsy-connector' ) . '</p></td></tr>';
		echo '</tbody></table>';
		echo '<p>';
		if ( $connected ) {
			$this->disconnect_button();
		} else {
			$this->oauth_button();
		}
		echo '</p>';

		// Config form.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mec_save_settings' );
		echo '<input type="hidden" name="action" value="mec_save_settings" />';

		echo '<h2>' . esc_html__( 'API Bilgileri', 'maia-etsy-connector' ) . '</h2><table class="form-table"><tbody>';
		$this->text_field( 'api_keystring', __( 'Etsy API Keystring', 'maia-etsy-connector' ), $s['api_keystring'] );
		$this->password_field( 'shared_secret', __( 'Etsy Shared Secret', 'maia-etsy-connector' ), $s['shared_secret'] );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Kargo ve Üretim (Varsayılan)', 'maia-etsy-connector' ) . '</h2><table class="form-table"><tbody>';
		$this->api_select( 'default_shipping_profile_id', __( 'Kargo Profili', 'maia-etsy-connector' ), $s['default_shipping_profile_id'], $this->shipping_choices() );
		$this->api_select( 'default_readiness_state_id', __( 'Hazırlık (Readiness) Profili', 'maia-etsy-connector' ), $s['default_readiness_state_id'], $this->readiness_choices() );
		$this->api_select( 'default_return_policy_id', __( 'İade Politikası', 'maia-etsy-connector' ), $s['default_return_policy_id'], $this->return_choices(), true );
		$this->select_field( 'who_made', __( 'Üretici (who_made)', 'maia-etsy-connector' ), $s['who_made'], array( 'i_did' => 'i_did', 'someone_else' => 'someone_else', 'collective' => 'collective' ) );
		$this->select_field( 'when_made', __( 'Üretim Zamanı (when_made)', 'maia-etsy-connector' ), $s['when_made'], $this->when_made_choices() );
		$this->number_field( 'default_quantity', __( 'Varsayılan Stok', 'maia-etsy-connector' ), $s['default_quantity'] );
		$this->text_field( 'default_taxonomy_id', __( 'Varsayılan Etsy Kategorisi (taxonomy_id)', 'maia-etsy-connector' ), $s['default_taxonomy_id'] );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Fiyat Yönetimi', 'maia-etsy-connector' ) . '</h2><table class="form-table"><tbody>';
		$this->select_field( 'price_mode', __( 'Fiyat Kuralı', 'maia-etsy-connector' ), $s['price_mode'], array(
			'woo'         => __( 'WooCommerce fiyatını kullan', 'maia-etsy-connector' ),
			'etsy_custom' => __( 'Etsy özel fiyatını kullan (ürün bazlı)', 'maia-etsy-connector' ),
			'percent'     => __( 'Yüzdesel artış uygula', 'maia-etsy-connector' ),
			'fixed'       => __( 'Sabit tutar ekle', 'maia-etsy-connector' ),
		) );
		$this->number_field( 'price_percent', __( 'Yüzde (%)', 'maia-etsy-connector' ), $s['price_percent'], '0.01' );
		$this->number_field( 'price_fixed', __( 'Sabit Tutar', 'maia-etsy-connector' ), $s['price_fixed'], '0.01' );
		$this->text_field( 'etsy_currency', __( 'Etsy Para Birimi', 'maia-etsy-connector' ), $s['etsy_currency'] );
		$this->number_field( 'exchange_rate', __( 'Kur (WooCommerce → Etsy çarpanı)', 'maia-etsy-connector' ), $s['exchange_rate'], '0.0001' );
		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'Otomatik kur çekilmez; belirttiğiniz kur kullanılır. Aktarım öncesi hesaplanan fiyat ürün detayında görünür.', 'maia-etsy-connector' ) . '</p></td></tr>';
		echo '</tbody></table>';

		submit_button( __( 'Ayarları Kaydet', 'maia-etsy-connector' ) );
		echo '</form>';
		$this->close();
	}

	/* ================= helpers ================= */

	private function stats(): array {
		$total = (int) ( wp_count_posts( 'product' )->publish ?? 0 );
		return array(
			'total'       => $total,
			'transferred' => $this->meta_count( '_mec_listing_id', '', 'EXISTS' ),
			'pending'     => $this->meta_count( '_mec_status', 'pending' ),
			'error'       => $this->meta_count( '_mec_status', 'error' ),
			'last_sync'   => MEC_Logger::last_sync(),
		);
	}

	private function meta_count( string $key, string $value = '', string $compare = '=' ): int {
		$meta = ( 'EXISTS' === $compare ) ? array( 'key' => $key, 'compare' => 'EXISTS' ) : array( 'key' => $key, 'value' => $value, 'compare' => '=' );
		$q    = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => array( $meta ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return (int) $q->found_posts;
	}

	private function product_query( int $paged, string $search, int $cat, string $filter ): WP_Query {
		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'paged'          => $paged,
			'fields'         => 'ids',
			's'              => $search,
			'orderby'        => 'ID',
			'order'          => 'DESC',
		);
		if ( $cat ) {
			$args['tax_query'] = array( array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		if ( 'transferred' === $filter ) {
			$args['meta_query'] = array( array( 'key' => '_mec_listing_id', 'compare' => 'EXISTS' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		} elseif ( 'not' === $filter ) {
			$args['meta_query'] = array( array( 'key' => '_mec_listing_id', 'compare' => 'NOT EXISTS' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		} elseif ( 'error' === $filter ) {
			$args['meta_query'] = array( array( 'key' => '_mec_status', 'value' => 'error', 'compare' => '=' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		return new WP_Query( $args );
	}

	private function status_badge( string $status, int $listing_id ): string {
		if ( $listing_id && ( 'done' === $status || '' === $status ) ) {
			return '<span class="mec-badge mec-ok">' . esc_html__( 'Aktarıldı', 'maia-etsy-connector' ) . '</span>';
		}
		return match ( $status ) {
			'pending' => '<span class="mec-badge mec-wait">' . esc_html__( 'Bekliyor', 'maia-etsy-connector' ) . '</span>',
			'error'   => '<span class="mec-badge mec-bad">' . esc_html__( 'Hatalı', 'maia-etsy-connector' ) . '</span>',
			'review'  => '<span class="mec-badge mec-wait">' . esc_html__( 'Kontrol Gerekli', 'maia-etsy-connector' ) . '</span>',
			default   => '<span class="mec-badge">' . esc_html__( 'Aktarılmadı', 'maia-etsy-connector' ) . '</span>',
		};
	}

	private function pagination( int $paged, int $max, array $extra ): void {
		if ( $max <= 1 ) {
			return;
		}
		$base = add_query_arg( array_merge( array( 'page' => sanitize_key( $_GET['page'] ?? '' ) ), $extra, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) );
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => $base,
					'format'    => '',
					'current'   => $paged,
					'total'     => $max,
					'prev_text' => '‹',
					'next_text' => '›',
				)
			)
		);
		echo '</div></div>';
	}

	private function oauth_button(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( 'mec_oauth_begin' );
		echo '<input type="hidden" name="action" value="mec_oauth_begin" />';
		echo '<button class="button button-primary button-hero">' . esc_html__( 'ETSY’YE BAĞLAN', 'maia-etsy-connector' ) . '</button>';
		echo '</form>';
	}

	private function disconnect_button(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'' . esc_js( __( 'Etsy bağlantısı kaldırılsın mı?', 'maia-etsy-connector' ) ) . '\')">';
		wp_nonce_field( 'mec_disconnect' );
		echo '<input type="hidden" name="action" value="mec_disconnect" />';
		echo '<button class="button">' . esc_html__( 'Bağlantıyı Kaldır', 'maia-etsy-connector' ) . '</button>';
		echo '</form>';
	}

	private function text_field( string $id, string $label, $value ): void {
		echo '<tr><th><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td><input type="text" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '" /></td></tr>';
	}

	private function password_field( string $id, string $label, $value ): void {
		// Masked: show a placeholder when a secret already exists; never print the real value.
		$has = '' !== (string) $value;
		echo '<tr><th><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td><input type="password" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="" placeholder="' . ( $has ? '••••••••' : '' ) . '" autocomplete="new-password" />';
		if ( $has ) {
			echo '<p class="description">' . esc_html__( 'Kayıtlı. Değiştirmek için yeni değer girin, boş bırakırsanız korunur.', 'maia-etsy-connector' ) . '</p>';
		}
		echo '</td></tr>';
	}

	private function number_field( string $id, string $label, $value, string $step = '1' ): void {
		echo '<tr><th><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td><input type="number" step="' . esc_attr( $step ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '" /></td></tr>';
	}

	private function select_field( string $id, string $label, $value, array $choices ): void {
		echo '<tr><th><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td><select id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '">';
		foreach ( $choices as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( (string) $value, (string) $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></td></tr>';
	}

	private function api_select( string $id, string $label, $value, array $choices, bool $optional = false ): void {
		if ( ! $choices ) {
			// API unavailable: fall back to manual id entry so setup never blocks.
			$this->text_field( $id, $label . ' (ID)', $value );
			return;
		}
		echo '<tr><th><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td><select id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '">';
		echo '<option value="">' . esc_html( $optional ? __( '— yok —', 'maia-etsy-connector' ) : __( '— seçiniz —', 'maia-etsy-connector' ) ) . '</option>';
		foreach ( $choices as $cid => $cl ) {
			echo '<option value="' . esc_attr( $cid ) . '"' . selected( (string) $value, (string) $cid, false ) . '>' . esc_html( $cl ) . '</option>';
		}
		echo '</select></td></tr>';
	}

	private function shipping_choices(): array {
		if ( ! MEC_Settings::is_connected() ) {
			return array();
		}
		$shop_id = (int) MEC_Settings::get( 'shop_id' );
		$resp    = MEC_Api::get( "/shops/{$shop_id}/shipping-profiles" );
		$out     = array();
		if ( ! is_wp_error( $resp ) ) {
			foreach ( (array) ( $resp['results'] ?? array() ) as $p ) {
				$out[ (int) $p['shipping_profile_id'] ] = (string) $p['title'];
			}
		}
		return $out;
	}

	private function readiness_choices(): array {
		if ( ! MEC_Settings::is_connected() ) {
			return array();
		}
		$shop_id = (int) MEC_Settings::get( 'shop_id' );
		$resp    = MEC_Api::get( "/shops/{$shop_id}/readiness-state-definitions" );
		$out     = array();
		if ( ! is_wp_error( $resp ) ) {
			foreach ( (array) ( $resp['results'] ?? array() ) as $r ) {
				$id = (int) ( $r['readiness_state_id'] ?? 0 );
				if ( $id ) {
					$out[ $id ] = sprintf( '%s (%s-%s %s)', (string) ( $r['readiness_state'] ?? '' ), (string) ( $r['min_processing_time'] ?? '' ), (string) ( $r['max_processing_time'] ?? '' ), (string) ( $r['processing_time_unit'] ?? '' ) );
				}
			}
		}
		return $out;
	}

	private function return_choices(): array {
		if ( ! MEC_Settings::is_connected() ) {
			return array();
		}
		$shop_id = (int) MEC_Settings::get( 'shop_id' );
		$resp    = MEC_Api::get( "/shops/{$shop_id}/policies/return" );
		$out     = array();
		if ( ! is_wp_error( $resp ) ) {
			foreach ( (array) ( $resp['results'] ?? array() ) as $p ) {
				$out[ (int) $p['return_policy_id'] ] = sprintf( '#%d', (int) $p['return_policy_id'] );
			}
		}
		return $out;
	}

	private function when_made_choices(): array {
		$vals = array( 'made_to_order', '2020_2026', '2010_2019', '2007_2009', 'before_2007', '2000_2006', '1990s', '1980s', '1970s', '1960s', '1950s', '1940s', '1930s', '1920s', '1910s', '1900s', '1800s', '1700s', 'before_1700' );
		return array_combine( $vals, $vals );
	}

	private function open( string $title ): void {
		echo '<div class="wrap mec-wrap">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		$this->notice();
	}

	private function close(): void {
		echo '</div>';
	}

	private function notice(): void {
		if ( empty( $_GET['mec_notice'] ) ) {
			return;
		}
		$type = ( isset( $_GET['mec_type'] ) && 'error' === $_GET['mec_type'] ) ? 'error' : 'success';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( wp_unslash( $_GET['mec_notice'] ) ) . '</p></div>';
	}

	private function card( string $label, $value ): void {
		echo '<div class="mec-card"><span class="mec-card-num">' . esc_html( $value ) . '</span><span class="mec-card-label">' . esc_html( $label ) . '</span></div>';
	}
}
