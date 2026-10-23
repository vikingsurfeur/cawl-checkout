<?php
/**
 * Tests du jeton de retour.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

use CawlCheckout\Return_Token;
use PHPUnit\Framework\TestCase;

final class ReturnTokenTest extends TestCase {
	public function test_generated_token_is_32_hex(): void {
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', Return_Token::generate() );
	}

	public function test_hash_is_sha256_hex(): void {
		$this->assertSame( hash( 'sha256', 'abc' ), Return_Token::hash( 'abc' ) );
	}

	public function test_verify_accepts_own_hash_and_rejects_other(): void {
		$t = Return_Token::generate();
		$this->assertTrue( Return_Token::verify( $t, Return_Token::hash( $t ) ) );
		$this->assertFalse( Return_Token::verify( Return_Token::generate(), Return_Token::hash( $t ) ) );
	}

	public function test_verify_rejects_malformed_token(): void {
		$this->assertFalse( Return_Token::verify( '../x', Return_Token::hash( 'x' ) ) );
	}
}
