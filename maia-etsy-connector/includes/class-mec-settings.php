<?php
/**
 * Settings + token storage wrapper.
 *
 * Config lives in the autoloaded option `mec_settings`.
 * OAuth tokens live in the NON-autoloaded option `mec_tokens` and are never
 * echoed to HTML/JS or written to logs. WordPress has no dedicated secret
 * vault, so — like every other WP credential — they rest in the options table.
 *
 * @package MaiaEtsyConnector
 */

defined( 'ABSPATH' ) || exit;

class MEC_Settings {

	const OPTION_CONFIG = 'mec_settings';
	const OPTION_TOKENS = 'mec_tokens';

	private static function defaults(): array {
		return array(
			'api_keystring'               => '',
			'shared_secret'               => '',
			'shop_id'                     => '',
			'shop_name'                   => '',
			'default_shipping_profile_id' => '',
			'default_readiness_state_id'  => '',
			'default_return_policy_id'    => '',
			'default_taxonomy_id'         => '',
			'who_made'                    => 'i_did',
			'when_made'                   => 'made_to_order',
			'default_quantity'            => 1,
			'price_mode'                  => 'woo',   // woo|etsy_custom|percent|fixed
			'price_percent'               => 0,
			'price_fixed'                 => 0,
			'etsy_currency'               => 'USD',
			'exchange_rate'               => 1,       // multiply Woo price by this to reach Etsy currency
		);
	}

	public static function all(): array {
		$saved = get_option( self::OPTION_CONFIG, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( string $key, $fallback = '' ) {
		$all = self::all();
		return $all[ $key ] ?? $fallback;
	}

	/**
	 * Save config. Only known keys are kept; everything is sanitized here.
	 */
	public static function save( array $input ): void {
		$clean = self::all();
		foreach ( self::defaults() as $key => $default ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = $input[ $key ];
			$clean[ $key ] = match ( $key ) {
				'default_quantity'                                    => max( 0, (int) $value ),
				'price_percent', 'price_fixed', 'exchange_rate'       => (float) $value,
				'price_mode'                                          => in_array( $value, array( 'woo', 'etsy_custom', 'percent', 'fixed' ), true ) ? $value : 'woo',
				'who_made'                                            => in_array( $value, array( 'i_did', 'someone_else', 'collective' ), true ) ? $value : 'i_did',
				default                                               => sanitize_text_field( (string) $value ),
			};
		}
		update_option( self::OPTION_CONFIG, $clean );
	}

	public static function is_configured(): bool {
		$c = self::all();
		return '' !== $c['api_keystring'] && '' !== $c['shared_secret'];
	}

	/* ---- Tokens ---- */

	public static function get_tokens(): array {
		$t = get_option( self::OPTION_TOKENS, array() );
		return is_array( $t ) ? $t : array();
	}

	public static function save_tokens( array $tokens ): void {
		// autoload = 'no' so secrets are not loaded on every request.
		update_option( self::OPTION_TOKENS, $tokens, false );
	}

	public static function clear_tokens(): void {
		delete_option( self::OPTION_TOKENS );
	}

	public static function is_connected(): bool {
		$t = self::get_tokens();
		return ! empty( $t['access_token'] ) && ! empty( MEC_Settings::get( 'shop_id' ) );
	}
}
