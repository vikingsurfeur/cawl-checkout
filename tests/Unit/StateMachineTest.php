<?php
/**
 * Tests de la machine à états.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

use CawlCheckout\State_Machine;
use PHPUnit\Framework\TestCase;

final class StateMachineTest extends TestCase {
	private const ALL = array( 'created', 'checkout_open', 'processing', 'paid', 'failed', 'cancelled', 'expired', 'refunded', 'anomaly' );

	public function test_paid_only_moves_to_refunded(): void {
		foreach ( array( 'created', 'checkout_open', 'processing', 'failed', 'cancelled', 'expired', 'anomaly' ) as $to ) {
			$this->assertFalse( State_Machine::can_transition( 'paid', $to ), $to );
		}
		$this->assertTrue( State_Machine::can_transition( 'paid', 'refunded' ) );
	}

	public function test_late_success_after_failure_is_accepted(): void {
		$this->assertTrue( State_Machine::can_transition( 'failed', 'paid' ) );
		$this->assertTrue( State_Machine::can_transition( 'expired', 'paid' ) );
		$this->assertTrue( State_Machine::can_transition( 'cancelled', 'processing' ) );
	}

	public function test_self_transition_is_not_a_transition(): void {
		$this->assertFalse( State_Machine::can_transition( 'processing', 'processing' ) );
	}

	public function test_terminal_states_never_move(): void {
		foreach ( array( 'refunded', 'anomaly' ) as $from ) {
			foreach ( self::ALL as $to ) {
				$this->assertFalse( State_Machine::can_transition( $from, $to ), "$from -> $to" );
			}
		}
	}

	public function test_created_and_checkout_open_rows(): void {
		$this->assertTrue( State_Machine::can_transition( 'created', 'checkout_open' ) );
		$this->assertTrue( State_Machine::can_transition( 'created', 'failed' ) );
		$this->assertFalse( State_Machine::can_transition( 'created', 'paid' ) );
		foreach ( array( 'processing', 'paid', 'failed', 'cancelled', 'expired', 'anomaly' ) as $to ) {
			$this->assertTrue( State_Machine::can_transition( 'checkout_open', $to ), $to );
		}
		$this->assertFalse( State_Machine::can_transition( 'checkout_open', 'refunded' ) );
	}

	public function test_processing_and_failed_rows(): void {
		foreach ( array( 'paid', 'failed', 'cancelled', 'anomaly' ) as $to ) {
			$this->assertTrue( State_Machine::can_transition( 'processing', $to ), $to );
		}
		$this->assertFalse( State_Machine::can_transition( 'processing', 'expired' ) );
		foreach ( array( 'failed', 'cancelled', 'expired' ) as $from ) {
			$this->assertTrue( State_Machine::can_transition( $from, 'anomaly' ), $from );
			$this->assertFalse( State_Machine::can_transition( $from, 'refunded' ), $from );
			$this->assertFalse( State_Machine::can_transition( $from, 'checkout_open' ), $from );
		}
	}

	public function test_unknown_state_is_rejected(): void {
		$this->assertFalse( State_Machine::can_transition( 'nope', 'paid' ) );
	}
}
