<?php

declare( strict_types=1 );

use CawlCheckout\Checkout_Session;
use CawlCheckout\Customer_Data;
use CawlCheckout\Fake_Gateway;
use CawlCheckout\Pages;
use CawlCheckout\Payment_Snapshot;
use CawlCheckout\Registration;
use CawlCheckout\Registration_Repository;
use CawlCheckout\Return_Controller;
use CawlCheckout\Return_Token;
use CawlCheckout\Return_View;
use CawlCheckout\Schema;
use PHPUnit\Framework\TestCase;

final class ReturnControllerTest extends TestCase {
	private Registration_Repository $repo;
	private Fake_Gateway $fake;
	private Return_Controller $ctrl;
	private \Closure $filter;
	private Registration $reg;
	private string $token = '';

	protected function setUp(): void {
		Schema::install();
		$this->repo = new Registration_Repository();
		$this->repo->purge();
		$this->fake   = new Fake_Gateway();
		$this->filter = fn() => $this->fake;
		add_filter( 'cawl_checkout_gateway', $this->filter );
		$this->token = Return_Token::generate();
		$reg         = $this->repo->create( wp_generate_uuid4(), Return_Token::hash( $this->token ), 2500, new Customer_Data( 'Jean', 'Dupont', 'jean@example.test', '1 rue de la Paix', '13100', 'Aix-en-Provence', 'FR' ) );
		$this->repo->open_checkout( $reg->id, new Checkout_Session( 'hc-1', 'https://payment.preprod.cawl-solutions.fr/hostedcheckout/x', gmdate( 'Y-m-d H:i:s', time() + 3600 ) ) );
		$this->reg  = $this->repo->find( $reg->id );
		$this->ctrl = new Return_Controller();
	}

	protected function tearDown(): void {
		remove_filter( 'cawl_checkout_gateway', $this->filter );
	}

	/** @return array<string,string> */
	private function query( int $n = 0 ): array {
		$q = array(
			'ref'   => $this->reg->reference,
			'token' => $this->token,
		);
		if ( $n > 0 ) {
			$q['n'] = (string) $n;
		}
		return $q;
	}

	private function snapshot( int $code ): Payment_Snapshot {
		return new Payment_Snapshot( 'p1', $code, $this->reg->reference, 2500, 'EUR', 'PAYMENT_CREATED' );
	}

	private function resolve( int $code, int $n = 0 ): Return_View {
		$this->fake->checkouts['hc-1'] = $this->snapshot( $code );
		return $this->ctrl->resolve( $this->query( $n ) );
	}

	public function test_wrong_token_and_unknown_reference_look_identical(): void {
		$wrong_token = $this->ctrl->resolve(
			array(
				'ref'   => $this->reg->reference,
				'token' => str_repeat( '0', 32 ),
			)
		);
		$unknown_ref = $this->ctrl->resolve(
			array(
				'ref'   => 'INS-inconnue',
				'token' => $this->token,
			)
		);
		$this->assertEquals( $wrong_token, $unknown_ref );
		$this->assertSame( 'invalid', $unknown_ref->kind );
		$this->assertSame( array(), $this->fake->calls );
	}

	public function test_missing_or_non_string_parameters_are_invalid(): void {
		$this->assertSame( 'invalid', $this->ctrl->resolve( array() )->kind );
		$this->assertSame(
			'invalid',
			$this->ctrl->resolve(
				array(
					'ref'   => array( 'x' ),
					'token' => $this->token,
				)
			)->kind
		);
	}

	public function test_captured_on_return_shows_paid(): void {
		$v = $this->resolve( 9 );
		$this->assertSame( 'paid', $v->kind );
		$this->assertSame( $this->reg->reference, $v->reference );
		$this->assertSame( 'paid', $this->repo->find( $this->reg->id )->state );
	}

	public function test_reference_is_only_exposed_on_paid_view(): void {
		$this->assertNull( $this->resolve( 91 )->reference );
		$this->assertNull(
			$this->ctrl->resolve(
				array(
					'ref'   => 'x',
					'token' => 'y',
				)
			)->reference
		);
	}

	public function test_processing_sets_bounded_refresh(): void {
		$v = $this->resolve( 91, 0 );
		$this->assertSame( 'processing', $v->kind );
		$this->assertStringContainsString( 'n=1', $v->refresh_url );
		$this->assertStringContainsString( 'ref=' . rawurlencode( $this->reg->reference ), $v->refresh_url );
		$this->assertSame( 'processing', $this->resolve( 91, 9 )->kind );
		$this->assertSame( 'processing_timeout', $this->resolve( 91, 10 )->kind );
		$this->assertNull( $this->resolve( 91, 10 )->refresh_url );
	}

	public function test_refused_payment_shows_failed_without_refresh(): void {
		$v = $this->resolve( 2 );
		$this->assertSame( 'failed', $v->kind );
		$this->assertNull( $v->refresh_url );
	}

	public function test_cancelled_checkout_shows_cancelled(): void {
		$this->fake->checkouts['hc-1'] = new Payment_Snapshot( null, null, null, null, null, 'CANCELLED_BY_CONSUMER' );
		$this->assertSame( 'cancelled', $this->ctrl->resolve( $this->query() )->kind );
	}

	public function test_still_open_checkout_is_pending(): void {
		$this->fake->checkouts['hc-1'] = new Payment_Snapshot( null, null, null, null, null, 'IN_PROGRESS' );
		$this->assertSame( 'pending', $this->ctrl->resolve( $this->query() )->kind );
	}

	public function test_mismatching_payment_shows_anomaly(): void {
		$this->fake->checkouts['hc-1'] = new Payment_Snapshot( 'p1', 9, $this->reg->reference, 1, 'EUR', 'PAYMENT_CREATED' );
		$this->assertSame( 'anomaly', $this->ctrl->resolve( $this->query() )->kind );
	}

	public function test_reload_of_paid_registration_does_not_call_api(): void {
		$this->repo->transition( $this->reg->id, 'checkout_open', 'paid', 'p1', 9 );
		$this->assertSame( 'paid', $this->ctrl->resolve( $this->query() )->kind );
		$this->assertSame( array(), $this->fake->calls );
	}

	public function test_refunded_registration_does_not_call_api(): void {
		$this->repo->transition( $this->reg->id, 'checkout_open', 'paid', 'p1', 9 );
		$this->repo->transition( $this->reg->id, 'paid', 'refunded', 'p1', 8 );
		$this->assertSame( 'refunded', $this->ctrl->resolve( $this->query() )->kind );
		$this->assertSame( array(), $this->fake->calls );
	}

	public function test_paid_registration_stays_paid_when_api_lags(): void {
		$this->repo->transition( $this->reg->id, 'checkout_open', 'paid', 'p1', 9 );
		$this->fake->checkouts['hc-1'] = $this->snapshot( 91 );
		$this->assertSame( 'paid', $this->ctrl->resolve( $this->query() )->kind );
		$this->assertSame( array(), $this->fake->calls );
	}

	public function test_gateway_error_falls_back_to_current_state(): void {
		$this->fake->fail = true;
		$v                = $this->ctrl->resolve( $this->query() );
		$this->assertSame( 'pending', $v->kind );
		$this->assertSame( 'checkout_open', $this->repo->find( $this->reg->id )->state );
	}

	public function test_registration_without_checkout_is_pending_without_api_call(): void {
		$reg = $this->repo->create( wp_generate_uuid4(), Return_Token::hash( $this->token ), 2500, new Customer_Data( 'A', 'B', 'a@example.test', '1 rue', '13100', 'Aix', 'FR' ) );
		$v   = $this->ctrl->resolve(
			array(
				'ref'   => $reg->reference,
				'token' => $this->token,
			)
		);
		$this->assertSame( 'pending', $v->kind );
		$this->assertSame( array(), $this->fake->calls );
	}

	public function test_status_url_encodes_values(): void {
		$url = Pages::status_url( 'a&b=c', 'd e', 2 );
		$this->assertStringContainsString( 'ref=a%26b%3Dc', $url );
		$this->assertStringContainsString( 'token=d%20e', $url );
	}

	/** @return array<string,mixed> */
	private function http_args(): array {
		// Hôte du site testé (home_url), quel que soit son port : la requête vise 127.0.0.1 depuis le conteneur.
		$home = wp_parse_url( home_url() );
		return array(
			'headers'     => array( 'Host' => $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) ),
			'redirection' => 0,
			'timeout'     => 20,
		);
	}

	/**
	 * Rendu du bloc statut pour une vue, sans requête HTTP : le processus web lit les tables de la
	 * campagne, pas les tables de test où vivent les inscriptions créées ici.
	 */
	private function render( Return_View $view ): string {
		$current = new ReflectionProperty( Return_Controller::class, 'view' );
		$current->setValue( null, $view );
		try {
			return do_blocks( '<!-- wp:cawl-checkout/statut /-->' );
		} finally {
			$current->setValue( null, null );
		}
	}

	public function test_processing_view_announces_auto_refresh_and_offers_manual_refresh(): void {
		$html = $this->render( $this->resolve( 91, 4 ) );
		$this->assertStringContainsString( 'Cette page se met à jour automatiquement pendant 30 secondes.', $html );
		// Lien vers la page sans compteur `n` : un nouveau cycle de rafraîchissement.
		$this->assertStringContainsString( '<a href="' . esc_url( Pages::status_url( $this->reg->reference, $this->token ) ) . '">Actualiser</a>', $html );
		$this->assertStringNotContainsString( 'n=5', $html );
		$this->assertDoesNotMatchRegularExpression( '/<script|\son[a-z]+=/i', $html );
	}

	public function test_processing_timeout_view_has_no_auto_refresh_notice(): void {
		$this->assertStringNotContainsString( 'se met à jour automatiquement', $this->render( $this->resolve( 91, 10 ) ) );
	}

	public function test_paid_view_renders_confirmation_and_reference(): void {
		$this->repo->transition( $this->reg->id, 'checkout_open', 'paid', 'p1', 9 );
		$html = $this->render( $this->ctrl->resolve( $this->query() ) );
		$this->assertStringContainsString( 'Paiement confirmé, inscription validée', $html );
		$this->assertStringContainsString( $this->reg->reference, $html );
	}

	public function test_rendered_status_page_is_private_nonced_and_noindex(): void {
		// Aucune ligne n'est nécessaire : le lien invalide suffit à rendre la page complète.
		$res = wp_remote_get( 'http://127.0.0.1/' . Pages::STATUS_SLUG . '/?ref=INS-20000101-000000000000&token=' . str_repeat( '0', 32 ), $this->http_args() );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $res ) );
		$html = wp_remote_retrieve_body( $res );
		$this->assertStringContainsString( 'Lien d&#039;inscription invalide.', $html );
		$this->assertStringContainsString( 'no-store', (string) wp_remote_retrieve_header( $res, 'cache-control' ) );
		$this->assertTrue(
			str_contains( strtolower( (string) wp_remote_retrieve_header( $res, 'x-robots-tag' ) ), 'noindex' )
			|| 1 === preg_match( '/<meta name=\'robots\'[^>]*noindex/i', $html )
		);
		preg_match_all( '/<script\b[^>]*>/i', $html, $scripts );
		foreach ( $scripts[0] as $tag ) {
			$this->assertStringContainsString( 'nonce=', $tag );
		}
		$this->assertDoesNotMatchRegularExpression( '/\son[a-z]+=/i', $html );
	}

	public function test_form_page_is_private_and_noindex(): void {
		$res = wp_remote_get( 'http://127.0.0.1/' . Pages::FORM_SLUG . '/', $this->http_args() );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $res ) );
		$this->assertStringContainsString( 'no-store', (string) wp_remote_retrieve_header( $res, 'cache-control' ) );
		$this->assertStringContainsString( 'noindex', strtolower( (string) wp_remote_retrieve_header( $res, 'x-robots-tag' ) ) . strtolower( wp_remote_retrieve_body( $res ) ) );
	}

	public function test_invalid_page_shows_generic_message_and_no_reference(): void {
		$html = $this->render(
			$this->ctrl->resolve(
				array(
					'ref'   => $this->reg->reference,
					'token' => str_repeat( '0', 32 ),
				)
			)
		);
		$this->assertStringContainsString( 'Lien d&#039;inscription invalide.', $html );
		$this->assertStringNotContainsString( $this->reg->reference, $html );
	}
}
