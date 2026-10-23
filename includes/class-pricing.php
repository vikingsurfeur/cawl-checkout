<?php
/**
 * Prix de l'inscription et durée de la session de paiement.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Réglages serveur : le prix ne vient jamais du navigateur.
 */
final class Pricing {
	public const OPTION         = 'cawl_checkout_price_cents';
	public const DEFAULT_CENTS  = 2500;
	public const SESSION_OPTION = 'cawl_checkout_session_minutes';

	/** Durée maximale d'une session Hosted Checkout CAWL (« maximum life span of 3 hours »). */
	public const MAX_SESSION_MINUTES = 180;

	/** Prix de l'inscription en centimes (option, 2500 par défaut). */
	public static function price_cents(): int {
		$cents = (int) get_option( self::OPTION, self::DEFAULT_CENTS );
		return $cents > 0 ? $cents : self::DEFAULT_CENTS;
	}

	/** Durée de la session de paiement en minutes (option, 1..180, 180 par défaut). */
	public static function session_minutes(): int {
		return max( 1, min( self::MAX_SESSION_MINUTES, (int) get_option( self::SESSION_OPTION, self::MAX_SESSION_MINUTES ) ) );
	}
}
