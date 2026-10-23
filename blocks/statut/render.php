<?php
/**
 * Rendu du statut de l'inscription (aucun script : la page se rafraîchit par une meta, CSP stricte).
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

use CawlCheckout\Pages;
use CawlCheckout\Return_Controller;

defined( 'ABSPATH' ) || exit;

$cawl_checkout_view = Return_Controller::current();
$cawl_checkout_kind = null === $cawl_checkout_view ? 'invalid' : $cawl_checkout_view->kind;

$cawl_checkout_messages = array(
	'paid'               => 'Paiement confirmé, inscription validée',
	'processing'         => 'Paiement accepté, validation finale en cours…',
	'processing_timeout' => 'Paiement accepté, validation finale en cours…',
	'anomaly'            => 'Paiement en cours de vérification',
	'failed'             => 'Paiement refusé',
	'cancelled'          => 'Paiement annulé ou expiré',
	'expired'            => 'Paiement annulé ou expiré',
	'pending'            => 'Paiement non finalisé.',
	'refunded'           => 'Inscription remboursée',
	'invalid'            => 'Lien d\'inscription invalide.',
);
$cawl_checkout_retry    = in_array( $cawl_checkout_kind, array( 'failed', 'cancelled', 'expired', 'pending' ), true );
$cawl_checkout_role     = in_array( $cawl_checkout_kind, array( 'processing', 'processing_timeout', 'anomaly', 'paid', 'refunded' ), true ) ? 'status' : 'alert';
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'cawl-checkout-status' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- produit par WordPress. ?>>
	<p class="cawl-checkout-status__message" role="<?php echo esc_attr( $cawl_checkout_role ); ?>"><?php echo esc_html( $cawl_checkout_messages[ $cawl_checkout_kind ] ?? $cawl_checkout_messages['invalid'] ); ?></p>
	<?php if ( 'paid' === $cawl_checkout_kind && null !== $cawl_checkout_view && null !== $cawl_checkout_view->reference ) : ?>
		<p>Référence : <strong><?php echo esc_html( $cawl_checkout_view->reference ); ?></strong></p>
	<?php endif; ?>
	<?php if ( 'processing' === $cawl_checkout_kind && null !== $cawl_checkout_view && null !== $cawl_checkout_view->refresh_url ) : ?>
		<?php // RGAA 13.1.1 : rafraîchissement annoncé et relance manuelle (URL sans compteur `n`), sans JavaScript. ?>
		<p>Cette page se met à jour automatiquement pendant <?php echo esc_html( (string) ( Return_Controller::MAX_REFRESH * Return_Controller::REFRESH_SECONDS ) ); ?> secondes.</p>
		<p><a href="<?php echo esc_url( remove_query_arg( 'n', $cawl_checkout_view->refresh_url ) ); ?>">Actualiser</a></p>
	<?php endif; ?>
	<?php if ( 'processing_timeout' === $cawl_checkout_kind ) : ?>
		<p>La confirmation peut prendre quelques minutes : revenez plus tard sur ce même lien.</p>
	<?php endif; ?>
	<?php if ( $cawl_checkout_retry ) : ?>
		<p><a href="<?php echo esc_url( Pages::form_url() ); ?>">Réessayer</a></p>
	<?php endif; ?>
</div>
