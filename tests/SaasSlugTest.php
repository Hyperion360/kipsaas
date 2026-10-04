<?php // tests/SaasSlugTest.php
declare(strict_types=1);

namespace KipSaaS\Tests;

use KipSaaS\Slug;
use PHPUnit\Framework\TestCase;

final class SaasSlugTest extends TestCase
{
    public function test_normalizes_acceptable_input(): void
    {
        self::assertSame('my-archive', Slug::normalize('My Archive'));
        self::assertSame('fandom-2026', Slug::normalize('  fandom-2026 '));
        self::assertSame('a-b-c', Slug::normalize('a b  c'));
    }

    public function test_rejects_bad_shapes(): void
    {
        foreach (['ab', '', '---', '-bad', 'bad-', str_repeat('x', 64)] as $bad) {
            try {
                Slug::normalize($bad);
                self::fail("accepted {$bad}");
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rejects_traversal_and_reserved_words(): void
    {
        foreach (['../..', '..', 'a/b', '..', "%2e%2e", 'admin', 'www', 'api', 'billing'] as $bad) {
            try {
                Slug::normalize($bad);
                self::fail("accepted {$bad}");
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_flagship_is_provisionable_tenant_zero(): void
    {
        self::assertSame('flagship', Slug::normalize('flagship'));
    }

    public function test_output_can_never_leave_the_tenants_root(): void
    {
        // Even for inputs that survive normalization, no dot, slash, or byte
        // outside [a-z0-9-] may reach a filesystem path built from the slug.
        foreach (['ok-slug', 'Slug With Spaces 42'] as $in) {
            $s = Slug::normalize($in);
            self::assertSame(1, preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $s));
            self::assertStringNotContainsString('..', $s);
            self::assertStringNotContainsString('/', $s);
        }
    }
}
