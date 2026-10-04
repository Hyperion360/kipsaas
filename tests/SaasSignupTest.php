<?php // tests/SaasSignupTest.php
declare(strict_types=1);

namespace KipSaaS\Tests;

use KipSaaS\Registry;
use KipSaaS\Signup;
use KipSaaS\Tenants;
use PHPUnit\Framework\TestCase;

final class SaasSignupTest extends TestCase
{
    private Tenants $tenants;
    private \PDO $pdo;
    /** @var list<array{to:string,subject:string,body:string}> */
    public array $mails = [];
    private array $config;

    protected function setUp(): void
    {
        $pdo = (new Registry('sqlite:' . sys_get_temp_dir() . '/signup-' . bin2hex(random_bytes(4)) . '.sqlite'))->pdo();
        $this->pdo = $pdo;
        $this->tenants = new Tenants($pdo);
        $this->config = [
            'base_domain' => 'saas.example.test',
            'control_base_url' => 'https://control.saas.example.test',
            'token_secret' => 'test-secret',
            'grace_days' => 7,
            'plans' => ['standard' => ['price_id' => 'price_x', 'powered_by' => true],
                        'pro' => ['price_id' => 'price_y', 'powered_by' => false]],
        ];
        $this->mails = [];
    }

    private function signup(): Signup
    {
        return new Signup($this->tenants, $this->config, fn(string $to, string $s, string $b) => $this->mails[] = ['to' => $to, 'subject' => $s, 'body' => $b]);
    }

    public function test_submit_creates_pending_tenant_and_mails_the_link(): void
    {
        $out = $this->signup()->submit('ow@example.test', 'Acme Archive!', 'standard', '1.2.3.4');
        self::assertSame('pending', $out['status']);
        self::assertSame('acme-archive', $out['slug']);
        $t = $this->tenants->bySlug('acme-archive');
        self::assertSame('ow@example.test', $t['owner_email']);
        self::assertSame('Acme Archive!', $t['title']);
        self::assertSame('acme-archive.saas.example.test', $t['host']);
        self::assertNotEmpty($t['verify_token_hash']);
        self::assertCount(1, $this->mails);
        self::assertStringContainsString('/verify?t=', $this->mails[0]['body']);
    }

    public function test_submit_rejects_duplicate_slug_bad_plan_bad_email(): void
    {
        $s = $this->signup();
        $s->submit('ow@example.test', 'acme', 'standard', '1.2.3.4');
        self::assertSame('error', $s->submit('other@example.test', 'ACME', 'standard', '1.2.3.4')['status']);
        self::assertSame('error', $s->submit('ow@example.test', 'okslug', 'gold', '1.2.3.4')['status']);
        self::assertSame('error', $s->submit('not-an-email', 'okslug2', 'standard', '1.2.3.4')['status']);
    }

    public function test_rate_limit_blocks_the_eleventh_signup_from_one_ip(): void
    {
        $s = $this->signup();
        for ($i = 0; $i < 10; $i++) {
            $s->submit("ow{$i}@example.test", "tenant-{$i}", 'standard', '9.9.9.9');
        }
        self::assertSame('rate_limited', $s->submit('ow11@example.test', 'tenant-11', 'standard', '9.9.9.9')['status']);
        self::assertSame('pending', $s->submit('ow11@example.test', 'tenant-11', 'standard', '8.8.8.8')['status']); // other ip fine
    }

    public function test_rate_limit_blocks_the_sixth_signup_for_one_email_even_from_a_new_ip(): void
    {
        $s = $this->signup();
        for ($i = 0; $i < 5; $i++) {
            $s->submit('ow@example.test', "tenant-{$i}", 'standard', "10.0.0.{$i}");
        }
        self::assertSame('rate_limited', $s->submit('ow@example.test', 'tenant-5', 'standard', '10.0.0.5')['status']);
    }

    public function test_claim_activates_only_with_a_valid_unexpired_unclaimed_token(): void
    {
        $s = $this->signup();
        $s->submit('ow@example.test', 'acme', 'standard', '1.2.3.4');
        $t = $this->tenants->bySlug('acme');
        $token = $this->mintToken($t); // re-derive the token from the stored hash using the test secret

        self::assertSame('verified', $s->claim($token)['status']);
        self::assertNull($this->tenants->bySlug('acme')['verify_token_hash']); // single use
        self::assertSame('error', $s->claim($token)['status']); // replay
        self::assertSame('error', $s->claim($token . 'x')['status']); // forged
    }

    /** Recompute a token matching the stored hash: same construction as Signup::submit. */
    private function mintToken(array $t): string
    {
        // The stored hash is sha256(token); for the test we brute the payload
        // space of exactly one: issue a token, overwrite the row's hash with
        // the matching one, and return the token.
        $tok = \KipSaaS\Tokens::issue($this->config['token_secret']);
        $this->tenants->update((int) $t['id'], ['verify_token_hash' => $tok['hash'], 'verify_expires_at' => $tok['expires_at']]);
        return $tok['token'];
    }
}
