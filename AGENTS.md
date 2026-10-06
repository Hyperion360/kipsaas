# KipSaaS: agent instructions

KipSaaS is an MIT-licensed SaaS starter kit for the Kip framework
(https://github.com/Hyperion360/kip): multi-tenant subscriptions with
production-hardened Stripe billing. PHP 8.3+, server-rendered, zero
JavaScript required, zero build step, SQLite-first. Product of Hyperion360
Inc (https://hyperion360.com/).

Unlike most private-tooling files, this AGENTS.md is COMMITTED and is part
of the product: agent context files are a feature of this repository.

## Repo layout

- `src/`: the engine, namespace `KipSaaS\` (PSR-4 to `src/`): Slug, Tokens,
  RateLimit, Plans, StripeHttp, StripeClient, StripeWebhook, Registry,
  Tenants, Signup, WebhookHandler, Provisioner, MapGen.
- `tests/`: the engine suite, namespace `KipSaaS\Tests\`, PHPUnit 11.
- `public/`, `views/`: the control web app (brand-parameterized).
- `demo/`: the demo SaaS app (a complete stampable mini-install: config, bin/kip, migrations, public front controller, DemoApp adapter).
- `bin/saas`: operator CLI. `bin/release`: changelog fragment release
  script. `changelog.d/`: one fragment per landed task.
- `config.sample.php`: copy to `config.php` (gitignored) and fill in.
- `docs/`: DEPLOY.md, STRIPE-TESTMODE.md, and the implementation plan.

## The engine-vs-demo boundary

- `src/` is generic SaaS machinery: no app-specific code, no customer names,
  paths, or domains. Neutral literals only (`saas.example.test`,
  `/srv/saas/*`, `SAAS_*` env names).
- The demo app is the worked example of consuming the engine: it implements
  the engine's tenant-app interface and binds config. Template-mode users
  replace it; package-mode users never touch it.
- The engine never writes tenant data or consumer app code.

## Porting rule (leak guard)

The engine was ported from a private, reviewed source. Nothing under
`src/`, `tests/`, `bin/`, `demo/`, `public/`, `views/`, `lang/`, or `config.sample.php` may
carry that provenance: no `Cloud\` namespaces, no `CLOUD_` env prefixes,
no `cloud:` log prefixes, no private repo names, private default paths, or
private domains. The guard must return nothing:

    grep -rEi 'Cloud\\|CLOUD_|cloud:|kiption-cloud|cloud\.example\.test' src/ tests/ bin/ demo/ public/ views/ lang/ config.sample.php

## Test commands

    PATH="/opt/homebrew/bin:$PATH" vendor/bin/phpunit

(The default `php` on dev machines may be old; the Homebrew one is
required.)

## Writing style

Operator voice, no marketing. No em dashes anywhere. Specificity over
slogans; real numbers. Banned words: leverage (verb), synergy, empower,
revolutionize, supercharge, delve, seamless, robust. Answer first.

## Releases

Every task lands exactly one `changelog.d/<slug>.md` fragment containing
one type-prefixed line (Added/Changed/Fixed/Security/Deprecated/Removed).
Nobody hand-edits CHANGELOG.md. `bin/release <version>` combines the
fragments, stamps the changelog, and tags, after a human reviews the
printed notes and picks the number. Pushing tags is a separate, explicit
owner step.

## Workflow credit

Development runs on the superskills agent workflow
(https://github.com/ariadoss/superskills): written plan, engineering
review, subagent-driven execution, and a full QA gate per feature.
