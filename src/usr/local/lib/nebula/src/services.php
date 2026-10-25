<?php
declare(strict_types=1);

namespace Nebula;

use RuntimeException;

function serviceAction(Utils $utils, string $action): void
{
    // Allow for the service lock, bounded shutdown and profile validation.
    [$code] = $utils->run([$utils->paths->services . '/rc.nebula', $action], 90);
    if ($code !== 0) {
        throw new RuntimeException('Could not ' . $action . ' the service. The saved profile is intact; check the connection log.');
    }
}

function serviceStatus(Config $config, Utils $utils): array
{
    $profile = $config->load();
    $pid = trim((string)@file_get_contents($utils->paths->run . '/nebula.pid'));
    $running = ctype_digit($pid) && @readlink('/proc/' . $pid . '/exe') === $utils->tool('nebula');
    $meta = $profile === null ? null : ['name' => $profile['name'], ...$profile['metadata']];
    return ['configured' => $profile !== null, 'running' => $running,
        'autostart' => $profile['autostart'] ?? false, 'profile' => $meta];
}
