<?php
/**
 * Correspondance entre codes de statut CAWL et états d'inscription.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Traduit un `statusCode` CAWL en état cible.
 *
 * Source des codes 81, 82, 92 et 99 : page « Statuts » de la documentation CAWL
 * (integration/api-developer-guide/statuses, « Codes de statut numériques »).
 */
final class Status_Mapper {
	private const MAP = array(
		9  => Registration::PAID,
		8  => Registration::REFUNDED,
		2  => Registration::FAILED,
		1  => Registration::CANCELLED,
		0  => Registration::CHECKOUT_OPEN,
		5  => Registration::PROCESSING,
		46 => Registration::PROCESSING,
		50 => Registration::PROCESSING,
		51 => Registration::PROCESSING,
		55 => Registration::PROCESSING,
		91 => Registration::PROCESSING,
		// 92 « Paiement incertain » et 99 « En cours de traitement » : statuts temporaires, suivis de 9 ou 93.
		92 => Registration::PROCESSING,
		99 => Registration::PROCESSING,
		7  => Registration::ANOMALY,
		84 => Registration::ANOMALY,
	);

	/**
	 * Codes connus sans transition : 81 « Remboursement en attente » et 82 « Remboursement incertain »,
	 * statuts temporaires suivis de 8 (remboursé) ou 83 (refusé, le paiement reste à 9).
	 */
	private const NO_TRANSITION = array( 81, 82 );

	/**
	 * État visé pour un code, ou null si le code est inconnu ou ne change rien.
	 *
	 * @param int $status_code Code de statut CAWL.
	 */
	public static function target_state( int $status_code ): ?string {
		return self::MAP[ $status_code ] ?? null;
	}

	/**
	 * Le code est documenté : il mène à un état ou, volontairement, à aucun.
	 *
	 * @param int $status_code Code de statut CAWL.
	 */
	public static function is_known( int $status_code ): bool {
		return isset( self::MAP[ $status_code ] ) || in_array( $status_code, self::NO_TRANSITION, true );
	}
}
