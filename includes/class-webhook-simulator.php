<?php
/**
 * Simulateur de notifications CAWL signées.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Fabrique et signe des webhooks au format CAWL v1, puis les envoie à la route locale.
 * payload() et sign() n'appellent aucune fonction WordPress (testables sans WordPress).
 */
final class Webhook_Simulator {
	/**
	 * @param Config $config Configuration CAWL (PSPID, identifiant et secret de webhook).
	 */
	public function __construct( private Config $config ) {}

	/**
	 * Corps JSON d'une notification.
	 *
	 * @param Registration $r            Inscription concernée.
	 * @param string       $type         Type d'événement (payment.captured…).
	 * @param int          $status_code  Code de statut CAWL.
	 * @param int|null     $amount_cents Montant ; celui de l'inscription par défaut.
	 * @param string|null  $event_id     Identifiant d'événement ; UUID v4 par défaut.
	 * @param string|null  $payment_id   Identifiant de paiement ; dérivé de la référence par défaut.
	 */
	public function payload(
		Registration $r,
		string $type,
		int $status_code,
		?int $amount_cents = null,
		?string $event_id = null,
		?string $payment_id = null
	): string {
		$data = array(
			'apiVersion' => 'v1',
			'id'         => $event_id ?? self::uuid_v4(),
			'created'    => gmdate( 'Y-m-d\TH:i:s' ) . '.000Z',
			'merchantId' => $this->config->pspid(),
			'type'       => $type,
			'payment'    => array(
				'id'            => $payment_id ?? ( 'sim-' . substr( hash( 'sha256', $r->reference ), 0, 16 ) . '_0' ),
				'paymentOutput' => array(
					'amountOfMoney' => array(
						'amount'       => $amount_cents ?? $r->amount_cents,
						'currencyCode' => $r->currency,
					),
					'references'    => array( 'merchantReference' => $r->reference ),
				),
				'status'        => self::status_label( $status_code ),
				'statusOutput'  => array(
					'statusCode'     => $status_code,
					'statusCategory' => self::status_category( $status_code ),
				),
			),
		);
		return (string) json_encode( $data, JSON_UNESCAPED_SLASHES );
	}

	/** Signature : base64(HMAC-SHA256(corps, secret de webhook)). */
	public function sign( string $body ): string {
		return base64_encode( hash_hmac( 'sha256', $body, $this->config->webhook_secret(), true ) );
	}

	/**
	 * Envoie la notification à la route, en interne.
	 *
	 * @param string $body          Corps brut.
	 * @param bool   $bad_signature Signature invalide (corps signé altéré).
	 */
	public function dispatch( string $body, bool $bad_signature = false ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/' . Webhook_Controller::NAMESPACE . Webhook_Controller::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-GCS-Signature', $this->sign( $bad_signature ? $body . ' ' : $body ) );
		$request->set_header( 'X-GCS-KeyId', $this->config->webhook_key_id() );
		$request->set_body( $body );
		return rest_do_request( $request );
	}

	/** UUID v4 sans WordPress. */
	private static function uuid_v4(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}

	/** Libellé `status` ; code 8 : REFUNDED (page Statuts CAWL). */
	private static function status_label( int $code ): string {
		return match ( $code ) {
			0 => 'CREATED',
			2 => 'REJECTED',
			5 => 'AUTHORIZED',
			6 => 'CANCELLED',
			7 => 'REJECTED_CAPTURE',
			8 => 'REFUNDED',
			9 => 'CAPTURED',
			default => 'UNKNOWN',
		};
	}

	/** Catégorie `statusCategory` ; code 8 : REVERSED (page Statuts CAWL). */
	private static function status_category( int $code ): string {
		return match ( $code ) {
			0 => 'CREATED',
			2, 7 => 'UNSUCCESSFUL',
			6 => 'UNSUCCESSFUL',
			8 => 'REVERSED',
			5 => 'PENDING_MERCHANT',
			9 => 'COMPLETED',
			default => 'UNKNOWN',
		};
	}
}
