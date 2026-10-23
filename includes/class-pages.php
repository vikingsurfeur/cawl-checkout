<?php
/**
 * Pages publiques de l'extension : formulaire et statut.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * URL et création des deux pages, affichées avec le modèle neutre (sans en-tête du site).
 */
final class Pages {
	public const FORM_SLUG   = 'inscription';
	public const STATUS_SLUG = 'inscription-statut';

	/**
	 * URL du formulaire, avec un éventuel code d'erreur (jamais de donnée saisie).
	 *
	 * @param string|null $error Code d'erreur.
	 */
	public static function form_url( ?string $error = null ): string {
		$url = home_url( '/' . self::FORM_SLUG . '/' );
		return null === $error ? $url : add_query_arg( 'erreur', $error, $url );
	}

	/**
	 * URL de la page de statut, utilisée comme URL de retour CAWL.
	 *
	 * @param string $reference Référence marchande.
	 * @param string $token     Jeton de retour en clair.
	 * @param int    $refresh   Compteur de rafraîchissements.
	 */
	public static function status_url( string $reference, string $token, int $refresh = 0 ): string {
		$args = array(
			'ref'   => rawurlencode( $reference ),
			'token' => rawurlencode( $token ),
		);
		if ( $refresh > 0 ) {
			$args['n'] = $refresh;
		}
		return add_query_arg( $args, home_url( '/' . self::STATUS_SLUG . '/' ) );
	}

	/**
	 * Crée les pages manquantes (idempotent).
	 *
	 * @return array{form:int,status:int} Identifiants des pages.
	 * @throws \RuntimeException Si une page ne peut être créée.
	 */
	public static function install(): array {
		$ids = array();
		foreach ( self::definitions() as $key => list( $slug, $title, $content ) ) {
			$existing = get_page_by_path( $slug, OBJECT, 'page' );
			$id       = $existing instanceof \WP_Post ? $existing->ID : wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_name'    => $slug,
					'post_title'   => $title,
					'post_content' => $content,
				),
				true
			);
			if ( is_wp_error( $id ) || 0 === $id ) {
				throw new \RuntimeException( 'Création de la page impossible : ' . $slug );
			}
			update_post_meta( $id, '_wp_page_template', Templates::PAGE_TEMPLATE );
			$ids[ $key ] = (int) $id;
		}
		return $ids;
	}

	/** @return array<string,array{string,string,string}> */
	private static function definitions(): array {
		return array(
			'form'   => array( self::FORM_SLUG, 'Inscription', '<!-- wp:cawl-checkout/inscription /-->' ),
			'status' => array( self::STATUS_SLUG, 'Statut de l\'inscription', '<!-- wp:cawl-checkout/statut /-->' ),
		);
	}
}
