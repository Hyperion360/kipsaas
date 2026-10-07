<?php // src/DomainService.php
declare(strict_types=1);

namespace KipSaaS;

/**
 * Manual white-glove custom domains. There is no TXT challenge and no
 * pending TTL: the operator confirms the domain's DNS by hand (their own
 * runbook) and only then flips the claim live. Claims are exclusive
 * registry-wide because the nginx map allows one value per host key.
 */
final class DomainService
{
    public function __construct(private \PDO $pdo, private array $config) {}

    /**
     * Claim a domain for a tenant. Normalizes (lowercase, trailing dot
     * stripped), enforces hostname grammar, gates on the PLAN's
     * custom_domains flag (a comped pilot on a flagged plan qualifies), and
     * rejects every collision with a reason of its own: the control host,
     * any existing tenant host, our own subdomain space, a prior claim.
     *
     * @return array{status: string, domain?: string, error?: string}
     *   ['status' => 'added', 'domain' => normalized] or ['status' => 'error', 'error' => reason]
     */
    public function add(array $tenant, string $domain): array
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
        // The pre-checks give the operator a precise reason; UNIQUE(domain)
        // is the backstop if two operators race the same domain.
        $this->pdo->prepare('INSERT INTO tenant_domains (tenant_id, domain) VALUES (?, ?)')
            ->execute([(int) $tenant['id'], $d]);
        return ['status' => 'added', 'domain' => $d];
    }

    /**
     * Flip a pending claim live once DNS is confirmed. Verifying an
     * already-active domain is a success no-op; an unknown domain errors.
     *
     * @return array{status: string, domain?: string, error?: string}
     */
    public function verify(string $domain): array
    {
        $d = strtolower(rtrim($domain, '.'));
        $q = $this->pdo->prepare("UPDATE tenant_domains SET status = 'active' WHERE domain = ? AND status = 'pending'");
        $q->execute([$d]);
        if ($q->rowCount() === 1) {
            return ['status' => 'verified', 'domain' => $d];
        }
        $q = $this->pdo->prepare('SELECT status FROM tenant_domains WHERE domain = ?');
        $q->execute([$d]);
        if ($q->fetchColumn() === false) {
            return $this->err("unknown domain {$d}");
        }
        return ['status' => 'verified', 'domain' => $d]; // already active
    }

    /** Drop a claim; deleting a domain nobody claimed is fine. */
    public function remove(string $domain): void
    {
        $this->pdo->prepare('DELETE FROM tenant_domains WHERE domain = ?')
            ->execute([strtolower(rtrim($domain, '.'))]);
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
