<?php // tests/SaasCfClientTest.php
declare(strict_types=1);

namespace KipSaaS\Tests;

use KipSaaS\CfClient;
use KipSaaS\CfError;
use PHPUnit\Framework\TestCase;

/**
 * CfClient against RecordingCfHttp (the SaasStripeTest double idiom): every
 * payload and idempotency rule is asserted without network. All lookups go
 * by hostname, so a claim added in manual mode and removed under automation
 * still cleans up its edge object.
 */
final class SaasCfClientTest extends TestCase
{
    /** @return array{0: CfClient, 1: RecordingCfHttp} */
    private function client(array $responses): array
    {
        $http = new RecordingCfHttp($responses);
        return [new CfClient($http, 'zone1'), $http];
    }

    private function created(string $id, string $status, string $ssl): array
    {
        return ['success' => true, 'result' => ['id' => $id, 'hostname' => 'archive.example.com',
            'status' => $status, 'ssl' => ['status' => $ssl]]];
    }

    /** The GET/list step's envelope: Cloudflare returns result as a LIST even filtered to one row. */
    private function listed(array $row): array
    {
        return ['success' => true, 'result' => [$row]];
    }

    public function test_ensure_creates_with_the_http_certificate_method(): void
    {
        [$client, $http] = $this->client([
            ['success' => true, 'result' => []], // the lookup: nothing there yet
            $this->created('ch1', 'pending', 'pending_validation'),
        ]);
        self::assertSame(['id' => 'ch1', 'status' => 'pending', 'ssl' => 'pending_validation'],
            $client->ensure('archive.example.com'));
        self::assertSame('GET', $http->calls[0]['method']);
        self::assertSame('/zones/zone1/custom_hostnames?hostname=archive.example.com', $http->calls[0]['path']);
        self::assertSame('POST', $http->calls[1]['method']);
        self::assertSame('/zones/zone1/custom_hostnames', $http->calls[1]['path']);
        self::assertSame(['hostname' => 'archive.example.com', 'ssl' => ['method' => 'http', 'type' => 'dv']], $http->calls[1]['json']);
    }

    public function test_ensure_adopts_the_existing_object_without_a_second_create(): void
    {
        [$client, $http] = $this->client([$this->listed(['id' => 'ch9', 'status' => 'active', 'ssl' => ['status' => 'active']])]);
        self::assertSame('ch9', $client->ensure('archive.example.com')['id']);
        self::assertCount(1, $http->calls); // the lookup only; no POST
    }

    public function test_get_returns_null_when_the_zone_has_no_such_hostname(): void
    {
        [$client] = $this->client([['success' => true, 'result' => []]]);
        self::assertNull($client->get('archive.example.com'));
    }

    public function test_delete_removes_by_id_and_skips_the_call_when_absent(): void
    {
        [$client, $http] = $this->client([
            $this->listed(['id' => 'ch1', 'status' => 'active', 'ssl' => ['status' => 'active']]), // the lookup finds it
            ['success' => true, 'result' => ['id' => 'ch1']],
        ]);
        $client->delete('archive.example.com');
        self::assertSame('DELETE', $http->calls[1]['method']);
        self::assertSame('/zones/zone1/custom_hostnames/ch1', $http->calls[1]['path']);

        [$quiet, $http2] = $this->client([['success' => true, 'result' => []]]);
        $quiet->delete('archive.example.com');
        self::assertCount(1, $http2->calls); // the lookup only; nothing to delete
    }

    public function test_api_errors_bubble_to_the_caller(): void
    {
        [$client] = $this->client([new CfError(403, 'authentication failed')]);
        $this->expectException(CfError::class);
        $client->get('archive.example.com');
    }

    public function test_set_fallback_origin_puts_the_designation(): void
    {
        [$client, $http] = $this->client([['success' => true, 'result' => ['status' => 'pending']]]);
        $r = $client->setFallbackOrigin('fallback.saas.example.test');
        self::assertSame('pending', $r['result']['status']);
        self::assertSame('PUT', $http->calls[0]['method']);
        self::assertSame('/zones/zone1/custom_hostnames/fallback_origin', $http->calls[0]['path']);
        self::assertSame(['origin' => 'fallback.saas.example.test'], $http->calls[0]['json']);
    }

    public function test_ensure_refuses_a_success_response_without_an_id(): void
    {
        // Eng-review fold (F-S2): a malformed envelope fails closed instead of
        // storing an empty id that marks the claim automated with nothing
        // behind it to retire.
        [$client] = $this->client([
            ['success' => true, 'result' => []], // the lookup: nothing there
            ['success' => true, 'result' => ['status' => 'pending']], // create: no id
        ]);
        $this->expectException(\RuntimeException::class);
        $client->ensure('archive.example.com');
    }
}
