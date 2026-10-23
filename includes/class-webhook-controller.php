<?php
/**
 * Route REST des notifications CAWL.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

use OnlinePayments\Sdk\Webhooks\ApiVersionMismatchException;
use OnlinePayments\Sdk\Webhooks\InMemorySecretKeyStore;
use OnlinePayments\Sdk\Webhooks\SignatureValidator;
use OnlinePayments\Sdk\Webhooks\WebhooksHelper;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * POST /wp-json/cawl-checkout/v1/webhook : signature vérifiée sur le corps brut avant tout traitement.
 */
final class Webhook_Controller {
	public const NAMESPACE = 'cawl-checkout/v1';
	public const ROUTE     = '/webhook';

	/** Enregistre la route (appelé sur rest_api_init). */
	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => array( self::class, 'check_signature' ),
			)
		);
	}

	/**
	 * En-têtes au format attendu par le SDK (WordPress les normalise en x_gcs_…).
	 *
	 * @param \WP_REST_Request $r Requête.
	 * @return array<string,string>
	 */
	public static function sdk_headers( \WP_REST_Request $r ): array {
		return array(
			'X-GCS-Signature' => (string) $r->get_header( 'x-gcs-signature' ),
			'X-GCS-KeyId'     => (string) $r->get_header( 'x-gcs-keyid' ),
		);
	}

	/**
	 * Vérifie la signature du corps brut. Aucun détail sur la cause d'un échec.
	 *
	 * @param \WP_REST_Request $r Requête.
	 * @return true|\WP_Error
	 */
	public static function check_signature( \WP_REST_Request $r ) {
		$config = Plugin::config();
		try {
			$validator = new SignatureValidator(
				new InMemorySecretKeyStore( array( $config->webhook_key_id() => $config->webhook_secret() ) )
			);
			$validator->validate( $r->get_body(), self::sdk_headers( $r ) );
			return true;
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'cawl_checkout_signature', 'Signature invalide.', array( 'status' => 401 ) );
		}
	}

	/**
	 * Traite une notification déjà authentifiée.
	 *
	 * @param \WP_REST_Request $r Requête.
	 */
	public static function handle( \WP_REST_Request $r ): \WP_REST_Response {
		$config = Plugin::config();
		try {
			$helper = new WebhooksHelper( new InMemorySecretKeyStore( array( $config->webhook_key_id() => $config->webhook_secret() ) ) );
			$event  = $helper->unmarshal( $r->get_body(), self::sdk_headers( $r ) );
		} catch ( ApiVersionMismatchException $e ) {
			Log::write( 'warning', 'webhook : version d\'API non prise en charge', array( 'source' => 'webhook' ) );
			return new \WP_REST_Response( array( 'received' => false ), 400 );
		} catch ( \Throwable $e ) {
			Log::write( 'warning', 'webhook : corps illisible', array( 'source' => 'webhook' ) );
			return new \WP_REST_Response( array( 'received' => false ), 400 );
		}

		$event_id = is_string( $event->id ) ? $event->id : '';
		$type     = is_string( $event->type ) ? $event->type : '';
		if ( '' === $event_id ) {
			Log::write( 'warning', 'webhook : identifiant d\'événement absent', array( 'source' => 'webhook' ) );
			return new \WP_REST_Response( array( 'received' => false ), 400 );
		}

		try {
			return self::process( $event_id, $type, $event->payment );
		} catch ( \Throwable $e ) {
			// Erreur interne (base de données…) : 500 pour que CAWL réessaie. Aucun détail dans la réponse ni le journal.
			Log::write( 'error', 'webhook : erreur de traitement', array( 'event_id' => $event_id, 'source' => 'webhook' ) );
			return new \WP_REST_Response( array( 'received' => false ), 500 );
		}
	}

	/**
	 * @param string                                            $event_id Identifiant d'événement.
	 * @param string                                            $type     Type d'événement.
	 * @param \OnlinePayments\Sdk\Domain\PaymentResponse|null   $payment  Paiement joint à l'événement.
	 */
	private static function process( string $event_id, string $type, $payment ): \WP_REST_Response {
		$events = Plugin::events();
		$ok     = new \WP_REST_Response( array( 'received' => true ), 200 );

		if ( null === $payment ) {
			if ( $events->record( $event_id, $type, null, null, null ) ) {
				$events->mark_processed( $event_id, 'ignored' );
			}
			return $ok;
		}

		$snapshot = Payment_Snapshot::from_payment( $payment );
		if ( ! $events->record( $event_id, $type, $snapshot->merchant_reference, $snapshot->payment_id, $snapshot->status_code ) ) {
			// Doublon : ignoré seulement s'il a été traité jusqu'au bout ; sinon (arrêt après record) on le retraite.
			if ( $events->is_processed( $event_id ) ) {
				Log::write( 'info', 'webhook : doublon ignoré', array( 'event_id' => $event_id, 'type' => $type, 'source' => 'webhook' ) );
				return $ok;
			}
			Log::write( 'info', 'webhook : doublon non traité, reprise', array( 'event_id' => $event_id, 'type' => $type, 'source' => 'webhook' ) );
		}

		$registration = null === $snapshot->merchant_reference ? null : Plugin::registrations()->find_by_reference( $snapshot->merchant_reference );
		if ( null === $registration ) {
			Log::write( 'warning', 'webhook : référence inconnue', array( 'event_id' => $event_id, 'type' => $type, 'source' => 'webhook' ) );
			$events->mark_processed( $event_id, 'unknown_reference' );
			return $ok;
		}

		$final = Plugin::applier()->apply( $registration, $snapshot, 'webhook' );
		$events->mark_processed( $event_id, Registration::ANOMALY === $final ? 'anomaly' : 'applied' );
		return $ok;
	}
}
