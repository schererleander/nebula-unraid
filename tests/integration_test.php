<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/usr/local/lib/nebula/src/bootstrap.php';

use Nebula\{Paths, System, Utils};

$temporary = sys_get_temp_dir() . '/nebula-binding-test-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700);
mkdir($temporary . '/services');
$network = $temporary . '/network-extra.cfg';
$original = "# user settings\ninclude_interfaces=\"tailscale1 wg0\"\nexclude_interfaces=\"eth9\"\ncustom_setting=\"retained\"\n";
file_put_contents($network, $original);
$services = ['rpc', 'nfsd', 'ntpd', 'nginx', 'sshd', 'avahidaemon', 'samba'];
foreach ($services as $service) {
    file_put_contents($temporary . '/services/rc.' . $service, 'test fixture');
    chmod($temporary . '/services/rc.' . $service, 0755);
}
$calls = [];
$fail = false;
$integration = new System(new Utils(new Paths(run: $temporary . '/run', network: $network, services: $temporary . '/services')),
    function (array $command) use (&$calls, &$fail): int {
        $calls[] = $command;
        return $fail && str_ends_with($command[0], 'rc.sshd') ? 1 : 0;
    });
function check(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException($label);
    echo 'PASS: ', $label, "\n";
}
function cleanup(string $path): void {
    if (is_dir($path)) {
        foreach (new FilesystemIterator($path) as $item) cleanup($item->getPathname());
        rmdir($path);
    } else unlink($path);
}
try {
    $integration->sync([]);
    check($calls === [], 'no service reload before the tunnel has an address');
    $integration->sync(['10.42.42.5']);
    $values = parse_ini_file($network);
    check($values['include_interfaces'] === 'tailscale1 wg0 nebula1', 'Nebula registers without replacing other interfaces');
    check($values['exclude_interfaces'] === 'eth9' && $values['custom_setting'] === 'retained', 'exclusions and unknown settings are preserved');
    check(str_contains(file_get_contents($network), '# user settings'), 'user comments are preserved');
    check(count($calls) === 7 && array_column($calls, 1) === array_fill(0, 7, 'update'), 'the Unraid service update scripts are called');
    $saved = file_get_contents($network);
    touch($network, 1000000000);
    $integration->sync(['10.42.42.5']);
    clearstatcache();
    check(count($calls) === 7 && filemtime($network) === 1000000000, 'stable connections cause no flash writes or service reloads');
    $integration->sync(['10.42.42.6']);
    check(count($calls) === 14, 'changing the mesh address refreshes all service listeners');
    $integration->sync([]);
    check(count($calls) === 21, 'losing the mesh address refreshes service listeners');
    $integration->sync([]);
    check(count($calls) === 21, 'remaining disconnected does not repeatedly reload services');
    check($integration->register(false), 'uninstall removes the Nebula interface');
    check(file_get_contents($network) === $original, 'uninstall preserves Tailscale, exclusions and other settings exactly');
    check(!$integration->register(false), 'interface removal is idempotent');
    file_put_contents($network, "include_interfaces=\"tailscale1\"\nexclude_interfaces=\"nebula1\"\n");
    try {
        $integration->register(true);
        check(false, 'explicit exclusion must be respected');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'explicitly excluded'), 'explicit exclusions are respected and reported');
    }
    file_put_contents($network, $original);
    $fail = true;
    try {
        $integration->sync(['10.42.42.5']);
        check(false, 'service update failures must be reported');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'sshd'), 'failed service updates are reported');
    }
    $beforeRetry = count($calls);
    $fail = false;
    $integration->sync(['10.42.42.5']);
    check(count($calls) === $beforeRetry + 7, 'failed updates are retried on the next watcher pass');
    check(file_get_contents($network) === $saved, 'retry does not duplicate the interface');
    check(glob($temporary . '/tmp-*') === [], 'atomic-write temporary files are cleaned');
} finally {
    cleanup($temporary);
}
