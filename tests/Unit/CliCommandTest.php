<?php
/**
 * Tests de la validation des options de `wp cawl-checkout settings`.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

use CawlCheckout\Cli_Command;
use PHPUnit\Framework\TestCase;

final class CliCommandTest extends TestCase {
	public function test_session_minutes_above_180_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( '--session-minutes doit être compris entre 1 et 180' );
		Cli_Command::settings_values( array( 'session-minutes' => '181' ) );
	}

	public function test_session_minutes_up_to_180_is_accepted(): void {
		$this->assertSame(
			array(
				'price'           => null,
				'session_minutes' => 180,
			),
			Cli_Command::settings_values( array( 'session-minutes' => '180' ) )
		);
	}

	public function test_zero_values_are_refused(): void {
		foreach ( array( 'price', 'session-minutes' ) as $name ) {
			try {
				Cli_Command::settings_values( array( $name => '0' ) );
				$this->fail( 'Exception attendue pour --' . $name . '=0.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertStringStartsWith( '--' . $name, $e->getMessage() );
			}
		}
	}

	public function test_flag_without_value_is_refused(): void {
		// Un drapeau sans valeur arrive sous la forme true : jamais converti en « 1 ».
		foreach ( array( 'price', 'session-minutes' ) as $name ) {
			try {
				Cli_Command::settings_values( array( $name => true ) );
				$this->fail( 'Exception attendue pour --' . $name . ' sans valeur.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertStringStartsWith( '--' . $name, $e->getMessage() );
			}
		}
	}

	public function test_price_alone_is_returned(): void {
		$this->assertSame(
			array(
				'price'           => 1302,
				'session_minutes' => null,
			),
			Cli_Command::settings_values( array( 'price' => '1302' ) )
		);
	}
}
