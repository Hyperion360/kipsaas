<?php // src/Provisioner.php
declare(strict_types=1);

namespace KipSaaS;

/** Your app's install ritual. The engine calls these; you implement them once. */
interface ProvisionerInterface
{
    /** Stamp and activate a tenant install. @return array{mail_sent: bool, one_time_password: ?string} */
    public function provision(array $tenant): array;
    /** A suspended customer paid again inside the retention window: restore service. */
    public function resume(array $tenant): void;
    public function suspend(array $tenant): void;
    public function purge(array $tenant): void;
}
