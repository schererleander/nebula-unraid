<?php
declare(strict_types=1);

namespace Nebula;

final class Paths
{
    public const INTERFACE = 'nebula1';

    public function __construct(
        public readonly string $library = '/usr/local/lib/nebula',
        public readonly string $state = '/boot/config/plugins/nebula',
        public readonly string $run = '/run/nebula',
        public readonly string $network = '/boot/config/network-extra.cfg',
        public readonly string $services = '/etc/rc.d',
        public readonly string $notify = '/usr/local/emhttp/webGui/scripts/notify',
    ) {}

    public static function environment(): self
    {
        return new self(
            getenv('NEBULA_LIBRARY') ?: '/usr/local/lib/nebula',
            getenv('NEBULA_STATE') ?: '/boot/config/plugins/nebula',
            getenv('NEBULA_RUN') ?: '/run/nebula',
        );
    }
}
