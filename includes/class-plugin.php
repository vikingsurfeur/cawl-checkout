<?php
/**
 * Câblage de l'extension et localisateur de services.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Localisateur statique : un seul exemplaire de chaque service par requête.
 */
final class Plugin {
	private static ?Config $config                    = null;
	private static ?Gateway $gateway                  = null;
	private static ?Registration_Repository $registrations = null;
	private static ?Webhook_Event_Repository $events  = null;
	private static ?Payment_Applier $applier          = null;

	/**
	 * Branche les hooks de l'extension.
	 *
	 * @param string $file Fichier principal de l'extension.
	 */
	public static function boot( string $file ): void {
		register_activation_hook( $file, array( Schema::class, 'install' ) );
		add_action( 'plugins_loaded', array( Schema::class, 'maybe_upgrade' ) );
		add_action( 'init', array( self::class, 'register_blocks' ) );
		add_action( 'init', array( Templates::class, 'register' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'cawl-checkout', Cli_Command::class );
		}

		if ( ! self::config()->is_usable() ) {
			add_action( 'admin_notices', array( self::class, 'config_notice' ) );
			return;
		}
		Csp::register( self::config() );
		add_action( 'rest_api_init', array( Webhook_Controller::class, 'register' ) );
		Checkout_Controller::register();
		Return_Controller::register();
	}

	/** Enregistre les blocs dynamiques de l'extension. */
	public static function register_blocks(): void {
		register_block_type( CAWL_CHECKOUT_DIR . 'blocks/inscription' );
		register_block_type( CAWL_CHECKOUT_DIR . 'blocks/statut' );
	}

	/** Avis aux seuls administrateurs : configuration CAWL absente ou hors preprod. */
	public static function config_notice(): void {
		if ( current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'CAWL Checkout : configuration CAWL incomplète ou hors preprod, aucune route n\'est active.', 'cawl-checkout' )
				. '</p></div>';
		}
	}

	public static function config(): Config {
		return self::$config ??= Config::from_env();
	}

	/** Passerelle CAWL, remplaçable par le filtre `cawl_checkout_gateway` (tests, simulation). */
	public static function gateway(): Gateway {
		$filtered = apply_filters( 'cawl_checkout_gateway', null );
		if ( $filtered instanceof Gateway ) {
			return $filtered;
		}
		return self::$gateway ??= new Cawl_Gateway( self::config(), Pricing::session_minutes() );
	}

	public static function registrations(): Registration_Repository {
		return self::$registrations ??= new Registration_Repository();
	}

	public static function events(): Webhook_Event_Repository {
		return self::$events ??= new Webhook_Event_Repository();
	}

	public static function applier(): Payment_Applier {
		return self::$applier ??= new Payment_Applier( self::registrations() );
	}
}
