<?php
/**
 * Jeton de retour du client après paiement.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Jeton aléatoire ; seule son empreinte sha256 est stockée.
 */
final class Return_Token {
	/**
	 * Génère un jeton de 32 caractères hexadécimaux.
	 */
	public static function generate(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Empreinte sha256 (hexadécimal) d'un jeton.
	 *
	 * @param string $token Jeton en clair.
	 */
	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Vérifie un jeton contre une empreinte, en temps constant ; rejette tout jeton mal formé.
	 *
	 * @param string $token Jeton reçu.
	 * @param string $hash  Empreinte stockée.
	 */
	public static function verify( string $token, string $hash ): bool {
		if ( 1 !== preg_match( '/\A[0-9a-f]{32}\z/', $token ) ) {
			return false;
		}
		return hash_equals( $hash, self::hash( $token ) );
	}
}
