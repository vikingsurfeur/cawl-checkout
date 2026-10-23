<?php

declare( strict_types=1 );

use CawlCheckout\Pricing;
use PHPUnit\Framework\TestCase;

/**
 * Réglages lus par un filtre pre_option : l'option réelle de la campagne n'est jamais écrite.
 */
final class PricingTest extends TestCase {
	private function session_minutes_for( mixed $stored ): int {
		$filter = static fn() => $stored;
		add_filter( 'pre_option_' . Pricing::SESSION_OPTION, $filter );
		try {
			return Pricing::session_minutes();
		} finally {
			remove_filter( 'pre_option_' . Pricing::SESSION_OPTION, $filter );
		}
	}

	public function test_session_minutes_is_capped_at_cawl_three_hours(): void {
		$this->assertSame( 180, $this->session_minutes_for( 1440 ) );
		$this->assertSame( 180, $this->session_minutes_for( 181 ) );
		$this->assertSame( 180, Pricing::MAX_SESSION_MINUTES );
	}

	public function test_session_minutes_within_bounds_is_kept(): void {
		$this->assertSame( 2, $this->session_minutes_for( 2 ) );
		$this->assertSame( 1, $this->session_minutes_for( 0 ) );
	}
}
