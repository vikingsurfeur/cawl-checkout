<?php
/**
 * Dépôt des inscriptions.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Persistance des inscriptions ; dates stockées en UTC.
 */
final class Registration_Repository implements Registration_Store {
	/**
	 * Nom de la table.
	 */
	private function table(): string {
		return Schema::table( 'registrations' );
	}

	/**
	 * Crée une inscription à l'état `created`.
	 *
	 * @param string        $submission_id Identifiant de soumission (unique).
	 * @param string        $token_hash    Empreinte du jeton de retour.
	 * @param int           $amount_cents  Montant en centimes.
	 * @param Customer_Data $c             Coordonnées.
	 * @throws \RuntimeException Si l'identifiant de soumission existe déjà ou si l'insertion échoue.
	 */
	public function create( string $submission_id, string $token_hash, int $amount_cents, Customer_Data $c ): Registration {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );

		// La clé UNIQUE fait foi : le doublon est détecté sur l'échec de l'insertion, pas sur un SELECT préalable.
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert(
			$this->table(),
			array(
				'reference'     => Reference::generate(),
				'submission_id' => $submission_id,
				'token_hash'    => $token_hash,
				'state'         => Registration::CREATED,
				'amount_cents'  => $amount_cents,
				'currency'      => 'EUR',
				'first_name'    => $c->first_name,
				'last_name'     => $c->last_name,
				'email'         => $c->email,
				'street'        => $c->street,
				'zip'           => $c->zip,
				'city'          => $c->city,
				'country_code'  => $c->country_code,
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$error    = $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );

		if ( false === $ok ) {
			if ( false !== stripos( $error, 'duplicate' ) && null !== $this->find_by_submission_id( $submission_id ) ) {
				throw new \RuntimeException( 'Inscription déjà enregistrée pour cette soumission.' );
			}
			throw new \RuntimeException( 'Échec de l\'enregistrement de l\'inscription.' );
		}

		$created = $this->find( (int) $wpdb->insert_id );
		if ( null === $created ) {
			throw new \RuntimeException( 'Inscription introuvable après insertion.' );
		}
		return $created;
	}

	/**
	 * @param int $id Identifiant.
	 */
	public function find( int $id ): ?Registration {
		global $wpdb;
		$table = $this->table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		$this->assert_no_error();
		return $this->hydrate( $row );
	}

	/**
	 * @param string $ref Référence marchande.
	 */
	public function find_by_reference( string $ref ): ?Registration {
		global $wpdb;
		$table = $this->table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE reference = %s", $ref ), ARRAY_A );
		$this->assert_no_error();
		return $this->hydrate( $row );
	}

	/**
	 * @param string $id Identifiant de soumission.
	 */
	public function find_by_submission_id( string $id ): ?Registration {
		global $wpdb;
		$table = $this->table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE submission_id = %s", $id ), ARRAY_A );
		$this->assert_no_error();
		return $this->hydrate( $row );
	}

	/**
	 * Transition conditionnelle à l'état courant.
	 *
	 * @param int         $id          Identifiant.
	 * @param string      $from        État attendu.
	 * @param string      $to          Nouvel état.
	 * @param string|null $payment_id  Identifiant de paiement (null : inchangé).
	 * @param int|null    $status_code Code de statut (null : inchangé).
	 */
	public function transition( int $id, string $from, string $to, ?string $payment_id, ?int $status_code ): bool {
		global $wpdb;
		$table = $this->table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		$set  = array( 'state = %s', 'updated_at = %s' );
		$args = array( $to, $now );
		if ( null !== $payment_id ) {
			$set[]  = 'payment_id = %s';
			$args[] = $payment_id;
		}
		if ( null !== $status_code ) {
			$set[]  = 'last_status_code = %d';
			$args[] = $status_code;
		}
		if ( Registration::PAID === $to ) {
			$set[]  = 'paid_at = %s';
			$args[] = $now;
		}
		$args[] = $id;
		$args[] = $from;

		$sql = $wpdb->prepare(
			"UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE id = %d AND state = %s',
			...$args
		);
		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- requête préparée ci-dessus.
		if ( false === $result ) {
			throw new \RuntimeException( 'Erreur de base de données.' );
		}
		return 1 === (int) $result;
	}

	/**
	 * Enregistre la session de paiement : `created` vers `checkout_open`.
	 *
	 * @param int              $id Identifiant.
	 * @param Checkout_Session $s  Session ouverte.
	 * @return bool True si la transition a eu lieu.
	 */
	public function open_checkout( int $id, Checkout_Session $s ): bool {
		global $wpdb;
		$table = $this->table();
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET state = %s, hosted_checkout_id = %s, redirect_url = %s, checkout_expires_at = %s, updated_at = %s WHERE id = %d AND state = %s",
				Registration::CHECKOUT_OPEN,
				$s->hosted_checkout_id,
				$s->redirect_url,
				$s->expires_at,
				gmdate( 'Y-m-d H:i:s' ),
				$id,
				Registration::CREATED
			)
		);
		if ( false === $result ) {
			throw new \RuntimeException( 'Erreur de base de données.' );
		}
		return 1 === (int) $result;
	}

	/**
	 * Inscriptions dans l'un des états donnés, les plus anciennes d'abord.
	 *
	 * @param string[] $states États recherchés.
	 * @param int      $limit    Nombre maximal de lignes.
	 * @param int      $after_id Curseur : seules les inscriptions d'identifiant supérieur sont renvoyées.
	 * @return Registration[]
	 */
	public function list_by_states( array $states, int $limit = 100, int $after_id = 0 ): array {
		global $wpdb;
		if ( array() === $states ) {
			return array();
		}
		$table        = $this->table();
		$placeholders = implode( ', ', array_fill( 0, count( $states ), '%s' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE state IN ({$placeholders}) AND id > %d ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders générés ci-dessus.
				...array_merge( array_values( $states ), array( $after_id, $limit ) )
			),
			ARRAY_A
		);
		$this->assert_no_error();
		return array_values( array_filter( array_map( array( $this, 'hydrate' ), (array) $rows ) ) );
	}

	/**
	 * Dates de création et de paiement (UTC) d'une inscription.
	 *
	 * @param int $id Identifiant.
	 * @return array{created_at:string, paid_at:string|null}|null
	 */
	public function find_dates( int $id ): ?array {
		global $wpdb;
		$table = $this->table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT created_at, paid_at FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table interne.
		$this->assert_no_error();
		if ( null === $row ) {
			return null;
		}
		return array(
			'created_at' => (string) $row['created_at'],
			'paid_at'    => null === $row['paid_at'] ? null : (string) $row['paid_at'],
		);
	}

	/**
	 * Vide la table.
	 *
	 * @return int Nombre de lignes supprimées.
	 */
	public function purge(): int {
		global $wpdb;
		$table   = $this->table();
		$deleted = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table interne.
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table interne.
		return $deleted;
	}

	/**
	 * Construit une inscription depuis une ligne SQL.
	 *
	 * @param array<string, mixed>|null $row Ligne.
	 */
	private function hydrate( ?array $row ): ?Registration {
		if ( null === $row ) {
			return null;
		}
		return new Registration(
			(int) $row['id'],
			(string) $row['reference'],
			(string) $row['submission_id'],
			(string) $row['token_hash'],
			(string) $row['state'],
			(int) $row['amount_cents'],
			(string) $row['currency'],
			new Customer_Data(
				(string) $row['first_name'],
				(string) $row['last_name'],
				(string) $row['email'],
				(string) $row['street'],
				(string) $row['zip'],
				(string) $row['city'],
				(string) $row['country_code']
			),
			null === $row['hosted_checkout_id'] ? null : (string) $row['hosted_checkout_id'],
			null === $row['redirect_url'] ? null : (string) $row['redirect_url'],
			null === $row['checkout_expires_at'] ? null : (string) $row['checkout_expires_at'],
			null === $row['payment_id'] ? null : (string) $row['payment_id'],
			null === $row['last_status_code'] ? null : (int) $row['last_status_code']
		);
	}

	/**
	 * Lève une exception générique si la dernière requête a échoué (sans SQL ni texte d'erreur).
	 *
	 * @throws \RuntimeException Erreur de base de données.
	 */
	private function assert_no_error(): void {
		global $wpdb;
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Erreur de base de données.' );
		}
	}
}
