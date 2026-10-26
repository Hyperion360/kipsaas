<?php // tests/RecordingCfHttp.php
declare(strict_types=1);

namespace KipSaaS\Tests;

use KipSaaS\CfError;
use KipSaaS\CfHttp;

/**
 * Scripts the custom-hostnames API for the engine suite: responses queue in
 * call order (a CfError entry throws when its turn comes), every request is
 * recorded, and an empty queue refuses the request so an unplanned API call
 * fails the test loudly instead of passing silently.
 */
final class RecordingCfHttp implements CfHttp
{
    /** @var list<array{method: string, path: string, json: array|null}> */
    public array $calls = [];

    /** @param list<array<string,mixed>|CfError> $responses */
    public function __construct(private array $responses = []) {}

    public function request(string $method, string $path, ?array $json = null): array
    {
        $this->calls[] = ['method' => $method, 'path' => $path, 'json' => $json];
        $next = array_shift($this->responses);
        if ($next instanceof CfError) throw $next;
        if ($next === null) throw new \RuntimeException('cf fake: unexpected request ' . $method . ' ' . $path);
        return $next;
    }
}
