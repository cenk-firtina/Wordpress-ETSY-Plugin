<?php
/**
 * Standalone self-check for the plugin's pure, deterministic logic.
 * Requires NO WordPress and NO Etsy credentials.
 *
 * Run:  php tests/selfcheck.php
 *
 * Covers the security/correctness-critical pure helpers:
 *  - PKCE code_challenge (RFC 7636 Appendix B test vector)
 *  - x-www-form-urlencoded flattening (repeated keys for arrays)
 *  - multipart/form-data body assembly
 *
 * Live product transfer is NOT tested here — that needs a real Etsy app and an
 * HTTPS site, and must be verified separately. This script does not claim the
 * live integration works.
 *
 * @package MaiaEtsyConnector
 */

error_reporting( E_ALL );

// --- Minimal WP shims used by the pure methods under test. ---
define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $len = 12, $special = true ) {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$out   = '';
		for ( $i = 0; $i < $len; $i++ ) {
			$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}
		return $out;
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) {
		return trim( strip_tags( (string) $s ) );
	}
}
if ( ! class_exists( 'MEC_Settings' ) ) {
	class MEC_Settings {
		public static function get( $k, $d = '' ) {
			return $d;
		}
	}
}

require __DIR__ . '/../includes/class-mec-api.php';
require __DIR__ . '/../includes/class-mec-auth.php';

$tests  = 0;
$failed = 0;

function check( string $name, bool $ok, string $detail = '' ): void {
	global $tests, $failed;
	++$tests;
	if ( $ok ) {
		echo "PASS  {$name}\n";
	} else {
		++$failed;
		echo "FAIL  {$name}" . ( $detail ? "  ({$detail})" : '' ) . "\n";
	}
}

function call_private( string $class, string $method, array $args ) {
	$ref = new ReflectionMethod( $class, $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( null, $args );
}

// 1. PKCE challenge, RFC 7636 Appendix B.
$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
$expected  = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
$challenge = call_private( 'MEC_Auth', 'base64url', array( hash( 'sha256', $verifier, true ) ) );
check( 'PKCE S256 challenge matches RFC 7636 vector', $challenge === $expected, $challenge );

// 2. form flattening with repeated array keys + urlencoding.
$form = call_private( 'MEC_Api', 'flatten_form', array( array( 'a' => '1', 'tags' => array( 'x', 'y z' ), 'price' => '2400' ) ) );
check( 'flatten_form repeats array keys', $form === 'a=1&tags=x&tags=y%20z&price=2400', $form );

// 3. multipart body assembly.
$body = call_private(
	'MEC_Api',
	'build_multipart',
	array(
		'B0UND',
		array( 'rank' => '1' ),
		array( 'name' => 'image', 'filename' => 'a.jpg', 'content' => 'BYTES', 'type' => 'image/jpeg' ),
	)
);
check( 'multipart has field part', str_contains( $body, "name=\"rank\"\r\n\r\n1\r\n" ) );
check( 'multipart has file headers', str_contains( $body, 'name="image"; filename="a.jpg"' ) && str_contains( $body, 'Content-Type: image/jpeg' ) );
check( 'multipart terminates with closing boundary', str_contains( $body, "--B0UND--\r\n" ) );

echo "\n{$tests} test, " . ( $tests - $failed ) . " PASS, {$failed} FAIL\n";
exit( $failed ? 1 : 0 );
