# Changelog

## [Unreleased]

## [0.2.0] - 2026-10-26

### Added
- src/CfClient.php, the custom-hostnames API client (ensure/get/delete by hostname, fallback origin) behind the CfHttp seam, with the scripted RecordingCfHttp test double covering every payload and idempotency rule offline
- src/CfHttp.php, the seam the custom-domains CNAME automation builds on (CfHttp interface, CfError, StreamCfHttp streams client for the Cloudflare client/v4 API), the same zero-dependency shape as StripeHttp
- automated custom domains through Cloudflare for SaaS: a CfHttp/CfClient seam (custom hostnames ensure/get/delete plus fallback origin), CNAME-mode claims that register on add and flip live only when Cloudflare reports the hostname and certificate active, manual claims preserved via cf_hostname_id NULL and domain:add --manual, domain:fallback for the one-time zone setup, and close-path release that retires edge objects and reports refusals as warnings
- automated custom domains through Cloudflare for SaaS: an add with the cloudflare client wired registers the custom hostname before the claim row is written and fails closed on any refusal, verify flips a claim live only when Cloudflare reports both the hostname and its certificate active, remove retires the edge object before dropping the row, and closing a tenant releases every claim while surfacing edge objects it could not retire as orphans
- .claude/skills/kip/SKILL.md, the Kip/KipSaaS contract for coding agents (hard constraints, gate commands, port rules, writing style, phase protocol, ledger locations); the framework repo carries an identical copy and the two are kept in sync by hand
- the registry carries cf_hostname_id on tenant_domains, the Cloudflare custom hostname behind a claim in CNAME mode, NULL for a manual claim; the column rides the CREATE TABLE for fresh registries and a guarded ALTER on open for every database that already exists, since CREATE IF NOT EXISTS cannot evolve a live table

### Changed
- hyperion360/kipsaas and kip/framework are listed on Packagist and the README reflects it: the package-mode composer block drops the two VCS repository stanzas (verified by a clean-project composer require installing kipsaas v0.1.4 and kip/framework v0.5.0 with no repositories configuration), the framework-constraint note and resolution-honesty section record the stanza era as retired, the quickstart constraint moves to ^0.1.4, and the status paragraph no longer names the listings as a pending gate

### Fixed
- the kit's composer.json defines the check script (vendor/bin/phpunit), so the full-gate command the kip agent skill names runs the suite in both repos instead of prefix-matching Composer's check-platform-reqs and exiting green without any tests
- the kit's own composer.json drops the kip VCS repository stanza and the minimum-stability dev / prefer-stable pair, whose documented revisit trigger (the framework's Packagist listing) is now live; resolution is unchanged (lock hash refreshed, nothing to modify, suite green)
- the control plane's suspension notice recognizes suspended tenants' custom domains through the same lookup as their primary hosts, so a mapped custom domain answers the notice instead of the control plane's default page

## [0.1.4] - 2026-10-20

### Added
- a Built for AI-agent development section in the README that replaces the bare AI-context-files note: the committed AGENTS.md/CLAUDE.md context, the CLI-first operator surface and lang packs that let an agent act without scraping HTML, and the superskills workflow (written plan, engineering review, one subagent per task, QA gate) as the pipeline that built the kit
- the reference provisioner asserts stamp integrity before kip migrate consumes a stamp (every app/Features directory and every migration file the code source has must be in the stamp; on drift provisioning aborts and removes the half-built tenant, naming the lost feature), and DEPLOY.md gains the same feature-set and migration-count check for operators to run against the deployed app artifact and inside the per-tenant refresh loop

### Changed
- composer.json carries the public-listing metadata (keywords, homepage, issue-tracker and source support links, maintainer author), validated by composer validate with the lock file verified still in sync
- the README matches the released repo: the status paragraph states the repo is public with CHANGELOG.md as the version record and names the two Packagist listings as the one remaining owner-gated task (was: pre-v0.1.0), the package-mode quickstart requires the tagged releases (^0.1.3) instead of *@dev with minimum-stability dev (both were only correct before the first tag), and the lift instructions copy from vendor/hyperion360 (was: vendor/hyper360, which does not exist)

### Fixed
- the README's ShipFast-vulnerabilities citation points at the writeup's live URL under /blog/ (the old link 404'd), keeping the trust claim in the market table checkable

## [0.1.3] - 2026-10-07

### Added
- suite coverage for the domain CLI on the real command surface (claim with the exact CNAME line, pending stays unrouted, verify routes in the same run, remove), the plan-gate refusal for Standard, and proof that purge:due releases a closed tenant's domain claim.
- bin/status-probe, a standalone health probe (cron every 5 minutes) that checks each active tenant host plus the control plane, keeps the per-host log in an operator-only jsonl beside status_file under one exclusive flock (30-day prune, torn-line tolerant, idempotent re-runs), and rewrites the public aggregate with counters only, so the /status page never reads or renders a host name.
- view_dir now resolves every control template override-first (the layout or any single page) and falls back to the package's own views, mirroring the framework's skin seam, so an operator overrides one page without lifting the whole directory.

### Fixed
- the default pack publishes the 30-day windows the product decision locks (full refund in the first 30 days; site data kept 30 days after closure before deletion), replacing the stray 14-day copy, and the neutrality guard now also bans the fiction-archive vocabulary so audience positioning cannot re-enter the kit pack.

## [0.1.2] - 2026-10-07

### Added
- manual white-glove custom domains (tenant_domains registry table, DomainService claim/verify workflow, domain:add/verify/remove/list CLI arms, nginx map emission for live and suspended tenants, domain release on close) and the plan column is now an editable tenant field
- the control app's trust surface: terms, privacy, acceptable-use, refund, and migration pages plus the aggregate /status board (state, 30-day uptime, checks, last incident; a missing or invalid aggregate is a friendly no-data page at 200, and host names never render because a host is a customer slug), footer links to all of them with a support line, a custom-domain pricing-table row keyed on a per-plan flag, refund and export notes plus a migration callout on the signup page, the status_file config key, and the neutral lang pack strings behind every new line of copy.

## [0.1.1] - 2026-10-07

### Added
- a GitHub Actions workflow running the full PHPUnit suite on PHP 8.3 and 8.4 for every push and pull request (free on public repositories).
- the demo ships a change-password flow at /auth/password (auth-gated GET form, POST verifies the current password and writes the new hash in one transaction; the session epoch rides the new hash so this session stays signed in and other sessions are revoked), and the README and STRIPE-TESTMODE hedge that said the demo password is standing now points at the form.
- saas prune (deletes webhook event rows past their 90-day audit window and rate-limit rows whose window started more than a day ago), a prunable-rows line in doctor output, and the weekly prune line in DEPLOY.md's cron set.

### Changed
- the tenants:list transcripts in README and docs/STRIPE-TESTMODE.md show the command's real column layout, captured from an actual run against a scratch registry.
- the /verify 503 page now names the exact tenant_smtp config keys to set (host, port, username, password, from) and points at docs/STRIPE-TESTMODE.md, instead of only telling the reader to email the operator.

### Fixed
- the claim is a single compare-and-swap (pending to verified, one winner), so a double-submitted verify link can no longer open two Stripe checkout sessions; a replayed link now gets a clean 303 to /start/pending instead of a 403, and the Stripe HTTP transport is bindable in config for tests.
- when nginx -t rejects a freshly published map, MapGen now restores the previous map file (the old bytes are kept in memory before the rename) before throwing, so a failed reload never leaves a map nginx cannot load live.
- the per-email signup bucket now burns only after input validation passes (the IP bucket stays first), so invalid submissions can no longer lock a real address out of signup.

## [0.1.0] - 2026-10-06

### Added
- nginx map generator (control-host line, active and grace routing, suspended-to-notice routing, atomic content-idempotent publish with reload guard) and the bin/saas operator CLI: tenants:list, tenant:create (manual invoicing, verified to active without Stripe), provision:tenant (prints the one-time password when mail was not sent), map:write, purge:due (republishes the map when it changes anything), doctor (registry, code_source, map path, webhook secret in prod), and version (kipsaas + framework lock ref from packages and packages-dev, with git-ref, path-repo, and tag-pin renderings)
- KipSaaS\ControlApp entry seam so embedding repos run the control web app over their own config (boot() keeps the SAAS_CONFIG front door, run() takes the config array; public/index.php is a two-call wrapper), a lang_dir config key pointing the string loader at an operator-owned pack, and manual-only plans (empty price_id) hidden from the pricing page and refused at signup
- control web app: public/index.php front controller (parameterized pricing from the plan catalog, signup with pending redirect, mail-scanner-safe verify interstitial plus CSRF-guarded claim POST, fail-closed signed Stripe webhook route, billing return, portal-by-email, suspension notice with suspended-host fallthrough, healthz, session cookie hardening, boot guards) with Csrf helpers, zero-JS views, lang/en.php for every UI string, brand_name and brand_url from config, and an HTTP smoke test over the built-in server covering signup, forged-token claim, unsigned-webhook 403, signed-webhook provisioning through a recording double, map publish, and the suspended-host notice
- demo/ mini-install, the complete stampable app the provisioner copies per tenant: demo/config.php source config (sqlite under demo/app/, log mail transport, brand strings), demo/bin/kip (migrate via Kip\Migrations\Migrator plus db:seed-demo writing app/nav.json and public/robots.txt, both COPY_EXCLUDES entries), migrations 001_users (users with email/password_hash/penname/role/is_admin plus the login_attempts table Kip\Auth needs) and 002_notes, demo/public/index.php front controller on Kip\App with framework Auth login/logout and #[Auth]-gated notes list/create/delete enforcing the plan note_cap from the rendered plan_flags, demo/src/Demo/DemoApp.php TenantAppInterface adapter, demo/composer.json requiring kip/framework from the VCS repo, kit composer autoload for KipSaaS\Demo, config.sample tenant_app comment and note_cap 10/100 plan rows, and DemoEndToEndTest driving tenant:create + provision:tenant against demo/ with an OTP login through the stamped tenant's own front controller (built-in server), note creation, and the free cap refusing the 11th note
- full README (what it is, the market-position table with sources, quickstarts in both consumption modes including the pre-Packagist resolution honesty and the template-mode delete-list, the three-way feature inventory, the explicit v1 exclusions, the AI-context files), docs/DEPLOY.md (generic one-VM guide: the nginx map include contract, the single-user FPM pool discipline, the two-source deploy with per-tenant refresh, the cron set, the offsite backup script with a restore drill, and a go-live smoke matrix), and docs/STRIPE-TESTMODE.md (test products and prices, local webhook forwarding, the 4242 happy path to a provisioned tenant, and the deliberately duplicated-event exercise proving the pending-window retry)
- saas doctor now fails when a tenant row squats the configured control host, catching the duplicate nginx map key before it surfaces as a failed reload.
- engine state and lifecycle ported from the reviewed engine: Registry schema (events pending/done, busy_timeout), Tenants repository (guarded transitions, event claims, host and due-date queries), rate-limited Signup with mail closure, and WebhookHandler with pending-window idempotency, map-safe publish, resume-inside-retention, and unknown-tenant acknowledgement, plus the ProvisionerInterface seam
- engine trust primitives ported from the reviewed engine under KipSaaS: Slug (stricter than the plan draft: malformed input rejects instead of laundering), Tokens, RateLimit, Plans, the StripeHttp seam, StripeClient, and StripeWebhook signature verification
- package skeleton: composer package hyperion360/kipsaas (MIT, kip/framework dependency, PHPUnit 11), committed agent-context files (AGENTS.md, CLAUDE.md), config sample with SAAS_* env names, and the changelog-fragment release script
- provisioner contract and reference implementation: TenantAppInterface (createOwner + seedCommand, injected so the kit carries no app knowledge) and ReferenceProvisioner stamps each tenant as a complete copy of code_source with COPY_EXCLUDES, var_export-rendered config (chmod 0600, per-tenant mail.log), escapeshellarg'd migrate + seed, cleanup-on-failure, maintenance-lock suspend/resume, recursive purge, and SMTP-or-one-time-password onboarding
- partial registry indexes for the token, email, subscription, and dunning sweep lookups plus the map queries, with an EXPLAIN QUERY PLAN test that fails any future full scan or temp b-tree; existing registries converge via CREATE INDEX IF NOT EXISTS on next open.

### Changed
- DEPLOY.md's doctor line says five checks and lists control host free with the original four, matching the command's actual output
- README's framework-constraint note now states the current truth (kip/framework ^0.5.0 against the public v0.5.0 tag; the VCS stanza is the temporary part pre-Packagist), and the package-mode quickstart sample and resolution bullets no longer say ^0.5@dev awaiting a tag
- the one-time-password handoff no longer claims owners change it after first login; the demo ships no change-password UI, so the README and the CLI print now say it stays the password until the app offers a change or reset flow, matching STRIPE-TESTMODE's hedge
- README's package-mode quickstart adds the missing mkdir -p data step and says to set token_secret before the first page serves, and notes that the lifted views/ and lang/ are read through config.sample.php's view_dir/lang_dir defaults
- README's quickstart says outright that signing up stops at /verify with a 503 until tenant_smtp and the Stripe test keys are set, pointing at docs/STRIPE-TESTMODE.md for both, instead of dead-ending after the signup mention
- framework requirement re-pinned from the dev branch to the v0.5.0 tag
- rendered tenant configs get trusted_proxy from the new tenant_trusted_proxy config key (default false) instead of an unconditional true, so bare-nginx deploys do not hand tenant visitors a spoofable X-Forwarded-For past per-IP throttles.

### Fixed
- a missing or non-array lang pack is a clean 500 instead of an uncatchable require fatal (healthz stays up), and the public billing-portal form is rate limited (10 per hour per IP) so it cannot be used as a mail-bomb or Stripe-quota faucet.
- DEPLOY.md corrections from the QA pass: the 443 server block uses the listen-line http2 form (nginx 1.24 on Ubuntu 24.04 rejects `http2 on;`), the PHP location passes fastcgi_param HTTPS so PHP apps can set Secure cookie flags on the documented topology, and tenant_trusted_proxy is documented as an opt-in for proxied fronts; the README leak-guard command now matches AGENTS.md's full path list, and the last em dashes in committed docs are gone.
- the nginx map now routes every tenant under the top-level tenants_root (the single source of truth, matching config.sample.php and the deploy guide); previously the engine read tenants_root from the nginx block only, so a config shaped like the shipped sample rendered tenant lines with a half-empty root plus a PHP warning, and a production reload could publish the broken map. MapGen now fails loudly when tenants_root is absent, and the CLI, web-smoke, and demo e2e fixtures use the sample's nginx-block shape so the suite pins the shipped config
- package-mode boot: public/index.php now passes the app-root config.php to ControlApp::boot() (SAAS_CONFIG still wins when set); with no argument boot() resolved config at the vendored package directory, so a lifted app ran on the package checkout's own config instead of its own
- the stamp aborts and rolls back when copy() or mkdir() fails (permissions, disk full) instead of finishing a tenant with files missing, and suspend/resume/purge revalidate the slug through Slug::normalize before building any path, closing the same defence-in-depth provision() already had.
- ReferenceProvisioner copySkeleton now dereferences symlinked directory trees into real files (a composer path-repo vendor stamps as a working vendored install instead of an empty dir), guards against symlink cycles, excludes nested .git/.gstack/.claude at any depth instead of only at the repo root, and creates the tenant root before iteration so a root-level file cannot race the first mkdir; renderConfig additionally passes every non-price, non-powered_by plan row key (note_cap and friends) into the tenant config under plan_flags so tenant apps can enforce plan quotas
- the provisioner's COPY_EXCLUDES also excludes app/data.sqlite-wal and app/data.sqlite-shm, so a code_source stamped from a live WAL-mode checkout no longer ships the template's journal sidecars (the match is exact-or-dir-prefix, so the sidecar names were falling through)
- the control app no longer starts a session on /healthz or /webhooks/stripe (no session file or Set-Cookie per machine request), and the session cookie's Secure flag now follows the configured control_base_url scheme, which nginx's stock fastcgi_params never signals otherwise.
- SMTP-mode provisioning now mails the one-time sign-in password in the welcome mail, and /verify/claim carries the same no-SMTP 503 gate as the interstitial, so no self-serve path can end at a tenant whose owner password nobody knows.
- ControlApp::run() honors a view_dir config key (the control layout directory), so package mode's lifted views/ copy actually renders; lang_dir existed already and both keys are now documented in config.sample.php defaulting to __DIR__-relative paths, which is the package-mode lift location and the same directory as the package default in template mode
- webhook state transitions now run the provisioner side effect BEFORE the registry status flip, so Stripe's retry after a failed provision/suspend/resume re-enters the whole transition instead of acking a half-applied row (paid-but-never-provisioned tenant).
