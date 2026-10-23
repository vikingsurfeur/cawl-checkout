<?php
/**
 * Tests de la configuration CAWL.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

use CawlCheckout\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase {
	private const VARS = array(
		'CAWL_ENV'            => 'preprod',
		'CAWL_PSPID'          => 'pspid',
		'CAWL_API_KEY'        => 'key',
		'CAWL_API_SECRET'     => 's3cr3t-api',
		'CAWL_WEBHOOK_KEY_ID' => 'kid',
		'CAWL_WEBHOOK_SECRET' => 's3cr3t-hook',
	);

	private function config( array $override = array() ): Config {
		$vars = array_merge( self::VARS, $override );
		return Config::from_env( fn( $name ) => $vars[ $name ] ?? false );
	}

	public function test_complete_preprod_config_is_usable(): void {
		$this->assertTrue( $this->config()->is_usable() );
		$this->assertSame( 'https://payment.preprod.cawl-solutions.fr', $this->config()->api_endpoint() );
		$this->assertSame( 'https://payment.preprod.cawl-solutions.fr', $this->config()->payment_host() );
	}

	public function test_missing_secret_makes_config_incomplete(): void {
		$c = $this->config( array( 'CAWL_API_SECRET' => '' ) );
		$this->assertFalse( $c->is_complete() );
		$this->assertFalse( $c->is_usable() );
	}

	public function test_non_preprod_env_is_refused(): void {
		$c = $this->config( array( 'CAWL_ENV' => 'prod' ) );
		$this->assertTrue( $c->is_complete() );
		$this->assertFalse( $c->is_usable() );
	}

	public function test_debug_output_masks_secrets(): void {
		$dump = print_r( $this->config(), true ) . var_export( $this->config()->__debugInfo(), true );
		$this->assertStringNotContainsString( 's3cr3t-api', $dump );
		$this->assertStringNotContainsString( 's3cr3t-hook', $dump );
	}
}
