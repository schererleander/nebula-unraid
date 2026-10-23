<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/usr/local/lib/nebula/src/bootstrap.php';

use Nebula\{Config, Paths, Utils};

$root = $argv[1] ?? '';
$fixtures = $argv[2] ?? '';
if (!is_dir($root . '/bin') || !is_file($fixtures . '/client.crt')) {
    fwrite(STDERR, "Usage: php tests/profile_test.php TOOLS_ROOT FIXTURE_DIRECTORY\n");
    exit(2);
}
$temporary = sys_get_temp_dir() . '/nebula-php-test-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700);
$utils = new Utils(new Paths(library: $root, state: $temporary . '/state', run: $temporary . '/run'));
$nebula = new Config($utils);
function check(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException($label);
    echo 'PASS: ', $label, "\n";
}
function rejects(callable $operation, string $label): void {
    try {
        $operation();
    } catch (RuntimeException $error) {
        check(true, $label);
        return;
    }
    check(false, $label);
}
function deleteTree(string $path): void {
    if (is_dir($path)) {
        foreach (new FilesystemIterator($path) as $item) {
            deleteTree($item->getPathname());
        }
        rmdir($path);
    } else {
        unlink($path);
    }
}

try {
    $fields = ['ca' => file_get_contents($fixtures . '/ca.crt'),
        'cert' => file_get_contents($fixtures . '/client.crt'),
        'key' => file_get_contents($fixtures . '/client.key'),
        'lighthouse_ip' => '10.253.254.1', 'endpoint' => '127.0.0.1:4242'];
    $config = $nebula->manual($fields);
    $imported = $nebula->import(json_encode($config));
    check($imported === $config, 'JSON profile imports without changing the identity');
    $yaml = "pki:\n";
    foreach ($config['pki'] as $field => $value) {
        $yaml .= '  ' . $field . ": |\n" . preg_replace('/^/m', '    ', trim($value)) . "\n";
    }
    $yaml .= "lighthouse:\n  hosts: [10.253.254.1]\nstatic_host_map:\n  10.253.254.1: ['127.0.0.1:4242']\n";
    check($nebula->import($yaml)['pki'] === $config['pki'], 'literal-block YAML imports inline credentials');
    $changed = $config;
    $changed['tun'] = ['dev' => 'eth0', 'mtu' => 1400];
    $changed['listen'] = ['host' => '0.0.0.0', 'port' => 4243];
    $changed['cipher'] = 'aes';
    $safe = $nebula->normalize($changed);
    check($safe['tun'] === ['dev' => 'nebula1', 'mtu' => 1400] && $safe['listen']['port'] === 4243 && $safe['cipher'] === 'aes', 'client settings survive import while the plugin owns the interface');
    foreach (['tun' => ['unsafe_routes' => [['route' => '0.0.0.0/0', 'via' => '10.253.254.1']]], 'sshd' => ['enabled' => true], 'relay' => ['am_relay' => true]] as $section => $values) {
        $unsupported = $config;
        $unsupported[$section] = $values;
        rejects(fn() => $nebula->normalize($unsupported), 'unsupported ' . $section . ' mode is explicitly rejected');
    }
    $static = $config;
    $static['lighthouse']['hosts'] = [];
    $static['static_host_map'] = [];
    $static = $nebula->normalize($static);
    check($nebula->normalize($static) == $static, 'empty peer maps support repeated normalization');
    $ipv6 = $config;
    $ipv6['lighthouse']['hosts'] = ['2001:db8::1'];
    $ipv6['static_host_map'] = ['2001:db8::1' => ['[2001:db8::2]:4242']];
    check($nebula->normalize($ipv6)['lighthouse']['hosts'] === ['2001:db8::1'], 'IPv6 mesh peers and endpoints are supported');
    $wrong = $fields;
    $wrong['ca'] = file_get_contents($fixtures . '/other-ca.crt');
    rejects(fn() => $nebula->validate($nebula->manual($wrong)), 'untrusted CA is rejected');
    $wrong['ca'] = '/etc/passwd';
    rejects(fn() => $nebula->manual($wrong), 'filesystem references cannot be imported as credentials');
    rejects(fn() => $nebula->import('broken: [yaml'), 'malformed YAML is rejected');
    rejects(fn() => $nebula->import(str_repeat('a', Config::MAX_SIZE + 1)), 'oversized profiles are rejected');
    $wrong = $config;
    $wrong['lighthouse']['am_lighthouse'] = true;
    rejects(fn() => $nebula->normalize($wrong), 'lighthouse mode is rejected');
    $wrong = $fields;
    $wrong['endpoint'] = '127.0.0.1:4242; touch /tmp/unsafe';
    rejects(fn() => $nebula->manual($wrong), 'command injection in endpoints is rejected');
    $wrong['endpoint'] = 'example.com:99999';
    rejects(fn() => $nebula->manual($wrong), 'invalid endpoint ports are rejected');
    $nebula->save($config, 'Test mesh', true);
    $saved = file_get_contents($temporary . '/state/profile.json');
    check(Nebula\serviceStatus($nebula, $utils)['configured'] && Nebula\serviceStatus($nebula, $utils)['autostart'], 'profile and startup preference persist');
    check((fileperms($temporary . '/state/profile.json') & 0777) === 0600, 'saved profile is owner-readable only on a Unix filesystem');
    check(!str_contains(json_encode(Nebula\serviceStatus($nebula, $utils)), trim($fields['key'])), 'status never exposes the private key');
    $wrong = $config;
    $wrong['pki']['key'] = file_get_contents($fixtures . '/other.key');
    rejects(fn() => $nebula->save($wrong, 'Broken', false), 'invalid replacement is rejected');
    check(file_get_contents($temporary . '/state/profile.json') === $saved, 'invalid replacement preserves the working profile');
    $nebula->autostart(false);
    check(!Nebula\serviceStatus($nebula, $utils)['autostart'], 'automatic startup can be disabled');
    $nebula->prepare();
    check(is_file($temporary . '/run/config.yml'), 'saved profile produces a validated runtime configuration');
    check(glob($temporary . '/run/tmp-*') === [], 'temporary credentials are removed after validation');
} finally {
    deleteTree($temporary);
}
