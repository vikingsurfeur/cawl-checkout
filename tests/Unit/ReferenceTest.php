<?php
/**
 * Tests de la référence d'inscription.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

use CawlCheckout\Reference;
use PHPUnit\Framework\TestCase;

final class ReferenceTest extends TestCase {
	public function test_reference_format_and_length(): void {
		$r = Reference::generate( new DateTimeImmutable( '2026-10-21' ) );
		$this->assertMatchesRegularExpression( '/^INS-20261021-[0-9a-f]{12}$/', $r );
		$this->assertSame( 25, strlen( $r ) );
		$this->assertLessThanOrEqual( 40, strlen( $r ) );
	}

	public function test_references_differ(): void {
		$this->assertNotSame( Reference::generate(), Reference::generate() );
	}

	public function test_default_date_is_used_when_none_given(): void {
		$this->assertMatchesRegularExpression( '/^INS-\d{8}-[0-9a-f]{12}$/', Reference::generate() );
	}
}
