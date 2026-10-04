<?php // src/Plans.php
declare(strict_types=1);

namespace KipSaaS;

final class Plans
{
    public static function get(array $config, string $id): array
    {
        return $config['plans'][$id] ?? throw new \DomainException("unknown plan: {$id}");
    }

    public static function poweredBy(array $config, string $id): bool
    {
        return (bool) self::get($config, $id)['powered_by'];
    }
}
