<?php
/**
 * Dépôt des événements webhook reçus.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Journal idempotent des événements webhook (clé unique sur l'identifiant d'événement).
 */
final class Webhook_Event_Repository {
	/**
	 * Nom de la table.
	 */
	private function table(): string {
		return Schema::table( 'webhook_events' );
	}

	/**
	 * Enregistre un événement.
	 *
	 * @param string      $event_id    Identifiant d'événement.
	 * @param string      $type        Type d'événement.
	 * @param string|null $reference   Référence marchande.
	 * @param string|null $payment_id  Identifiant de paiement.
	 * @param int|null    $status_code Code de statut.
	 * @return bool True si l'événement est nouveau.
	 */
	public function record( string $event_id, string $type, ?string $reference, ?string $payment_id, ?int $status_code ): bool {
		global $wpdb;
		$table = $this->table();

		// INSERT IGNORE : un doublon d'event_id n'insère rien. Les NULL sont écrits en clair (prepare ne les porte pas).
		$sql = $wpdb->prepare(
			"INSERT IGNORE INTO {$table} (event_id, type, reference, payment_id, status_code, received_at) VALUES (%s, %s, "
			. ( null === $reference ? 'NULL' : '%s' ) . ', '
			. ( null === $payment_id ? 'NULL' : '%s' ) . ', '
			. ( null === $status_code ? 'NULL' : '%d' ) . ', %s)',
			...array_values(
				array_filter(
					array( $event_id, $type, $reference, $payment_id, $status_code, gmdate( 'Y-m-d H:i:s' ) ),
					static fn( $v ) => null !== $v
				)
			)
		);

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- requête préparée ci-dessus.
		if ( false === $result ) {
			throw new \RuntimeException( 'Erreur de base de données.' );
		}
		return 1 === (int) $result;
	}

	/**
	 * Marque un événement comme traité.
	 *
	 * @param string $event_id Identifiant d'événement.
	 * @param string $outcome  applied|ignored|unknown_reference|anomaly.
	 */
	public function mark_processed( string $event_id, string $outcome ): void {
		global $wpdb;
		$table = $this->table();
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET outcome = %s, processed_at = %s WHERE event_id = %s",
				$outcome,
				gmdate( 'Y-m-d H:i:s' ),
				$event_id
			)
		);
		if ( false === $result ) {
			throw new \RuntimeException( 'Erreur de base de données.' );
		}
	}

	/**
	 * L'événement existe et son traitement est terminé (processed_at renseigné).
	 *
	 * @param string $event_id Identifiant d'événement.
	 */
	public function is_processed( string $event_id ): bool {
		global $wpdb;
		$table = $this->table();
		$found = $wpdb->get_var(
			$wpdb->prepare( "SELECT event_id FROM {$table} WHERE event_id = %s AND processed_at IS NOT NULL", $event_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table interne.
		);
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Erreur de base de données.' );
		}
		return null !== $found;
	}

	/**
	 * Nombre d'événements enregistrés.
	 */
	public function count(): int {
		global $wpdb;
		$table = $this->table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table interne, sans entrée externe.
	}

	/**
	 * Vide la table.
	 *
	 * @return int Nombre de lignes supprimées.
	 */
	public function purge(): int {
		global $wpdb;
		$table   = $this->table();
		$deleted = $this->count();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table interne.
		return $deleted;
	}
}
