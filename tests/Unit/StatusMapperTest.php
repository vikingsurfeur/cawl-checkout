<?php
/**
 * Tests de la correspondance statut CAWL vers état.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

use CawlCheckout\Registration;
use CawlCheckout\Status_Mapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusMapperTest extends TestCase {
	/**
	 * @return array<string, array{int, string}>
	 */
	public static function mapped_codes(): array {
		return array(
			'9'  => array( 9, Registration::PAID ),
			'8'  => array( 8, Registration::REFUNDED ),
			'2'  => array( 2, Registration::FAILED ),
			'1'  => array( 1, Registration::CANCELLED ),
			'0'  => array( 0, Registration::CHECKOUT_OPEN ),
			'5'  => array( 5, Registration::PROCESSING ),
			'46' => array( 46, Registration::PROCESSING ),
			'50' => array( 50, Registration::PROCESSING ),
			'51' => array( 51, Registration::PROCESSING ),
			'55' => array( 55, Registration::PROCESSING ),
			'91' => array( 91, Registration::PROCESSING ),
			'92' => array( 92, Registration::PROCESSING ),
			'99' => array( 99, Registration::PROCESSING ),
			'7'  => array( 7, Registration::ANOMALY ),
			'84' => array( 84, Registration::ANOMALY ),
		);
	}

	#[DataProvider( 'mapped_codes' )]
	public function test_code_maps_to_state( int $code, string $state ): void {
		$this->assertSame( $state, Status_Mapper::target_state( $code ) );
	}

	public function test_unknown_code_maps_to_null(): void {
		$this->assertNull( Status_Mapper::target_state( 999 ) );
		$this->assertFalse( Status_Mapper::is_known( 999 ) );
	}

	public function test_refund_in_progress_codes_are_known_without_transition(): void {
		foreach ( array( 81, 82 ) as $code ) {
			$this->assertNull( Status_Mapper::target_state( $code ) );
			$this->assertTrue( Status_Mapper::is_known( $code ) );
		}
		$this->assertTrue( Status_Mapper::is_known( 9 ) );
	}
}
