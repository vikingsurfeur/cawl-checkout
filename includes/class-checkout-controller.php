<?php
/**
 * Traitement de l'envoi du formulaire d'inscription.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Valide le formulaire, crée l'inscription puis la session de paiement CAWL.
 */
final class Checkout_Controller {
	public const ACTION = 'cawl_checkout_register';

	private const UUID_V4 = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i';

	/** Double envoi : attente de la session du premier envoi, 10 relectures espacées de 300 ms. */
	private const WAIT_ATTEMPTS     = 10;
	private const WAIT_MICROSECONDS = 300000;

	/** Au-delà, une inscription restée `created` n'est plus attendue (premier envoi interrompu). */
	private const RECENT_SECONDS = 15;

	/** Attente en microsecondes. */
	private \Closure $sleep;

	/**
	 * @param \Closure|null $sleep Attente, reçoit des microsecondes (usleep par défaut ; remplaçable en test).
	 */
	public function __construct( ?\Closure $sleep = null ) {
		$this->sleep = $sleep ?? static function ( int $microseconds ): void {
			usleep( $microseconds );
		};
	}

	/** Branche le traitement sur admin-post (visiteurs connectés ou non). */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'dispatch' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'dispatch' ) );
	}

	/** Point d'entrée admin-post : traite puis redirige. */
	public static function dispatch(): void {
		$redirect = ( new self() )->handle( self::request_data( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans handle().
		self::with_payment_redirect_host( static fn() => wp_safe_redirect( $redirect->url, $redirect->status ) );
		exit;
	}

	/**
	 * Exécute $fn avec l'hôte de paiement CAWL parmi les hôtes de redirection autorisés, puis le retire :
	 * le reste du site (wp-login.php?redirect_to=…) ne redirige jamais vers CAWL.
	 *
	 * @param callable $fn Appel à encadrer.
	 * @return mixed Valeur renvoyée par $fn.
	 */
	public static function with_payment_redirect_host( callable $fn ): mixed {
		$domain = wp_parse_url( Plugin::config()->payment_host(), PHP_URL_HOST );
		$allow  = static function ( $hosts ) use ( $domain ) {
			$hosts = is_array( $hosts ) ? $hosts : array();
			if ( is_string( $domain ) && '' !== $domain ) {
				$hosts[] = $domain;
			}
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $allow );
		try {
			return $fn();
		} finally {
			remove_filter( 'allowed_redirect_hosts', $allow );
		}
	}

	/**
	 * Annule les guillemets magiques de WordPress sur `$_POST`.
	 *
	 * @param array<mixed> $raw_post Données brutes.
	 * @return array<mixed>
	 */
	public static function request_data( array $raw_post ): array {
		return wp_unslash( $raw_post );
	}

	/**
	 * Traite un envoi (données déjà déslashées).
	 *
	 * @param array<mixed> $post Données du formulaire.
	 * @throws \LogicException Si l'URL de retour dépasse 200 caractères.
	 */
	public function handle( array $post ): Redirect {
		$nonce = isset( $post['_cawl_checkout_nonce'] ) && is_string( $post['_cawl_checkout_nonce'] ) ? $post['_cawl_checkout_nonce'] : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			return $this->to_form( 'session' );
		}

		$submission_id = isset( $post['submission_id'] ) && is_string( $post['submission_id'] ) ? $post['submission_id'] : '';
		if ( 1 !== preg_match( self::UUID_V4, $submission_id ) ) {
			return $this->to_form( 'champs' );
		}
		$submission_id = strtolower( $submission_id );

		try {
			$existing = Plugin::registrations()->find_by_submission_id( $submission_id );
		} catch ( \RuntimeException ) {
			return $this->unavailable( 'Lecture de l\'inscription impossible.' );
		}
		if ( null !== $existing ) {
			return $this->from_existing( $existing );
		}

		$customer = $this->customer( $post );
		if ( null === $customer ) {
			return $this->to_form( 'champs' );
		}

		return $this->create_and_open( $submission_id, $customer );
	}

	/**
	 * Crée l'inscription puis la session de paiement.
	 *
	 * @param string        $submission_id Identifiant de soumission.
	 * @param Customer_Data $customer      Coordonnées validées.
	 * @throws \LogicException Si l'URL de retour dépasse 200 caractères.
	 */
	private function create_and_open( string $submission_id, Customer_Data $customer ): Redirect {
		$repo  = Plugin::registrations();
		$token = Return_Token::generate();

		try {
			try {
				$reg = $repo->create( $submission_id, Return_Token::hash( $token ), Pricing::price_cents(), $customer );
			} catch ( \RuntimeException ) {
				// Deux envois concurrents ? Le doublon se reconnaît à la relecture.
				$existing = $repo->find_by_submission_id( $submission_id );
				return null === $existing
					? $this->unavailable( 'Création de l\'inscription impossible.' )
					: $this->from_existing( $existing );
			}

			$return_url = Pages::status_url( $reg->reference, $token );
			if ( strlen( $return_url ) > 200 ) {
				$repo->transition( $reg->id, Registration::CREATED, Registration::FAILED, null, null );
				throw new \LogicException( 'URL de retour trop longue (200 caractères au plus).' );
			}

			try {
				$session = Plugin::gateway()->create_checkout( $reg, $return_url );
			} catch ( Gateway_Exception ) {
				$repo->transition( $reg->id, Registration::CREATED, Registration::FAILED, null, null );
				return $this->unavailable( 'Création de la session de paiement refusée.', array( 'reference' => $reg->reference ) );
			}

			$host = wp_parse_url( $session->redirect_url, PHP_URL_HOST );
			$want = wp_parse_url( Plugin::config()->payment_host(), PHP_URL_HOST );
			if ( ! is_string( $host ) || $host !== $want ) {
				$repo->transition( $reg->id, Registration::CREATED, Registration::FAILED, null, null );
				return $this->unavailable(
					'Hôte de redirection inattendu.',
					array(
						'reference' => $reg->reference,
						'host'      => is_string( $host ) ? $host : '',
					)
				);
			}

			if ( ! $repo->open_checkout( $reg->id, $session ) ) {
				return $this->unavailable( 'Ouverture de la session impossible.', array( 'reference' => $reg->reference ) );
			}
			return new Redirect( $session->redirect_url, 303 );
		} catch ( \RuntimeException ) {
			return $this->unavailable( 'Erreur de base de données.' );
		}
	}

	/**
	 * Une inscription existe déjà pour cette soumission (double envoi) : réutiliser sa session valide.
	 * Si le premier envoi est encore en train d'ouvrir la session (`created` récent), l'attendre brièvement.
	 */
	private function from_existing( Registration $reg ): Redirect {
		try {
			if ( Registration::CREATED === $reg->state && $this->is_recent( $reg ) ) {
				$reg = $this->wait_for_session( $reg );
				if ( Registration::CREATED === $reg->state ) {
					return $this->to_form( 'en-cours' );
				}
			}
		} catch ( \RuntimeException ) {
			return $this->unavailable( 'Lecture de l\'inscription impossible.' );
		}

		$valid = Registration::CHECKOUT_OPEN === $reg->state
			&& null !== $reg->redirect_url
			&& null !== $reg->checkout_expires_at
			&& $reg->checkout_expires_at > gmdate( 'Y-m-d H:i:s' );
		return $valid ? new Redirect( $reg->redirect_url, 303 ) : $this->to_form( 'deja-soumis' );
	}

	/**
	 * L'inscription a été créée il y a moins de RECENT_SECONDS secondes.
	 *
	 * @throws \RuntimeException Erreur de base de données.
	 */
	private function is_recent( Registration $reg ): bool {
		$dates   = Plugin::registrations()->find_dates( $reg->id );
		$created = null === $dates ? false : strtotime( $dates['created_at'] . ' UTC' );
		return false !== $created && time() - $created < self::RECENT_SECONDS;
	}

	/**
	 * Relit l'inscription jusqu'à ce qu'elle quitte l'état `created`, au plus WAIT_ATTEMPTS fois.
	 *
	 * @throws \RuntimeException Erreur de base de données.
	 */
	private function wait_for_session( Registration $reg ): Registration {
		for ( $i = 0; $i < self::WAIT_ATTEMPTS && Registration::CREATED === $reg->state; $i++ ) {
			( $this->sleep )( self::WAIT_MICROSECONDS );
			$reg = Plugin::registrations()->find( $reg->id ) ?? $reg;
		}
		return $reg;
	}

	/**
	 * Valide et assainit les champs ; null si l'un d'eux est invalide.
	 *
	 * @param array<mixed> $post Données du formulaire.
	 */
	private function customer( array $post ): ?Customer_Data {
		$value = static fn( string $key ): string => isset( $post[ $key ] ) && is_string( $post[ $key ] ) ? sanitize_text_field( $post[ $key ] ) : '';

		$first  = $value( 'first_name' );
		$last   = $value( 'last_name' );
		$email  = $value( 'email' );
		$street = $value( 'street' );
		$zip    = $value( 'zip' );
		$city   = $value( 'city' );

		$ok = $this->length_between( $first, 1, 50 )
			&& $this->length_between( $last, 1, 50 )
			&& $this->length_between( $email, 1, 100 ) && false !== is_email( $email )
			&& $this->length_between( $street, 1, 100 )
			&& 1 === preg_match( '/\A\d{5}\z/', $zip )
			&& $this->length_between( $city, 1, 50 );

		return $ok ? new Customer_Data( $first, $last, $email, $street, $zip, $city, 'FR' ) : null;
	}

	private function length_between( string $s, int $min, int $max ): bool {
		$len = mb_strlen( $s );
		return $len >= $min && $len <= $max;
	}

	private function to_form( string $error ): Redirect {
		return new Redirect( Pages::form_url( $error ), 303 );
	}

	/**
	 * Journalise (sans donnée personnelle) et renvoie vers le formulaire en erreur.
	 *
	 * @param string               $message Message fixe.
	 * @param array<string, mixed> $context Contexte (clés de la liste blanche).
	 */
	private function unavailable( string $message, array $context = array() ): Redirect {
		Log::write( 'error', $message, $context );
		return $this->to_form( 'paiement-indisponible' );
	}
}
