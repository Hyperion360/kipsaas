<?php // src/Version.php
declare(strict_types=1);

namespace KipSaaS;

/**
 * Version self-knowledge: what a support report needs to name the whole
 * stack. The framework pin comes from composer.lock scanning BOTH packages
 * and packages-dev (a dev-only pin is still the code you run), rendered by
 * entry type: a git dev pin prints version + first 12 of the reference
 * (dev-main@2979b924372e style), a path repository prints version + path
 * (the path-repo convention has no git reference to show), a tag pin prints
 * the tag.
 */
final class Version
{
    /** Bumped by the release process; before the first tag this is a dev marker. */
    public const VERSION = '0.1.0-dev';

    public static function lockedRef(string $lockPath, string $package): ?string
    {
        if (!is_file($lockPath)) return null;
        $lock = json_decode((string) file_get_contents($lockPath), true);
        if (!is_array($lock)) return null;
        $entries = array_merge(is_array($lock['packages'] ?? null) ? $lock['packages'] : [],
            is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : []);
        foreach ($entries as $p) {
            if (($p['name'] ?? '') !== $package) continue;
            $v = (string) ($p['version'] ?? 'unknown');
            if (($p['dist']['type'] ?? '') === 'path') {
                return $v . ' (' . (string) ($p['dist']['url'] ?? 'path') . ')';
            }
            $ref = $p['source']['reference'] ?? $p['dist']['reference'] ?? null;
            if (str_starts_with($v, 'dev-') && is_string($ref) && $ref !== '') {
                return $v . '@' . substr($ref, 0, 12);
            }
            return $v; // a tag pin: the version IS the tag
        }
        return null;
    }

    /**
     * The kit's own identity: the composer.lock entry in package mode
     * (hyperion360/kipsaas as a dependency), the git ref in template mode
     * (this repo is the root package and the lock carries no self entry).
     */
    public static function kipsaas(string $rootDir): string
    {
        $locked = self::lockedRef($rootDir . '/composer.lock', 'hyperion360/kipsaas');
        if ($locked !== null) return $locked;
        exec('git -C ' . escapeshellarg($rootDir) . ' describe --tags --always --dirty 2>/dev/null', $out, $code);
        return $code === 0 && $out !== [] ? self::VERSION . ' (' . trim($out[0]) . ')' : self::VERSION;
    }
}
