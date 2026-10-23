<?php

declare( strict_types=1 );

use CawlCheckout\Checkout_Controller;
use CawlCheckout\Checkout_Session;
use CawlCheckout\Customer_Data;
use CawlCheckout\Fake_Gateway;
use CawlCheckout\Pages;
use CawlCheckout\Registration;
use CawlCheckout\Registration_Repository;
use CawlCheckout\Return_Token;
use CawlCheckout\Schema;
use PHPUnit\Framework\TestCase;

final class CheckoutControllerTest extends TestCase {
	private Registration_Repository $repo;
	private Fake_Gateway $fake;
	private Checkout_Controller $controller;
	private \Closure $filter;
	private string $submission_id = '';

	protected function setUp(): void {
		Schema::install();
		$this->repo = new Registration_Repository();
		$this->repo->purge();
		$this->submission_id      = wp_generate_uuid4();
		$this->fake               = new Fake_Gateway();
		$this->fake->next_session = new Checkout_Session( 'hc-x', 'https://payment.preprod.cawl-solutions.fr/hostedcheckout/x', gmdate( 'Y-m-d H:i:s', time() + 3600 ) );
		$this->filter             = fn() => $this->fake;
		add_filter( 'cawl_checkout_gateway', $this->filter );
		$this->controller = new Checkout_Controller();
	}

	protected function tearDown(): void {
		remove_filter( 'cawl_checkout_gateway', $this->filter );
	}

	/** @return array<string,string> */
	private function post( string $first_name = 'Jean', string $last_name = 'Dupont', ?string $submission_id = null, ?string $nonce = null ): array {
		return array(
			'action'          => Checkout_Controller::ACTION,
			'_cawl_checkout_nonce' => $nonce ?? wp_create_nonce( Checkout_Controller::ACTION ),
			'submission_id'   => $submission_id ?? $this->submission_id,
			'first_name'      => $first_name,
			'last_name'       => $last_name,
			'email'           => 'jean@example.test',
			'street'          => '1 rue de la Paix',
			'zip'             => '13100',
			'city'            => 'Aix-en-Provence',
		);
	}

	public function test_valid_submission_redirects_303_to_cawl_and_opens_checkout(): void {
		$r = $this->controller->handle( $this->post() );
		$this->assertSame( array( 'https://payment.preprod.cawl-solutions.fr/hostedcheckout/x', 303 ), array( $r->url, $r->status ) );
		$reg = $this->repo->find_by_submission_id( $this->submission_id );
		$this->assertSame( 'checkout_open', $reg->state );
		$this->assertSame( 2500, $reg->amount_cents );
		$this->assertSame( 'FR', $reg->customer->country_code );
	}

	public function test_price_comes_from_server_not_from_post(): void {
		$post           = $this->post();
		$post['amount'] = '1';
		$this->controller->handle( $post );
		$this->assertSame( 2500, $this->repo->find_by_submission_id( $this->submission_id )->amount_cents );
	}

	public function test_bad_nonce_redirects_to_form_with_session_error(): void {
		$r = $this->controller->handle( $this->post( nonce: 'bad' ) );
		$this->assertStringEndsWith( '?erreur=session', $r->url );
		$this->assertSame( 303, $r->status );
		$this->assertNull( $this->repo->find_by_submission_id( $this->submission_id ) );
	}

	public function test_invalid_submission_id_is_a_field_error(): void {
		$r = $this->controller->handle( $this->post( submission_id: 'pas-un-uuid' ) );
		$this->assertStringEndsWith( '?erreur=champs', $r->url );
	}

	public function test_double_submission_reuses_open_checkout(): void {
		$first  = $this->controller->handle( $this->post() );
		$second = $this->controller->handle( $this->post() );
		$this->assertSame( $first->url, $second->url );
		$this->assertSame( 303, $second->status );
		$this->assertSame( array( 'create_checkout' ), $this->fake->calls );
	}

	/** Inscription `created` de la soumission courante : un premier envoi est en train d'ouvrir sa session. */
	private function first_submission_in_progress(): Registration {
		return $this->repo->create( $this->submission_id, Return_Token::hash( Return_Token::generate() ), 2500, new Customer_Data( 'Jean', 'Dupont', 'jean@example.test', '1 rue de la Paix', '13100', 'Aix-en-Provence', 'FR' ) );
	}

	public function test_double_click_waits_for_first_session_and_reuses_it(): void {
		$first      = $this->first_submission_in_progress();
		$waits      = 0;
		$controller = new Checkout_Controller(
			function () use ( $first, &$waits ): void {
				if ( 2 === ++$waits ) {
					// Le premier envoi ouvre sa session pendant l'attente du second.
					$this->repo->open_checkout( $first->id, new Checkout_Session( 'hc-first', 'https://payment.preprod.cawl-solutions.fr/hostedcheckout/first', gmdate( 'Y-m-d H:i:s', time() + 3600 ) ) );
				}
			}
		);
		$r = $controller->handle( $this->post() );
		$this->assertSame( array( 'https://payment.preprod.cawl-solutions.fr/hostedcheckout/first', 303 ), array( $r->url, $r->status ) );
		$this->assertSame( 2, $waits );
		$this->assertSame( array(), $this->fake->calls );
	}

	public function test_double_click_on_stalled_creation_says_in_progress(): void {
		$this->first_submission_in_progress();
		$slept      = array();
		$controller = new Checkout_Controller(
			function ( int $microseconds ) use ( &$slept ): void {
				$slept[] = $microseconds;
			}
		);
		$r = $controller->handle( $this->post() );
		$this->assertStringEndsWith( '?erreur=en-cours', $r->url );
		$this->assertSame( 303, $r->status );
		$this->assertSame( array_fill( 0, 10, 300000 ), $slept );
		$this->assertSame( array(), $this->fake->calls );
	}

	public function test_stale_created_registration_is_not_awaited(): void {
		global $wpdb;
		$first = $this->first_submission_in_progress();
		$wpdb->update( Schema::table( 'registrations' ), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $first->id ) );
		$waits      = 0;
		$controller = new Checkout_Controller(
			function () use ( &$waits ): void {
				++$waits;
			}
		);
		$this->assertStringEndsWith( '?erreur=deja-soumis', $controller->handle( $this->post() )->url );
		$this->assertSame( 0, $waits );
		$this->assertSame( array(), $this->fake->calls );
	}

	public function test_resubmission_after_expiry_creates_no_new_checkout(): void {
		$this->controller->handle( $this->post() );
		$reg = $this->repo->find_by_submission_id( $this->submission_id );
		$this->repo->transition( $reg->id, 'checkout_open', 'expired', null, null );
		$r = $this->controller->handle( $this->post() );
		$this->assertStringEndsWith( '?erreur=deja-soumis', $r->url );
		$this->assertSame( array( 'create_checkout' ), $this->fake->calls );
	}

	public function test_accented_names_are_kept_verbatim(): void {
		$this->controller->handle( $this->post( first_name: 'Ève', last_name: "O'Connor-Dupont" ) );
		$c = $this->repo->find_by_submission_id( $this->submission_id )->customer;
		$this->assertSame( array( 'Ève', "O'Connor-Dupont" ), array( $c->first_name, $c->last_name ) );
	}

	public function test_slashed_request_keeps_apostrophe(): void {
		// Reproduit wp_magic_quotes() d'une vraie requête admin-post.php.
		$post = $this->post( last_name: "O'Connor-Dupont" );
		$this->controller->handle( Checkout_Controller::request_data( wp_slash( $post ) ) );
		$this->assertSame( "O'Connor-Dupont", $this->repo->find_by_submission_id( $this->submission_id )->customer->last_name );
	}

	public function test_name_longer_than_50_chars_is_rejected(): void {
		$r = $this->controller->handle( $this->post( last_name: str_repeat( 'a', 51 ) ) );
		$this->assertStringEndsWith( '?erreur=champs', $r->url );
		$this->assertNull( $this->repo->find_by_submission_id( $this->submission_id ) );
	}

	public function test_invalid_zip_and_email_are_rejected(): void {
		$bad_zip        = $this->post();
		$bad_zip['zip'] = '1310';
		$this->assertStringEndsWith( '?erreur=champs', $this->controller->handle( $bad_zip )->url );
		$bad_mail          = $this->post();
		$bad_mail['email'] = 'pas-un-mail';
		$this->assertStringEndsWith( '?erreur=champs', $this->controller->handle( $bad_mail )->url );
	}

	public function test_error_redirect_never_echoes_submitted_values(): void {
		$r = $this->controller->handle( $this->post( last_name: str_repeat( 'Zorglub', 10 ) ) );
		$this->assertStringNotContainsString( 'Zorglub', $r->url );
		$this->assertStringNotContainsString( 'jean', $r->url );
	}

	public function test_gateway_failure_marks_failed(): void {
		$this->fake->fail = true;
		$r                = $this->controller->handle( $this->post() );
		$this->assertStringEndsWith( '?erreur=paiement-indisponible', $r->url );
		$this->assertSame( 'failed', $this->repo->find_by_submission_id( $this->submission_id )->state );
	}

	public function test_foreign_redirect_host_is_refused(): void {
		$this->fake->next_session = new Checkout_Session( 'hc-x', 'https://evil.test/', gmdate( 'Y-m-d H:i:s', time() + 3600 ) );
		$r                        = $this->controller->handle( $this->post() );
		$this->assertStringEndsWith( '?erreur=paiement-indisponible', $r->url );
		$this->assertSame( 'failed', $this->repo->find_by_submission_id( $this->submission_id )->state );
	}

	public function test_return_url_fits_200_chars(): void {
		$url = Pages::status_url( 'INS-20261021-ABCDEFGHJKLMN', str_repeat( 'a', 32 ), 99 );
		$this->assertLessThanOrEqual( 200, strlen( $url ) );
	}

	public function test_status_url_carries_ref_token_and_refresh_counter(): void {
		$this->assertStringContainsString( 'ref=R1&token=T1', Pages::status_url( 'R1', 'T1' ) );
		$this->assertStringNotContainsString( '&n=', Pages::status_url( 'R1', 'T1' ) );
		$this->assertStringContainsString( '&n=3', Pages::status_url( 'R1', 'T1', 3 ) );
	}
}
