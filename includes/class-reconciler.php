<?php
/**
 * Réconciliation des inscriptions avec CAWL.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Relit l'état chez CAWL des inscriptions non réglées (ou payées, pour constater un remboursement).
 */
final class Reconciler {
	/** Taille d'une page de lecture. */
	private const PAGE_SIZE = 100;

	/** États examinés. */
	private const STATES = array(
		Registration::CHECKOUT_OPEN,
		Registration::PROCESSING,
		Registration::FAILED,
		Registration::CANCELLED,
		Registration::EXPIRED,
		Registration::PAID,
	);

	/**
	 * Réconcilie une inscription ou le lot.
	 *
	 * @param string|null $reference Référence à traiter seule ; null pour toutes.
	 * @return array<int, array{reference:string, from:string, to:string}> Changements d'état constatés.
	 */
	public function run( ?string $reference = null ): array {
		$changes = array();
		foreach ( $this->targets( $reference ) as $r ) {
			try {
				$to = $this->reconcile_one( $r );
			} catch ( Gateway_Exception | \RuntimeException $e ) {
				// Seule la référence est journalisée : le message d'exception reste hors du journal.
				Log::write(
					'error',
					'réconciliation en échec',
					array(
						'reference' => $r->reference,
						'source'    => 'reconcile',
					)
				);
				continue;
			}
			if ( $to !== $r->state ) {
				$changes[] = array(
					'reference' => $r->reference,
					'from'      => $r->state,
					'to'        => $to,
				);
			}
		}
		return $changes;
	}

	/**
	 * Inscriptions à examiner : une seule, ou toutes par pages (curseur sur l'identifiant).
	 *
	 * @param string|null $reference Référence à traiter seule ; null pour toutes.
	 * @return \Generator<int, Registration>
	 * @throws \RuntimeException Erreur de base de données.
	 */
	private function targets( ?string $reference ): \Generator {
		$repo = Plugin::registrations();
		if ( null !== $reference ) {
			$one = $repo->find_by_reference( $reference );
			if ( null !== $one && in_array( $one->state, self::STATES, true ) ) {
				yield $one;
			}
			return;
		}
		$cursor = 0;
		do {
			$page = $repo->list_by_states( self::STATES, self::PAGE_SIZE, $cursor );
			foreach ( $page as $r ) {
				$cursor = max( $cursor, $r->id );
				yield $r;
			}
		} while ( count( $page ) === self::PAGE_SIZE );
	}

	/**
	 * Lit l'instantané CAWL et l'applique ; renvoie l'état final.
	 *
	 * Inscription non payée : le checkout d'abord, car une session accepte plusieurs tentatives (jusqu'à 10)
	 * et porte le dernier paiement ; un `payment_id` connu peut être celui d'une tentative refusée.
	 * Checkout disparu (NOT_FOUND) : repli sur le paiement connu. Inscription payée : le paiement, pour
	 * constater un remboursement.
	 *
	 * @param Registration $r Inscription.
	 * @throws Gateway_Exception Erreur réseau ou API.
	 */
	private function reconcile_one( Registration $r ): string {
		$gateway  = Plugin::gateway();
		$checkout = null !== $r->hosted_checkout_id && ( Registration::PAID !== $r->state || null === $r->payment_id );
		if ( $checkout ) {
			$snapshot = $gateway->get_checkout( (string) $r->hosted_checkout_id );
			if ( 'NOT_FOUND' === $snapshot->checkout_status && null !== $r->payment_id ) {
				$snapshot = $gateway->get_payment( $r->payment_id );
			}
		} elseif ( null !== $r->payment_id ) {
			$snapshot = $gateway->get_payment( $r->payment_id );
		} else {
			return $r->state;
		}
		return Plugin::applier()->apply( $r, $snapshot, 'reconcile' );
	}
}
