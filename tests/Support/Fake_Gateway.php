<?php
/**
 * Passerelle factice pour les tests.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

/**
 * Passerelle en mémoire : les tests posent les réponses attendues et lisent le journal des appels.
 */
final class Fake_Gateway implements Gateway {
	public ?Checkout_Session $next_session = null;
	/** @var array<string,Payment_Snapshot> */
	public array $checkouts = array();
	/** @var array<string,Payment_Snapshot> */
	public array $payments = array();
	public bool $fail       = false;
	/** @var string[] */
	public array $calls = array();

	public function create_checkout( Registration $r, string $return_url ): Checkout_Session {
		$this->calls[] = __FUNCTION__;
		$this->maybe_fail();
		return $this->next_session ?? new Checkout_Session( 'HC-' . $r->reference, 'https://pay.example.test/' . $r->reference, gmdate( 'Y-m-d H:i:s', time() + 10800 ) );
	}

	public function get_checkout( string $hosted_checkout_id ): Payment_Snapshot {
		$this->calls[] = __FUNCTION__;
		$this->maybe_fail();
		return $this->checkouts[ $hosted_checkout_id ] ?? Payment_Snapshot::checkout_not_found();
	}

	public function get_payment( string $payment_id ): Payment_Snapshot {
		$this->calls[] = __FUNCTION__;
		$this->maybe_fail();
		return $this->payments[ $payment_id ] ?? throw new Gateway_Exception( 'Paiement inconnu.' );
	}

	public function refund( string $payment_id, int $amount_cents, string $idempotence_key ): string {
		$this->calls[] = __FUNCTION__;
		$this->maybe_fail();
		return 'RF-' . $payment_id;
	}

	public function validate_webhook_credentials(): bool {
		$this->calls[] = __FUNCTION__;
		$this->maybe_fail();
		return true;
	}

	private function maybe_fail(): void {
		if ( $this->fail ) {
			throw new Gateway_Exception( 'Échec simulé.' );
		}
	}
}
