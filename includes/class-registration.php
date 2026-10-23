<?php
/**
 * Inscription payante.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Valeur immuable représentant une ligne d'inscription et ses états possibles.
 */
final class Registration {
	public const CREATED       = 'created';
	public const CHECKOUT_OPEN = 'checkout_open';
	public const PROCESSING    = 'processing';
	public const PAID          = 'paid';
	public const FAILED        = 'failed';
	public const CANCELLED     = 'cancelled';
	public const EXPIRED       = 'expired';
	public const REFUNDED      = 'refunded';
	public const ANOMALY       = 'anomaly';

	/**
	 * @param int           $id                   Identifiant en base.
	 * @param string        $reference            Référence marchande (INS-AAAAMMJJ-…).
	 * @param string        $submission_id        Identifiant de soumission du formulaire (anti-doublon).
	 * @param string        $token_hash           Empreinte sha256 du jeton de retour.
	 * @param string        $state                État courant (constantes de la classe).
	 * @param int           $amount_cents         Montant en centimes.
	 * @param string        $currency             Devise ISO 4217.
	 * @param Customer_Data $customer             Coordonnées du client.
	 * @param string|null   $hosted_checkout_id   Identifiant du Hosted Checkout CAWL.
	 * @param string|null   $redirect_url         URL de redirection vers la page de paiement.
	 * @param string|null   $checkout_expires_at  Expiration du checkout, UTC `Y-m-d H:i:s`.
	 * @param string|null   $payment_id           Identifiant du paiement CAWL.
	 * @param int|null      $last_status_code     Dernier code de statut CAWL reçu.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $reference,
		public readonly string $submission_id,
		public readonly string $token_hash,
		public readonly string $state,
		public readonly int $amount_cents,
		public readonly string $currency,
		public readonly Customer_Data $customer,
		public readonly ?string $hosted_checkout_id = null,
		public readonly ?string $redirect_url = null,
		public readonly ?string $checkout_expires_at = null,
		public readonly ?string $payment_id = null,
		public readonly ?int $last_status_code = null
	) {}
}
