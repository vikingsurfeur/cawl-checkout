<?php

declare( strict_types=1 );

use CawlCheckout\Checkout_Session;
use CawlCheckout\Customer_Data;
use CawlCheckout\Plugin;
use CawlCheckout\Registration;
use CawlCheckout\Registration_Repository;
use CawlCheckout\Return_Token;
use CawlCheckout\Schema;
use CawlCheckout\Webhook_Event_Repository;
use CawlCheckout\Webhook_Simulator;
use PHPUnit\Framework\TestCase;

final class WebhookControllerTest extends TestCase {
	private Registration_Repository $repo;
	private Webhook_Event_Repository $events;
	private Webhook_Simulator $sim;
	private Registration $reg;

	protected function setUp(): void {
		Schema::install();
		$this->repo   = new Registration_Repository();
		$this->events = new Webhook_Event_Repository();
		$this->repo->purge();
		$this->events->purge();
		$this->sim = new Webhook_Simulator( Plugin::config() );

		$created = $this->repo->create(
			wp_generate_uuid4(),
			Return_Token::hash( 't' ),
			2500,
			new Customer_Data( 'Jean', 'Dupont', 'jean@example.test', '1 rue de la Paix', '13100', 'Aix-en-Provence', 'FR' )
		);
		$this->repo->open_checkout( $created->id, new Checkout_Session( 'hc-1', 'https://example.test/pay', gmdate( 'Y-m-d H:i:s', time() + 3600 ) ) );
		$this->reg = $this->repo->find( $created->id );
	}

	private function state(): string {
		return $this->repo->find( $this->reg->id )->state;
	}

	private function outcome( string $event_id ): ?string {
		global $wpdb;
		$table = Schema::table( 'webhook_events' );
		return $wpdb->get_var( $wpdb->prepare( "SELECT outcome FROM {$table} WHERE event_id = %s", $event_id ) );
	}

	private function request( string $body, ?string $signature, ?string $key_id ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/cawl-checkout/v1/webhook' );
		$request->set_body( $body );
		if ( null !== $signature ) {
			$request->set_header( 'X-GCS-Signature', $signature );
		}
		if ( null !== $key_id ) {
			$request->set_header( 'X-GCS-KeyId', $key_id );
		}
		return rest_do_request( $request );
	}

	public function test_valid_signed_capture_marks_paid(): void {
		$res = $this->sim->dispatch( $this->sim->payload( $this->reg, 'payment.captured', 9 ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'received' => true ), $res->get_data() );
		$this->assertSame( Registration::PAID, $this->state() );
	}

	public function test_invalid_signature_is_401_and_changes_nothing(): void {
		$res = $this->sim->dispatch( $this->sim->payload( $this->reg, 'payment.captured', 9 ), true );
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( 'cawl_checkout_signature', $res->get_data()['code'] );
		$this->assertSame( Registration::CHECKOUT_OPEN, $this->state() );
		$this->assertSame( 0, $this->events->count() );
	}

	public function test_missing_headers_is_401(): void {
		$res = $this->request( $this->sim->payload( $this->reg, 'payment.captured', 9 ), null, null );
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( Registration::CHECKOUT_OPEN, $this->state() );
	}

	public function test_unknown_key_id_is_401(): void {
		$body = $this->sim->payload( $this->reg, 'payment.captured', 9 );
		$res  = $this->request( $body, $this->sim->sign( $body ), 'autre-cle' );
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( 'cawl_checkout_signature', $res->get_data()['code'] );
	}

	public function test_duplicate_event_is_processed_once(): void {
		$body = $this->sim->payload( $this->reg, 'payment.captured', 9, null, 'evt-dup' );
		$this->assertSame( 200, $this->sim->dispatch( $body )->get_status() );
		$this->assertSame( 200, $this->sim->dispatch( $body )->get_status() );
		$this->assertSame( 1, $this->events->count() );
		$this->assertSame( 'applied', $this->outcome( 'evt-dup' ) );
		$this->assertSame( Registration::PAID, $this->state() );
	}

	public function test_unprocessed_duplicate_is_processed_on_retry(): void {
		// Simule un arrêt après record() : la ligne existe, processed_at est NULL.
		$this->events->record( 'evt-crash', 'payment.captured', $this->reg->reference, 'p1', 9 );
		$this->assertFalse( $this->events->is_processed( 'evt-crash' ) );
		$body = $this->sim->payload( $this->reg, 'payment.captured', 9, null, 'evt-crash' );
		$this->assertSame( 200, $this->sim->dispatch( $body )->get_status() );
		$this->assertSame( Registration::PAID, $this->state() );
		$this->assertTrue( $this->events->is_processed( 'evt-crash' ) );
		$this->assertSame( 200, $this->sim->dispatch( $body )->get_status() );
		$this->assertSame( Registration::PAID, $this->state() );
		$this->assertSame( 1, $this->events->count() );
	}

	/**
	 * Casse les requêtes qui commencent par $prefix (erreur SQL réelle) tant que le filtre est actif.
	 */
	private function break_queries( string $prefix ): \Closure {
		$filter = static fn( string $sql ): string => str_starts_with( ltrim( $sql ), $prefix ) ? 'SELEC broken' : $sql;
		add_filter( 'query', $filter );
		return static function () use ( $filter ): void {
			remove_filter( 'query', $filter );
		};
	}

	public function test_database_error_on_event_insert_is_500_then_retry_succeeds(): void {
		$body    = $this->sim->payload( $this->reg, 'payment.captured', 9, null, 'evt-db1' );
		$restore = $this->break_queries( 'INSERT IGNORE' );
		try {
			$res = $this->sim->dispatch( $body );
		} finally {
			$restore();
		}
		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( Registration::CHECKOUT_OPEN, $this->state() );
		$this->assertSame( 0, $this->events->count() );

		$this->assertSame( 200, $this->sim->dispatch( $body )->get_status() );
		$this->assertSame( Registration::PAID, $this->state() );
	}

	public function test_database_error_on_registration_update_is_500_then_retry_succeeds(): void {
		$body    = $this->sim->payload( $this->reg, 'payment.captured', 9, null, 'evt-db2' );
		$restore = $this->break_queries( 'UPDATE ' . Schema::table( 'registrations' ) );
		try {
			$res = $this->sim->dispatch( $body );
		} finally {
			$restore();
		}
		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( array( 'received' => false ), $res->get_data() );
		$this->assertSame( Registration::CHECKOUT_OPEN, $this->state() );
		$this->assertFalse( $this->events->is_processed( 'evt-db2' ) );

		$this->assertSame( 200, $this->sim->dispatch( $body )->get_status() );
		$this->assertSame( Registration::PAID, $this->state() );
		$this->assertTrue( $this->events->is_processed( 'evt-db2' ) );
		$this->assertSame( 1, $this->events->count() );
	}

	public function test_out_of_order_created_after_captured_keeps_paid(): void {
		$this->sim->dispatch( $this->sim->payload( $this->reg, 'payment.captured', 9 ) );
		$res = $this->sim->dispatch( $this->sim->payload( $this->reg, 'payment.created', 0 ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( Registration::PAID, $this->state() );
	}

	public function test_forged_amount_is_anomaly(): void {
		$res = $this->sim->dispatch( $this->sim->payload( $this->reg, 'payment.captured', 9, 100 ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( Registration::ANOMALY, $this->state() );
	}

	public function test_unknown_reference_is_acknowledged(): void {
		$ghost = new Registration( 999999, 'INS-20261021-ffffffffffff', 's', 'h', Registration::CREATED, 2500, 'EUR', $this->reg->customer );
		$res   = $this->sim->dispatch( $this->sim->payload( $ghost, 'payment.captured', 9, null, 'evt-ghost' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'unknown_reference', $this->outcome( 'evt-ghost' ) );
		$this->assertSame( Registration::CHECKOUT_OPEN, $this->state() );
	}

	public function test_event_without_payment_is_ignored(): void {
		$body = (string) wp_json_encode(
			array(
				'apiVersion' => 'v1',
				'id'         => 'evt-nopay',
				'created'    => gmdate( 'Y-m-d\TH:i:s.v\Z' ),
				'merchantId' => 'x',
				'type'       => 'payment.created',
			)
		);
		$this->assertSame( 200, $this->sim->dispatch( $body )->get_status() );
		$this->assertSame( 'ignored', $this->outcome( 'evt-nopay' ) );
	}

	public function test_wrong_api_version_is_400(): void {
		$body = (string) wp_json_encode(
			array(
				'apiVersion' => 'v2',
				'id'         => 'evt-v2',
				'type'       => 'payment.created',
			)
		);
		$this->assertSame( 400, $this->sim->dispatch( $body )->get_status() );
		$this->assertSame( 0, $this->events->count() );
	}

	public function test_body_tampered_after_signing_is_401(): void {
		$body     = $this->sim->payload( $this->reg, 'payment.captured', 9 );
		$tampered = str_replace( '"amount":2500', '"amount":1', $body );
		$this->assertNotSame( $body, $tampered );
		$res = $this->request( $tampered, $this->sim->sign( $body ), Plugin::config()->webhook_key_id() );
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( Registration::CHECKOUT_OPEN, $this->state() );
	}
}
