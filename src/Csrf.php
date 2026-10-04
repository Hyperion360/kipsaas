<?php // src/Csrf.php
declare(strict_types=1);

namespace KipSaaS;

final class Csrf
{
    public static function token(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    public static function check(?string $given): void
    {
        if (!is_string($given) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $given)) {
            throw new \RuntimeException('csrf check failed');
        }
    }
}
