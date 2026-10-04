# KipSaaS

Open-source SaaS starter kit for the Kip framework: multi-tenant
subscriptions with production-hardened Stripe billing. PHP 8.3,
server-rendered, zero JavaScript required, zero build step, SQLite-first.
MIT licensed, Hyperion360 Inc.

Status: under active development toward v0.1.0. This README is a stub
until the release task replaces it with the full quickstart in both
consumption modes (package mode via composer, template mode via clone).

Note on the framework constraint: composer.json requires
`kip/framework: ^0.5@dev` against the Kip VCS repository because Kip has
no release tags yet. Flipping the constraint to `^0.5.0` is gated on the
Kip v0.5.0 tag and happens in the release task; that flip is the only
change the gate waits on.
