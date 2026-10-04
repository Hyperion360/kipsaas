<?php // src/Slug.php
declare(strict_types=1);

namespace KipSaaS;

/**
 * Tenant slug trust boundary: everything downstream (tenant directory path,
 * nginx map host, subdomain) is built from this output, so dots, slashes, and
 * case never survive it. The regex is the firewall; the reserved list keeps
 * infrastructure names out of the tenant namespace.
 */
final class Slug
{
    private const RESERVED = ['www', 'api', 'app', 'admin', 'mail', 'smtp', 'control', 'status',
        'billing', 'help', 'support', 'blog', 'cdn', 'ns1', 'ns2', 'cloud'];

    public static function normalize(string $raw): string
    {
        $s = strtolower(trim($raw));
        // Spaces fold to dashes; every other character outside [a-z0-9-]
        // rejects the input outright. Malformed shapes are never laundered
        // into valid ones: '-bad' must not become 'bad', 'a/b' must not
        // become 'a-b'.
        if (preg_match('/[^a-z0-9\s-]/', $s)) {
            throw new \DomainException('slug may contain only letters, digits, dashes, and spaces');
        }
        $s = (string) preg_replace('/\s+/', '-', $s);
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{1,61}[a-z0-9])?$/', $s) || strlen($s) < 3 || strlen($s) > 63) {
            throw new \DomainException('slug must be 3-63 characters: letters, digits, dashes');
        }
        if (in_array($s, self::RESERVED, true)) {
            throw new \DomainException('that name is reserved');
        }
        return $s;
    }

    public static function hostFor(string $slug, string $baseDomain): string
    {
        return $slug . '.' . $baseDomain;
    }
}
