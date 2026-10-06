# KipSaaS

KipSaaS is an MIT-licensed SaaS starter kit for the Kip framework
(https://github.com/Hyperion360/kip). It gives you the parts every
subscription SaaS needs and nobody wants to write twice: a tenant registry
with a subscription state machine, Stripe checkout and webhooks with
pending-window idempotency, email-verified signup with rate limiting, a
dunning lifecycle (grace, suspend, resume, purge), per-tenant provisioning,
nginx subdomain routing, an operator CLI, and a control web app (pricing,
signup, billing portal, suspension notice). PHP 8.3+, server-rendered, zero
JavaScript required, zero build step, SQLite-first: one small VM runs the
whole thing. You consume it two ways: as a composer package (your app is
your code, engine fixes arrive via `composer update`) or as a clone-and-own
template (this repo IS a working demo SaaS you can strip and rename).
Product of Hyperion360 Inc (https://hyperion360.com/), written and
maintained by Danilo Stern-Sapad (https://danilosapad.com/).

Status: pre-v0.1.0. The engine and the demo are complete; the release tasks
(public repo, Packagist listings, the version tag) are owner-gated and
pending. Commands below run against this checkout as-is.

Note on the framework constraint: composer.json requires
`kip/framework: ^0.5.0`, resolved against Kip's public v0.5.0 tag. The
VCS repository stanza it resolves through is the temporary part: until
Kip is listed on Packagist, every composer.json that requires it (this
one and yours) must carry the stanza, because Composer does not inherit
repositories from dependencies.

## Who this is for and what the market looks like

The paid starter-kit market is overwhelmingly clone-and-own: you buy a
fork, and updates are your problem. Surveyed October 2026:

| Kit | Price | Distribution |
|---|---|---|
| ShipFast (shipfa.st) | $199-249 | clone-and-own fork |
| supastarter (supastarter.dev) | $299-1499 | clone-and-own fork |
| SaaSykit (saasykit.com) | $239-299 | clone-and-own fork |
| MakerKit (makerkit.dev) | $349-649 | clone-and-own fork |
| Larafast (larafast.com) | $169-499 | clone-and-own fork |
| Laravel Spark (spark.laravel.com) | $99-199 | composer package, clean upgrades |
| OpenSaaS (opensaas.sh, Wasp) | free, MIT | clone-and-own, React/Node |

Every PHP kit in that list is Livewire/Filament + Tailwind with a build
step. The three demands we found repeatedly voiced and repeatedly unmet,
with what KipSaaS actually does about them:

| Unmet demand (evidence) | KipSaaS answer |
|---|---|
| Boring, non-React, cheap-to-run stacks. HN on starter kits: "I wish a lot more of these boilerplates didn't go the react route" (https://news.ycombinator.com/item?id=39192304); production SQLite repeatedly requested; every surveyed PHP kit carries a JS build pipeline. | PHP 8.3, server-rendered, zero JavaScript required, zero build step, SQLite-first, one small VM. Honest limit: you must want server-rendered PHP; if you want React, this is not your kit. |
| Genuinely updateable starters. Fork rot is the norm; when we checked (Oct 2026) ShipFast's own page admitted the last update was 8 months earlier. Spark's composer model is the lone widely-shipped counter-example. | Engine-as-package: your app code stays yours, `composer update` pulls engine fixes against tags. The kit's own demo and a production control plane run this engine in package mode. Honest limit: 0.x semver means minor versions may break, loudly, per release notes. |
| Trust: maintained, production-hardened billing. ShipFast's 2024 paywall and email-webhook vulnerabilities (https://kristianfreeman.com/shipfast-vulnerabilities) propagated to every customer who had not patched their fork; no surveyed kit markets dunning. | Billing built from a reviewed production design with the edge cases pinned by tests: pending-window webhook idempotency (a duplicate arriving mid-processing gets a 500 so Stripe retries instead of being told ok for work that may still fail), rollback-on-failure, grace/suspend/resume dunning, forged-signature and traversal rejection. |

Sources for the survey: the product pages above, HN threads
https://news.ycombinator.com/item?id=39192304 and
https://news.ycombinator.com/item?id=37333976, and Kristian Freeman's
writeup linked in the table. Prices are the vendors' listed figures on the
day we checked; verify before you buy anything.

## Quickstart, template mode (clone and own)

You need PHP 8.3+ with pdo_sqlite, composer, and git. Everything runs on
your machine; no infrastructure purchase is involved.

```
git clone https://github.com/Hyperion360/kipsaas.git myapp
cd myapp
composer install            # engine + framework (the VCS stanza is already in composer.json)
composer install --working-dir=demo    # the demo app's own vendor
cp config.sample.php config.php
mkdir -p data
```

Edit `config.php` for local use:

- `'tenant_app' => new \KipSaaS\Demo\DemoApp()` (binds the demo as the app
  being provisioned; the autoload entry for `KipSaaS\Demo\` is already in
  composer.json)
- `'code_source'`: the default `/srv/saas/app` only exists on a server;
  run `export SAAS_CODE_SOURCE="$PWD/demo"` instead of editing, or point
  the key at the absolute path of `demo/`
- `'base_domain' => 'saas.example.test'` (dev default is fine), and
  `'control_base_url' => 'http://127.0.0.1:8095'`
- `'token_secret'`: any long random string in dev (32+ bytes); required in
  prod, the front controller refuses to boot without it
- `'nginx' => ['map_file' => ...]`: point it at a scratch file (for
  example `__DIR__ . '/data/saas-tenants.map'`) so provisioning
  republishes a map you can inspect; `/etc/nginx/` is the prod location
- `'mail'`: keep `transport => log` (signup links land in `data/mail.log`)

Sell a tenant by hand, no Stripe, no web server:

```
php bin/saas tenant:create acme standard owner@example.test 'Acme Notes'
# tenant acme recorded as active (paid manually). Next: saas provision:tenant acme

php bin/saas provision:tenant acme
# provisioned acme at https://acme.saas.example.test
# SMTP is not configured for tenants, so no welcome mail was sent.
# One-time password for owner@example.test: <base64 blob>
# Hand it to the owner over a trusted channel; they change it after first login.

php bin/saas tenants:list
# id   slug   status   host                     plan      map
# 1    acme   active   acme.saas.example.test   standard  routed
```

`provision:tenant` copied `demo/` into `tenants/acme/` (minus the excluded
state files), rendered its `config.php`, ran `bin/kip migrate` and the
demo's seed command inside it, and created the owner row. Look at the
stamped tenant and serve it:

```
ls tenants/acme
cat data/saas-tenants.map
php -S 127.0.0.1:8096 -t tenants/acme/public
```

The login accepts `owner@example.test` and the printed one-time password.

The self-serve path (signup form, email verification, Stripe checkout,
webhook-driven provisioning) runs from the control web app:

```
php -S 127.0.0.1:8095 -t public
```

Open http://127.0.0.1:8095/start. Signing up stops at /verify with a 503
page until `tenant_smtp` and the Stripe test keys are configured. For
the full billed walkthrough in Stripe TEST mode (test products, webhook
forwarding, the 4242 card, and a duplicate-event exercise that proves the
retry behavior), read docs/STRIPE-TESTMODE.md; it covers both settings.

### Build your app here

The demo is the worked example of everything the engine does not do for
you. Three files carry the whole app-specific obligation:

- `demo/src/Demo/DemoApp.php`: implements `KipSaaS\TenantAppInterface`
  (`createOwner()` returns a one-time password for the owner row;
  `seedCommand()` names the CLI arm that seeds a fresh install)
- `demo/migrations/`: your app's schema
- `demo/public/index.php` and views: your app's pages, on `Kip\App`

Template-mode users replace these, delete the demo, and rename. The exact
delete-list when you start your real app:

1. `rm -rf demo/` (the whole directory: config, bin/kip, migrations,
   public, src/Demo, composer.json)
2. In the root `composer.json`, drop the `"KipSaaS\\Demo\\": "demo/src/"`
   autoload entry
3. In your `config.php`, drop `note_cap` from the plan rows (a demo plan
   flag) and put your own plan keys there instead; non-price keys pass
   through to the tenant config under `plan_flags`
4. Set `brand_name` and `brand_url`, write your own `TenantAppInterface`
   implementation, and point `code_source` at your app's checkout

## Quickstart, package mode (require the engine)

This is the updateable mode and how production consumers use the engine.
Your app is your own package/repo; the engine arrives as a dependency.

Create your app's composer.json:

```json
{
    "name": "yourname/yourapp",
    "require": {
        "php": ">=8.3",
        "hyperion360/kipsaas": "*@dev",
        "kip/framework": "^0.5.0"
    },
    "repositories": [
        { "type": "vcs", "url": "https://github.com/Hyperion360/kipsaas" },
        { "type": "vcs", "url": "https://github.com/Hyperion360/kip" }
    ],
    "minimum-stability": "dev",
    "prefer-stable": true,
    "autoload": { "psr-4": { "App\\": "src/" } }
}
```

Resolution honesty, read this before it bites you:

- Composer does NOT inherit `repositories` from a dependency's
  composer.json. `hyperion360/kipsaas` itself requires `kip/framework`,
  so until BOTH packages are listed on Packagist, your root composer.json
  must carry BOTH VCS stanzas. One stanza (just ours) fails resolution
  with "could not find kip/framework in any version".
- `minimum-stability: dev` is required because kipsaas itself has no
  tagged release yet, so it resolves from the `main` branch (`*@dev`).
  `kip/framework ^0.5.0` resolves to the public v0.5.0 tag through its
  stanza; `prefer-stable` keeps you off the dev branch.
- When kipsaas is tagged and both Packagist listings are live, the stanzas
  and the dev stability go away and its constraint becomes `^0.1.0`
  (kip/framework is already `^0.5.0`). That switch is one commit in your
  repo.

Install, then lift the control surface into your app root (it is small,
self-contained, and yours to brand):

```
composer install
cp -R vendor/hyper360/kipsaas/public public
cp -R vendor/hyper360/kipsaas/views views
cp -R vendor/hyper360/kipsaas/lang lang
mkdir -p bin && cp vendor/hyper360/kipsaas/bin/saas bin/saas && chmod +x bin/saas
cp vendor/hyper360/kipsaas/config.sample.php config.php
mkdir -p data
```

No edits are needed for the copies to run: `public/index.php` and
`bin/saas` resolve the autoloader, `views/`, `lang/`, and `config.php`
relative to your app root (`dirname(__DIR__)` from `public/` and `bin/`),
which is exactly where you just put them; the lifted `views/` and `lang/`
are read through config.sample.php's `view_dir`/`lang_dir` defaults, which
point at your app root. One edit is needed before the first page serves:
set `token_secret` in `config.php` (any long random string in dev, 32+
bytes; the front controller refuses to boot without it). `data/` holds
the registry and the log-transport mail. You will edit `views/` and
`lang/en.php` for branding (every UI string lives in the lang pack), and
`config.php` for real values. `composer update` afterwards updates the
engine under your copies; your copies are yours.

Your app then implements the two engine contracts:

- `KipSaaS\TenantAppInterface`: `createOwner(\PDO $tenantDb, array $tenant)`
  inserts the tenant's first admin and returns a one-time password;
  `seedCommand()` names the CLI command that seeds a fresh install.
- `KipSaaS\ProvisionerInterface`: only if you do not want the reference
  provisioner. The bundled `KipSaaS\ReferenceProvisioner` stamps each
  tenant as a copy of your configured `code_source` with its own SQLite
  files and a var_export-rendered config, and binds `tenant_app` from
  config. Most apps use it and never write a provisioner.

Set `'tenant_app' => new \App\YourApp()` in config.php, point
`code_source` at your app's deployable checkout, and the quickstart
commands from template mode (`tenant:create`, `provision:tenant`,
`tenants:list`, `php -S ... -t public`) work identically.

## What you get, split three ways

From the Kip framework (arrives via composer, zero kit code): session auth
with throttled password reset and OAuth (Google, GitHub, Microsoft; PKCE);
a schema-introspecting admin CRUD panel; fixed-window rate limiting
enforced pre-routing; transactional mail over log/mail/smtp with
header-injection protection; S3-compatible uploads; a cron scheduler;
durable background jobs with a worker; a browser-like test client; codegen
scaffolding; OpenAPI generation; VACUUM-INTO backups with retention; HMAC
webhook verification; a static page cache; validated uploads.

The KipSaaS engine (`src/`, namespace `KipSaaS\`):

- Tenant registry: SQLite with WAL, foreign keys, and a 5000 ms
  busy_timeout (FPM and cron share the file). Subscription state machine
  with legal transitions only: pending, verified, active, past_due,
  suspended, closed; 7-day grace and 30-day retention defaults
  (`grace_days`, `retention_days`).
- Plan catalog in config: monthly amounts, Stripe price ids, per-plan
  flags; `powered_by` controls the attribution link, every other non-price
  key reaches the tenant config under `plan_flags`.
- Signup: HMAC-signed single-use email tokens (24 h expiry, only the hash
  stored), a GET interstitial plus CSRF-guarded POST claim so a mail
  scanner prefetching the link cannot burn the token, and dual rate
  buckets: 10 signups/hour per IP and 5/hour per email.
- Stripe over raw HTTPS, no SDK: checkout sessions and billing portal
  (stream context, Bearer auth, form-encoded POSTs). The tenant id
  travels as `client_reference_id` AND subscription metadata so later
  events can find their tenant.
- Webhooks: timestamped-signature verification (300 s tolerance,
  constant-time compare, fail-closed on missing secret or bad signature),
  pending-window idempotency (duplicate while processing throws so Stripe
  retries; duplicate after completion is a no-op 200; a failed action
  unclaims so the retry reprocesses), and unknown-tenant acknowledgement.
- Dunning: `invoice.payment_failed` moves the tenant to past_due with a
  grace clock; expired grace suspends (the app's own maintenance lock
  becomes the suspension screen) and sets a purge date; payment inside
  the window resumes service; `purge:due` closes and deletes.
- Provisioning: `ProvisionerInterface` + `TenantAppInterface` contracts,
  the reference implementation (COPY_EXCLUDES so tenant state never ships
  in a stamp, var_export config render at chmod 0600 with the per-tenant
  mail.log override, escapeshellarg'd migrate + seed, owner creation,
  delete-on-failure so a retry starts clean).
- Routing: nginx host map (`map $http_host $tenant_root`), atomically
  published (tmp + rename), content-idempotent (no write, no reload when
  unchanged), `nginx -t` gates every reload.
- `bin/saas`: `tenants:list`, `tenant:create` (manual invoicing, no
  Stripe needed), `provision:tenant`, `map:write`, `purge:due`,
  `doctor`, `version`.
- Control web app: `/start` (pricing table from the catalog + signup),
  `/start/pending`, `/verify` + `/verify/claim`, `/webhooks/stripe`,
  `/billing/return`, `/billing/portal` (portal link by email),
  `/suspended` plus suspended-host fallthrough, `/healthz`. CSRF on every
  form, hardened session cookies, boot guards (no config or missing
  token_secret in prod is a 500, not a silent hole).

The demo (`demo/`): a notes app, deliberately tiny, because the kit's job
is billing and tenancy, not your product. Users and notes migrations,
framework session auth, note list/create/delete gated on login, and the
per-plan `note_cap` (standard 10, pro 100) consumed from `plan_flags`,
with a seeded starter nav and robots.txt. It is the worked example above,
nothing more.

## Deliberately not in v1

- Teams and multi-seat: one tenant is one customer's app install; seats
  are a v2 module over the registry, not a billing edge case to hack in.
- Usage-based billing: metered Stripe prices need per-tenant usage
  reporting; the plan catalog leaves the keys, v2 wires the plumbing.
- Two-factor auth: it belongs in Kip Auth (the framework), not the
  billing engine; tracked there.
- Admin analytics: the framework's admin CRUD over the registry plus
  `tenants:list` cover operations; a metrics dashboard is not
  billing-critical.

## AI-context files

`AGENTS.md` and `CLAUDE.md` sit at the repo root and are committed: they
are a product feature, not private tooling. A coding agent dropped into
this repo gets from them the repo layout, the engine-vs-demo boundary (the
engine never carries app-specific code; the demo is replaceable), the
porting leak guard, the exact test command, the house writing style, and
the release process (changelog fragments, nobody hand-edits CHANGELOG.md).
`CLAUDE.md` just points at `AGENTS.md` so Claude-specific tooling lands in
the same place.

## Docs

- docs/DEPLOY.md: the one-VM production guide (nginx map include, FPM pool
  discipline, the two-source deploy, cron, backups with a restore drill).
- docs/STRIPE-TESTMODE.md: test products and prices, webhook forwarding,
  the 4242 happy path, and the duplicate-event exercise.

## Developing

```
composer install
PATH="/opt/homebrew/bin:$PATH" vendor/bin/phpunit
```

The suite covers the engine and the control web app over the built-in
server. The leak guard must stay empty (the bracketed character classes
keep the pattern from matching its own documentation, here and in
AGENTS.md):

```
grep -rEi 'Clo[u]d\\|CLO[U]D_|clo[u]d:|kiption-clou[d]|clou[d]\.example\.test' src/ tests/ bin/ demo/ public/ views/ lang/ config.sample.php docs/ AGENTS.md CLAUDE.md README.md
```

Releases: every task lands one `changelog.d/<slug>.md` fragment;
`bin/release <version>` compiles, stamps, and tags after a human reviews
the printed notes. Pushing tags is a separate, explicit owner step.

## License and credits

MIT, Hyperion360 Inc. See LICENSE.

Development runs on the superskills agent workflow
(https://github.com/ariadoss/superskills): written plan, engineering
review, subagent-driven execution, and a full QA gate per feature.
