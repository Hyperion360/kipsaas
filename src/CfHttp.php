<?php // src/CfHttp.php
declare(strict_types=1);

namespace KipSaaS;

interface CfHttp
{
    /** JSON-bodied request, decode JSON, throw CfError on an API error. @return array<string,mixed> */
    public function request(string $method, string $path, ?array $json = null): array;
}

final class CfError extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct("cloudflare {$status}: {$message}");
    }
}

/**
 * Streams implementation, the same zero-dependency choice as
 * StreamStripeHttp. Not unit-tested; exercised in the go-live smoke
 * (CLOUD-OPS 15 in the consuming product). Cloudflare's envelope gates the
 * error path: success=false with any HTTP status is an error, so a 2xx
 * carrying a failure body cannot be mistaken for a good response.
 */
final class StreamCfHttp implements CfHttp
{
    public function __construct(private string $apiToken) {}

    public function request(string $method, string $path, ?array $json = null): array
    {
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => "Authorization: Bearer {$this->apiToken}\r\n"
                . "Content-Type: application/json\r\n",
            'content' => $json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 15,
        ]]);
        $raw = file_get_contents('https://api.cloudflare.com/client/v4' . $path, false, $ctx);
        if ($raw === false) throw new \RuntimeException('cloudflare: request failed');
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int) $m[1];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new \RuntimeException('cloudflare: undecodable response');
        if (empty($data['success'])) {
            $msg = $data['errors'][0]['message'] ?? 'unknown error';
            throw new CfError($status, is_string($msg) ? $msg : 'unknown error');
        }
        return $data;
    }
}
