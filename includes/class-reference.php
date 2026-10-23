<?php
/**
 * Référence marchande d'une inscription.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Génère les références `INS-AAAAMMJJ-` suivies de 12 hexadécimaux (25 caractères).
 */
final class Reference {
	/**
	 * @param \DateTimeImmutable|null $now Date à encoder (maintenant par défaut).
	 */
	public static function generate( ?\DateTimeImmutable $now = null ): string {
		$now ??= new \DateTimeImmutable();
		return 'INS-' . $now->format( 'Ymd' ) . '-' . bin2hex( random_bytes( 6 ) );
	}
}
