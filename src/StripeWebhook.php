<?php // src/StripeWebhook.php
declare(strict_types=1);

namespace KipSaaS;

/**
 * Stripe's signature scheme: Stripe-Signature: t=<unix>,v1=<hex>,... where the
 * MAC input is "<t>.<raw body>". Kip\Webhook::verify covers the plain
 * body-HMAC shape, so this is the Stripe-specific variant of the same idea
 * (raw body, hash_equals, constant-time), plus the timestamp tolerance.
 */
final class StripeWebhook
{
    public static function verify(string $rawBody, string $sigHeader, string $secret, int $toleranceSeconds = 300): bool
    {
        if ($secret === '') return false;
        $t = '';
        $v1s = [];
        foreach (explode(',', $sigHeader) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) continue;
            if ($kv[0] === 't') $t = trim($kv[1]);
            if ($kv[0] === 'v1') $v1s[] = trim($kv[1]);
        }
        if ($t === '' || $v1s === []) return false;
        if (!ctype_digit($t) || abs(time() - (int) $t) > $toleranceSeconds) return false;
        $expected = hash_hmac('sha256', $t . '.' . $rawBody, $secret);
        foreach ($v1s as $v1) {
            if (hash_equals($expected, strtolower($v1))) return true;
        }
        return false;
    }
}
