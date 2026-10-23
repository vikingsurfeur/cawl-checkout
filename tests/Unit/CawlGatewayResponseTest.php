<?php
/**
 * Tests de la conversion des réponses et erreurs du SDK par la passerelle CAWL.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

use CawlCheckout\Cawl_Gateway;
use CawlCheckout\Config;
use CawlCheckout\Gateway_Exception;
use OnlinePayments\Sdk\Domain\GetHostedCheckoutResponse;
use OnlinePayments\Sdk\Domain\ProblemDetailsResponse;
use OnlinePayments\Sdk\Domain\ValidateCredentialsRequest;
use OnlinePayments\Sdk\Domain\ValidateCredentialsResponse;
use OnlinePayments\Sdk\ExceptionFactory;
use PHPUnit\Framework\TestCase;

final class CawlGatewayResponseTest extends TestCase {
	private function config(): Config {
		$vars = array(
			'CAWL_ENV'            => 'preprod',
			'CAWL_PSPID'          => 'pspid',
			'CAWL_API_KEY'        => 'key',
			'CAWL_API_SECRET'     => 'secret',
			'CAWL_WEBHOOK_KEY_ID' => 'kid',
			'CAWL_WEBHOOK_SECRET' => 'hook',
		);
		return Config::from_env( fn( $name ) => $vars[ $name ] ?? false );
	}

	/** Passerelle dont les appels SDK sont remplacés par un rappel. */
	private function stubbed( callable $checkout, ?callable $validation = null ): Cawl_Gateway {
		return new class( $this->config(), 180, $checkout, $validation ) extends Cawl_Gateway {
			public function __construct( Config $config, int $minutes, private $checkout, private $validation ) {
				parent::__construct( $config, $minutes );
			}

			protected function fetch_checkout( string $hosted_checkout_id ): GetHostedCheckoutResponse {
				return ( $this->checkout )( $hosted_checkout_id );
			}

			protected function fetch_webhook_validation( ValidateCredentialsRequest $request ): ValidateCredentialsResponse {
				return ( $this->validation )( $request );
			}
		};
	}

	private function validation_gateway( string $json ): Cawl_Gateway {
		return $this->stubbed(
			fn() => new GetHostedCheckoutResponse(),
			fn() => ( new ValidateCredentialsResponse() )->fromJson( $json )
		);
	}

	public function test_valid_credentials_response_is_true(): void {
		$this->assertTrue( $this->validation_gateway( '{"result":"Valid"}' )->validate_webhook_credentials() );
	}

	public function test_invalid_credentials_response_is_false(): void {
		$this->assertFalse( $this->validation_gateway( '{"result":"Invalid"}' )->validate_webhook_credentials() );
	}

	public function test_missing_result_is_false(): void {
		$this->assertFalse( $this->validation_gateway( '{}' )->validate_webhook_credentials() );
	}

	public function test_validation_sends_key_and_hashed_secret_never_the_raw_secret(): void {
		$sent    = null;
		$gateway = $this->stubbed(
			fn() => new GetHostedCheckoutResponse(),
			function ( ValidateCredentialsRequest $request ) use ( &$sent ) {
				$sent = $request;
				return ( new ValidateCredentialsResponse() )->fromJson( '{"result":"Valid"}' );
			}
		);
		$gateway->validate_webhook_credentials();

		// Contrat validateCredentialsRequest.secret : le secret haché comme une signature, sur un corps vide.
		$this->assertSame( 'kid', $sent->getKey() );
		$this->assertSame( base64_encode( hash_hmac( 'sha256', '', 'hook', true ) ), $sent->getSecret() );
		$this->assertSame( 44, strlen( $sent->getSecret() ) );
		$this->assertStringNotContainsString( 'hook', $sent->toJson() );
	}

	private function throwing( int $status, bool $problem_details = false ): Cawl_Gateway {
		$error = $problem_details ? new ProblemDetailsResponse() : new \OnlinePayments\Sdk\Domain\ErrorResponse();
		return $this->stubbed(
			function () use ( $status, $error ) {
				throw ( new ExceptionFactory() )->createException( $status, $error );
			}
		);
	}

	public function test_404_maps_to_not_found(): void {
		$this->assertSame( 'NOT_FOUND', $this->throwing( 404 )->get_checkout( 'HC1' )->checkout_status );
	}

	public function test_404_problem_details_maps_to_not_found(): void {
		$this->assertSame( 'NOT_FOUND', $this->throwing( 404, true )->get_checkout( 'HC1' )->checkout_status );
	}

	public function test_409_is_a_gateway_exception(): void {
		$this->expectException( Gateway_Exception::class );
		$this->throwing( 409 )->get_checkout( 'HC1' );
	}

	public function test_410_is_a_gateway_exception(): void {
		$this->expectException( Gateway_Exception::class );
		$this->throwing( 410 )->get_checkout( 'HC1' );
	}

	public function test_500_is_a_gateway_exception_without_secret(): void {
		try {
			$this->throwing( 500 )->get_checkout( 'HC1' );
			$this->fail( 'Gateway_Exception attendue.' );
		} catch ( Gateway_Exception $e ) {
			$this->assertStringContainsString( 'HTTP 500', $e->getMessage() );
			$this->assertStringNotContainsString( 'secret', $e->getMessage() );
		}
	}

	public function test_network_error_is_a_gateway_exception(): void {
		$gateway = $this->stubbed( fn() => throw new \RuntimeException( 'cURL error with key=abc' ) );
		try {
			$gateway->get_checkout( 'HC1' );
			$this->fail( 'Gateway_Exception attendue.' );
		} catch ( Gateway_Exception $e ) {
			$this->assertStringNotContainsString( 'abc', $e->getMessage() );
		}
	}
}
