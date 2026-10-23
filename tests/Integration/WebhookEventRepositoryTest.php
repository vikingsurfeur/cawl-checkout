<?php

declare( strict_types=1 );

use CawlCheckout\Schema;
use CawlCheckout\Webhook_Event_Repository;
use PHPUnit\Framework\TestCase;

final class WebhookEventRepositoryTest extends TestCase {
	private Webhook_Event_Repository $events;

	protected function setUp(): void {
		Schema::install();
		$this->events = new Webhook_Event_Repository();
		$this->events->purge();
	}

	public function test_same_event_id_is_recorded_once(): void {
		$this->assertTrue( $this->events->record( 'evt-1', 'payment.captured', 'INS-1', 'p1', 9 ) );
		$this->assertFalse( $this->events->record( 'evt-1', 'payment.captured', 'INS-1', 'p1', 9 ) );
		$this->assertSame( 1, $this->events->count() );
	}

	public function test_nullable_fields_and_mark_processed(): void {
		$this->assertTrue( $this->events->record( 'evt-2', 'payment.created', null, null, null ) );
		$this->events->mark_processed( 'evt-2', 'ignored' );
		global $wpdb;
		$table = Schema::table( 'webhook_events' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT outcome, processed_at, received_at FROM {$table} WHERE event_id = %s", 'evt-2' ), ARRAY_A );
		$this->assertSame( 'ignored', $row['outcome'] );
		$this->assertNotNull( $row['processed_at'] );
		$this->assertNotNull( $row['received_at'] );
	}

	public function test_is_processed_only_after_mark_processed(): void {
		$this->assertFalse( $this->events->is_processed( 'evt-5' ) );
		$this->events->record( 'evt-5', 'payment.captured', 'INS-1', 'p1', 9 );
		$this->assertFalse( $this->events->is_processed( 'evt-5' ) );
		$this->events->mark_processed( 'evt-5', 'applied' );
		$this->assertTrue( $this->events->is_processed( 'evt-5' ) );
	}

	public function test_purge_empties_table(): void {
		$this->events->record( 'evt-3', 'payment.captured', 'INS-1', 'p1', 9 );
		$this->events->record( 'evt-4', 'payment.captured', 'INS-1', 'p1', 9 );
		$this->assertSame( 2, $this->events->purge() );
		$this->assertSame( 0, $this->events->count() );
	}
}
