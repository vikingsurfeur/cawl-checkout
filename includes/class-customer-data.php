<?php
/**
 * Coordonnées du client.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Valeur immuable : identité et adresse de facturation du participant.
 */
final class Customer_Data {
	/**
	 * @param string $first_name   Prénom.
	 * @param string $last_name    Nom.
	 * @param string $email        Adresse e-mail.
	 * @param string $street       Rue.
	 * @param string $zip          Code postal.
	 * @param string $city         Ville.
	 * @param string $country_code Code pays ISO 3166-1 alpha-2.
	 */
	public function __construct(
		public readonly string $first_name,
		public readonly string $last_name,
		public readonly string $email,
		public readonly string $street,
		public readonly string $zip,
		public readonly string $city,
		public readonly string $country_code
	) {}
}
