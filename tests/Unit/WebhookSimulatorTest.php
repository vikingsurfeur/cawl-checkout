<?php

declare( strict_types=1 );

use CawlCheckout\Config;
use CawlCheckout\Customer_Data;
use CawlCheckout\Registration;
use CawlCheckout\Webhook_Simulator;
use OnlinePayments\Sdk\Webhooks\InMemorySecretKeyStore;
use OnlinePayments\Sdk\Webhooks\WebhooksHelper;
use PHPUnit\Framework\TestCase;

final class WebhookSimulatorTest extends TestCase {
	private Webhook_Simulator $simulator;

	protected function setUp(): void {
		$env             = array(
			'CAWL_ENV'            => 'preprod',
			'CAWL_PSPID'          => 'PSPID',
			'CAWL_API_KEY'        => 'k',
			'CAWL_API_SECRET'     => 's',
			'CAWL_WEBHOOK_KEY_ID' => 'kid',
			'CAWL_WEBHOOK_SECRET' => 's3cr3t-hook',
		);
		$this->simulator = new Webhook_Simulator( Config::from_env( static fn( string $n ) => $env[ $n ] ?? false ) );
	}

	private function reg(): Registration {
		return new Registration(
			1,
			'INS-20261021-0123456789ab',
			'sub',
			'hash',
			Registration::CHECKOUT_OPEN,
			2500,
			'EUR',
			new Customer_Data( 'Jean', 'Dupont', 'jean@example.test', '1 rue', '13100', 'Aix', 'FR' )
		);
	}

	public function test_signed_payload_passes_sdk_validation(): void {
		$body   = $this->simulator->payload( $this->reg(), 'payment.captured', 9 );
		$helper = new WebhooksHelper( new InMemorySecretKeyStore( array( 'kid' => 's3cr3t-hook' ) ) );
		$event  = $helper->unmarshal(
			$body,
			array(
				'X-GCS-Signature' => $this->simulator->sign( $body ),
				'X-GCS-KeyId'     => 'kid',
			)
		);
		$this->assertSame( 9, $event->payment->statusOutput->statusCode );
		$this->assertSame( 'INS-20261021-0123456789ab', $event->payment->paymentOutput->references->merchantReference );
		$this->assertSame( 2500, $event->payment->paymentOutput->amountOfMoney->amount );
		$this->assertSame( 'PSPID', $event->merchantId );
	}

	public function test_refund_code_carries_refunded_status(): void {
		// Page Statuts CAWL : code 8, payment.status REFUNDED, statusCategory REVERSED.
		$payment = json_decode( $this->simulator->payload( $this->reg(), 'payment.refunded', 8 ), true )['payment'];
		$this->assertSame( 'REFUNDED', $payment['status'] );
		$this->assertSame( 'REVERSED', $payment['statusOutput']['statusCategory'] );
	}

	public function test_event_id_is_a_uuid_v4_and_overridable(): void {
		$data = json_decode( $this->simulator->payload( $this->reg(), 'payment.created', 0 ), true );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $data['id'] );
		$fixed = json_decode( $this->simulator->payload( $this->reg(), 'payment.created', 0, 100, 'evt-x', 'pay-x' ), true );
		$this->assertSame( 'evt-x', $fixed['id'] );
		$this->assertSame( 'pay-x', $fixed['payment']['id'] );
		$this->assertSame( 100, $fixed['payment']['paymentOutput']['amountOfMoney']['amount'] );
	}
}
