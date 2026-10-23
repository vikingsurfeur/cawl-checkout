<?php
/**
 * Plugin Name:       CAWL Checkout
 * Description:       Inscriptions payantes réglées sur la page de paiement hébergée par CAWL (environnement de test uniquement).
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.3
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cawl-checkout
 *
 * @package CawlCheckout
 */

defined( 'ABSPATH' ) || exit;

define( 'CAWL_CHECKOUT_FILE', __FILE__ );
define( 'CAWL_CHECKOUT_DIR', plugin_dir_path( __FILE__ ) );

$cawl_checkout_autoload = CAWL_CHECKOUT_DIR . 'vendor/autoload.php';

if ( ! is_readable( $cawl_checkout_autoload ) ) {
	// Dépendances absentes : avis réservé aux administrateurs, rien d'autre n'est chargé.
	add_action(
		'admin_notices',
		static function (): void {
			if ( current_user_can( 'manage_options' ) ) {
				echo '<div class="notice notice-error"><p>'
					. esc_html__( 'CAWL Checkout : dépendances manquantes, lancer « composer install --no-dev » dans le dossier de l\'extension.', 'cawl-checkout' )
					. '</p></div>';
			}
		}
	);
	return;
}

require_once $cawl_checkout_autoload;

// Câblage : activation (tables), mise à niveau du schéma, route webhook.
\CawlCheckout\Plugin::boot( __FILE__ );
