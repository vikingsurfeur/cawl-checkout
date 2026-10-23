# CAWL Checkout

Extension WordPress pour des **inscriptions payantes réglées sur la page de paiement hébergée par CAWL** (offre
e-commerce du Crédit Agricole, plateforme Worldline Direct). Le visiteur remplit un formulaire sur le site, paie sur la
page CAWL (carte, 3-D Secure, Google Pay…), puis revient sur le site, qui vérifie le paiement auprès de CAWL côté
serveur. Aucune donnée de carte ne transite par le site (niveau PCI DSS SAQ A).

> **État** : validée sur l'environnement de test CAWL (`preprod`). L'extension **refuse de fonctionner hors
> `preprod`** : la mise en production demande les travaux décrits dans le dossier d'étude (hébergement HTTPS public,
> réception réelle des webhooks, compte et clés de production, réconciliation planifiée).

## Prérequis

- WordPress 7.0 ou plus (modèles de bloc enregistrés par une extension) ; thème bloc.
- PHP 8.3 ou plus, avec les extensions `curl`, `json`, `mbstring` et `zlib`.
- Composer, pour installer la bibliothèque officielle `online-payments/sdk-php` (11.2.1, licence MIT).
- Un thème ou un socle qui expose un des filtres CSP décrits plus bas.

## Installation

```bash
# Dans wp-content/plugins/ : une archive de livraison, sans tests ni configuration PHPUnit (.gitattributes)
git archive --format=tar --prefix=cawl-checkout/ HEAD | tar -x -C wp-content/plugins/
cd wp-content/plugins/cawl-checkout
composer install --no-dev --classmap-authoritative
wp plugin activate cawl-checkout
wp cawl-checkout install-pages        # crée /inscription/ et /inscription-statut/
wp cawl-checkout check-config         # complétude des CAWL_* et identifiants webhook reconnus par CAWL
```

Sans `vendor/`, l'extension ne charge rien et affiche un avis aux administrateurs.

## Variables d'environnement `CAWL_*`

Lues par `getenv()` : jamais en base, dans une option, un journal, une sortie WP-CLI ni un message d'erreur. Avec
Docker, les transmettre au conteneur WordPress par la section `environment` de Compose, depuis un `.env` non versionné.

| Variable | Rôle |
|---|---|
| `CAWL_ENV` | Environnement CAWL. Seule valeur acceptée : `preprod` |
| `CAWL_PSPID` | Identifiant marchand (PSPID) du compte CAWL |
| `CAWL_API_KEY` | Clé de l'API (Merchant Portal > Developer > Payment API) |
| `CAWL_API_SECRET` | Secret de l'API, affiché 60 secondes à sa création |
| `CAWL_WEBHOOK_KEY_ID` | Identifiant de la clé des webhooks |
| `CAWL_WEBHOOK_SECRET` | Secret des webhooks (signature HMAC-SHA256 des notifications) |

Si une variable manque, l'extension n'enregistre aucune route et l'affiche aux seuls administrateurs.
`wp cawl-checkout check-config` n'affiche que « oui » ou « non » par variable, jamais une valeur.

## Politique de sécurité du contenu (CSP)

Le formulaire est envoyé au site, qui redirige vers la page de paiement CAWL. Chrome applique `form-action` à la cible
de cette redirection : l'hôte de paiement (`https://payment.preprod.cawl-solutions.fr`) doit donc figurer dans
`form-action`, sinon le navigateur bloque le départ vers CAWL. Aucune autre directive n'est ouverte et les pages de
l'extension n'utilisent aucun JavaScript.

L'extension ajoute cet hôte par l'un de ces filtres, que le thème ou le socle du site doit appliquer en construisant
sa CSP :

- **`wp_test_csp_form_action`** : liste des sources de `form-action`, `array( "'self'" )` par défaut. Exemple côté
  thème :

  ```php
  $sources = apply_filters( 'wp_test_csp_form_action', array( "'self'" ) );
  // Garder seulement 'self' et des hôtes https:// nommés, puis :
  $policy[] = 'form-action ' . implode( ' ', $sources );
  ```

- **`socle_csp_directives`** (socle WordPress) : tableau `directive => sources`. L'hôte n'est ajouté que si
  `form-action` y est déjà déclarée ; créer la directive restreindrait les envois vers le site lui-même.

La redirection vers CAWL n'est autorisée par `wp_safe_redirect` qu'autour de celle du formulaire, jamais pour tout le
site. `Permissions-Policy: payment=()` sur le site reste compatible : Google Pay et Apple Pay s'exécutent sur la page
CAWL.

## Ce que l'extension ajoute

| Élément | Valeur |
|---|---|
| Blocs dynamiques | `cawl-checkout/inscription` (formulaire), `cawl-checkout/statut` (retour de paiement) |
| Modèle de page | `cawl-checkout//inscription-neutre`, sans en-tête ni pied de page |
| Pages (`install-pages`) | `/inscription/` et `/inscription-statut/`, non indexées et non mises en cache |
| Route des webhooks | `POST /wp-json/cawl-checkout/v1/webhook`, signature vérifiée sur le corps brut |
| Options | `cawl_checkout_price_cents` (2500 par défaut), `cawl_checkout_session_minutes` (180, au plus 180) |
| Tables | `{prefix}cawl_checkout_registrations`, `{prefix}cawl_checkout_webhook_events` |
| Références d'inscription | `INS-AAAAMMJJ-` suivi de 12 caractères hexadécimaux |
| Journal | lignes `[cawl-checkout]` dans le journal PHP, sans secret, jeton ni donnée personnelle |

Un dossard, une inscription, un billet : le prix est unique et fixé côté serveur (`settings --price`), en euros.

## Commandes WP-CLI

| Commande | Effet |
|---|---|
| `wp cawl-checkout install-pages` | crée les deux pages si elles manquent |
| `wp cawl-checkout check-config` | complétude des `CAWL_*` et contrôle des identifiants webhook auprès de CAWL |
| `wp cawl-checkout settings [--price=<centimes>] [--session-minutes=<1-180>]` | lit ou modifie prix et durée de session |
| `wp cawl-checkout status <référence>` | état d'une inscription, sans donnée personnelle |
| `wp cawl-checkout reconcile [--reference=<référence>]` | relit l'état chez CAWL des inscriptions non réglées (et des payées, pour constater un remboursement) |
| `wp cawl-checkout refund <référence>` | rembourse une inscription payée |
| `wp cawl-checkout simulate-webhook <référence> --type=<type> [--status-code=<n>] [--amount=<centimes>] [--event-id=<id>] [--bad-signature] [--replay]` | envoie un webhook signé à la route locale |
| `wp cawl-checkout purge --yes` | vide les deux tables |

La réconciliation doit être planifiée en production : à l'expiration d'une session, CAWL ne renvoie pas le visiteur
vers le site, et une session n'est consultable que 3 heures.

## Sécurité du dépôt et de la livraison

- **`.gitignore`** : `vendor/`, caches PHPUnit et fichiers `.env` ne sont jamais versionnés.
- **`.gitattributes`** : `tests/` et `phpunit.xml.dist` sont exclus de `git archive` ; une archive de livraison
  n'embarque ni les tests ni PHPUnit. Installer ensuite les dépendances avec `composer install --no-dev`.
- **`.htaccess`** (Apache) : l'accès web direct répond 403 pour `vendor/`, `tests/`, `.git/`, `composer.json`,
  `composer.lock` et `phpunit.xml(.dist)`, sans tenir compte de la casse. Il repose sur `RedirectMatch` (mod_alias),
  qui ne demande que `AllowOverride FileInfo`, déjà requis par les permaliens. Équivalent Nginx :

  ```nginx
  location ~* /wp-content/plugins/cawl-checkout/(\.git|vendor|tests)(/|$) { return 403; }
  location ~* /wp-content/plugins/cawl-checkout/(composer\.(json|lock)|phpunit\.xml(\.dist)?)$ { return 403; }
  ```

- Les fichiers PHP de `includes/` refusent l'exécution directe hors de WordPress.

## Tests

```bash
composer install
vendor/bin/phpunit --testsuite unit         # sans WordPress
```

Les tests d'intégration chargent WordPress et s'exécutent dans le conteneur du site, extension active, depuis le
dossier de l'extension :

```bash
php vendor/bin/phpunit --testsuite integration --bootstrap tests/bootstrap-integration.php
```

- Ils posent des identifiants CAWL factices et utilisent leurs propres tables (`{prefix}cawl_checkout_test_*`) : les
  inscriptions réelles du site ne sont jamais touchées.
- Leur journal va dans `/tmp/cawl-checkout-integration.log`, pas dans `debug.log`.
- Variables facultatives : `WP_LOAD_PATH` (chemin de `wp-load.php`, `/var/www/html/wp-load.php` par défaut) et
  `CAWL_CHECKOUT_TEST_HOST` (hôte HTTP simulé, `localhost` par défaut).
- Après tout ajout de classe : `composer dump-autoload` (autoload en `classmap`).

## Origine

Extraite de la preuve de concept menée dans le dépôt `wp-test` (branche `worktree-etude-paiement`, commit `4014918`),
validée par une campagne de paiements sur l'environnement de test CAWL. Le dossier d'étude (Word et PDF) et les preuves
de la campagne restent dans ce dépôt d'origine, sous `docs/etude-paiement/`.

Licence : GPL-2.0-or-later.
