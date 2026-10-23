<?php
/**
 * Application des instantanés de paiement aux inscriptions.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Seul point où un retour navigateur, un webhook ou une réconciliation modifient l'état d'une inscription.
 */
final class Payment_Applier {
	private Registration_Store $store;
	private \Closure $now;

	/**
	 * @param Registration_Store $store Dépôt d'inscriptions.
	 * @param \Closure|null      $now   Horloge : renvoie un \DateTimeImmutable (par défaut, maintenant en UTC).
	 */
	public function __construct( Registration_Store $store, ?\Closure $now = null ) {
		$this->store = $store;
		$this->now   = $now ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Applique un instantané et renvoie l'état final de l'inscription.
	 *
	 * @param Registration     $r      Inscription telle que lue.
	 * @param Payment_Snapshot $s      Instantané CAWL.
	 * @param string           $source `return`, `webhook` ou `reconcile`.
	 */
	public function apply( Registration $r, Payment_Snapshot $s, string $source ): string {
		$target = $this->target( $r, $s, $source );
		if ( null === $target ) {
			return $r->state;
		}

		// Une seule reprise si l'état a changé entre la lecture et la mise à jour.
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			if ( $target === $r->state ) {
				return $r->state;
			}
			if ( ! State_Machine::can_transition( $r->state, $target ) ) {
				Log::write(
					'info',
					'transition refusée',
					array(
						'reference' => $r->reference,
						'from'      => $r->state,
						'to'        => $target,
						'source'    => $source,
					)
				);
				return $r->state;
			}
			if ( $this->store->transition( $r->id, $r->state, $target, $s->payment_id, $s->status_code ) ) {
				return $target;
			}
			$fresh = $this->store->find( $r->id );
			if ( null === $fresh ) {
				return $r->state;
			}
			$r = $fresh;
		}
		return $r->state;
	}

	/**
	 * État visé par l'instantané, ou null si rien ne doit changer.
	 */
	private function target( Registration $r, Payment_Snapshot $s, string $source ): ?string {
		if ( null !== $s->status_code ) {
			$target = Status_Mapper::target_state( $s->status_code );
			if ( null === $target ) {
				// Code documenté sans transition (remboursement en cours) : rien à signaler.
				if ( ! Status_Mapper::is_known( $s->status_code ) ) {
					Log::write(
						'warning',
						'code de statut inconnu',
						array(
							'reference'   => $r->reference,
							'status_code' => $s->status_code,
							'source'      => $source,
						)
					);
				}
				return null;
			}
			if ( Registration::PAID === $target && ! $this->matches( $r, $s ) ) {
				$target = Registration::ANOMALY;
				Log::write(
					'warning',
					'paiement incohérent avec l\'inscription',
					array(
						'reference'   => $r->reference,
						'status_code' => $s->status_code,
						'payment_id'  => $s->payment_id,
						'source'      => $source,
					)
				);
			}
			if ( 5 === $s->status_code ) {
				Log::write(
					'warning',
					'code 5 : vérifier authorizationMode',
					array(
						'reference'   => $r->reference,
						'status_code' => 5,
						'source'      => $source,
					)
				);
			}
			return $target;
		}

		switch ( $s->checkout_status ) {
			case 'CANCELLED_BY_CONSUMER':
				// Contrat : « cancelled by the customer or by expiration ».
				return $this->is_expired( $r ) ? Registration::EXPIRED : Registration::CANCELLED;
			case 'NOT_FOUND':
				return $this->not_found( $r, $source );
			case 'IN_PROGRESS':
				return $this->is_expired( $r ) ? Registration::EXPIRED : null;
			default:
				return null;
		}
	}

	/**
	 * Checkout inconnu de CAWL. Contrat GetHostedCheckout : une session vit 3 heures au plus et ne se lit
	 * plus ensuite. `expired` seulement si son échéance est passée ; avant, aucune transition.
	 */
	private function not_found( Registration $r, string $source ): ?string {
		$context = array(
			'reference' => $r->reference,
			'state'     => $r->state,
			'source'    => $source,
		);
		if ( null === $r->payment_id ) {
			Log::write( 'warning', 'session disparue sans résultat observé : vérifier le portail', $context );
		}
		if ( $this->is_expired( $r ) ) {
			return Registration::EXPIRED;
		}
		Log::write( 'warning', 'checkout introuvable avant son expiration', $context );
		return null;
	}

	/** Montant, devise et référence de l'instantané correspondent à l'inscription. */
	private function matches( Registration $r, Payment_Snapshot $s ): bool {
		return $s->amount_cents === $r->amount_cents
			&& 'EUR' === $r->currency
			&& 'EUR' === $s->currency
			&& $s->merchant_reference === $r->reference;
	}

	/** Le checkout a dépassé son expiration (UTC). */
	private function is_expired( Registration $r ): bool {
		if ( null === $r->checkout_expires_at ) {
			return false;
		}
		$expires = new \DateTimeImmutable( $r->checkout_expires_at, new \DateTimeZone( 'UTC' ) );
		return ( $this->now )() > $expires;
	}
}
