<?php
/**
 * Modèle de page neutre de l'extension.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Modèle de bloc sans en-tête ni pied de page, ni logo : les pages de l'extension restent neutres.
 */
final class Templates {
	public const SLUG          = 'inscription-neutre';
	public const NAME          = 'cawl-checkout//inscription-neutre';
	public const PAGE_TEMPLATE = 'inscription-neutre';

	/** Enregistre le modèle (WordPress 6.7 ou plus). */
	public static function register(): void {
		if ( ! function_exists( 'register_block_template' ) ) {
			return;
		}
		// Le cœur (7.1) fusionne les modèles du registre avec leur clé texte, alors que l'éditeur lit l'indice 0
		// (block-editor.php) : on rend une liste, sinon « Undefined array key 0 » à l'édition des pages de l'extension.
		add_filter( 'get_block_templates', 'array_values' );
		register_block_template(
			self::NAME,
			array(
				'title'       => 'Inscription (neutre)',
				'description' => 'Page simple sans en-tête ni pied de page, pour le formulaire et le statut de paiement.',
				'post_types'  => array( 'page' ),
				'content'     => '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->'
					. '<main class="wp-block-group"><!-- wp:post-title {"level":1,"style":{"typography":{"fontSize":"2rem"}}} /-->'
					. '<!-- wp:post-content /--></main><!-- /wp:group -->',
			)
		);
	}
}
