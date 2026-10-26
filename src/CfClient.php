<?php // src/CfClient.php
declare(strict_types=1);

namespace KipSaaS;

/**
 * The only place that speaks Cloudflare's custom-hostnames API (Cloudflare
 * for SaaS). Everything is looked up by hostname: the registry stores the
 * id for show, delete works from the hostname alone, so an object left
 * behind by a crashed add is removable without knowing its id.
 */
final class CfClient
{
    public function __construct(private CfHttp $http, private string $zoneId) {}

    /**
     * The custom hostname for $hostname, creating it when the zone does not
     * have one and adopting the existing object when it does (a re-add
     * after a registry hiccup must not wedge on a duplicate). Certificates
     * validate over HTTP, which only succeeds once the customer's CNAME
     * answers, so creation alone proves nothing about DNS control.
     * @return array{id: string, status: string, ssl: string}
     */
    public function ensure(string $hostname): array
    {
        $existing = $this->get($hostname);
        if ($existing !== null) return $existing;
        $r = $this->http->request('POST', "/zones/{$this->zoneId}/custom_hostnames", [
            'hostname' => $hostname,
            'ssl' => ['method' => 'http', 'type' => 'dv'],
        ]);
        $created = $this->shape(is_array($r['result'] ?? null) ? $r['result'] : []);
        if ($created['id'] === '') {
            // success:true without an id is a malformed envelope; an empty id
            // would mark the claim automated with nothing behind it
            throw new \RuntimeException('cloudflare: create returned no custom hostname id');
        }
        return $created;
    }

    /** @return array{id: string, status: string, ssl: string}|null null when the zone has no such custom hostname */
    public function get(string $hostname): ?array
    {
        $r = $this->http->request('GET', '/zones/' . $this->zoneId . '/custom_hostnames?hostname=' . rawurlencode($hostname));
        $rows = $r['result'] ?? [];
        if (!is_array($rows) || $rows === []) return null;
        $row = $this->shape(is_array($rows[0] ?? null) ? $rows[0] : []);
        if ($row['id'] === '') {
            // a success list whose first row carries no id is a malformed
            // envelope; adopting it would store an empty id as an automated
            // claim, and delete would fire a DELETE with nothing behind it
            throw new \RuntimeException('cloudflare: malformed custom hostname row for ' . $hostname);
        }
        return $row;
    }

    /** Idempotent: deleting a hostname the zone does not have is a success no-op. */
    public function delete(string $hostname): void
    {
        $row = $this->get($hostname);
        if ($row === null) return;
        $this->http->request('DELETE', "/zones/{$this->zoneId}/custom_hostnames/{$row['id']}");
    }

    /** One-time zone setup: designate the proxied record custom hostnames route to. @return array<string,mixed> */
    public function setFallbackOrigin(string $origin): array
    {
        return $this->http->request('PUT', "/zones/{$this->zoneId}/custom_hostnames/fallback_origin", ['origin' => $origin]);
    }

    /** @param array<string,mixed> $row @return array{id: string, status: string, ssl: string} */
    private function shape(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'ssl' => (string) ($row['ssl']['status'] ?? ''),
        ];
    }
}
