<?php
/**
 * Schéma des tables de l'extension.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Création et mise à niveau des tables (dbDelta).
 */
final class Schema {
	public const DB_VERSION        = '1';
	public const DB_VERSION_OPTION = 'cawl_checkout_db_version';

	/**
	 * Nom complet d'une table de l'extension (`registrations` ou `webhook_events`).
	 * Avec la constante `CAWL_CHECKOUT_TEST_TABLES` à true (suite d'intégration), les tables de test
	 * `cawl_checkout_test_*` remplacent celles de la campagne.
	 *
	 * @param string $name Nom court de la table.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . ( self::uses_test_tables() ? 'cawl_checkout_test_' : 'cawl_checkout_' ) . $name;
	}

	/** Les tables de test sont actives (constante posée par le bootstrap d'intégration). */
	private static function uses_test_tables(): bool {
		return defined( 'CAWL_CHECKOUT_TEST_TABLES' ) && true === constant( 'CAWL_CHECKOUT_TEST_TABLES' );
	}

	/**
	 * Crée ou met à jour les deux tables, puis mémorise la version du schéma.
	 * Les tables de test ne mémorisent rien : l'option suit le schéma des tables de la campagne.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset       = $wpdb->get_charset_collate();
		$registrations = self::table( 'registrations' );
		$events        = self::table( 'webhook_events' );

		dbDelta(
			"CREATE TABLE {$registrations} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference VARCHAR(40) NOT NULL,
  submission_id CHAR(36) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  state VARCHAR(20) NOT NULL,
  amount_cents INT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL,
  street VARCHAR(255) NOT NULL,
  zip VARCHAR(20) NOT NULL,
  city VARCHAR(100) NOT NULL,
  country_code CHAR(2) NOT NULL,
  hosted_checkout_id VARCHAR(100) DEFAULT NULL,
  redirect_url VARCHAR(512) DEFAULT NULL,
  checkout_expires_at DATETIME DEFAULT NULL,
  payment_id VARCHAR(64) DEFAULT NULL,
  last_status_code INT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  paid_at DATETIME DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY reference (reference),
  UNIQUE KEY submission_id (submission_id),
  KEY state (state)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$events} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id VARCHAR(64) NOT NULL,
  type VARCHAR(64) NOT NULL,
  reference VARCHAR(40) DEFAULT NULL,
  payment_id VARCHAR(64) DEFAULT NULL,
  status_code INT DEFAULT NULL,
  outcome VARCHAR(20) DEFAULT NULL,
  received_at DATETIME NOT NULL,
  processed_at DATETIME DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY event_id (event_id)
) {$charset};"
		);

		if ( ! self::uses_test_tables() ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		}
	}

	/**
	 * Met le schéma à niveau si la version mémorisée diffère.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}
}
