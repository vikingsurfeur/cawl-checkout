<?php
/**
 * Dépôt d'inscriptions en mémoire pour les tests.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

/**
 * Reproduit la mise à jour conditionnelle `UPDATE … WHERE state = $from`.
 */
final class In_Memory_Registration_Store implements Registration_Store {
	/** @var array<int,Registration> */
	public array $rows = array();
	/** @var array<int,array{int,string,string}> Appels à transition() : id, from, to. */
	public array $calls = array();
	/** @var \Closure|null Exécutée une fois, juste avant la première tentative : simule un changement concurrent. */
	public ?\Closure $before_first_transition = null;

	public function add( Registration $r ): void {
		$this->rows[ $r->id ] = $r;
	}

	/** Remplace l'état d'une ligne (changement concurrent). */
	public function force_state( int $id, string $state ): void {
		$this->rows[ $id ] = $this->with( $this->rows[ $id ], $state, null, null );
	}

	public function find( int $id ): ?Registration {
		return $this->rows[ $id ] ?? null;
	}

	public function transition( int $id, string $from, string $to, ?string $payment_id, ?int $status_code ): bool {
		if ( null !== $this->before_first_transition ) {
			$hook                          = $this->before_first_transition;
			$this->before_first_transition = null;
			$hook( $this );
		}
		$this->calls[] = array( $id, $from, $to );
		$r             = $this->rows[ $id ] ?? null;
		if ( null === $r || $r->state !== $from ) {
			return false;
		}
		$this->rows[ $id ] = $this->with( $r, $to, $payment_id, $status_code );
		return true;
	}

	/** Copie de l'inscription avec un nouvel état ; null conserve payment_id / last_status_code. */
	private function with( Registration $r, string $state, ?string $payment_id, ?int $status_code ): Registration {
		return new Registration(
			$r->id,
			$r->reference,
			$r->submission_id,
			$r->token_hash,
			$state,
			$r->amount_cents,
			$r->currency,
			$r->customer,
			$r->hosted_checkout_id,
			$r->redirect_url,
			$r->checkout_expires_at,
			$payment_id ?? $r->payment_id,
			$status_code ?? $r->last_status_code
		);
	}
}
