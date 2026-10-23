<?php
/**
 * Page de retour : état de l'inscription après le paiement.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Résout la vue de la page de statut, une seule fois par requête, et pose les en-têtes des pages de l'extension.
 */
final class Return_Controller {
	public const MAX_REFRESH     = 10;
	public const REFRESH_SECONDS = 3;

	private static ?Return_View $view = null;

	/** Branche les en-têtes des deux pages et la résolution de la vue de statut. */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'on_template_redirect' ) );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
		add_action( 'wp_head', array( self::class, 'print_refresh' ), 1 );
	}

	/** Vue résolue pour la requête en cours, ou null hors de la page de statut. */
	public static function current(): ?Return_View {
		return self::$view;
	}

	/** Hook `template_redirect` : en-têtes privés sur les deux pages, vue résolue sur la page de statut. */
	public static function on_template_redirect(): void {
		if ( ! is_page( array( Pages::FORM_SLUG, Pages::STATUS_SLUG ) ) ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( is_page( Pages::STATUS_SLUG ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lien de retour, protégé par son jeton.
			self::$view = ( new self() )->resolve( wp_unslash( $_GET ) );
		}
	}

	/**
	 * Filtre `wp_robots` : noindex, nofollow sur les deux pages.
	 *
	 * @param mixed $robots Directives.
	 * @return mixed
	 */
	public static function robots( $robots ) {
		if ( ! is_array( $robots ) || ! is_page( array( Pages::FORM_SLUG, Pages::STATUS_SLUG ) ) ) {
			return $robots;
		}
		unset( $robots['follow'], $robots['index'] );
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
		return $robots;
	}

	/** Meta refresh de la page de statut (aucun JavaScript). */
	public static function print_refresh(): void {
		if ( null === self::$view || null === self::$view->refresh_url ) {
			return;
		}
		printf(
			'<meta http-equiv="refresh" content="%d;url=%s">' . "\n",
			self::REFRESH_SECONDS,
			esc_url( self::$view->refresh_url )
		);
	}

	/**
	 * Résout la vue à afficher.
	 *
	 * @param array<mixed> $query Paramètres `ref`, `token` et `n`.
	 * @throws \RuntimeException Si la lecture initiale de l'inscription échoue.
	 */
	public function resolve( array $query ): Return_View {
		$ref   = isset( $query['ref'] ) && is_string( $query['ref'] ) ? $query['ref'] : '';
		$token = isset( $query['token'] ) && is_string( $query['token'] ) ? $query['token'] : '';
		$n     = isset( $query['n'] ) && is_string( $query['n'] ) && ctype_digit( $query['n'] ) && strlen( $query['n'] ) <= 4 ? (int) $query['n'] : 0;

		// Une erreur de lecture se propage : la page échoue plutôt que d'annoncer un lien invalide.
		$reg = '' === $ref ? null : Plugin::registrations()->find_by_reference( $ref );
		if ( null === $reg || ! Return_Token::verify( $token, $reg->token_hash ) ) {
			return new Return_View( 'invalid' );
		}

		$state = $reg->state;
		if ( ! in_array( $state, array( Registration::PAID, Registration::REFUNDED, Registration::ANOMALY ), true ) && null !== $reg->hosted_checkout_id ) {
			try {
				$snapshot = Plugin::gateway()->get_checkout( $reg->hosted_checkout_id );
				$state    = Plugin::applier()->apply( $reg, $snapshot, 'return' );
			} catch ( Gateway_Exception | \RuntimeException ) {
				// Journal sans message d'exception : il peut contenir des données de la requête.
				Log::write( 'warning', 'retour : état lu sans la passerelle', array( 'reference' => $reg->reference ) );
				$state = $reg->state;
			}
		}

		return $this->view_for( $reg, $state, $token, $n );
	}

	/** Vue correspondant à un état d'inscription. */
	private function view_for( Registration $reg, string $state, string $token, int $n ): Return_View {
		switch ( $state ) {
			case Registration::PAID:
				return new Return_View( 'paid', $reg->reference );
			case Registration::PROCESSING:
				if ( $n >= self::MAX_REFRESH ) {
					return new Return_View( 'processing_timeout' );
				}
				return new Return_View( 'processing', null, Pages::status_url( $reg->reference, $token, $n + 1 ) );
			case Registration::FAILED:
			case Registration::CANCELLED:
			case Registration::EXPIRED:
			case Registration::REFUNDED:
			case Registration::ANOMALY:
				return new Return_View( $state );
			default:
				return new Return_View( 'pending' );
		}
	}
}
