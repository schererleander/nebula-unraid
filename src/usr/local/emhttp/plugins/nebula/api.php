<?php
declare(strict_types=1);

namespace Nebula;

use RuntimeException;
use Throwable;

require __DIR__ . '/include/common.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Use POST.');
    }
    // Unraid validates and removes the POST token in its PHP auto-prepend.
    $protected = function_exists('csrf_terminate') &&
        realpath((string)ini_get('auto_prepend_file')) === realpath('/usr/local/emhttp/webGui/include/local_prepend.php');
    if (!$protected) {
        http_response_code(403);
        throw new RuntimeException('Unraid request protection is unavailable. Check the WebGUI PHP configuration.');
    }
    $utils = new Utils(Paths::environment());
    $config = new Config($utils);
    $action = $_POST['action'] ?? '';
    if ($action === 'status') {
        echo json_encode(['ok' => true, 'status' => serviceStatus($config, $utils)], JSON_THROW_ON_ERROR);
        return;
    }
    $lock = $utils->lock('settings');
    try {
        switch ($action) {
            case 'save':
                $mode = $_POST['mode'] ?? '';
                if (!in_array($mode, ['import', 'manual'], true)) {
                    throw new RuntimeException('Choose YAML import or manual setup.');
                }
                $profile = $mode === 'import' ? $config->import((string)($_POST['yaml'] ?? '')) : $config->manual($_POST);
                $config->save($profile, (string)($_POST['name'] ?? ''), isset($_POST['autostart']));
                serviceAction($utils, 'restart');
                break;
            case 'start':
            case 'stop':
            case 'restart':
                serviceAction($utils, $action);
                break;
            case 'autostart':
                $config->autostart(($_POST['enabled'] ?? '') === 'true');
                break;
            default:
                throw new RuntimeException('Unknown action.');
        }
        echo json_encode(['ok' => true, 'status' => serviceStatus($config, $utils)], JSON_THROW_ON_ERROR);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
} catch (Throwable $error) {
    if (http_response_code() < 400) {
        http_response_code(400);
    }
    // Never return command output, a profile or credentials in an error response.
    echo json_encode(['ok' => false, 'error' => $error instanceof RuntimeException ?
        $error->getMessage() : 'Invalid request. Check the supplied fields.']);
}
