<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\InStore;

/**
 * Lightweight kiosk authentication for the In-Store Quick Order screen.
 *
 * Deliberately NOT a WordPress user session: the iPad must never be logged out
 * by WP cookie/nonce expiry. Instead:
 *
 *  1. Staff unlock the screen once with the store password.
 *  2. The server issues a signed token (HMAC of the store-managed secret) into a
 *     long-lived httpOnly cookie. The token is self-validating — there is no
 *     server-side session to expire.
 *  3. Every protected endpoint validates the cookie token. Rotating the secret
 *     ("Sign out all devices") instantly invalidates every issued token.
 *
 * The per-order staff PIN (see StaffPins) is a separate gate layered on top for
 * attribution — it is not a replacement for this token check.
 */
final class KioskAuth {

	public const COOKIE        = 'fn_kiosk_token';
	private const TOKEN_PREFIX = 'fnk1';
	private const COOKIE_TTL   = 31536000; // 1 year; refreshed on each validated request.

	/** Verify a submitted store password against the stored hash. */
	public static function verify_password( string $password ): bool {
		$hash = InStoreSettings::store_password_hash();
		if ( '' === $hash || '' === trim( $password ) ) {
			return false;
		}
		return wp_check_password( $password, $hash );
	}

	/**
	 * Mint a signed token. Format: fnk1.<issued_at>.<hmac>. The HMAC covers the
	 * issued-at so the secret alone can validate it; no stored state required.
	 */
	public static function issue_token(): string {
		$issued  = (string) time();
		$payload = self::TOKEN_PREFIX . '.' . $issued;
		return $payload . '.' . self::sign( $payload );
	}

	/** Validate a token's signature against the current secret (constant-time). */
	public static function token_is_valid( string $token ): bool {
		$parts = explode( '.', $token );
		if ( 4 !== count( $parts ) || self::TOKEN_PREFIX !== $parts[0] ) {
			return false;
		}
		$payload   = $parts[0] . '.' . $parts[1];
		$signature = $parts[3];
		return hash_equals( self::sign( $payload ), $signature );
	}

	private static function sign( string $payload ): string {
		return hash_hmac( 'sha256', $payload, InStoreSettings::token_secret() );
	}

	/** True when the current request carries a valid kiosk token cookie. */
	public static function request_is_authorised(): bool {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
		return '' !== $token && self::token_is_valid( $token );
	}

	/** Set the long-lived token cookie (httpOnly, Secure when on https, SameSite=Lax). */
	public static function set_cookie( string $token ): void {
		$secure = is_ssl();
		setcookie(
			self::COOKIE,
			$token,
			[
				'expires'  => time() + self::COOKIE_TTL,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => $secure,
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
		$_COOKIE[ self::COOKIE ] = $token;
	}

	public static function clear_cookie(): void {
		setcookie(
			self::COOKIE,
			'',
			[
				'expires'  => time() - 3600,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
		unset( $_COOKIE[ self::COOKIE ] );
	}

	/**
	 * Simple per-IP rate limiter for the unlock endpoint, backed by a transient.
	 * Returns true when the caller is allowed to attempt; false when throttled.
	 */
	public static function unlock_rate_ok(): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'fn_kiosk_unlock_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= 10 ) {
			return false;
		}
		set_transient( $key, $n + 1, 5 * MINUTE_IN_SECONDS );
		return true;
	}
}
