<?php
/**
 * Commandes WP-CLI de l'extension.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

namespace CawlCheckout;

defined( 'ABSPATH' ) || ( PHP_SAPI === 'cli' ) || exit;

/**
 * Gestion de l'extension en ligne de commande.
 */
final class Cli_Command {
	/**
	 * Crée les pages d'inscription et de statut si elles manquent.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cawl-checkout install-pages
	 *
	 * @subcommand install-pages
	 */
	public function install_pages(): void {
		try {
			$ids = Pages::install();
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::success( sprintf( 'Pages prêtes : formulaire #%d, statut #%d.', $ids['form'], $ids['status'] ) );
	}

	/**
	 * Affiche la complétude de la configuration CAWL (jamais les valeurs) et teste les identifiants webhook.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cawl-checkout check-config
	 *
	 * @subcommand check-config
	 */
	public function check_config(): void {
		$config = Plugin::config();
		$vars   = array(
			'CAWL_ENV'            => $config->env(),
			'CAWL_PSPID'          => $config->pspid(),
			'CAWL_API_KEY'        => $config->api_key(),
			'CAWL_API_SECRET'     => $config->api_secret(),
			'CAWL_WEBHOOK_KEY_ID' => $config->webhook_key_id(),
			'CAWL_WEBHOOK_SECRET' => $config->webhook_secret(),
		);
		foreach ( $vars as $name => $value ) {
			\WP_CLI::line( sprintf( '%s renseignée : %s', $name, '' !== $value ? 'oui' : 'non' ) );
		}
		\WP_CLI::line( sprintf( 'CAWL_ENV valide (preprod) : %s', 'preprod' === $config->env() ? 'oui' : 'non' ) );
		if ( ! $config->is_usable() ) {
			\WP_CLI::error( 'Configuration CAWL incomplète ou hors preprod : validation des identifiants impossible.' );
		}
		try {
			$ok = Plugin::gateway()->validate_webhook_credentials();
		} catch ( Gateway_Exception $e ) {
			\WP_CLI::error( 'Validation des identifiants webhook en échec (réseau, API ou identifiants refusés).' );
		}
		if ( ! $ok ) {
			\WP_CLI::error( 'Identifiants webhook non reconnus par CAWL.' );
		}
		\WP_CLI::success( 'Identifiants webhook reconnus par CAWL' );
	}

	/**
	 * Lit ou modifie le prix de l'inscription et la durée de la session de paiement.
	 *
	 * ## OPTIONS
	 *
	 * [--price=<centimes>]
	 * : Prix en centimes (entier > 0).
	 *
	 * [--session-minutes=<n>]
	 * : Durée de la session de paiement, de 1 à 180 minutes (maximum CAWL : 3 heures).
	 *
	 * ## EXAMPLES
	 *
	 *     wp cawl-checkout settings --price=100 --session-minutes=2
	 *
	 * @param array<int,string>    $args       Arguments positionnels.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function settings( array $args, array $assoc_args ): void {
		try {
			$values = self::settings_values( $assoc_args );
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		if ( null !== $values['price'] ) {
			update_option( Pricing::OPTION, $values['price'] );
		}
		if ( null !== $values['session_minutes'] ) {
			update_option( Pricing::SESSION_OPTION, $values['session_minutes'] );
		}
		\WP_CLI::line( sprintf( 'price : %d centimes', Pricing::price_cents() ) );
		\WP_CLI::line( sprintf( 'session-minutes : %d', Pricing::session_minutes() ) );
	}

	/**
	 * Valide les options de `settings` (méthode statique : WP-CLI n'en fait pas une sous-commande).
	 *
	 * @param array<string,mixed> $assoc_args Options WP-CLI.
	 * @return array{price:int|null, session_minutes:int|null} Valeurs à enregistrer ; null si absente.
	 * @throws \InvalidArgumentException Valeur non entière ou hors bornes (message destiné à l'opérateur).
	 */
	public static function settings_values( array $assoc_args ): array {
		$price   = self::int_option( $assoc_args, 'price' );
		$minutes = self::int_option( $assoc_args, 'session-minutes' );
		if ( null !== $price && $price < 1 ) {
			throw new \InvalidArgumentException( '--price doit être un entier supérieur à 0 (centimes).' );
		}
		if ( null !== $minutes && ( $minutes < 1 || $minutes > Pricing::MAX_SESSION_MINUTES ) ) {
			throw new \InvalidArgumentException(
				sprintf( '--session-minutes doit être compris entre 1 et %d : une session de paiement CAWL dure 3 heures au plus.', Pricing::MAX_SESSION_MINUTES )
			);
		}
		return array(
			'price'           => $price,
			'session_minutes' => $minutes,
		);
	}

	/**
	 * Option entière décimale stricte, ou null si elle est absente.
	 *
	 * @param array<string,mixed> $assoc_args Options WP-CLI.
	 * @param string              $name       Nom de l'option.
	 * @throws \InvalidArgumentException Valeur absente (drapeau seul) ou non entière.
	 */
	private static function int_option( array $assoc_args, string $name ): ?int {
		if ( ! array_key_exists( $name, $assoc_args ) ) {
			return null;
		}
		$value = $assoc_args[ $name ];
		// Drapeau sans valeur (true) : refusé, (string) l'aurait changé en « 1 ». En ligne de commande, WP-CLI
		// l'écarte déjà avec un avertissement puisque la synopsis exige une valeur ; garde pour tout autre appel.
		if ( ! is_string( $value ) ) {
			throw new \InvalidArgumentException( sprintf( '--%1$s exige une valeur : --%1$s=<n>.', $name ) );
		}
		if ( ! ctype_digit( $value ) || strlen( $value ) > 9 ) {
			throw new \InvalidArgumentException( sprintf( '--%s doit être un entier positif.', $name ) );
		}
		return (int) $value;
	}

	/**
	 * Affiche l'état d'une inscription (aucune donnée personnelle).
	 *
	 * ## OPTIONS
	 *
	 * <reference>
	 * : Référence de l'inscription.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cawl-checkout status INS-20261021-ABCDEF
	 *
	 * @param array<int,string> $args Arguments positionnels.
	 */
	public function status( array $args ): void {
		$r = $this->require_registration( (string) $args[0] );
		try {
			$dates = Plugin::registrations()->find_dates( $r->id );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( 'Erreur de base de données.' );
		}
		\WP_CLI::line( 'reference : ' . $r->reference );
		\WP_CLI::line( 'state : ' . $r->state );
		\WP_CLI::line( 'amount_cents : ' . $r->amount_cents );
		\WP_CLI::line( 'payment_id : ' . ( $r->payment_id ?? '-' ) );
		\WP_CLI::line( 'last_status_code : ' . ( null === $r->last_status_code ? '-' : (string) $r->last_status_code ) );
		\WP_CLI::line( 'created_at : ' . ( $dates['created_at'] ?? '-' ) );
		\WP_CLI::line( 'paid_at : ' . ( $dates['paid_at'] ?? '-' ) );
	}

	/**
	 * Relit l'état chez CAWL des inscriptions non réglées (ou payées, pour constater un remboursement).
	 *
	 * ## OPTIONS
	 *
	 * [--reference=<ref>]
	 * : Ne traiter que cette inscription.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cawl-checkout reconcile
	 *
	 * @param array<int,string>    $args       Arguments positionnels.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function reconcile( array $args, array $assoc_args ): void {
		$reference = isset( $assoc_args['reference'] ) ? (string) $assoc_args['reference'] : null;
		try {
			$changes = ( new Reconciler() )->run( $reference );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( 'Erreur de base de données.' );
		}
		foreach ( $changes as $c ) {
			\WP_CLI::line( sprintf( '%s : %s → %s', $c['reference'], $c['from'], $c['to'] ) );
		}
		\WP_CLI::success( sprintf( '%d changement(s) d\'état.', count( $changes ) ) );
	}

	/**
	 * Rembourse une inscription payée.
	 *
	 * ## OPTIONS
	 *
	 * <reference>
	 * : Référence de l'inscription (état `paid` exigé).
	 *
	 * ## EXAMPLES
	 *
	 *     wp cawl-checkout refund INS-20261021-ABCDEF
	 *
	 * @param array<int,string> $args Arguments positionnels.
	 */
	public function refund( array $args ): void {
		$r = $this->require_registration( (string) $args[0] );
		if ( Registration::PAID !== $r->state ) {
			\WP_CLI::error( sprintf( 'Remboursement impossible : état « %s » (« paid » exigé).', $r->state ) );
		}
		if ( null === $r->payment_id ) {
			\WP_CLI::error( 'Remboursement impossible : identifiant de paiement inconnu.' );
		}
		try {
			$refund_id = Plugin::gateway()->refund( $r->payment_id, $r->amount_cents, 'refund-' . $r->reference );
		} catch ( Gateway_Exception $e ) {
			Log::write(
				'error',
				'remboursement en échec',
				array(
					'reference'  => $r->reference,
					'payment_id' => $r->payment_id,
				)
			);
			\WP_CLI::error( 'Remboursement refusé ou en échec (réseau ou API).' );
		}
		\WP_CLI::line( 'Remboursement : ' . $refund_id );
		\WP_CLI::success( 'Remboursement demandé : lancer `wp cawl-checkout reconcile` pour constater le nouvel état.' );
	}

	/**
	 * Envoie un webhook signé à la route locale.
	 *
	 * ## OPTIONS
	 *
	 * <reference>
	 * : Référence de l'inscription.
	 *
	 * --type=<type>
	 * : Type d'événement (payment.captured, payment.rejected…).
	 *
	 * [--status-code=<n>]
	 * : Code de statut CAWL (obligatoire sans --replay).
	 *
	 * [--amount=<centimes>]
	 * : Montant ; celui de l'inscription par défaut.
	 *
	 * [--event-id=<id>]
	 * : Identifiant d'événement (réutiliser le même simule un doublon).
	 *
	 * [--bad-signature]
	 * : Signature invalide.
	 *
	 * [--replay]
	 * : Reprend code de statut, montant et identifiant de paiement de get_payment( payment_id ).
	 *
	 * ## EXAMPLES
	 *
	 *     wp cawl-checkout simulate-webhook INS-20261021-ABCDEF --type=payment.captured --status-code=9
	 *
	 * @subcommand simulate-webhook
	 *
	 * @param array<int,string>    $args       Arguments positionnels.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function simulate_webhook( array $args, array $assoc_args ): void {
		$r      = $this->require_registration( (string) $args[0] );
		$type   = (string) ( $assoc_args['type'] ?? '' );
		$replay = ! empty( $assoc_args['replay'] );
		if ( '' === $type ) {
			\WP_CLI::error( '--type est obligatoire.' );
		}

		try {
			$code   = self::int_option( $assoc_args, 'status-code' );
			$amount = self::int_option( $assoc_args, 'amount' );
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		$payment_id = null;
		if ( $replay ) {
			if ( null === $r->payment_id ) {
				\WP_CLI::error( '--replay impossible : cette inscription n\'a pas d\'identifiant de paiement.' );
			}
			try {
				$snapshot = Plugin::gateway()->get_payment( $r->payment_id );
			} catch ( Gateway_Exception $e ) {
				\WP_CLI::error( 'Lecture du paiement impossible (réseau ou API).' );
			}
			if ( null === $snapshot->status_code ) {
				\WP_CLI::error( '--replay impossible : le paiement n\'a pas de code de statut.' );
			}
			$code       = $snapshot->status_code;
			$amount     = $snapshot->amount_cents;
			$payment_id = $snapshot->payment_id ?? $r->payment_id;
		}
		if ( null === $code ) {
			\WP_CLI::error( '--status-code est obligatoire (sans --replay).' );
		}
		if ( ! Plugin::config()->is_usable() ) {
			\WP_CLI::error( 'Configuration CAWL incomplète ou hors preprod : la route webhook n\'est pas active.' );
		}

		$simulator = new Webhook_Simulator( Plugin::config() );
		$body      = $simulator->payload( $r, $type, $code, $amount, isset( $assoc_args['event-id'] ) ? (string) $assoc_args['event-id'] : null, $payment_id );
		$response  = $simulator->dispatch( $body, ! empty( $assoc_args['bad-signature'] ) );
		$after     = Plugin::registrations()->find_by_reference( $r->reference );

		\WP_CLI::line( 'statut HTTP : ' . $response->get_status() );
		\WP_CLI::line( 'état : ' . ( null === $after ? '-' : $after->state ) );
	}

	/**
	 * Vide les tables d'inscriptions et d'événements webhook.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Confirmation obligatoire (sans elle, rien n'est supprimé).
	 *
	 * ## EXAMPLES
	 *
	 *     wp cawl-checkout purge --yes
	 *
	 * @param array<int,string>    $args       Arguments positionnels.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function purge( array $args, array $assoc_args ): void {
		if ( empty( $assoc_args['yes'] ) ) {
			\WP_CLI::error( 'Opération destructive : ajouter --yes pour confirmer.' );
		}
		$registrations = Plugin::registrations()->purge();
		$events        = Plugin::events()->purge();
		\WP_CLI::success( sprintf( '%d inscription(s) et %d événement(s) webhook supprimés.', $registrations, $events ) );
	}

	/**
	 * Inscription par référence, ou erreur WP-CLI.
	 *
	 * @param string $reference Référence marchande.
	 */
	private function require_registration( string $reference ): Registration {
		try {
			$r = Plugin::registrations()->find_by_reference( $reference );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( 'Erreur de base de données.' );
		}
		if ( null === $r ) {
			\WP_CLI::error( 'Inscription introuvable.' );
		}
		return $r;
	}
}
