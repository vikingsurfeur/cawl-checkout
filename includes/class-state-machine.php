<?php
/**
 * Machine à états d'une inscription.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Table des transitions autorisées entre états.
 */
final class State_Machine {
	private const TRANSITIONS = array(
		Registration::CREATED       => array( Registration::CHECKOUT_OPEN, Registration::FAILED ),
		Registration::CHECKOUT_OPEN => array(
			Registration::PROCESSING,
			Registration::PAID,
			Registration::FAILED,
			Registration::CANCELLED,
			Registration::EXPIRED,
			Registration::ANOMALY,
		),
		Registration::PROCESSING    => array( Registration::PAID, Registration::FAILED, Registration::CANCELLED, Registration::ANOMALY ),
		Registration::FAILED        => array( Registration::PROCESSING, Registration::PAID, Registration::ANOMALY ),
		Registration::CANCELLED     => array( Registration::PROCESSING, Registration::PAID, Registration::ANOMALY ),
		Registration::EXPIRED       => array( Registration::PROCESSING, Registration::PAID, Registration::ANOMALY ),
		Registration::PAID          => array( Registration::REFUNDED ),
		Registration::REFUNDED      => array(),
		Registration::ANOMALY       => array(),
	);

	/**
	 * Indique si le passage de $from à $to est autorisé (un état vers lui-même ne l'est jamais).
	 *
	 * @param string $from État actuel.
	 * @param string $to   État visé.
	 */
	public static function can_transition( string $from, string $to ): bool {
		return in_array( $to, self::TRANSITIONS[ $from ] ?? array(), true );
	}
}
