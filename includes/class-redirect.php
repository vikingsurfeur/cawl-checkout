<?php
/**
 * Redirection HTTP à émettre.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Valeur immuable : le contrôleur décide, le hook admin-post émet.
 */
final readonly class Redirect {
	/**
	 * @param string $url    Destination.
	 * @param int    $status Code HTTP (303).
	 */
	public function __construct(
		public string $url,
		public int $status
	) {}
}
