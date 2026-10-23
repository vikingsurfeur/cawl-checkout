<?php
/**
 * Bootstrap des tests d'intégration : charge WordPress (base du site de test, tables de test dédiées).
 *
 * @package CawlCheckout
 */

// 1. Configuration CAWL factice : jamais de vrais identifiants dans les tests.
putenv( 'CAWL_ENV=preprod' );
putenv( 'CAWL_PSPID=test-pspid' );
putenv( 'CAWL_API_KEY=test-api-key' );
putenv( 'CAWL_API_SECRET=test-api-secret' );
putenv( 'CAWL_WEBHOOK_KEY_ID=test-key-id' );
putenv( 'CAWL_WEBHOOK_SECRET=test-webhook-secret' );

// 2. Hôte HTTP, absent en CLI.
$_SERVER['HTTP_HOST'] = getenv( 'CAWL_CHECKOUT_TEST_HOST' ) ?: 'localhost';

// 3. Tables cawl_checkout_test_* (cf. Schema::table()) : les inscriptions de la campagne restent intactes.
define( 'CAWL_CHECKOUT_TEST_TABLES', true );

// 4. WordPress.
require getenv( 'WP_LOAD_PATH' ) ?: '/var/www/html/wp-load.php';

// 5. Journal des tests hors de debug.log (WordPress vient d'y diriger error_log) : erreurs SQL provoquées
// et lignes [cawl-checkout] des tests n'atteignent pas le journal du site.
ini_set( 'error_log', '/tmp/cawl-checkout-integration.log' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- processus de test uniquement.

// 6. Création ou mise à niveau des tables de test.
\CawlCheckout\Schema::install();
