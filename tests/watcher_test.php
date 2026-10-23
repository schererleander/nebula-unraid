<?php
declare(strict_types=1);

namespace Nebula {
    // Supply interface snapshots and boot readiness without touching the host.
    function net_get_interfaces(): array|false { return $GLOBALS['interfaces']; }
    function is_file(string $path): bool { return $path === '/var/local/emhttp/var.ini' || \is_file($path); }
    function pcntl_async_signals(bool $enabled): void {}
    function pcntl_signal(int $signal, callable $handler): void {}
}

namespace {
    require dirname(__DIR__) . '/src/usr/local/lib/nebula/src/bootstrap.php';
    if (!defined('SIGTERM')) define('SIGTERM', 15);
    if (!defined('SIGINT')) define('SIGINT', 2);

    $directory = sys_get_temp_dir() . '/nebula-watcher-test-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    mkdir($directory . '/services');
    file_put_contents($directory . '/network.cfg', "include_interfaces=\"tailscale1\"\n");
    file_put_contents($directory . '/services/rc.nginx', 'fixture');
    chmod($directory . '/services/rc.nginx', 0755);
    $utils = new Nebula\Utils(new Nebula\Paths(run: $directory . '/run',
        network: $directory . '/network.cfg', services: $directory . '/services'));
    $calls = 0;
    $system = new Nebula\System($utils, function (array $command) use (&$calls): int {
        ++$calls;
        return 0;
    });
    $watcher = new Nebula\Watcher($system, $utils);
    function check(bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
        echo 'PASS: ', $message, "\n";
    }
    function cleanup(string $path): void {
        if (is_dir($path)) {
            foreach (new FilesystemIterator($path) as $item) cleanup($item->getPathname());
            rmdir($path);
        } else unlink($path);
    }
    try {
        $interfaces = ['nebula1' => ['unicast' => [
            ['address' => '10.42.42.5'], ['address' => 'fd00::5'], ['address' => '00:11:22:33:44:55'],
        ]]];
        $watcher->run(true);
        $signature = file_get_contents($directory . '/run/service-addresses');
        check($signature === '10.42.42.5 fd00::5' && $calls === 1,
            'interface discovery refreshes IPv4 and IPv6 listeners');
        $interfaces = false;
        try {
            $watcher->run(true);
            throw new LogicException('Interface discovery failure was ignored.');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'Cannot read network interfaces'),
                'discovery failures are reported');
        }
        check(file_get_contents($directory . '/run/service-addresses') === $signature && $calls === 1,
            'discovery failure does not falsely mark the tunnel disconnected');
        $interfaces = [];
        $watcher->run(true);
        check($calls === 2 && file_get_contents($directory . '/run/service-addresses') === '',
            'interface removal refreshes listeners and releases the lock after a failure');
    } finally {
        cleanup($directory);
    }
}
