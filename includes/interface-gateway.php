<?php
/**
 * Contrat de la passerelle de paiement.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Accès à l'API CAWL, remplaçable en test. Toute erreur devient une Gateway_Exception.
 */
interface Gateway {
	/**
	 * Crée un Hosted Checkout pour l'inscription.
	 *
	 * @throws Gateway_Exception En cas d'erreur réseau ou API.
	 */
	public function create_checkout( Registration $r, string $return_url ): Checkout_Session;

	/**
	 * Lit l'état d'un Hosted Checkout.
	 *
	 * @throws Gateway_Exception En cas d'erreur réseau ou API.
	 */
	public function get_checkout( string $hosted_checkout_id ): Payment_Snapshot;

	/**
	 * Lit les détails d'un paiement.
	 *
	 * @throws Gateway_Exception En cas d'erreur réseau ou API.
	 */
	public function get_payment( string $payment_id ): Payment_Snapshot;

	/**
	 * Rembourse un paiement et renvoie l'identifiant du remboursement.
	 *
	 * @throws Gateway_Exception En cas d'erreur réseau ou API.
	 */
	public function refund( string $payment_id, int $amount_cents, string $idempotence_key ): string;

	/**
	 * Vérifie que les identifiants de webhook sont acceptés par CAWL.
	 *
	 * @throws Gateway_Exception En cas d'erreur réseau ou API.
	 */
	public function validate_webhook_credentials(): bool;
}
