# STRIPE-TESTMODE: the billed path for $0

Answer first: this walks the whole self-serve flow against Stripe TEST
mode on your machine: test products and prices, local webhook forwarding,
a signup that ends at a provisioned tenant via the 4242 test card, and a
deliberately duplicated webhook that proves the engine's pending-window
retry behavior. No money moves, nothing recurs, and at the end you delete
what you created.

Prerequisite state (from the README template-mode quickstart): the kit
checked out, `composer install` run at the root and in `demo/`,
`config.php` created, `data/` present. You also need a Stripe account in
test mode and the Stripe CLI installed and logged in:

```
stripe login
```

## 1. Test products and prices

With the CLI (dashboard equivalents in parentheses; Developers ->
Product catalog -> Add product, then add a recurring price):

```
stripe products create --name "Standard" --description "2 GB, attribution shown"
# -> id prod_...
stripe prices create --product prod_REPLACE --unit-amount 900 --currency usd --recurring interval=month
# -> id price_...

stripe products create --name "Pro" --description "10 GB, attribution removed"
# -> id prod_...
stripe prices create --product prod_REPLACE --unit-amount 1900 --currency usd --recurring interval=month
# -> id price_...
```

Put the price ids into `config.php`:

```php
'standard' => ['price_id' => 'price_...', 'label' => 'Standard', 'amount_month' => 900, ...],
'pro'      => ['price_id' => 'price_...', 'label' => 'Pro',      'amount_month' => 1900, ...],
```

The `amount_month` values only feed the pricing table's display; Stripe
charges what the price says. Keep them honest anyway.

Then set the secret key: `sk_test_...` from Developers -> API keys,
either in config.php's `stripe_secret` or via the environment
(`export SAAS_STRIPE_SECRET=sk_test_...` before starting the server).

## 2. Mail for the walkthrough

Two mail paths are involved and both must work:

- The CONTROL plane's signup mail (the verification link): keep
  `'mail' => ['transport' => 'log', ...]`. Links land in
  `data/mail.log`; you will grep them out below.
- `tenant_smtp`: the verify route refuses (503) when it is null, because
  a self-serve tenant whose welcome mail cannot send is a support trap,
  and provisioning itself would fail on the send. Point it at any SMTP
  you can actually deliver through. For the walkthrough, a local catcher
  is enough, for example mailpit on port 1025
  (`docker run -p 1025:1025 -p 8025:8025 axllent/mailpit`, web inbox on
  http://localhost:8025, $0), then in config.php:

```php
'tenant_smtp' => ['host' => '127.0.0.1', 'port' => 1025,
    'username' => '', 'password' => '', 'from' => 'noreply@saas.example.test'],
```

The tenant welcome mail then arrives in the catcher; with a null
`tenant_smtp` the manual CLI path prints a one-time password instead,
but the WEB path needs the array set.

## 3. Webhook delivery, locally

The engine's only Stripe write path is `POST /webhooks/stripe` on the
control app, verified by Stripe's timestamped signature (300 s
tolerance) and fail-closed without a secret. Locally the Stripe CLI IS
the endpoint: it signs real test events with a session secret and
forwards them.

Start the control app and the forwarder in two terminals:

```
PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8095 -t public
stripe listen --forward-to 127.0.0.1:8095/webhooks/stripe
```

`stripe listen` prints a fresh signing secret for this session:

```
> Ready! Your webhook signing secret is whsec_... (^C to quit)
```

Put that `whsec_...` into config.php's `stripe_webhook_secret` (or
`export SAAS_STRIPE_WEBHOOK_SECRET=whsec_...` and restart the PHP
server). Note the secret rotates on every `stripe listen` invocation, so
restarts mean a config touch. The workers setting matters only for the
duplicate-event exercise in section 6; the default built-in server is
single-threaded and serializes requests, which would hide the behavior.

For later production use, the dashboard endpoint replaces the forwarder:
Developers -> Webhooks -> Add endpoint, URL
`https://control.<your-domain>/webhooks/stripe`, selecting the four
event types the engine acts on: `checkout.session.completed`,
`invoice.paid`, `invoice.payment_failed`,
`customer.subscription.deleted`. Unknown types are acknowledged rather
than errored, so a send-all-events endpoint also works, just noisier.
The dashboard endpoint's signing secret is fixed per endpoint; put that
one in the production config.

## 4. The 4242 happy path

With the forwarder running:

1. Open http://127.0.0.1:8095/start. The pricing table is rendered from
   your plan catalog. Submit the form: email, site name (it becomes the
   subdomain; 3-63 chars, letters/digits/dashes), plan.
2. The next page says check your email. The link went to the log
   transport:

   ```
   grep -o 'verify?t=[^ ]*' data/mail.log | tail -1
   ```

   Open http://127.0.0.1:8095/verify?t=... in your browser. This
   interstitial exists so a mail scanner prefetching the link cannot
   burn the single-use token; the claim is the button's POST.
3. Press the confirm button. You are redirected to Stripe Checkout
   (test mode banner visible), email pre-filled.
4. Pay with the canonical test card: number `4242 4242 4242 4242`, any
   future expiry, any CVC, any name. Complete the payment.
5. Stripe drops you back at `/billing/return` ("your site is being
   built"). The return page is cosmetic: activation comes ONLY from the
   forwarded `checkout.session.completed` webhook, never from a client
   redirect.
6. Watch the `stripe listen` terminal: it prints the forwarded event.
   A second or two later provisioning finishes (a full copy of `demo/`
   including vendor, plus migrations, seed, and the owner row).

Observe the tenant, from the repo root:

```
php bin/saas tenants:list
# id   slug                     status     host                         plan map
# 1    acme                     active     acme.saas.example.test       standard routed

ls tenants/acme
cat data/saas-tenants.map    # if you pointed map_file at a scratch file
```

The welcome mail ("Your site is ready: ...") is in the mailpit inbox.
The owner account is the signup email; the mail carries a one-time
sign-in password for the first login (change it at /auth/password in the
tenant app on first login). Serve the tenant to believe it:

```
php -S 127.0.0.1:8096 -t tenants/acme/public
```

Cleanup when done: delete the tenant directory and its registry row is
yours to make in test mode (there is no tenant:delete in v1; the real
purge path is the retention sweep). Easiest full reset: stop the server,
delete `data/registry.sqlite*`, `data/saas-tenants.map`, and
`tenants/<slug>`, and start over. Note signup is rate limited (10 per
hour per IP, 5 per hour per email), so use a fresh email and site name
on each pass or expect a "Too many signups" 422.

## 5. What the duplicate-event exercise proves

Stripe retries webhook deliveries that do not return 2xx, and it
re-delivers the SAME event id more than once even on success paths. A
naive handler acks every delivery and can double-provision, or worse:
ack a duplicate of an event whose first processing is still running and
may still FAIL, which converts a recoverable retry into lost work.

The engine's rule: the event id is claimed first (unique insert, status
pending), the action runs, then the claim flips to done. A duplicate
arriving while the first delivery is still pending gets an explicit 500
(telling Stripe: retry later, this may still fail). A duplicate arriving
after completion gets a plain 200 no-op. A failed action deletes its own
claim so the retry reprocesses from scratch. What you should observe:
one provisioned tenant, exactly once, no matter the delivery count.

## 6. The exercise

You will complete a second checkout whose webhook is never auto-forwarded,
then deliver that one event twice, simultaneously, by hand.

1. Stop the forwarder (Ctrl-C the `stripe listen` terminal). Keep the
   PHP server running.

2. Do a second signup end to end (fresh email, fresh site name,
   4242 again) through http://127.0.0.1:8095/start. Checkout works: it
   is a direct API call. The completion event now exists on your Stripe
   account, undelivered, because nothing is forwarding. The pending page
   and `/billing/return` show; the tenant is NOT provisioned. Check the
   registry:

   ```
   php bin/saas tenants:list
   ```

   The second row sits at `pending` (just signed up) and moves to
   `verified` the moment you claim the email token; either way its map
   column reads `-`, and it stays exactly there until a webhook
   activates it. That is the point: no client redirect, only a verified
   Stripe event, moves a tenant toward provisioning.

3. Pull the event as JSON and confirm it is the right one:

   ```
   stripe events list --limit 5
   # take the newest evt_... of type checkout.session.completed
   stripe events retrieve evt_REPLACE > event.json
   grep -o '"client_reference_id": *"[0-9]*"' event.json
   ```

   The number must equal the second tenant's id (the first column of
   `tenants:list`). Do not reformat event.json: the signature below is
   computed over its exact bytes.

4. Deliver it twice, at the same time. Stripe's signature scheme is an
   HMAC of "<t>.<body>" with the webhook secret, and the engine verifies
   exactly that, so you can sign it locally:

   ```
   WHSEC='whsec_REPLACE'   # the secret your stripe listen session printed
   T=$(date +%s)
   BODY="$(cat event.json)"
   SIG=$(php -r 'echo hash_hmac("sha256", $argv[1] . "." . $argv[2], $argv[3]);' "$T" "$BODY" "$WHSEC")

   post() { curl -s -o /dev/null -w '%{http_code}\n' -X POST \
       -H "Stripe-Signature: t=$T,v1=$SIG" \
       -H 'Content-Type: application/json' \
       --data-binary "$BODY" \
       http://127.0.0.1:8095/webhooks/stripe; }
   post & post; wait
   ```

   Expected output: two lines, one `200` and one `500`, in either
   order. The 200 is the delivery that won the unique-claim insert and
   provisioned the tenant (its body is the plain text `ok`). The 500 is
   the loser: it found the event's status still `pending`, threw, and
   served the error page. That 500 is deliberate; in production it is
   what makes Stripe queue a retry instead of recording a delivery that
   might outlive the work it acked.

   If you see two 200s: the second request landed after provisioning
   finished (the pending window is exactly the provisioning run: the
   tree copy plus migrations, seconds for the demo). The built-in server
   serialized your requests because `PHP_CLI_SERVER_WORKERS` was not
   set when it started. Restart it with the workers setting and repeat
   from step 4.

5. Play the retry. The real 500 tells Stripe to redeliver minutes later;
   you do it immediately:

   ```
   post
   ```

   `200`, and nothing changes anywhere: the event's status is `done`,
   the delivery is a no-op. This is the same response Stripe's own retry
   eventually gets.

6. Observe one provisioned tenant:

   ```
   php bin/saas tenants:list
   # exactly one new active, routed row for the second slug
   ls tenants/                    # exactly one new directory
   ```

   Exactly one welcome mail in the mailpit inbox. The map gained one
   host line. Three deliveries, one tenant.

## 7. Teardown

Test mode costs nothing and recurs nothing, but leave a clean slate for
the next run: stop both processes, then delete `event.json`, the
`data/registry.sqlite*` files, the scratch map file, and `tenants/<slug>`
directories as you see fit. The Stripe test products, prices, customers,
and subscriptions can stay in the dashboard's test data or be deleted
there; nothing in the engine references them after the registry is
cleared.
