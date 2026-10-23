<?php

declare( strict_types=1 );

use CawlCheckout\Customer_Data;
use CawlCheckout\Registration_Repository;
use CawlCheckout\Return_Token;
use CawlCheckout\Schema;
use PHPUnit\Framework\TestCase;

/**
 * La suite d'intégration travaille sur ses propres tables et journalise hors de debug.log :
 * un lancement en pleine campagne ne touche ni aux inscriptions ni au journal du site.
 */
final class TestIsolationTest extends TestCase {
	public function test_suite_uses_dedicated_test_tables(): void {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'cawl_checkout_test_registrations', Schema::table( 'registrations' ) );
		$this->assertSame( $wpdb->prefix . 'cawl_checkout_test_webhook_events', Schema::table( 'webhook_events' ) );
	}

	public function test_repository_never_touches_campaign_table(): void {
		global $wpdb;
		$campaign = $wpdb->prefix . 'cawl_checkout_registrations';
		$count    = static fn(): int => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$campaign}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table interne.
		$before   = $count();

		$repo = new Registration_Repository();
		$r    = $repo->create( wp_generate_uuid4(), Return_Token::hash( 't' ), 2500, new Customer_Data( 'Jean', 'Dupont', 'jean@example.test', '1 rue de la Paix', '13100', 'Aix-en-Provence', 'FR' ) );

		$this->assertSame( $before, $count() );
		$this->assertSame( $r->id, $repo->find_by_reference( $r->reference )->id );
		$repo->purge();
		$this->assertSame( $before, $count() );
	}

	public function test_error_log_is_redirected_out_of_debug_log(): void {
		$this->assertSame( '/tmp/cawl-checkout-integration.log', ini_get( 'error_log' ) );
	}
}
