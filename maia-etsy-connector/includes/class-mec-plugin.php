<?php
/**
 * Plugin orchestrator: wires hooks, menu, assets and the OAuth endpoints.
 *
 * @package MaiaEtsyConnector
 */

defined( 'ABSPATH' ) || exit;

class MEC_Plugin {

	const CAP = 'manage_woocommerce';

	private static ?MEC_Plugin $instance = null;

	public static function instance(): MEC_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		MEC_Logger::maybe_upgrade();
		MEC_Product_Meta::init();
		MEC_Queue::init();
		MEC_Ajax::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_mec_oauth_begin', array( $this, 'oauth_begin' ) );
		add_action( 'admin_post_mec_oauth_callback', array( $this, 'oauth_callback' ) );
		add_action( 'admin_post_mec_disconnect', array( $this, 'disconnect' ) );
	}

	public function register_menu(): void {
		$admin = new MEC_Admin();
		add_menu_page(
			__( 'WordPress Etsy Plugin', 'maia-etsy-connector' ),
			__( 'Etsy Plugin', 'maia-etsy-connector' ),
			self::CAP,
			'mec-dashboard',
			array( $admin, 'render_dashboard' ),
			'dashicons-store',
			56
		);
		$pages = array(
			'mec-dashboard' => array( __( 'Dashboard', 'maia-etsy-connector' ), 'render_dashboard' ),
			'mec-products'  => array( __( 'Ürün Aktarımı', 'maia-etsy-connector' ), 'render_products' ),
			'mec-listings'  => array( __( 'Etsy Ürünleri', 'maia-etsy-connector' ), 'render_listings' ),
			'mec-category'  => array( __( 'Kategori Eşleştirme', 'maia-etsy-connector' ), 'render_category' ),
			'mec-history'   => array( __( 'Aktarım Geçmişi', 'maia-etsy-connector' ), 'render_history' ),
			'mec-settings'  => array( __( 'Ayarlar', 'maia-etsy-connector' ), 'render_settings' ),
		);
		foreach ( $pages as $slug => $meta ) {
			add_submenu_page( 'mec-dashboard', $meta[0], $meta[0], self::CAP, $slug, array( $admin, $meta[1] ) );
		}
	}

	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'mec-' ) ) {
			return;
		}
		wp_enqueue_style( 'mec-admin', MEC_URL . 'assets/css/admin.css', array(), MEC_VERSION );
		wp_enqueue_script( 'mec-admin', MEC_URL . 'assets/js/admin.js', array( 'jquery' ), MEC_VERSION, true );
		wp_localize_script(
			'mec-admin',
			'MEC',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mec_ajax' ),
				'i18n'    => array(
					'confirmBulk' => __( 'Seçili ürünler Etsy’ye TASLAK olarak aktarılacak. Devam edilsin mi?', 'maia-etsy-connector' ),
					'noSelection' => __( 'Lütfen en az bir ürün seçin.', 'maia-etsy-connector' ),
					'queued'      => __( 'Kuyruğa eklendi', 'maia-etsy-connector' ),
					'working'     => __( 'İşleniyor…', 'maia-etsy-connector' ),
					'error'       => __( 'Hata', 'maia-etsy-connector' ),
				),
			)
		);
	}

	/* ---------- OAuth endpoints ---------- */

	public function oauth_begin(): void {
		$this->guard( 'mec_oauth_begin' );
		if ( ! MEC_Settings::is_configured() ) {
			$this->redirect_notice( 'mec-settings', __( 'Önce API Keystring ve Shared Secret girin.', 'maia-etsy-connector' ), 'error' );
		}
		wp_redirect( MEC_Auth::begin() );
		exit;
	}

	public function oauth_callback(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'maia-etsy-connector' ) );
		}
		// Etsy's own state value is validated against our stored transient in MEC_Auth.
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( '' === $code || '' === $state ) {
			$err = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : __( 'Bağlantı iptal edildi.', 'maia-etsy-connector' );
			$this->redirect_notice( 'mec-settings', $err, 'error' );
		}
		$result = MEC_Auth::handle_callback( $code, $state );
		if ( is_wp_error( $result ) ) {
			$this->redirect_notice( 'mec-settings', $result->get_error_message(), 'error' );
		}
		$this->redirect_notice( 'mec-dashboard', __( 'Etsy bağlantısı başarılı.', 'maia-etsy-connector' ), 'success' );
	}

	public function disconnect(): void {
		$this->guard( 'mec_disconnect' );
		MEC_Settings::clear_tokens();
		$config = MEC_Settings::all();
		$config['shop_id']   = '';
		$config['shop_name'] = '';
		update_option( MEC_Settings::OPTION_CONFIG, $config );
		$this->redirect_notice( 'mec-settings', __( 'Etsy bağlantısı kaldırıldı.', 'maia-etsy-connector' ), 'success' );
	}

	private function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'maia-etsy-connector' ) );
		}
		check_admin_referer( $action );
	}

	private function redirect_notice( string $page, string $message, string $type ): void {
		wp_redirect(
			add_query_arg(
				array(
					'page'        => $page,
					'mec_notice'  => rawurlencode( $message ),
					'mec_type'    => $type,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
