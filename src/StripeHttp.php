<?php // src/StripeHttp.php
declare(strict_types=1);

namespace KipSaaS;

interface StripeHttp
{
    /** POST form-encoded, decode JSON, throw StripeError on >= 400. @return array<string,mixed> */
    public function post(string $path, array $form): array;
}

final class StripeError extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct("stripe {$status}: {$message}");
    }
}

/** Streams implementation: zero dependencies, same choice as Kip's Mailer/S3. Not unit-tested; exercised in the go-live smoke (docs/DEPLOY.md). */
final class StreamStripeHttp implements StripeHttp
{
    public function __construct(private string $secretKey) {}

    public function post(string $path, array $form): array
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer {$this->secretKey}\r\n"
                . "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($form),
            'ignore_errors' => true,
            'timeout' => 15,
        ]]);
        $raw = file_get_contents('https://api.stripe.com' . $path, false, $ctx);
        if ($raw === false) throw new \RuntimeException('stripe: request failed');
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int) $m[1];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new \RuntimeException('stripe: undecodable response');
        if ($status >= 400) {
            throw new StripeError($status, (string) ($data['error']['message'] ?? 'unknown error'));
        }
        return $data;
    }
}
