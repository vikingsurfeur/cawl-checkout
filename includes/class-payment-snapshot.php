<?php
/**
 * Instantané d'un paiement ou d'un checkout chez CAWL.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

use OnlinePayments\Sdk\Domain\GetHostedCheckoutResponse;
use OnlinePayments\Sdk\Domain\PaymentDetailsResponse;
use OnlinePayments\Sdk\Domain\PaymentResponse;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Valeur immuable extraite d'une réponse du SDK.
 */
final class Payment_Snapshot {
	/**
	 * @param string|null $payment_id         Identifiant du paiement.
	 * @param int|null    $status_code        Code de statut CAWL.
	 * @param string|null $merchant_reference Référence marchande.
	 * @param int|null    $amount_cents       Montant en centimes.
	 * @param string|null $currency           Devise ISO 4217.
	 * @param string|null $checkout_status    Statut du Hosted Checkout (ou NOT_FOUND).
	 */
	public function __construct(
		public readonly ?string $payment_id,
		public readonly ?int $status_code,
		public readonly ?string $merchant_reference,
		public readonly ?int $amount_cents,
		public readonly ?string $currency,
		public readonly ?string $checkout_status = null
	) {}

	/** Instantané d'un paiement. */
	public static function from_payment( PaymentResponse|PaymentDetailsResponse $p, ?string $checkout_status = null ): self {
		$output = $p->getPaymentOutput();
		$amount = $output?->getAmountOfMoney();
		return new self(
			$p->getId(),
			$p->getStatusOutput()?->getStatusCode(),
			$output?->getReferences()?->getMerchantReference(),
			$amount?->getAmount(),
			$amount?->getCurrencyCode(),
			$checkout_status
		);
	}

	/** Instantané d'un Hosted Checkout, avec son paiement s'il en existe un. */
	public static function from_hosted_checkout( GetHostedCheckoutResponse $r ): self {
		$payment = $r->getCreatedPaymentOutput()?->getPayment();
		if ( null !== $payment ) {
			return self::from_payment( $payment, $r->getStatus() );
		}
		return new self( null, null, null, null, null, $r->getStatus() );
	}

	/** Hosted Checkout inconnu de CAWL (404). */
	public static function checkout_not_found(): self {
		return new self( null, null, null, null, null, 'NOT_FOUND' );
	}
}
