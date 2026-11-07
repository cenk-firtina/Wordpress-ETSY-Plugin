<?php
/**
 * Etsy OAuth 2.0 Authorization Code + PKCE.
 *
 * Authorize: https://www.etsy.com/oauth/connect  (GET)
 * Token:     https://api.etsy.com/v3/public/oauth/token  (POST, x-www-form-urlencoded)
 * No client secret is sent in the token request (PKCE). The shared secret is
 * only used in the x-api-key header via MEC_Api.
 *
 * @package MaiaEtsyConnector
 */

defined( 'ABSPATH' ) || exit;

class MEC_Auth {

	const CONNECT_URL = 'https://www.etsy.com/oauth/connect';
	const TOKEN_URL   = 'https://api.etsy.com/v3/public/oauth/token';

	/** Least-privilege scopes: read/write listings + read shop for profiles/taxonomy context. */
	const SCOPES = array( 'listings_r', 'listings_w', 'shops_r' );

	/**
	 * Exact redirect URI. The user must register this string in their Etsy app.
	 */
	public static function redirect_uri(): string {
		return admin_url( 'admin-post.php?action=mec_oauth_callback' );
	}

	/**
	 * Build the authorize URL and remember the PKCE verifier against the state.
	 */
	public static function begin(): string {
		$verifier  = self::base64url( random_bytes( 48 ) ); // 64 chars, within 43-128.
		$challenge = self::base64url( hash( 'sha256', $verifier, true ) );
		$state     = wp_generate_password( 24, false );

		set_transient( 'mec_oauth_' . $state, $verifier, 10 * MINUTE_IN_SECONDS );

		return add_query_arg(
			array(
				'response_type'         => 'code',
				'client_id'             => rawurlencode( MEC_Settings::get( 'api_keystring' ) ),
				'redirect_uri'          => rawurlencode( self::redirect_uri() ),
				'scope'                 => rawurlencode( implode( ' ', self::SCOPES ) ),
				'state'                 => $state,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			),
			self::CONNECT_URL
		);
	}

	/**
	 * Handle the Etsy callback: validate state, exchange the code, fetch shop.
	 *
	 * @return true|WP_Error
	 */
	public static function handle_callback( string $code, string $state ) {
		$verifier = get_transient( 'mec_oauth_' . $state );
		if ( empty( $verifier ) ) {
			return new WP_Error( 'mec_state', __( 'OAuth state doğrulaması başarısız. Lütfen bağlantıyı yeniden deneyin.', 'maia-etsy-connector' ) );
		}
		delete_transient( 'mec_oauth_' . $state );

		$resp = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'client_id'     => MEC_Settings::get( 'api_keystring' ),
					'redirect_uri'  => self::redirect_uri(),
					'code'          => $code,
					'code_verifier' => $verifier,
				),
			)
		);

		$tokens = self::parse_token_response( $resp );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		MEC_Settings::save_tokens( $tokens );

		$shop = self::fetch_shop();
		if ( is_wp_error( $shop ) ) {
			return $shop;
		}
		return true;
	}

	/**
	 * Return a valid access token, refreshing if expired.
	 *
	 * @return string|WP_Error
	 */
	public static function get_access_token() {
		$t = MEC_Settings::get_tokens();
		if ( empty( $t['access_token'] ) || empty( $t['refresh_token'] ) ) {
			return new WP_Error( 'mec_no_token', __( 'Etsy bağlantısı yok. Önce Etsy hesabına bağlanın.', 'maia-etsy-connector' ) );
		}
		if ( ! empty( $t['expires_at'] ) && time() < (int) $t['expires_at'] ) {
			return $t['access_token'];
		}
		return self::refresh( $t['refresh_token'] );
	}

	/**
	 * @return string|WP_Error new access token
	 */
	private static function refresh( string $refresh_token ) {
		$resp = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'client_id'     => MEC_Settings::get( 'api_keystring' ),
					'refresh_token' => $refresh_token,
				),
			)
		);
		$tokens = self::parse_token_response( $resp );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		MEC_Settings::save_tokens( $tokens );
		return $tokens['access_token'];
	}

	/**
	 * @return array|WP_Error tokens with access_token, refresh_token, expires_at, scope
	 */
	private static function parse_token_response( $resp ) {
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );

		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			$msg = is_array( $body ) ? ( $body['error_description'] ?? $body['error'] ?? '' ) : '';
			return new WP_Error( 'mec_token', sprintf( /* translators: %d HTTP code */ __( 'Token alınamadı (HTTP %1$d). %2$s', 'maia-etsy-connector' ), $code, (string) $msg ) );
		}

		return array(
			'access_token'  => (string) $body['access_token'],
			'refresh_token' => (string) ( $body['refresh_token'] ?? '' ),
			'expires_at'    => time() + (int) ( $body['expires_in'] ?? 3600 ) - 60,
			'scope'         => (string) ( $body['scope'] ?? '' ),
		);
	}

	/**
	 * Resolve shop id + name via getMe, persist to settings.
	 *
	 * @return array|WP_Error
	 */
	private static function fetch_shop() {
		$me = MEC_Api::get( '/users/me' );
		if ( is_wp_error( $me ) ) {
			return $me;
		}
		$shop_id = (int) ( $me['shop_id'] ?? 0 );
		if ( ! $shop_id ) {
			return new WP_Error( 'mec_no_shop', __( 'Bu Etsy hesabına bağlı bir mağaza bulunamadı.', 'maia-etsy-connector' ) );
		}
		$config            = MEC_Settings::all();
		$config['shop_id'] = (string) $shop_id;

		$shop = MEC_Api::get( '/shops/' . $shop_id );
		if ( ! is_wp_error( $shop ) && ! empty( $shop['shop_name'] ) ) {
			$config['shop_name'] = sanitize_text_field( (string) $shop['shop_name'] );
		}
		update_option( MEC_Settings::OPTION_CONFIG, $config );
		return $config;
	}

	/** RFC 4648 §5 base64url without padding. */
	private static function base64url( string $bin ): string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}
}
