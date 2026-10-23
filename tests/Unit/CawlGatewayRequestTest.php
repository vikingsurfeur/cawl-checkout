<?php
/**
 * Tests de la requête de checkout CAWL.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

use CawlCheckout\Cawl_Gateway;
use CawlCheckout\Config;
use CawlCheckout\Customer_Data;
use CawlCheckout\Registration;
use PHPUnit\Framework\TestCase;

final class CawlGatewayRequestTest extends TestCase {
	private function gateway( int $minutes = 180 ): Cawl_Gateway {
		$vars   = array(
			'CAWL_ENV'            => 'preprod',
			'CAWL_PSPID'          => 'pspid',
			'CAWL_API_KEY'        => 'key',
			'CAWL_API_SECRET'     => 'secret',
			'CAWL_WEBHOOK_KEY_ID' => 'kid',
			'CAWL_WEBHOOK_SECRET' => 'hook',
		);
		$config = Config::from_env( fn( $name ) => $vars[ $name ] ?? false );
		return new Cawl_Gateway( $config, $minutes );
	}

	private function registration(): Registration {
		return new Registration(
			1,
			'INS-20261021-0123456789ab',
			'sub-1',
			str_repeat( 'a', 64 ),
			Registration::CREATED,
			2500,
			'EUR',
			new Customer_Data( 'Ève', "O'Connor-Dupont", 'eve@example.test', '1 rue des Lilas', '13100', 'Aix-en-Provence', 'FR' )
		);
	}

	public function test_checkout_request_pins_amount_currency_and_reference(): void {
		$req = $this->gateway()->build_checkout_request( $this->registration(), 'https://exemple.test/inscription-statut/?ref=x&token=y' );
		$this->assertSame( 2500, $req->getOrder()->getAmountOfMoney()->getAmount() );
		$this->assertSame( 'EUR', $req->getOrder()->getAmountOfMoney()->getCurrencyCode() );
		$this->assertSame( 'INS-20261021-0123456789ab', $req->getOrder()->getReferences()->getMerchantReference() );
	}

	public function test_checkout_request_forces_sale_for_card_and_mobile(): void {
		$req = $this->gateway()->build_checkout_request( $this->registration(), 'https://exemple.test/x' );
		$this->assertSame( 'SALE', $req->getCardPaymentMethodSpecificInput()->getAuthorizationMode() );
		$this->assertSame( 'SALE', $req->getMobilePaymentMethodSpecificInput()->getAuthorizationMode() );
	}

	public function test_checkout_request_return_settings(): void {
		$h = $this->gateway( 180 )->build_checkout_request( $this->registration(), 'https://exemple.test/x' )->getHostedCheckoutSpecificInput();
		$this->assertSame( 'https://exemple.test/x', $h->getReturnUrl() );
		$this->assertSame( 'fr_FR', $h->getLocale() );
		$this->assertSame( 180, $h->getSessionTimeout() );
		$this->assertFalse( $h->getShowResultPage() );
	}

	public function test_checkout_request_carries_3ds_customer_data(): void {
		$c = $this->gateway()->build_checkout_request( $this->registration(), 'https://exemple.test/x' )->getOrder()->getCustomer();
		$this->assertSame( 'eve@example.test', $c->getContactDetails()->getEmailAddress() );
		$this->assertSame( 'FR', $c->getBillingAddress()->getCountryCode() );
		$this->assertSame( "O'Connor-Dupont", $c->getPersonalInformation()->getName()->getSurname() );
	}
}
