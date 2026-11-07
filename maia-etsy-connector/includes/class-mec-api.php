<?php
/**
 * Authenticated Etsy Open API v3 HTTP client.
 *
 * Base: https://api.etsy.com/v3/application
 * Every request carries:
 *   x-api-key: <keystring>
 *   Authorization: Bearer <access_token>
 *
 * Non-2xx responses return WP_Error('mec_api') whose error_data holds
 * http_code, retry_after and the raw body. The caller (queue) decides whether
 * to reschedule; this client never sleeps so it cannot block a PHP worker.
 *
 * @package MaiaEtsyConnector
 */

defined( 'ABSPATH' ) || exit;

class MEC_Api {

	const BASE = 'https://api.etsy.com/v3/application';

	/**
	 * x-api-key value.
	 *
	 * shortcut: Etsy's auth tutorial text once said "keystring:shared_secret",
	 * but every working v3 integration (and the OAuth client_id) uses the bare
	 * keystring. If Etsy ever rejects with an api-key error, append
	 * ':' . shared_secret here.
	 */
	private static function api_key(): string {
		return MEC_Settings::get( 'api_keystring' ) . ':' . MEC_Settings::get( 'shared_secret' );
	}

	public static function get( string $path, array $query = array() ) {
		return self::request( 'GET', $path, array( 'query' => $query ) );
	}

	/** Form-encoded POST (createDraftListing uses this). */
	public static function post_form( string $path, array $body ) {
		return self::request( 'POST', $path, array( 'form' => $body ) );
	}

	/** Form-encoded PATCH (updateListing uses this). */
	public static function patch_form( string $path, array $body ) {
		return self::request( 'PATCH', $path, array( 'form' => $body ) );
	}

	/** JSON PUT (inventory uses this). */
	public static function put_json( string $path, array $body ) {
		return self::request( 'PUT', $path, array( 'json' => $body ) );
	}

	/**
	 * Multipart upload (uploadListingImage). $file = [name, filename, content, type].
	 */
	public static function upload( string $path, array $fields, array $file ) {
		return self::request( 'POST', $path, array( 'multipart' => array( 'fields' => $fields, 'file' => $file ) ) );
	}

	/**
	 * @return array|WP_Error decoded response body (array) on 2xx, WP_Error otherwise
	 */
	private static function request( string $method, string $path, array $opts ) {
		$token = MEC_Auth::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = self::BASE . $path;
		if ( ! empty( $opts['query'] ) ) {
			$url = add_query_arg( $opts['query'], $url ); // add_query_arg urlencodes values.
		}

		$headers = array(
			'x-api-key'     => self::api_key(),
			'Authorization' => 'Bearer ' . $token,
		);

		$args = array(
			'method'  => $method,
			'timeout' => 45,
			'headers' => $headers,
		);

		if ( isset( $opts['form'] ) ) {
			$headers['Content-Type'] = 'application/x-www-form-urlencoded';
			$args['headers']         = $headers;
			$args['body']            = self::flatten_form( $opts['form'] );
		} elseif ( isset( $opts['json'] ) ) {
			$headers['Content-Type'] = 'application/json';
			$args['headers']         = $headers;
			$args['body']            = wp_json_encode( $opts['json'] );
		} elseif ( isset( $opts['multipart'] ) ) {
			$boundary                = 'mecbnd' . wp_generate_password( 16, false );
			$headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
			$args['headers']         = $headers;
			$args['body']            = self::build_multipart( $boundary, $opts['multipart']['fields'], $opts['multipart']['file'] );
		}

		$resp = wp_remote_request( $url, $args );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$raw  = (string) wp_remote_retrieve_body( $resp );

		if ( $code >= 200 && $code < 300 ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		$retry_after = (int) wp_remote_retrieve_header( $resp, 'retry-after' );
		$body        = json_decode( $raw, true );
		$message     = is_array( $body ) ? ( $body['error'] ?? $body['error_description'] ?? '' ) : '';
		if ( '' === $message ) {
			$message = wp_strip_all_tags( substr( $raw, 0, 300 ) );
		}

		return new WP_Error(
			'mec_api',
			sprintf( /* translators: 1: HTTP code, 2: message */ __( 'Etsy API hatası (HTTP %1$d): %2$s', 'maia-etsy-connector' ), $code, $message ),
			array(
				'http_code'   => $code,
				'retry_after' => $retry_after,
			)
		);
	}

	/**
	 * Etsy form endpoints accept repeated keys for arrays (tags, materials).
	 * wp_remote_request urlencodes a nested array wrong, so flatten here.
	 */
	private static function flatten_form( array $body ): string {
		$pairs = array();
		foreach ( $body as $key => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $item );
				}
			} else {
				$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
			}
		}
		return implode( '&', $pairs );
	}

	private static function build_multipart( string $boundary, array $fields, array $file ): string {
		$eol = "\r\n";
		$out = '';
		foreach ( $fields as $name => $value ) {
			$out .= '--' . $boundary . $eol;
			$out .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
			$out .= $value . $eol;
		}
		$out .= '--' . $boundary . $eol;
		$out .= 'Content-Disposition: form-data; name="' . $file['name'] . '"; filename="' . $file['filename'] . '"' . $eol;
		$out .= 'Content-Type: ' . $file['type'] . $eol . $eol;
		$out .= $file['content'] . $eol;
		$out .= '--' . $boundary . '--' . $eol;
		return $out;
	}

	/** Convenience accessor for error http code. */
	public static function http_code( WP_Error $e ): int {
		$data = $e->get_error_data();
		return (int) ( $data['http_code'] ?? 0 );
	}

	public static function retry_after( WP_Error $e ): int {
		$data = $e->get_error_data();
		return (int) ( $data['retry_after'] ?? 0 );
	}
}
