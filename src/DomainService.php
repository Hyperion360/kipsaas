<?php // src/DomainService.php
declare(strict_types=1);

namespace KipSaaS;

/**
 * Custom-domain claims, two modes, decided per claim at add time and read
 * back from the row (cf_hostname_id NOT NULL = CNAME mode). With a CfClient
 * wired, add() registers the hostname with Cloudflare for SaaS and verify()
 * flips the claim live only when Cloudflare reports the hostname AND its
 * certificate active; the certificate issues over HTTP validation, which
 * only succeeds once the claimant's DNS answers, so their DNS is the proof
 * of control. Without a client, or with --manual, the operator confirms
 * DNS by hand and flips the claim (white-glove). Claims stay exclusive
 * registry-wide because the nginx map allows one value per host key.
 */
final class DomainService
{
    public function __construct(private \PDO $pdo, private array $config, private ?CfClient $cf = null) {}

    /**
     * Claim a domain for a tenant. Normalizes (lowercase, trailing dot
     * stripped), enforces hostname grammar, gates on the PLAN's
     * custom_domains flag (a comped pilot on a flagged plan qualifies), and
     * rejects every collision with a reason of its own: the control host,
     * any existing tenant host, our own subdomain space, the base zone
     * apex in CNAME mode (it rides its own zone TLS; claim it --manual), a
     * prior claim. With a client and no --manual, the claim registers the
     * custom hostname BEFORE the row is written, so any refusal leaves no
     * half-state behind.
     *
     * @return array{status: string, domain?: string, cf?: string, error?: string}
     *   ['status' => 'added', 'domain' => normalized, 'cf' => hostname id] in
     *   CNAME mode, ['status' => 'added', 'domain' => normalized] in manual
     *   mode, ['status' => 'error', 'error' => reason] otherwise
     */
    public function add(array $tenant, string $domain, bool $manual = false): array
    {
        $d = strtolower(rtrim($domain, '.'));
        if ($d === '') {
            return $this->err('empty domain');
        }
        if (strlen($d) > 253) {
            return $this->err("{$d} is longer than the 253-character host limit");
        }
        // Labels of letters/digits/dashes, final label letters only: this
        // rejects underscores, slashes, traversal, and single-label hosts.
        if (!preg_match('/^([a-z0-9-]{1,63}\.)+[a-z]{2,}$/', $d)) {
            return $this->err("{$d} is not a hostname like archive.example.com");
        }
        // The plan gate runs before anything touches Cloudflare: a refused
        // claim must not leave a custom-hostname object behind it.
        if (empty($this->config['plans'][$tenant['plan']]['custom_domains'])) {
            return $this->err("plan {$tenant['plan']} does not include custom domains");
        }
        if ($d === (string) ($this->config['nginx']['control_host'] ?? '')) {
            return $this->err("{$d} is the control plane host");
        }
        $q = $this->pdo->prepare('SELECT slug FROM tenants WHERE host = ?');
        $q->execute([$d]);
        if (($slug = $q->fetchColumn()) !== false) {
            return $this->err("{$d} is the host of tenant '{$slug}'");
        }
        $base = (string) ($this->config['base_domain'] ?? '');
        if ($base !== '' && str_ends_with($d, '.' . $base)) {
            return $this->err("{$d} is inside the {$base} subdomain space");
        }
        $q = $this->pdo->prepare('SELECT t.slug FROM tenant_domains d JOIN tenants t ON t.id = d.tenant_id WHERE d.domain = ?');
        $q->execute([$d]);
        if (($slug = $q->fetchColumn()) !== false) {
            return $this->err("{$d} is already claimed by tenant '{$slug}'");
        }
        $cfId = null;
        if ($this->cf !== null && !$manual) {
            if ($d === $base) {
                return $this->err("{$d} is the base zone itself; it rides its own zone TLS, claim it with --manual");
            }
            try {
                $cfId = $this->cf->ensure($d)['id'];
            } catch (\RuntimeException $e) {
                return $this->err($e->getMessage()); // fail closed: no object, no claim
            }
        }
        try {
            // The pre-checks give the operator a precise reason; UNIQUE(domain)
            // is the backstop if two operators race the same domain.
            $this->pdo->prepare('INSERT INTO tenant_domains (tenant_id, domain, cf_hostname_id) VALUES (?, ?, ?)')
                ->execute([(int) $tenant['id'], $d, $cfId]);
        } catch (\PDOException $e) {
            // The race's loser lands here. Whatever object the edge kept
            // belongs to the WINNING claim now, so never advise removing it
            // (the outside-review race finding: the cleanup must distinguish
            // an unclaimed orphan from another tenant's live claim).
            $q = $this->pdo->prepare('SELECT t.slug FROM tenant_domains d JOIN tenants t ON t.id = d.tenant_id WHERE d.domain = ?');
            $q->execute([$d]);
            $winner = $q->fetchColumn();
            return $this->err($winner !== false
                ? "{$d} was claimed by tenant '{$winner}' mid-flight; the cloudflare object is theirs, nothing to clean up"
                : "{$d} was claimed and released mid-flight; retry the add");
        }
        $out = ['status' => 'added', 'domain' => $d];
        if ($cfId !== null) {
            $out['cf'] = $cfId;
        }
        return $out;
    }

    /**
     * Flip a pending claim live. A manual claim (NULL cf_hostname_id) flips
     * on the operator's confirmation; a CNAME-mode claim flips only when
     * Cloudflare reports the hostname and its certificate active. Verifying
     * an already-active domain is a success no-op; an unknown domain errors.
     *
     * @return array{status: string, domain?: string, error?: string}
     */
    public function verify(string $domain): array
    {
        $d = strtolower(rtrim($domain, '.'));
        $q = $this->pdo->prepare('SELECT id, status, cf_hostname_id FROM tenant_domains WHERE domain = ?');
        $q->execute([$d]);
        $row = $q->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return $this->err("unknown domain {$d}");
        }
        if ($row['status'] === 'active') {
            return ['status' => 'verified', 'domain' => $d];
        }
        if ($row['cf_hostname_id'] !== null) {
            if ($this->cf === null) {
                return $this->err("{$d} is a CNAME-mode claim but no cloudflare client is configured; restore the cloudflare config first");
            }
            try {
                $info = $this->cf->get($d);
            } catch (\RuntimeException $e) {
                return $this->err($e->getMessage());
            }
            if ($info === null) {
                return $this->err("cloudflare has no custom hostname for {$d}; remove the claim and re-add it");
            }
            if ($info['status'] !== 'active' || $info['ssl'] !== 'active') {
                return $this->err("{$d} is still pending at cloudflare (hostname: {$info['status']}, certificate: {$info['ssl']}); check the customer's CNAME, then retry");
            }
        }
        $this->pdo->prepare("UPDATE tenant_domains SET status = 'active' WHERE id = ?")->execute([(int) $row['id']]);
        return ['status' => 'verified', 'domain' => $d];
    }

    /**
     * Drop a claim, retiring the Cloudflare object first (an object that
     * outlives its claim keeps an edge certificate alive for a hostname we
     * no longer route). With a client wired, removing a domain nobody
     * claimed is the orphan-cleanup path, still a success. Without one,
     * removing an unclaimed domain stays the old idempotent no-op.
     *
     * @return array{status: string, error?: string}
     */
    public function remove(string $domain): array
    {
        $d = strtolower(rtrim($domain, '.'));
        $q = $this->pdo->prepare('SELECT cf_hostname_id FROM tenant_domains WHERE domain = ?');
        $q->execute([$d]);
        $row = $q->fetch(\PDO::FETCH_ASSOC);
        if ($row !== false && $row['cf_hostname_id'] !== null && $this->cf === null) {
            return $this->err("{$d} is a CNAME-mode claim but no cloudflare client is configured; restore the cloudflare config first");
        }
        if ($this->cf !== null) {
            try {
                $this->cf->delete($d);
            } catch (\RuntimeException $e) {
                return $this->err("cloudflare refused the delete ({$e->getMessage()}); the claim stays, retry domain:remove {$d}");
            }
        }
        $this->pdo->prepare('DELETE FROM tenant_domains WHERE domain = ?')->execute([$d]);
        return ['status' => 'removed'];
    }

    /**
     * The close path: release every claim the tenant holds before the row
     * goes closed (Tenants::setStatus deletes the rows as a backstop, but
     * only this pass retires the Cloudflare objects). Registry release is
     * the safety-relevant act, so a Cloudflare failure on one domain is a
     * warning, not an abort: an object that outlives its claim only ever
     * serves the empty-root notice page, never a tenant. A claim whose
     * automation is no longer wired (config removed after add) surfaces in
     * the same orphan list: its edge object stays unretired and the
     * operator gets the cleanup line instead of silence.
     *
     * @return array{released: int, orphans: string[]} orphans: domains whose edge object stayed
     */
    public function removeForTenant(int $tenantId): array
    {
        $q = $this->pdo->prepare('SELECT domain, cf_hostname_id FROM tenant_domains WHERE tenant_id = ?');
        $q->execute([$tenantId]);
        $rows = $q->fetchAll(\PDO::FETCH_ASSOC);
        $orphans = [];
        foreach ($rows as $row) {
            if ($row['cf_hostname_id'] === null) continue;
            if ($this->cf === null) {
                $orphans[] = (string) $row['domain']; // nothing wired to retire it with
                continue;
            }
            try {
                $this->cf->delete((string) $row['domain']);
            } catch (\RuntimeException $e) {
                $orphans[] = (string) $row['domain'];
            }
        }
        $this->pdo->prepare('DELETE FROM tenant_domains WHERE tenant_id = ?')->execute([$tenantId]);
        return ['released' => count($rows), 'orphans' => $orphans];
    }

    /** Every claim, alphabetical by domain, tenant slug joined in (the domain:list surface). */
    public function list(): array
    {
        return $this->pdo->query('SELECT d.id, d.tenant_id, d.domain, d.status, d.created_at, t.slug
            FROM tenant_domains d JOIN tenants t ON t.id = d.tenant_id ORDER BY d.domain')->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function err(string $error): array
    {
        return ['status' => 'error', 'error' => $error];
    }
}
