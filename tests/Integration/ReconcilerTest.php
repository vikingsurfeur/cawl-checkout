<?php

declare( strict_types=1 );

use CawlCheckout\Checkout_Session;
use CawlCheckout\Customer_Data;
use CawlCheckout\Fake_Gateway;
use CawlCheckout\Payment_Snapshot;
use CawlCheckout\Reconciler;
use CawlCheckout\Registration;
use CawlCheckout\Registration_Repository;
use CawlCheckout\Schema;
use PHPUnit\Framework\TestCase;

final class ReconcilerTest extends TestCase {
	private Registration_Repository $repo;
	private Fake_Gateway $fake;
	private Reconciler $reconciler;
	private \Closure $filter;
	private Registration $reg;

	protected function setUp(): void {
		Schema::install();
		$this->repo = new Registration_Repository();
		$this->repo->purge();
		$this->fake   = new Fake_Gateway();
		$this->filter = fn() => $this->fake;
		add_filter( 'cawl_checkout_gateway', $this->filter );
		$this->reg        = $this->open( 'hc-1', time() + 3600 );
		$this->reconciler = new Reconciler();
	}

	protected function tearDown(): void {
		remove_filter( 'cawl_checkout_gateway', $this->filter );
	}

	private function open( string $checkout_id, int $expires ): Registration {
		$reg = $this->repo->create( wp_generate_uuid4(), hash( 'sha256', $checkout_id ), 2500, new Customer_Data( 'Jean', 'Dupont', 'jean@example.test', '1 rue de la Paix', '13100', 'Aix-en-Provence', 'FR' ) );
		$this->repo->open_checkout( $reg->id, new Checkout_Session( $checkout_id, 'https://payment.preprod.cawl-solutions.fr/hostedcheckout/x', gmdate( 'Y-m-d H:i:s', $expires ) ) );
		return $this->repo->find( $reg->id );
	}

	private function snapshot( int $code, string $payment_id = 'p1' ): Payment_Snapshot {
		return new Payment_Snapshot( $payment_id, $code, $this->reg->reference, 2500, 'EUR', 'PAYMENT_CREATED' );
	}

	public function test_reconcile_marks_paid_when_runner_never_returned(): void {
		$this->fake->checkouts['hc-1'] = $this->snapshot( 9 );
		$this->assertSame( array( array( 'reference' => $this->reg->reference, 'from' => 'checkout_open', 'to' => 'paid' ) ), $this->reconciler->run() );
	}

	public function test_reconcile_detects_refund_of_paid_registration(): void {
		$this->repo->transition( $this->reg->id, 'checkout_open', 'paid', 'p1', 9 );
		$this->fake->payments['p1'] = $this->snapshot( 8 );
		$this->assertSame( array( array( 'reference' => $this->reg->reference, 'from' => 'paid', 'to' => 'refunded' ) ), $this->reconciler->run() );
		$this->assertSame( array( 'get_payment' ), $this->fake->calls );
	}

	public function test_new_payment_after_refusal_on_same_session_is_paid(): void {
		// P1 refusé (code 2), puis P2 réglé sur la même page de paiement : le checkout porte le dernier paiement.
		$this->repo->transition( $this->reg->id, 'checkout_open', 'failed', 'p1', 2 );
		$this->fake->payments['p1']    = $this->snapshot( 2, 'p1' );
		$this->fake->checkouts['hc-1'] = $this->snapshot( 9, 'p2' );
		$this->assertSame( array( array( 'reference' => $this->reg->reference, 'from' => 'failed', 'to' => 'paid' ) ), $this->reconciler->run() );
		$this->assertSame( 'p2', $this->repo->find( $this->reg->id )->payment_id );
	}

	public function test_vanished_checkout_falls_back_to_known_payment(): void {
		// Le checkout n'est plus connu de CAWL (session de plus de 3 h) : le paiement connu fait foi.
		$this->repo->transition( $this->reg->id, 'checkout_open', 'processing', 'p1', 91 );
		$this->fake->payments['p1'] = $this->snapshot( 9, 'p1' );
		$this->assertSame( array( array( 'reference' => $this->reg->reference, 'from' => 'processing', 'to' => 'paid' ) ), $this->reconciler->run() );
		$this->assertSame( array( 'get_checkout', 'get_payment' ), $this->fake->calls );
	}

	public function test_reconcile_expires_abandoned_session(): void {
		$old = $this->open( 'hc-old', time() - 60 );
		$this->fake->checkouts['hc-old'] = new Payment_Snapshot( null, null, null, null, null, 'IN_PROGRESS' );
		$this->assertSame( array( array( 'reference' => $old->reference, 'from' => 'checkout_open', 'to' => 'expired' ) ), $this->reconciler->run( $old->reference ) );
	}

	public function test_gateway_error_on_one_registration_does_not_stop_batch(): void {
		// La 1re a un paiement inconnu de la passerelle (exception), la 2e se règle.
		$this->repo->transition( $this->reg->id, 'checkout_open', 'processing', 'p-inconnu', 5 );
		$second                        = $this->open( 'hc-2', time() + 3600 );
		$this->fake->checkouts['hc-2'] = new Payment_Snapshot( 'p2', 9, $second->reference, 2500, 'EUR', 'PAYMENT_CREATED' );
		$this->assertSame( array( array( 'reference' => $second->reference, 'from' => 'checkout_open', 'to' => 'paid' ) ), $this->reconciler->run() );
	}

	public function test_global_run_reaches_registrations_beyond_first_page(): void {
		// 101 inscriptions payées plus anciennes que la dernière, restée checkout_open.
		$this->repo->purge();
		for ( $i = 0; $i < 101; $i++ ) {
			$paid = $this->open( 'hc-paid-' . $i, time() + 3600 );
			$this->repo->transition( $paid->id, 'checkout_open', 'paid', 'pp-' . $i, 9 );
			$this->fake->payments[ 'pp-' . $i ] = new Payment_Snapshot( 'pp-' . $i, 9, $paid->reference, 2500, 'EUR', 'PAYMENT_CREATED' );
		}
		$last                           = $this->open( 'hc-last', time() + 3600 );
		$this->fake->checkouts['hc-last'] = new Payment_Snapshot( 'p-last', 9, $last->reference, 2500, 'EUR', 'PAYMENT_CREATED' );
		$this->assertSame( array( array( 'reference' => $last->reference, 'from' => 'checkout_open', 'to' => 'paid' ) ), $this->reconciler->run() );
		$this->repo->purge();
	}

	public function test_unchanged_registration_is_not_reported(): void {
		$this->fake->checkouts['hc-1'] = new Payment_Snapshot( null, null, null, null, null, 'IN_PROGRESS' );
		$this->assertSame( array(), $this->reconciler->run() );
	}
}
