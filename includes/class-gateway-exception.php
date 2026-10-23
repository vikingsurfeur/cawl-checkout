<?php
/**
 * Erreur de la passerelle de paiement.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Erreur réseau ou API. Le message ne contient ni secret ni réponse brute.
 */
final class Gateway_Exception extends \RuntimeException {}
