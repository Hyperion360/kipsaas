<?php // src/RateLimit.php
declare(strict_types=1);

namespace KipSaaS;

/** Fixed-window limiter over the registry's rate_limits table. */
final class RateLimit
{
    public static function hit(\PDO $pdo, string $bucket, int $max, int $windowSeconds): bool
    {
        $now = time();
        $q = $pdo->prepare('INSERT INTO rate_limits (bucket, hits, window_start) VALUES (?, 1, ?)
            ON CONFLICT(bucket) DO UPDATE SET
                hits = CASE WHEN window_start < ? THEN 1 ELSE hits + 1 END,
                window_start = CASE WHEN window_start < ? THEN ? ELSE window_start END');
        $q->execute([$bucket, $now, $now - $windowSeconds, $now - $windowSeconds, $now]);
        $hits = (int) $pdo->query('SELECT hits FROM rate_limits WHERE bucket = ' . $pdo->quote($bucket))->fetchColumn();
        return $hits <= $max;
    }

    /** Test hook only: pretend the window elapsed. */
    public static function rewind(\PDO $pdo, string $bucket): void
    {
        $pdo->prepare('UPDATE rate_limits SET window_start = 0 WHERE bucket = ?')->execute([$bucket]);
    }
}
