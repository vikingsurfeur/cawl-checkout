<?php
/**
 * Session de paiement ouverte chez CAWL.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Valeur immuable : Hosted Checkout créé.
 */
final class Checkout_Session {
	/**
	 * @param string $hosted_checkout_id Identifiant du Hosted Checkout.
	 * @param string $redirect_url       URL de la page de paiement.
	 * @param string $expires_at         Expiration, UTC `Y-m-d H:i:s`.
	 */
	public function __construct(
		public readonly string $hosted_checkout_id,
		public readonly string $redirect_url,
		public readonly string $expires_at
	) {}
}
