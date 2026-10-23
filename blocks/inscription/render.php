<?php
/**
 * Rendu du formulaire d'inscription (aucun script, aucun gestionnaire d'événement : CSP stricte).
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

use CawlCheckout\Checkout_Controller;
use CawlCheckout\Plugin;
use CawlCheckout\Pricing;

defined( 'ABSPATH' ) || exit;

$cawl_checkout_wrapper = get_block_wrapper_attributes( array( 'class' => 'cawl-checkout-form' ) );

if ( ! Plugin::config()->is_usable() ) {
	printf(
		'<div %s><p>%s</p></div>',
		$cawl_checkout_wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- produit par WordPress.
		esc_html__( 'Les inscriptions ne sont pas encore ouvertes.', 'cawl-checkout' )
	);
	return;
}

$cawl_checkout_errors = array(
	'session'               => 'Votre session a expiré. Merci de renvoyer le formulaire.',
	'champs'                => 'Certains champs sont manquants ou invalides.',
	'deja-soumis'           => 'Cette inscription a déjà été envoyée. Rechargez la page pour en commencer une nouvelle.',
	'en-cours'              => 'Votre inscription est en cours de création. Réessayez dans quelques secondes.',
	'paiement-indisponible' => 'Le paiement est momentanément indisponible. Merci de réessayer plus tard.',
);
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple code d'affichage, comparé à une liste fixe.
$cawl_checkout_code    = isset( $_GET['erreur'] ) && is_string( $_GET['erreur'] ) ? sanitize_key( wp_unslash( $_GET['erreur'] ) ) : '';
$cawl_checkout_message = $cawl_checkout_errors[ $cawl_checkout_code ] ?? null;

$cawl_checkout_fields = array(
	'first_name' => array( 'Prénom', 'text', 'given-name', 50 ),
	'last_name'  => array( 'Nom', 'text', 'family-name', 50 ),
	'email'      => array( 'Adresse e-mail', 'email', 'email', 100 ),
	'street'     => array( 'Adresse', 'text', 'street-address', 100 ),
	'zip'        => array( 'Code postal', 'text', 'postal-code', 5 ),
	'city'       => array( 'Ville', 'text', 'address-level2', 50 ),
);

$cawl_checkout_price = number_format( Pricing::price_cents() / 100, 2, ',', "\u{202F}" ) . "\u{00A0}€";
?>
<div <?php echo $cawl_checkout_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- produit par WordPress. ?>>
	<?php if ( null !== $cawl_checkout_message ) : ?>
		<p class="cawl-checkout-form__error" role="alert"><?php echo esc_html( $cawl_checkout_message ); ?></p>
	<?php endif; ?>
	<p class="cawl-checkout-form__price">Montant à régler : <strong><?php echo esc_html( $cawl_checkout_price ); ?></strong></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( Checkout_Controller::ACTION ); ?>">
		<?php wp_nonce_field( Checkout_Controller::ACTION, '_cawl_checkout_nonce', false ); ?>
		<input type="hidden" name="submission_id" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
		<?php foreach ( $cawl_checkout_fields as $cawl_checkout_name => list( $cawl_checkout_label, $cawl_checkout_type, $cawl_checkout_autocomplete, $cawl_checkout_max ) ) : ?>
			<p class="cawl-checkout-form__field">
				<label for="cawl-checkout-<?php echo esc_attr( $cawl_checkout_name ); ?>"><?php echo esc_html( $cawl_checkout_label ); ?></label>
				<input type="<?php echo esc_attr( $cawl_checkout_type ); ?>" id="cawl-checkout-<?php echo esc_attr( $cawl_checkout_name ); ?>" name="<?php echo esc_attr( $cawl_checkout_name ); ?>" autocomplete="<?php echo esc_attr( $cawl_checkout_autocomplete ); ?>" maxlength="<?php echo esc_attr( (string) $cawl_checkout_max ); ?>" required<?php echo 'zip' === $cawl_checkout_name ? ' inputmode="numeric" pattern="[0-9]{5}"' : ''; ?>>
			</p>
		<?php endforeach; ?>
		<p class="cawl-checkout-form__field">
			<button type="submit">Payer mon inscription</button>
		</p>
	</form>
</div>
