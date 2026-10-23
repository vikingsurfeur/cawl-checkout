<?php
/**
 * Tests des instantanés de paiement.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

use CawlCheckout\Payment_Snapshot;
use OnlinePayments\Sdk\Domain\GetHostedCheckoutResponse;
use OnlinePayments\Sdk\Domain\PaymentDetailsResponse;
use PHPUnit\Framework\TestCase;

final class PaymentSnapshotTest extends TestCase {
	private const CAPTURED_JSON = '{"id":"3066019730_1","status":"CAPTURED","statusOutput":{"statusCode":9},'
		. '"paymentOutput":{"amountOfMoney":{"amount":2500,"currencyCode":"EUR"},'
		. '"references":{"merchantReference":"INS-20261021-0123456789ab"}}}';

	public function test_snapshot_from_captured_payment(): void {
		$s = Payment_Snapshot::from_payment( ( new PaymentDetailsResponse() )->fromJson( self::CAPTURED_JSON ) );
		$this->assertSame(
			array( '3066019730_1', 9, 'INS-20261021-0123456789ab', 2500, 'EUR' ),
			array( $s->payment_id, $s->status_code, $s->merchant_reference, $s->amount_cents, $s->currency )
		);
		$this->assertNull( $s->checkout_status );
	}

	public function test_snapshot_from_hosted_checkout_in_progress_has_no_payment(): void {
		$s = Payment_Snapshot::from_hosted_checkout( ( new GetHostedCheckoutResponse() )->fromJson( '{"status":"IN_PROGRESS"}' ) );
		$this->assertNull( $s->status_code );
		$this->assertNull( $s->payment_id );
		$this->assertSame( 'IN_PROGRESS', $s->checkout_status );
	}

	public function test_snapshot_from_hosted_checkout_with_created_payment(): void {
		$json = '{"status":"PAYMENT_CREATED","createdPaymentOutput":{"payment":' . self::CAPTURED_JSON . '}}';
		$s    = Payment_Snapshot::from_hosted_checkout( ( new GetHostedCheckoutResponse() )->fromJson( $json ) );
		$this->assertSame( 9, $s->status_code );
		$this->assertSame( '3066019730_1', $s->payment_id );
		$this->assertSame( 'PAYMENT_CREATED', $s->checkout_status );
	}

	public function test_checkout_not_found(): void {
		$s = Payment_Snapshot::checkout_not_found();
		$this->assertSame( 'NOT_FOUND', $s->checkout_status );
		$this->assertNull( $s->payment_id );
	}
}
