<?php

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Ouvre la CSP form-action vers l'hôte de paiement CAWL, par l'un des deux filtres que le site peut fournir :
 * - « wp_test_csp_form_action » (liste de sources) ;
 * - « socle_csp_directives » (directives => sources), seulement si form-action y est déjà déclarée.
 * Sans configuration utilisable, ne fait rien : la CSP du site reste à 'self'.
 * La redirection vers CAWL n'est autorisée qu'autour de celle du formulaire
 * (Checkout_Controller::with_payment_redirect_host()), jamais pour tout le site.
 */
final class Csp {
	public static function register( Config $c ): void {
		if ( ! $c->is_usable() ) {
			return;
		}
		$host = $c->payment_host();
		add_filter(
			'wp_test_csp_form_action',
			static function ( $sources ) use ( $host ) {
				$sources   = is_array( $sources ) ? $sources : array();
				$sources[] = $host;
				return $sources;
			}
		);
		add_filter(
			'socle_csp_directives',
			static function ( $directives ) use ( $host ) {
				// Créer une directive absente restreindrait les envois vers le site lui-même : on n'y touche pas.
				if ( is_array( $directives ) && isset( $directives['form-action'] ) && is_array( $directives['form-action'] ) ) {
					$directives['form-action'][] = $host;
				}
				return $directives;
			}
		);
	}
}
