# DEPLOY: one VM, start to working SaaS

Answer first: one small VM runs the control plane (your KipSaaS consumer
app), one directory per tenant stamped from your app source, and nginx
routing arbitrary subdomains to those directories through a generated map
file. SQLite everywhere, one php-fpm pool, one unprivileged service user,
cron for convergence and backups. Nothing here needs a Kubernetes cluster,
a queue server, or a managed database. Costs you should expect: the VM
itself, the domain, and Stripe's per-transaction fee. Everything else in
this guide is free software.

This guide is generic: no product names, placeholder domain
`saas.example.com` (control host `control.saas.example.com`), service user
`saas`, tree under `/srv/saas`. Replace those four wherever they appear.
It assumes your control app is a KipSaaS consumer in either mode from the
README quickstart (package mode with the control surface lifted into your
repo, or template mode) and that your product app (the thing tenants get
copies of) is a separate deployable checkout. In template mode running
the shipped demo as the product, the app source is the repo's `demo/`
directory itself: install its vendor locally, then deploy `demo/` to
`/srv/saas/app` with the same rsync command.

## 1. The shape

```
                     DNS: control.saas.example.com  and  *.saas.example.com
                                      |
                  +-------------------v--------------------+
                  |  one VM, Ubuntu 24.04                 |
                  |  nginx                                |
                  |    include /etc/nginx/saas-tenants.map  <- bin/saas map:write
                  |    map $http_host $tenant_root          (atomic, idempotent)
                  |    root $tenant_root                    |
                  |  php-fpm 8.3, ONE pool, user saas       |
                  |  cron: map converge, purge, backup      |
                  +----------------------------------------+
                    /srv/saas/
                      control/    your control app + vendor/ + data/registry.sqlite
                      app/        your product app checkout + vendor/ (code_source)
                      tenants/<slug>/   a complete app install per tenant:
                        config.php app/data.sqlite app/logs.sqlite
                        app/cache.sqlite public/cache public/uploads
                      empty/      static default root for unknown hosts
```

Tenancy is operational, not framework code: a tenant directory is a
complete copy of your app with its own SQLite files and rendered config,
stamped by the reference provisioner from `control/`'s configured
`code_source`. Migrating a tenant off your platform is copying its
directory; it runs standalone.

Isolation honesty, state it to yourself before you sell it: tenants are
isolated at the data layer (separate SQLite databases, separate upload and
cache directories, separate configs at chmod 0600), NOT at the OS layer.
All tenant PHP runs as the one service user under the one FPM pool,
because routing is a map lookup shared by one server block. Per-tenant
Unix users would need one FPM pool per tenant and dynamic pool wiring per
provision, which this design deliberately avoids. If you need OS-level
tenant isolation, this is not your architecture.

## 2. VM bootstrap

Any 1-2 vCPU VM with 2-4 GB RAM and a local disk (SQLite WAL on a network
filesystem risks corruption under concurrent writes; do not put
`/srv/saas` on NFS). Ubuntu 24.04, then as root:

```
apt-get update
apt-get install -y nginx unzip sqlite3 rclone ca-certificates curl gnupg lsb-release

# PHP 8.3 (Ubuntu 24.04 ships 8.3; on older releases use the sury.org apt repo)
apt-get install -y php8.3-fpm php8.3-sqlite3 php8.3-mbstring php8.3-xml php8.3-curl

useradd --system --home /srv/saas --shell /usr/sbin/nologin saas
install -d -o saas -g saas /srv/saas/control /srv/saas/app /srv/saas/tenants /srv/saas/empty/public
printf '<!doctype html><p>Nothing is served at this address.</p>\n' > /srv/saas/empty/public/index.html
chown saas:saas /srv/saas/empty/public/index.html
```

Open inbound 22 (your IP), 80, and 443 only.

## 3. The FPM pool discipline

One pool, running as `saas`. FPM, the cron jobs, and deploys all read and
write the same tree; the Ubuntu default `www-data` would break SQLite WAL
writes in both directions. Write `/etc/php/8.3/fpm/pool.d/saas.conf`:

```
[saas]
user = saas
group = saas
listen = /run/php/php8.3-fpm.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 2
pm.max_spare_servers = 4
```

The listen socket stays www-data-owned so nginx (running as www-data) can
connect while the worker processes run as `saas`. And
`/etc/php/8.3/fpm/conf.d/90-saas.ini`:

```
display_errors = Off
log_errors = On
error_log = /var/log/php-fpm-saas.log
```

## 4. nginx and the map include contract

The contract between nginx and the engine: `bin/saas map:write` publishes
`/etc/nginx/saas-tenants.map`, a COMPLETE
`map $http_host $tenant_root { ... }` block: a default line to the empty
root, the control host routed to the control plane's `public/`, every
active and grace-period tenant routed to its own `public/`, and suspended
hosts routed back to the control plane so it can serve the suspension
notice. The engine writes it atomically (a tmp file then rename, so nginx
never reads a half-written map) and skips the write AND the reload when
the content is unchanged, which is what makes a 10-minute convergence
cron free. Before any reload it runs `nginx -t` and throws rather than
reloading a broken config.

Consequences for your nginx config:

- The include sits at HTTP scope, not inside your server block: the map
  file IS the whole `map { }` directive, and nesting it inside another
  map is invalid nginx.
- Your server block uses `$tenant_root` as its root; the map's default
  line (the empty root) is the answer for every unknown host.
- The map file must be writable by `saas` (FPM runs map writes from the
  webhook path): `touch /etc/nginx/saas-tenants.map && chown saas:saas /etc/nginx/saas-tenants.map`.

Site config `/etc/nginx/sites-available/saas.conf` (TLS terminated by
whichever front you use; a wildcard certificate is required because
tenant hosts are `anything.saas.example.com`):

```
include /etc/nginx/saas-tenants.map;

server {
    listen 80;
    server_name _;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;  # http2 belongs on the listen line: nginx before 1.25.1 (Ubuntu 24.04 ships 1.24) rejects `http2 on;`
    ssl_certificate     /etc/ssl/saas-origin.pem;      # covers *.<domain> + <domain>
    ssl_certificate_key /etc/ssl/saas-origin.key;

    root $tenant_root;
    index index.php index.html;  # index.html: the empty root is static, never PHP

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
        fastcgi_param HTTPS on;  # stock fastcgi_params never passes HTTPS; PHP apps read it for Secure cookie flags
        fastcgi_read_timeout 60;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

Notes on the PHP location: every tenant app is a front-controller shape
(one `index.php`), so SCRIPT_FILENAME pins the file instead of trusting
the request URI, and `$real_path_root` resolves symlinks if your deploy
uses them. The try_files fallback lets each tenant app serve its own
static page cache from `public/cache/` (cookie and query conditions live
in the app's cache layer; nginx cannot express them in try_files).

DNS: one A record for `control.<domain>` and a wildcard A for
`*.<domain>`, both to the VM. If you front the VM with a proxy service,
verify the wildcard proxy and certificate cover one-level subdomains
before onboarding the first tenant, and restrict origin 80/443 to the
proxy's published IP ranges so the real-client header cannot be spoofed
by going around it.

## 5. The two-source deploy

There are exactly two code sources, deployed separately:

1. The control plane: your control app checkout WITH its `vendor/`
   (package mode: your repo with the lifted `public/`, `views/`, `lang/`,
   `bin/saas`; template mode: this repo). It lands in
   `/srv/saas/control`. Server-side state lives beside it and must never
   be overwritten: `config.php` (secrets), `data/` (the registry).
2. The app source: your product app checkout WITH its `vendor/`, landing
   in `/srv/saas/app`. This is the provisioner's `code_source`; tenants
   are stamped from it.

From the machine holding both checkouts:

```
# control plane
rsync -azL --delete \
    --exclude '.git' --exclude 'tests/' --exclude 'docs/' \
    --exclude 'data/' --exclude 'tenants/' --exclude 'config.php' \
    ./control-checkout/ user@vm:/srv/saas/control/

# app source (same exclusion discipline the provisioner enforces:
# no dev runtime state ever ships in a stamp)
rsync -azL --delete \
    --exclude '.git' --exclude 'tests/' --exclude 'docs/' --exclude 'resources/' \
    --exclude 'app/data.sqlite*' --exclude 'app/logs.sqlite*' --exclude 'app/cache.sqlite*' \
    --exclude 'app/backups/' --exclude 'app/mail.log' --exclude 'app/maintenance.lock' \
    --exclude 'app/nav.json' --exclude 'public/cache/' --exclude 'public/uploads/' \
    --exclude 'public/robots.txt' --exclude 'config.php' \
    ./app-checkout/ user@vm:/srv/saas/app/

ssh user@vm 'chown -R saas:saas /srv/saas/control /srv/saas/app'
```

`-L` matters if your dev install used composer path repositories
(vendored symlinks would dangle on the VM; `-L` stamps real files).

Existing tenants are copies and do not update themselves. Refresh them
from the new app source locally on the VM (same excludes, plus never
touch a tenant's config or data), run each tenant's migrations, then
converge the map:

```
ssh user@vm 'for t in /srv/saas/tenants/*/; do
    rsync -a --delete \
      --exclude "config.php" --exclude "app/data.sqlite*" --exclude "app/logs.sqlite*" \
      --exclude "app/cache.sqlite*" --exclude "app/backups/" --exclude "app/maintenance.lock" \
      --exclude "app/mail.log" --exclude "app/nav.json" \
      --exclude "public/cache/" --exclude "public/uploads/" --exclude "public/robots.txt" \
      /srv/saas/app/ "$t"
    (cd "$t" && php bin/kip migrate)
    chown -R saas:saas "$t"
  done'
```

First deploy only: create the VM-side config, once, by hand (never
rsynced; deploy excludes it):

```
ssh user@vm
sudo -u saas cp /srv/saas/control/config.sample.php /srv/saas/control/config.php
sudo -u saas vi /srv/saas/control/config.php
```

Set, at minimum:

- `'env' => 'prod'` and a strong `'token_secret'` (the front controller
  refuses to boot in prod without it)
- `'registry_dsn' => 'sqlite:/srv/saas/control/data/registry.sqlite'`
- `'tenants_root' => '/srv/saas/tenants'` and
  `'code_source' => '/srv/saas/app'`
- `'base_domain' => 'saas.example.com'`,
  `'control_base_url' => 'https://control.saas.example.com'`
- `'tenant_app' => new \App\YourApp(...)` (your implementation)
- `'nginx'` block: the real map path, roots under `/srv/saas`, the
  control host, and `'reload' => true`
- `tenant_smtp` (the self-serve signup path refuses to run without it,
  and provisioned tenants need working mail) and your Stripe keys when
  you go billed
- `'tenant_trusted_proxy' => true` ONLY if a TLS-terminating front (a
  CDN or load balancer) sits in front of nginx AND origin 80/443 is
  restricted to that front's IP ranges; otherwise leave it false, so
  tenant apps do not honor client-spoofable X-Forwarded-* headers

Then:

```
sudo chmod 600 /srv/saas/control/config.php
sudo -u saas env SAAS_CONFIG=/srv/saas/control/config.php \
    php /srv/saas/control/bin/saas map:write
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl enable --now nginx php8.3-fpm
```

## 6. Cron

Install into `/etc/crontab` (these are standing scheduled jobs; that is a
decision, not a default):

```
# Convergence: republish the map every 10 minutes. A no-op when the
# content is unchanged; catches any webhook-path publish failure.
*/10 * * * * saas cd /srv/saas/control && SAAS_CONFIG=/srv/saas/control/config.php php bin/saas map:write

# Suspension and purge sweep, daily 05:00 (republishes the map itself
# when it changes anything)
0 5 * * * saas SAAS_CONFIG=/srv/saas/control/config.php php /srv/saas/control/bin/saas purge:due

# Nightly offsite backup, 04:00 (section 7)
0 4 * * * saas bash /srv/saas/control/server/offsite-backup.sh
```

Add your app's own per-tenant arms the same way, one loop over
`/srv/saas/tenants/*/` (scheduled publishing, digests, log retention,
whatever your product defines in its CLI). Keep every entry running as
`saas` so file ownership stays uniform.

## 7. Backups and the restore drill

What must be backed up: every tenant's databases (plus whatever uploads
and app state your product keeps) and the control registry. Offsite
target: any S3-compatible object store via rclone (Cloudflare R2 and
others have free tiers at this scale; egress is the cost to check).

One-time: `rclone config`, create a remote named `backup` with an access
key from your storage provider. Then write this as
`/srv/saas/control/server/offsite-backup.sh` (the kit ships no server
scripts; this one is yours to own and adapt):

```
#!/usr/bin/env bash
set -euo pipefail
SRV=/srv/saas
STAMP=$(date -u +%Y-%m-%d)

for t in "$SRV"/tenants/*/; do
    slug=$(basename "$t")
    [ -d "$t/app" ] || continue
    # A live WAL database is only safe to copy through sqlite3 .backup
    # (a raw cp tears the file and drops the WAL sidecars). If your app
    # ships a backup CLI arm, prefer it here.
    mkdir -p "$t/app/backups"
    for db in data logs cache; do
        [ -f "$t/app/$db.sqlite" ] && sqlite3 "$t/app/$db.sqlite" ".backup '$t/app/backups/$db-$STAMP.sqlite'"
    done
    rclone copy "$t/app/backups" "backup:saas-backups/$slug" --max-age 25h
done

mkdir -p "$SRV/control/data/snapshots"
sqlite3 "$SRV/control/data/registry.sqlite" ".backup '$SRV/control/data/snapshots/registry-$STAMP.sqlite'"
rclone copy "$SRV/control/data/snapshots" "backup:saas-backups/control/$STAMP"

find "$SRV"/tenants/*/app/backups -type f -mtime +14 -delete 2>/dev/null || true
echo "offsite backup: done ($STAMP)"
```

The restore drill. DO IT ONCE on a scratch target (a second cheap VM or a
local container host; never the live origin) and write the date next to
it in your own runbook: drill not yet run is a backup you are guessing
about.

1. Bootstrap + deploy on the scratch machine (sections 2-5), empty
   registry, no cron.
2. `rclone copy backup:saas-backups/<slug> /tmp/restore` and copy the
   newest `data-*.sqlite` to `/srv/saas/tenants/<slug>/app/data.sqlite`
   (plus logs/cache if you carry them).
3. Registry: `rclone copy backup:saas-backups/control/<date> /tmp/reg`,
   then copy the snapshot to
   `/srv/saas/control/data/registry.sqlite` with FPM stopped (or accept
   the WAL swap on a quiet box).
4. `cd /srv/saas/tenants/<slug> && php bin/kip migrate` (no-op when the
   schema matches; catches a backup older than a migration).
5. `SAAS_CONFIG=... php /srv/saas/control/bin/saas map:write`, reload
   nginx, then curl the tenant URL and the control `/healthz`.

## 8. Go-live smoke matrix

Run all of these before the first real customer; each must pass exactly
once, in this order:

- `curl -sI https://control.<domain>/healthz` returns 200 (control host
  routed through the map).
- An unknown host, `curl -sI https://nope.<domain>/`, returns the empty
  root's static page, not a tenant, not a 5xx (the map default works).
- `sudo -u saas SAAS_CONFIG=... php bin/saas doctor` prints ok on all
  five checks (registry, control host free, code_source, map path,
  webhook secret) and both version lines (kipsaas, kip/framework lock
  refs; put them in your go-live note).
- Provision one test tenant through the manual path
  (`tenant:create` + `provision:tenant`), then
  `curl -s https://<slug>.<domain>/` serves it.
- Stripe smoke (also the only live exercise of the engine's streaming
  HTTP layer, which unit tests do not cover): with test-mode keys in
  config, complete the docs/STRIPE-TESTMODE.md happy path against the
  live control host, including webhook forwarding to the public URL.
  Delete the test tenant afterwards.
- Suspend the test tenant (flip it past_due with an expired grace, or
  wait for the real event) and confirm its host serves the control
  notice page; then let `purge:due` close it and confirm the host falls
  back to the empty root.

## 9. Day-two notes

- `bin/saas tenants:list` is the source of truth for who exists, their
  status, and whether the map routes them.
- A half-built tenant (provisioning died mid-stamp) cleans itself up: the
  provisioner deletes the directory on failure so a retry starts clean.
  If a directory ever survives a failed run, delete it by hand before
  retrying; provisioning refuses to stamp over an existing directory.
- Purge policy is customer-facing: data is destroyed `retention_days`
  (default 30) after cancellation. Closed is terminal; a returning
  customer is a new tenant.
- Per-tenant application logs: `tenants/<slug>/app/logs.sqlite`
  (or your app's equivalent); control-plane PHP errors:
  `/var/log/php-fpm-saas.log`; nginx logs as usual.
- The registry is one SQLite file under WAL: the busy_timeout is already
  set (5000 ms) for FPM-plus-cron contention. If you ever see
  "database is locked" there, the answer is less concurrent CLI, not a
  bigger server.
