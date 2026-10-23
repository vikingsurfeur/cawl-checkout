<?php

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class InscriptionBlockTest extends TestCase {
	public function test_form_markup_has_no_inline_script_or_handlers(): void {
		$html = do_blocks( '<!-- wp:cawl-checkout/inscription /-->' );
		$this->assertStringContainsString( 'name="_cawl_checkout_nonce"', $html );
		$this->assertStringContainsString( 'name="submission_id"', $html );
		$this->assertDoesNotMatchRegularExpression( '/<script|\son[a-z]+=/i', $html );
	}

	public function test_form_fields_are_labelled_required_and_autocompleted(): void {
		$html   = do_blocks( '<!-- wp:cawl-checkout/inscription /-->' );
		$fields = array(
			'first_name' => 'given-name',
			'last_name'  => 'family-name',
			'email'      => 'email',
			'street'     => 'street-address',
			'zip'        => 'postal-code',
			'city'       => 'address-level2',
		);
		foreach ( $fields as $name => $autocomplete ) {
			$this->assertMatchesRegularExpression( '/<label[^>]+for="cawl-checkout-' . $name . '"/', $html );
			$this->assertMatchesRegularExpression( '/<input[^>]+id="cawl-checkout-' . $name . '"[^>]+required/', $html );
			$this->assertStringContainsString( 'autocomplete="' . $autocomplete . '"', $html );
		}
		$this->assertStringContainsString( '25,00', $html );
		$this->assertStringContainsString( 'admin-post.php', $html );
	}

	public function test_error_message_is_announced(): void {
		$_GET['erreur'] = 'champs';
		try {
			$html = do_blocks( '<!-- wp:cawl-checkout/inscription /-->' );
		} finally {
			unset( $_GET['erreur'] );
		}
		$this->assertMatchesRegularExpression( '/role="alert"[^>]*>[^<]*Certains champs sont manquants ou invalides\./', $html );
	}

	public function test_in_progress_message_is_announced(): void {
		$_GET['erreur'] = 'en-cours';
		try {
			$html = do_blocks( '<!-- wp:cawl-checkout/inscription /-->' );
		} finally {
			unset( $_GET['erreur'] );
		}
		$this->assertMatchesRegularExpression( '/role="alert"[^>]*>Votre inscription est en cours de création\. Réessayez dans quelques secondes\.</', $html );
	}

	public function test_unknown_error_code_shows_no_message(): void {
		$_GET['erreur'] = '<b>x</b>';
		try {
			$html = do_blocks( '<!-- wp:cawl-checkout/inscription /-->' );
		} finally {
			unset( $_GET['erreur'] );
		}
		$this->assertStringNotContainsString( 'role="alert"', $html );
		$this->assertStringNotContainsString( '<b>x</b>', $html );
	}
}
