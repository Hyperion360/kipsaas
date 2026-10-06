<?php // demo/src/Demo/DemoApp.php
declare(strict_types=1);

namespace KipSaaS\Demo;

use KipSaaS\TenantAppInterface;

/**
 * The demo app's adapter: the whole obligation a consuming app owes the
 * engine. It runs in the CONTROL process (the kit's autoloader maps
 * KipSaaS\Demo\ to demo/src/), never inside a stamped tenant, where the
 * KipSaaS engine classes do not exist.
 */
final class DemoApp implements TenantAppInterface
{
    public function createOwner(\PDO $tenantDb, array $tenant): string
    {
        $otp = base64_encode(random_bytes(24));
        $tenantDb->prepare("INSERT INTO users (email, password_hash, penname, role, is_admin)
            VALUES (?, ?, 'Owner', 'owner', 1)")
            ->execute([(string) $tenant['owner_email'], password_hash($otp, PASSWORD_DEFAULT)]);
        return $otp;
    }

    public function seedCommand(): string { return 'db:seed-demo'; }
}
