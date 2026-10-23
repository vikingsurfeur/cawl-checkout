<?php
/**
 * Passerelle CAWL fondée sur le SDK PHP officiel.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

use OnlinePayments\Sdk\Authentication\V1HmacAuthenticator;
use OnlinePayments\Sdk\CallContext;
use OnlinePayments\Sdk\Client;
use OnlinePayments\Sdk\Communicator;
use OnlinePayments\Sdk\CommunicatorConfiguration;
use OnlinePayments\Sdk\Domain\Address;
use OnlinePayments\Sdk\Domain\AmountOfMoney;
use OnlinePayments\Sdk\Domain\CardPaymentMethodSpecificInputBase;
use OnlinePayments\Sdk\Domain\ContactDetails;
use OnlinePayments\Sdk\Domain\CreateHostedCheckoutRequest;
use OnlinePayments\Sdk\Domain\Customer;
use OnlinePayments\Sdk\Domain\GetHostedCheckoutResponse;
use OnlinePayments\Sdk\Domain\HostedCheckoutSpecificInput;
use OnlinePayments\Sdk\Domain\MobilePaymentMethodHostedCheckoutSpecificInput;
use OnlinePayments\Sdk\Domain\Order;
use OnlinePayments\Sdk\Domain\OrderReferences;
use OnlinePayments\Sdk\Domain\PersonalInformation;
use OnlinePayments\Sdk\Domain\PersonalName;
use OnlinePayments\Sdk\Domain\RefundRequest;
use OnlinePayments\Sdk\Domain\ValidateCredentialsRequest;
use OnlinePayments\Sdk\Domain\ValidateCredentialsResponse;
use OnlinePayments\Sdk\Merchant\MerchantClient;
use OnlinePayments\Sdk\ResponseException;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Appels réels à l'API CAWL. Les exceptions du SDK sont converties sans exposer secret ni réponse brute.
 */
class Cawl_Gateway implements Gateway {
	/**
	 * @param Config $config          Configuration CAWL.
	 * @param int    $session_minutes Durée de vie de la session de paiement, en minutes.
	 */
	public function __construct(
		private Config $config,
		private int $session_minutes
	) {}

	/** Construit la requête de checkout (méthode pure, sans appel réseau). */
	public function build_checkout_request( Registration $r, string $return_url ): CreateHostedCheckoutRequest {
		$amount = new AmountOfMoney();
		$amount->setAmount( $r->amount_cents );
		$amount->setCurrencyCode( $r->currency );

		$references = new OrderReferences();
		$references->setMerchantReference( $r->reference );

		$contact = new ContactDetails();
		$contact->setEmailAddress( $r->customer->email );

		$address = new Address();
		$address->setStreet( $r->customer->street );
		$address->setZip( $r->customer->zip );
		$address->setCity( $r->customer->city );
		$address->setCountryCode( $r->customer->country_code );

		$name = new PersonalName();
		$name->setFirstName( $r->customer->first_name );
		$name->setSurname( $r->customer->last_name );
		$personal = new PersonalInformation();
		$personal->setName( $name );

		$customer = new Customer();
		$customer->setContactDetails( $contact );
		$customer->setBillingAddress( $address );
		$customer->setPersonalInformation( $personal );

		$order = new Order();
		$order->setAmountOfMoney( $amount );
		$order->setReferences( $references );
		$order->setCustomer( $customer );

		$hosted = new HostedCheckoutSpecificInput();
		$hosted->setReturnUrl( $return_url );
		$hosted->setLocale( 'fr_FR' );
		$hosted->setSessionTimeout( $this->session_minutes );
		$hosted->setShowResultPage( false );

		// Vente directe : capture immédiate, pour la carte comme pour les paiements mobiles.
		$card = new CardPaymentMethodSpecificInputBase();
		$card->setAuthorizationMode( 'SALE' );
		$mobile = new MobilePaymentMethodHostedCheckoutSpecificInput();
		$mobile->setAuthorizationMode( 'SALE' );

		$request = new CreateHostedCheckoutRequest();
		$request->setOrder( $order );
		$request->setHostedCheckoutSpecificInput( $hosted );
		$request->setCardPaymentMethodSpecificInput( $card );
		$request->setMobilePaymentMethodSpecificInput( $mobile );
		return $request;
	}

	public function create_checkout( Registration $r, string $return_url ): Checkout_Session {
		$request  = $this->build_checkout_request( $r, $return_url );
		$response = $this->call(
			fn() => $this->merchant()->hostedCheckout()->createHostedCheckout( $request ),
			'création du checkout'
		);
		$id  = $response->getHostedCheckoutId();
		$url = $response->getRedirectUrl();
		if ( null === $id || '' === $id || null === $url || '' === $url ) {
			throw new Gateway_Exception( 'CAWL : réponse incomplète lors de la création du checkout.' );
		}
		return new Checkout_Session( $id, $url, gmdate( 'Y-m-d H:i:s', time() + $this->session_minutes * 60 ) );
	}

	public function get_checkout( string $hosted_checkout_id ): Payment_Snapshot {
		try {
			$response = $this->fetch_checkout( $hosted_checkout_id );
		} catch ( ResponseException $e ) {
			// Seul un 404 signifie « checkout inconnu » (409/410 et autres statuts sont des erreurs).
			if ( 404 === $e->getHttpStatusCode() ) {
				return Payment_Snapshot::checkout_not_found();
			}
			throw $this->wrap( $e, 'lecture du checkout' );
		} catch ( \Throwable $e ) {
			throw $this->wrap( $e, 'lecture du checkout' );
		}
		return Payment_Snapshot::from_hosted_checkout( $response );
	}

	public function get_payment( string $payment_id ): Payment_Snapshot {
		$response = $this->call(
			fn() => $this->merchant()->payments()->getPaymentDetails( $payment_id ),
			'lecture du paiement'
		);
		return Payment_Snapshot::from_payment( $response );
	}

	public function refund( string $payment_id, int $amount_cents, string $idempotence_key ): string {
		$money = new AmountOfMoney();
		$money->setAmount( $amount_cents );
		$money->setCurrencyCode( 'EUR' );
		$request = new RefundRequest();
		$request->setAmountOfMoney( $money );

		$context = new CallContext();
		$context->setIdempotenceKey( $idempotence_key );

		$response = $this->call(
			fn() => $this->merchant()->payments()->refundPayment( $payment_id, $request, $context ),
			'remboursement'
		);
		$id = $response->getId();
		if ( null === $id || '' === $id ) {
			throw new Gateway_Exception( 'CAWL : réponse incomplète lors du remboursement.' );
		}
		return $id;
	}

	public function validate_webhook_credentials(): bool {
		$request = new ValidateCredentialsRequest();
		$request->setKey( $this->config->webhook_key_id() );
		// Contrat (validateCredentialsRequest.secret) : le secret haché comme pour vérifier une signature,
		// sur un corps vide, soit base64(HMAC-SHA256('', secret)). Le secret brut ne quitte jamais le serveur.
		$request->setSecret( base64_encode( hash_hmac( 'sha256', '', $this->config->webhook_secret(), true ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- format imposé par CAWL.
		$response = $this->call(
			fn() => $this->fetch_webhook_validation( $request ),
			'validation des identifiants de webhook'
		);
		return self::is_valid_credentials_response( $response );
	}

	/** Le contrat CAWL renvoie `Valid` ou `Invalid`. */
	public static function is_valid_credentials_response( ValidateCredentialsResponse $response ): bool {
		return 'Valid' === $response->getResult();
	}

	/** Appel SDK isolé (point de substitution des tests). */
	protected function fetch_checkout( string $hosted_checkout_id ): GetHostedCheckoutResponse {
		return $this->merchant()->hostedCheckout()->getHostedCheckout( $hosted_checkout_id );
	}

	/** Appel SDK isolé (point de substitution des tests). */
	protected function fetch_webhook_validation( ValidateCredentialsRequest $request ): ValidateCredentialsResponse {
		return $this->merchant()->webhooks()->validateWebhookCredentials( $request );
	}

	/** Client SDK limité au marchand configuré. */
	private function merchant(): MerchantClient {
		$configuration = new CommunicatorConfiguration(
			$this->config->api_key(),
			$this->config->api_secret(),
			$this->config->api_endpoint(),
			'cawl-checkout',
			null,
			10,
			20
		);
		$communicator = new Communicator( $configuration, new V1HmacAuthenticator( $configuration ) );
		return ( new Client( $communicator ) )->merchant( $this->config->pspid() );
	}

	/**
	 * Exécute un appel SDK en convertissant toute erreur.
	 *
	 * @template T
	 * @param callable():T $fn      Appel SDK.
	 * @param string       $context Action, pour le message d'erreur.
	 * @return T
	 * @throws Gateway_Exception En cas d'erreur réseau ou API.
	 */
	private function call( callable $fn, string $context ): mixed {
		try {
			return $fn();
		} catch ( \Throwable $e ) {
			throw $this->wrap( $e, $context );
		}
	}

	/** Message sans secret ni corps de réponse : classe d'erreur et code HTTP seulement. */
	private function wrap( \Throwable $e, string $context ): Gateway_Exception {
		$detail = ( new \ReflectionClass( $e ) )->getShortName();
		if ( $e instanceof ResponseException ) {
			$detail .= ' HTTP ' . $e->getHttpStatusCode();
		}
		return new Gateway_Exception( sprintf( 'CAWL : échec de la %s (%s).', $context, $detail ), 0, $e );
	}
}
