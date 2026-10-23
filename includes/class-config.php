<?php
/**
 * Configuration CAWL lue dans l'environnement du conteneur.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Valeurs immuables des six variables CAWL_*. Seul l'environnement « preprod » est utilisable.
 */
final class Config {
	private const HOST = 'https://payment.preprod.cawl-solutions.fr';

	/**
	 * Constructeur privé : passer par from_env().
	 *
	 * @param string $env            Environnement CAWL (seul « preprod » est accepté).
	 * @param string $pspid          Identifiant marchand.
	 * @param string $api_key        Clé d'API.
	 * @param string $api_secret     Secret d'API.
	 * @param string $webhook_key_id Identifiant de la clé de webhook.
	 * @param string $webhook_secret Secret de webhook.
	 */
	private function __construct(
		private string $env,
		private string $pspid,
		private string $api_key,
		private string $api_secret,
		private string $webhook_key_id,
		private string $webhook_secret
	) {}

	/**
	 * Construit la configuration depuis l'environnement.
	 *
	 * @param callable|string $getenv Lecteur de variable (nom => valeur ou false).
	 */
	public static function from_env( callable|string $getenv = 'getenv' ): self {
		$read = static function ( string $name ) use ( $getenv ): string {
			$value = $getenv( $name );
			return is_string( $value ) ? trim( $value ) : '';
		};
		return new self(
			$read( 'CAWL_ENV' ),
			$read( 'CAWL_PSPID' ),
			$read( 'CAWL_API_KEY' ),
			$read( 'CAWL_API_SECRET' ),
			$read( 'CAWL_WEBHOOK_KEY_ID' ),
			$read( 'CAWL_WEBHOOK_SECRET' )
		);
	}

	/** Les six variables sont non vides. */
	public function is_complete(): bool {
		foreach ( array( $this->env, $this->pspid, $this->api_key, $this->api_secret, $this->webhook_key_id, $this->webhook_secret ) as $value ) {
			if ( '' === $value ) {
				return false;
			}
		}
		return true;
	}

	/** Configuration complète et sur la sandbox preprod. */
	public function is_usable(): bool {
		return $this->is_complete() && 'preprod' === $this->env;
	}

	public function env(): string {
		return $this->env;
	}

	public function pspid(): string {
		return $this->pspid;
	}

	public function api_key(): string {
		return $this->api_key;
	}

	public function api_secret(): string {
		return $this->api_secret;
	}

	public function webhook_key_id(): string {
		return $this->webhook_key_id;
	}

	public function webhook_secret(): string {
		return $this->webhook_secret;
	}

	public function api_endpoint(): string {
		return self::HOST;
	}

	public function payment_host(): string {
		return self::HOST;
	}

	/**
	 * Sortie de var_dump()/print_r() : secrets masqués.
	 *
	 * @return array<string,string>
	 */
	public function __debugInfo(): array {
		return array(
			'env'            => $this->env,
			'pspid'          => $this->pspid,
			'api_key'        => '***',
			'api_secret'     => '***',
			'webhook_key_id' => $this->webhook_key_id,
			'webhook_secret' => '***',
		);
	}
}
