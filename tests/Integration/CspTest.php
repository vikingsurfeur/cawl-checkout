<?php

declare( strict_types=1 );

use CawlCheckout\Checkout_Controller;
use PHPUnit\Framework\TestCase;

final class CspTest extends TestCase {
	private const CAWL_HOST = 'https://payment.preprod.cawl-solutions.fr';
	private const CAWL_URL  = 'https://payment.preprod.cawl-solutions.fr/hostedcheckout/x';

	public function test_cawl_host_is_added_to_form_action_sources(): void {
		$this->assertSame( array( "'self'", self::CAWL_HOST ), apply_filters( 'wp_test_csp_form_action', array( "'self'" ) ) );
	}

	public function test_cawl_host_is_added_to_socle_form_action_directive(): void {
		$directives = apply_filters(
			'socle_csp_directives',
			array(
				'script-src'  => array( "'self'" ),
				'form-action' => array( "'self'" ),
			)
		);
		$this->assertSame( array( "'self'", self::CAWL_HOST ), $directives['form-action'] );
		$this->assertSame( array( "'self'" ), $directives['script-src'] );
	}

	public function test_socle_without_form_action_is_left_unrestricted(): void {
		$directives = apply_filters( 'socle_csp_directives', array( 'script-src' => array( "'self'" ) ) );
		$this->assertArrayNotHasKey( 'form-action', $directives );
	}

	public function test_cawl_host_is_not_a_site_wide_redirect_host(): void {
		$this->assertNotContains( 'payment.preprod.cawl-solutions.fr', apply_filters( 'allowed_redirect_hosts', array() ) );
		$this->assertSame( 'http://fallback.test/', wp_validate_redirect( self::CAWL_URL, 'http://fallback.test/' ) );
	}

	public function test_cawl_host_is_allowed_only_around_checkout_redirect(): void {
		$inside = Checkout_Controller::with_payment_redirect_host( static fn() => wp_validate_redirect( self::CAWL_URL, 'http://fallback.test/' ) );
		$this->assertSame( self::CAWL_URL, $inside );
		$this->assertSame( 'http://fallback.test/', wp_validate_redirect( self::CAWL_URL, 'http://fallback.test/' ) );
	}
}
