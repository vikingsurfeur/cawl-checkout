<?php
/**
 * Tests de l'application des instantanés de paiement.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

use CawlCheckout\Customer_Data;
use CawlCheckout\In_Memory_Registration_Store;
use CawlCheckout\Payment_Applier;
use CawlCheckout\Payment_Snapshot;
use CawlCheckout\Registration;
use PHPUnit\Framework\TestCase;

final class PaymentApplierTest extends TestCase {
	private const REF = 'INS-20261021-0123456789ab';

	private In_Memory_Registration_Store $store;
	private Payment_Applier $applier;
	private string $log_file;
	private string|false $previous;

	protected function setUp(): void {
		$this->log_file = (string) tempnam( sys_get_temp_dir(), 'cawl-log' );
		$this->previous = ini_set( 'error_log', $this->log_file );
		$this->store    = new In_Memory_Registration_Store();
		$this->applier  = new Payment_Applier( $this->store );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', false === $this->previous ? '' : $this->previous );
		@unlink( $this->log_file );
	}

	private function reg( string $state = 'checkout_open', ?string $expires_at = null, ?string $payment_id = null ): Registration {
		$r = new Registration(
			1,
			self::REF,
			'sub-1',
			'hash',
			$state,
			2500,
			'EUR',
			new Customer_Data( 'A', 'B', 'a@b.fr', '1 rue X', '13100', 'Aix', 'FR' ),
			'HC-1',
			null,
			$expires_at,
			$payment_id
		);
		$this->store->add( $r );
		return $r;
	}

	private function snap( ?int $code, int $amount = 2500, string $currency = 'EUR', string $reference = self::REF ): Payment_Snapshot {
		return new Payment_Snapshot( 'PAY-1', $code, $reference, $amount, $currency );
	}

	private function checkout( string $status ): Payment_Snapshot {
		return new Payment_Snapshot( null, null, null, null, null, $status );
	}

	public function test_code_9_with_matching_data_marks_paid(): void {
		$this->assertSame( 'paid', $this->applier->apply( $this->reg(), $this->snap( 9 ), 'return' ) );
		$this->assertSame( 'paid', $this->store->find( 1 )->state );
		$this->assertSame( 'PAY-1', $this->store->find( 1 )->payment_id );
	}

	public function test_code_9_with_wrong_amount_is_anomaly(): void {
		$this->assertSame( 'anomaly', $this->applier->apply( $this->reg(), $this->snap( 9, amount: 100 ), 'webhook' ) );
	}

	public function test_code_9_with_wrong_reference_or_currency_is_anomaly(): void {
		$this->assertSame( 'anomaly', $this->applier->apply( $this->reg(), $this->snap( 9, reference: 'INS-autre' ), 'webhook' ) );
		$this->assertSame( 'anomaly', $this->applier->apply( $this->reg(), $this->snap( 9, currency: 'USD' ), 'webhook' ) );
	}

	public function test_code_9_with_missing_data_is_anomaly(): void {
		$s = new Payment_Snapshot( 'PAY-1', 9, null, null, null );
		$this->assertSame( 'anomaly', $this->applier->apply( $this->reg(), $s, 'return' ) );
	}

	public function test_processing_codes_mark_processing(): void {
		foreach ( array( 91, 51 ) as $code ) {
			$this->assertSame( 'processing', $this->applier->apply( $this->reg(), $this->snap( $code ), 'webhook' ) );
		}
	}

	public function test_late_created_event_does_not_regress_paid(): void {
		$paid = $this->reg( state: 'paid' );
		$this->assertSame( 'paid', $this->applier->apply( $paid, $this->snap( 0 ), 'webhook' ) );
		$this->assertSame( array(), $this->store->calls );
	}

	public function test_refused_transition_is_logged_and_state_kept(): void {
		$paid = $this->reg( state: 'paid' );
		$this->assertSame( 'paid', $this->applier->apply( $paid, $this->snap( 2 ), 'webhook' ) );
		$this->assertStringContainsString( 'transition refusée', (string) file_get_contents( $this->log_file ) );
		$this->assertSame( array(), $this->store->calls );
	}

	public function test_unknown_code_changes_nothing(): void {
		$this->assertSame( 'checkout_open', $this->applier->apply( $this->reg(), $this->snap( 999 ), 'webhook' ) );
		$this->assertSame( array(), $this->store->calls );
	}

	public function test_refund_in_progress_keeps_paid_without_unknown_code_warning(): void {
		foreach ( array( 81, 82 ) as $code ) {
			$this->assertSame( 'paid', $this->applier->apply( $this->reg( state: 'paid' ), $this->snap( $code ), 'reconcile' ) );
		}
		$this->assertSame( array(), $this->store->calls );
		$this->assertStringNotContainsString( 'code de statut inconnu', $this->log() );
	}

	public function test_code_5_logs_authorization_mode_warning(): void {
		$this->assertSame( 'processing', $this->applier->apply( $this->reg(), $this->snap( 5 ), 'webhook' ) );
		$this->assertStringContainsString( 'authorizationMode', (string) file_get_contents( $this->log_file ) );
	}

	public function test_same_state_writes_nothing(): void {
		$this->assertSame( 'checkout_open', $this->applier->apply( $this->reg(), $this->snap( 0 ), 'return' ) );
		$this->assertSame( array(), $this->store->calls );
	}

	public function test_expired_session_without_payment_is_expired(): void {
		$reg     = $this->reg( expires_at: '2026-10-21 10:00:00' );
		$applier = new Payment_Applier( $this->store, fn() => new DateTimeImmutable( '2026-10-21 10:05:00', new DateTimeZone( 'UTC' ) ) );
		$this->assertSame( 'expired', $applier->apply( $reg, $this->checkout( 'IN_PROGRESS' ), 'reconcile' ) );
	}

	public function test_in_progress_before_expiry_changes_nothing(): void {
		$reg     = $this->reg( expires_at: '2026-10-21 10:00:00' );
		$applier = new Payment_Applier( $this->store, fn() => new DateTimeImmutable( '2026-10-21 09:55:00', new DateTimeZone( 'UTC' ) ) );
		$this->assertSame( 'checkout_open', $applier->apply( $reg, $this->checkout( 'IN_PROGRESS' ), 'reconcile' ) );
	}

	public function test_consumer_cancel_is_cancelled(): void {
		$this->assertSame( 'cancelled', $this->applier->apply( $this->reg(), $this->checkout( 'CANCELLED_BY_CONSUMER' ), 'return' ) );
		$reg = $this->reg( expires_at: '2026-10-21 10:00:00' );
		$this->assertSame( 'cancelled', $this->at( '2026-10-21 09:55:00' )->apply( $reg, $this->checkout( 'CANCELLED_BY_CONSUMER' ), 'return' ) );
	}

	public function test_consumer_cancel_after_expiry_is_expired(): void {
		// Contrat : CANCELLED_BY_CONSUMER couvre aussi la fermeture de la session à son expiration.
		$reg = $this->reg( expires_at: '2026-10-21 10:00:00' );
		$this->assertSame( 'expired', $this->at( '2026-10-21 10:05:00' )->apply( $reg, $this->checkout( 'CANCELLED_BY_CONSUMER' ), 'reconcile' ) );
	}

	/** Applicateur dont l'horloge marque l'heure donnée (UTC). */
	private function at( string $now ): Payment_Applier {
		return new Payment_Applier( $this->store, fn() => new DateTimeImmutable( $now, new DateTimeZone( 'UTC' ) ) );
	}

	private function log(): string {
		return (string) file_get_contents( $this->log_file );
	}

	public function test_not_found_before_expiry_changes_nothing_and_warns(): void {
		$reg = $this->reg( expires_at: '2026-10-21 10:00:00' );
		$this->assertSame( 'checkout_open', $this->at( '2026-10-21 09:55:00' )->apply( $reg, $this->checkout( 'NOT_FOUND' ), 'reconcile' ) );
		$this->assertSame( array(), $this->store->calls );
		$this->assertStringContainsString( 'WARNING checkout introuvable avant son expiration', $this->log() );
	}

	public function test_not_found_after_expiry_is_expired(): void {
		$reg = $this->reg( expires_at: '2026-10-21 10:00:00' );
		$this->assertSame( 'expired', $this->at( '2026-10-21 10:05:00' )->apply( $reg, $this->checkout( 'NOT_FOUND' ), 'reconcile' ) );
	}

	public function test_not_found_without_expiry_date_changes_nothing(): void {
		$this->assertSame( 'checkout_open', $this->applier->apply( $this->reg(), $this->checkout( 'NOT_FOUND' ), 'reconcile' ) );
		$this->assertSame( array(), $this->store->calls );
	}

	public function test_not_found_without_known_payment_asks_to_check_portal(): void {
		$reg = $this->reg( expires_at: '2026-10-21 10:00:00' );
		$this->at( '2026-10-21 10:05:00' )->apply( $reg, $this->checkout( 'NOT_FOUND' ), 'reconcile' );
		$this->assertStringContainsString( 'WARNING session disparue sans résultat observé : vérifier le portail', $this->log() );
	}

	public function test_not_found_with_known_payment_does_not_ask_to_check_portal(): void {
		$reg = $this->reg( expires_at: '2026-10-21 10:00:00', payment_id: 'PAY-0' );
		$this->assertSame( 'expired', $this->at( '2026-10-21 10:05:00' )->apply( $reg, $this->checkout( 'NOT_FOUND' ), 'reconcile' ) );
		$this->assertStringNotContainsString( 'vérifier le portail', $this->log() );
	}

	public function test_concurrent_change_is_retried_once(): void {
		$reg                                  = $this->reg();
		$this->store->before_first_transition = fn( In_Memory_Registration_Store $s ) => $s->force_state( 1, 'processing' );
		$this->assertSame( 'paid', $this->applier->apply( $reg, $this->snap( 9 ), 'webhook' ) );
		$this->assertSame( 'paid', $this->store->find( 1 )->state );
		$this->assertCount( 2, $this->store->calls );
	}

	public function test_concurrent_change_to_terminal_state_is_not_overwritten(): void {
		$reg                                  = $this->reg();
		$this->store->before_first_transition = fn( In_Memory_Registration_Store $s ) => $s->force_state( 1, 'paid' );
		$this->assertSame( 'paid', $this->applier->apply( $reg, $this->snap( 2 ), 'webhook' ) );
		$this->assertCount( 1, $this->store->calls );
	}
}
