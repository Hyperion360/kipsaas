<?php // src/Tokens.php
declare(strict_types=1);

namespace KipSaaS;

/**
 * Single-use verification tokens. Only the sha256 of the full token is stored
 * (a registry leak cannot mint links), the signature is constant-time
 * compared, and expiry rides the stored row. Single use comes from the flow:
 * claiming atomically flips the tenant status (a compare-and-swap that admits
 * one winner) and keeps the spent hash on the row so a replayed link resolves
 * to "already claimed" instead of "unknown token".
 */
final class Tokens
{
    /** @return array{token: string, hash: string, expires_at: string} */
    public static function issue(string $secret): array
    {
        $payload = self::b64url(random_bytes(32));
        $token = $payload . '.' . hash_hmac('sha256', $payload, $secret);
        return [
            'token' => $token,
            'hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400),
        ];
    }

    public static function valid(string $given, string $secret, string $storedHash, string $expiresAt): bool
    {
        if (!hash_equals($storedHash, hash('sha256', $given))) return false; // unknown token: fail without leaking timing
        if (strtotime($expiresAt) === false || strtotime($expiresAt) < time()) return false;
        $dot = strrpos($given, '.');
        if ($dot === false || $dot === 0 || $dot === strlen($given) - 1) return false;
        $expected = hash_hmac('sha256', substr($given, 0, $dot), $secret);
        return hash_equals($expected, strtolower(substr($given, $dot + 1)));
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
