<?php
/**
 * Accès aux inscriptions.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Contrat minimal du dépôt d'inscriptions, substituable dans les tests.
 */
interface Registration_Store {
	/**
	 * @param int $id Identifiant.
	 */
	public function find( int $id ): ?Registration;

	/**
	 * Transition conditionnelle à l'état courant ; pose `paid_at` si `$to` vaut `paid`.
	 *
	 * @param int         $id          Identifiant.
	 * @param string      $from        État attendu.
	 * @param string      $to          Nouvel état.
	 * @param string|null $payment_id  Identifiant de paiement (null : inchangé).
	 * @param int|null    $status_code Code de statut CAWL (null : inchangé).
	 * @return bool True si exactement une ligne a changé.
	 */
	public function transition( int $id, string $from, string $to, ?string $payment_id, ?int $status_code ): bool;
}
