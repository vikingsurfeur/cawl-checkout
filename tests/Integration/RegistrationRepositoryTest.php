<?php

declare( strict_types=1 );

use CawlCheckout\Checkout_Session;
use CawlCheckout\Customer_Data;
use CawlCheckout\Registration;
use CawlCheckout\Registration_Repository;
use CawlCheckout\Return_Token;
use CawlCheckout\Schema;
use CawlCheckout\Webhook_Event_Repository;
use PHPUnit\Framework\TestCase;

final class RegistrationRepositoryTest extends TestCase {
	private Registration_Repository $repo;

	protected function setUp(): void {
		Schema::install();
		$this->repo = new Registration_Repository();
		$this->repo->purge();
		( new Webhook_Event_Repository() )->purge();
	}

	private function customer( string $first = 'Jean', string $last = 'Dupont' ): Customer_Data {
		return new Customer_Data( $first, $last, 'jean@example.test', '1 rue de la Paix', '13100', 'Aix-en-Provence', 'FR' );
	}

	private function paid_at( int $id ): ?string {
		global $wpdb;
		$table = Schema::table( 'registrations' );
		return $wpdb->get_var( $wpdb->prepare( "SELECT paid_at FROM {$table} WHERE id = %d", $id ) );
	}

	public function test_create_returns_created_registration(): void {
		$r = $this->repo->create( wp_generate_uuid4(), Return_Token::hash( 't' ), 2500, $this->customer() );
		$this->assertGreaterThan( 0, $r->id );
		$this->assertSame( Registration::CREATED, $r->state );
		$this->assertSame( 'EUR', $r->currency );
		$this->assertSame( 2500, $r->amount_cents );
		$this->assertMatchesRegularExpression( '/^INS-\d{8}-[0-9a-f]{12}$/', $r->reference );
		$this->assertSame( $r->id, $this->repo->find_by_reference( $r->reference )->id );
		$this->assertSame( $r->id, $this->repo->find_by_submission_id( $r->submission_id )->id );
		$this->assertNull( $this->repo->find_by_reference( 'INS-inconnue' ) );
		$this->assertNull( $this->repo->find( 999999 ) );
	}

	public function test_transition_is_conditional_on_current_state(): void {
		$r = $this->repo->create( wp_generate_uuid4(), Return_Token::hash( 't' ), 2500, $this->customer() );
		$this->assertTrue( $this->repo->transition( $r->id, 'created', 'failed', null, null ) );
		$this->assertFalse( $this->repo->transition( $r->id, 'created', 'checkout_open', null, null ) );
		$this->assertSame( 'failed', $this->repo->find( $r->id )->state );
	}

	public function test_duplicate_submission_id_is_rejected(): void {
		$id = wp_generate_uuid4();
		$this->repo->create( $id, Return_Token::hash( 't' ), 2500, $this->customer() );
		$this->expectException( RuntimeException::class );
		$this->repo->create( $id, Return_Token::hash( 'u' ), 2500, $this->customer() );
	}

	public function test_open_checkout_stores_session(): void {
		$r = $this->repo->create( wp_generate_uuid4(), Return_Token::hash( 't' ), 2500, $this->customer() );
		$s = new Checkout_Session( 'hc-123', 'https://pay.example.test/session/hc-123?x=1', '2026-10-21 14:30:00' );
		$this->assertTrue( $this->repo->open_checkout( $r->id, $s ) );
		$f = $this->repo->find( $r->id );
		$this->assertSame( Registration::CHECKOUT_OPEN, $f->state );
		$this->assertSame( 'hc-123', $f->hosted_checkout_id );
		$this->assertSame( 'https://pay.example.test/session/hc-123?x=1', $f->redirect_url );
		$this->assertSame( '2026-10-21 14:30:00', $f->checkout_expires_at );
		$this->assertFalse( $this->repo->open_checkout( $r->id, $s ) );
	}

	public function test_paid_transition_sets_paid_at_and_payment_fields(): void {
		$r = $this->repo->create( wp_generate_uuid4(), Return_Token::hash( 't' ), 2500, $this->customer() );
		$this->assertTrue( $this->repo->transition( $r->id, 'created', 'paid', '3066019730_1', 9 ) );
		$f = $this->repo->find( $r->id );
		$this->assertSame( 'paid', $f->state );
		$this->assertSame( '3066019730_1', $f->payment_id );
		$this->assertSame( 9, $f->last_status_code );
		$this->assertNotNull( $this->paid_at( $r->id ) );
	}

	public function test_non_paid_transition_leaves_paid_at_null(): void {
		$r = $this->repo->create( wp_generate_uuid4(), Return_Token::hash( 't' ), 2500, $this->customer() );
		$this->repo->transition( $r->id, 'created', 'failed', 'p1', 2 );
		$this->assertNull( $this->paid_at( $r->id ) );
	}

	public function test_transition_without_payment_fields_keeps_existing_ones(): void {
		$r = $this->repo->create( wp_generate_uuid4(), Return_Token::hash( 't' ), 2500, $this->customer() );
		$this->repo->transition( $r->id, 'created', 'processing', 'p1', 5 );
		$this->repo->transition( $r->id, 'processing', 'failed', null, null );
		$f = $this->repo->find( $r->id );
		$this->assertSame( 'p1', $f->payment_id );
		$this->assertSame( 5, $f->last_status_code );
	}

	public function test_accented_customer_data_round_trips(): void {
		$c = new Customer_Data( 'Ève', "O'Connor-Dupont", 'eve@example.test', '12 rue de l’Étoile', '13100', 'Saint-Rémy', 'FR' );
		$r = $this->repo->create( wp_generate_uuid4(), Return_Token::hash( 't' ), 2500, $c );
		$this->assertEquals( $c, $this->repo->find( $r->id )->customer );
	}

	public function test_list_by_states_and_purge(): void {
		$a = $this->repo->create( wp_generate_uuid4(), Return_Token::hash( 'a' ), 2500, $this->customer() );
		$b = $this->repo->create( wp_generate_uuid4(), Return_Token::hash( 'b' ), 2500, $this->customer() );
		$this->repo->transition( $b->id, 'created', 'failed', null, null );
		$this->assertSame( array( $a->id ), array_map( static fn( $r ) => $r->id, $this->repo->list_by_states( array( 'created' ) ) ) );
		$this->assertCount( 2, $this->repo->list_by_states( array( 'created', 'failed' ) ) );
		$this->assertCount( 1, $this->repo->list_by_states( array( 'created', 'failed' ), 1 ) );
		$this->assertSame( array(), $this->repo->list_by_states( array() ) );
		$this->assertSame( 2, $this->repo->purge() );
		$this->assertNull( $this->repo->find( $a->id ) );
	}
}
