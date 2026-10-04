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
        // Path and encoding material rejects outright: a raw dash at either
        // end, or a slash, dot, percent, or backslash anywhere. '-bad' must
        // not become 'bad', 'a/b' must not become 'a-b', '%2e%2e' must not
        // decode into anything. Other punctuation folds into dashes and the
        // folded edge dashes trim away ('Acme Archive!' -> 'acme-archive').
        if ($s === '' || $s[0] === '-' || str_ends_with($s, '-') || preg_match('#[./%\\\\]#', $s)) {
            throw new \DomainException('slug must be 3-63 characters: letters, digits, dashes');
        }
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
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
