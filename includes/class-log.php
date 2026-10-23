<?php
/**
 * Journal de l'extension.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Écrit dans error_log, avec une liste blanche de clés : aucune donnée personnelle ni secret.
 */
final class Log {
	private const ALLOWED_KEYS = array(
		'reference',
		'state',
		'from',
		'to',
		'status_code',
		'payment_id',
		'event_id',
		'type',
		'source',
		'host',
		'http_status',
	);

	/**
	 * Écrit `[cawl-checkout] LEVEL message {json}`.
	 *
	 * @param string               $level   Niveau (info, warning, error…).
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Contexte ; seules les clés de la liste blanche sont conservées.
	 */
	public static function write( string $level, string $message, array $context = array() ): void {
		$safe = array_intersect_key( $context, array_flip( self::ALLOWED_KEYS ) );
		// Pas de wp_json_encode : la classe reste utilisable sans WordPress.
		$json = json_encode( $safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( '[cawl-checkout] %s %s %s', strtoupper( $level ), $message, $json ) );
	}
}
