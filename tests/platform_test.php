<?php
declare(strict_types=1);

namespace Nebula {
    // Simulate request protection without requiring host WebGUI files.
    function function_exists(string $name): bool {
        return $name === 'csrf_terminate' ? $GLOBALS['protected'] : \function_exists($name);
    }
    function ini_get(string $name): string|false {
        return $name === 'auto_prepend_file' ? $GLOBALS['prepend'] : \ini_get($name);
    }
    function realpath(string $path): string|false {
        return $path === '/usr/local/emhttp/webGui/include/local_prepend.php' ? $path : \realpath($path);
    }
    function openlog(string $prefix, int $flags, int $facility): bool {
        return true;
    }
    function syslog(int $priority, string $message): bool {
        $GLOBALS['logs'][] = [$priority, $message];
        return true;
    }
    function closelog(): bool { return true; }
}

namespace {
    ob_start();
    require dirname(__DIR__) . '/src/usr/local/lib/nebula/src/bootstrap.php';

    function check(bool $condition, string $label): void {
        if (!$condition) throw new RuntimeException($label);
        echo 'PASS: ', $label, "\n";
    }
    $utils = new Nebula\Utils(new Nebula\Paths(notify: '/fixture/notify'));

    putenv('NEBULA_LIBRARY=' . dirname(__DIR__) . '/src/usr/local/lib/nebula');
    putenv('NEBULA_STATE=' . sys_get_temp_dir() . '/nebula-api-' . bin2hex(random_bytes(6)));
    putenv('NEBULA_RUN=' . getenv('NEBULA_STATE') . '/run');
    function apiRequest(string $method): array {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_POST = ['action' => 'status'];
        http_response_code(200);
        ob_start();
        require dirname(__DIR__) . '/src/usr/local/emhttp/plugins/nebula/api.php';
        return json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
    }
    $protected = false;
    $prepend = '/usr/local/emhttp/webGui/include/local_prepend.php';
    $response = apiRequest('POST');
    check(http_response_code() === 403 && !$response['ok'], 'requests fail closed without CSRF protection');
    $protected = true;
    $prepend = '/different/prepend.php';
    $response = apiRequest('POST');
    check(http_response_code() === 403 && !$response['ok'], 'a similarly named helper in another prepend is not trusted');
    $prepend = '/usr/local/emhttp/webGui/include/local_prepend.php';
    $response = apiRequest('POST');
    check($response['ok'] && !$response['status']['configured'], 'a validated POST needs no second token after Unraid removes it');
    $response = apiRequest('GET');
    check(http_response_code() === 405 && !$response['ok'], 'the API still requires POST');

    $commands = [];
    $notifications = new Nebula\Notifications($utils, function (array $command) use (&$commands): int {
        $commands[] = $command;
        return 0;
    });
    $now = new DateTimeImmutable('2026-10-23T12:00:00Z');
    $profile = ['config' => ['pki' => ['cert' => 'test-certificate']],
        'metadata' => ['expires' => '2026-12-01T12:00:00Z']];
    $notifications->expiry(null, $now);
    $notifications->expiry($profile, $now);
    check($commands === [], 'no expiry notification without a profile or outside the warning period');
    $profile['metadata']['expires'] = '2026-11-12T12:00:00Z';
    $notifications->expiry($profile, $now);
    $notifications->expiry($profile, $now);
    check($commands[0] === $commands[1] && end($commands[0]) === '-x',
        'repeated warnings use the same single-ticket event');
    check($commands[0][0] === '/fixture/notify' && $commands[0][8] === 'warning' &&
        $commands[0][10] === '/Settings/Nebula', 'warnings use Unraid notify with severity and settings link');
    $notifications->expiry($profile, new DateTimeImmutable('2026-11-10T12:00:00Z'));
    $notifications->expiry($profile, new DateTimeImmutable('2026-11-13T12:00:00Z'));
    check($commands[2][8] === 'alert' && $commands[2][2] !== $commands[0][2] &&
        $commands[3][2] !== $commands[2][2], 'near-expiry and expired states each escalate with a distinct ticket');
    foreach (['integration', 'nebula', 'watcher', 'startup'] as $kind) {
        $notifications->failure($kind);
    }
    check(count($commands) === 8 && !str_contains(json_encode($commands), 'test-certificate'),
        'all operational events use notifications without certificate contents');
    $before = count($logs ?? []);
    (new Nebula\Notifications($utils, fn(array $command): int => 1))->failure('startup');
    check(count($logs) === $before + 1, 'notification failures log to syslog without interrupting recovery');
}
