<?php
/**
 * Vue de la page de statut.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Valeur immuable : ce que la page de statut doit afficher.
 */
final class Return_View {
	/**
	 * @param string      $kind        invalid, paid, processing, processing_timeout, failed, cancelled, expired, refunded, anomaly ou pending.
	 * @param string|null $reference   Référence, uniquement pour la vue `paid`.
	 * @param string|null $refresh_url URL de rafraîchissement automatique, si la vue en demande un.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly ?string $reference = null,
		public readonly ?string $refresh_url = null
	) {}
}
