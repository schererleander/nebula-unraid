<?php
declare(strict_types=1);

namespace Nebula;

use RuntimeException;

final class Utils
{
    public function __construct(public readonly Paths $paths) {}

    public function directory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Cannot create the configuration directory.');
        }
    }

    public function temporary(string $content = '', ?string $directory = null): string
    {
        $directory ??= $this->paths->run;
        $this->directory($directory);
        $path = tempnam($directory, 'tmp-');
        if ($path === false) {
            throw new RuntimeException('Cannot create a temporary file.');
        }
        try {
            if (!chmod($path, 0600) || file_put_contents($path, $content) !== strlen($content)) {
                throw new RuntimeException('Cannot write a temporary file.');
            }
            return $path;
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        }
    }

    public function atomic(string $path, string $content): void
    {
        $temporary = $this->temporary($content, dirname($path));
        try {
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Cannot save the configuration.');
            }
        } finally {
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    public static function json(mixed $value): string
    {
        // Go's YAML reader does not accept PHP's default JSON slash escaping.
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    public function run(array $arguments, int $timeout = 15): array
    {
        $output = $this->temporary();
        try {
            $process = proc_open(['/usr/bin/timeout', '--kill-after=2', (string)$timeout, ...$arguments],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $output, 'w'],
                 2 => ['file', '/dev/null', 'w']], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot run the required system command.');
            }
            $code = proc_close($process);
            $content = file_get_contents($output, false, null, 0, 2097152);
            return [$code, $content === false ? '' : $content];
        } finally {
            @unlink($output);
        }
    }

    public function tool(string $name): string
    {
        return $this->paths->library . '/bin/' . $name;
    }

    public function lock(string $name, bool $wait = false): mixed
    {
        $this->directory($this->paths->run);
        $handle = fopen($this->paths->run . '/' . $name . '.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX | ($wait ? 0 : LOCK_NB))) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Another action is in progress. Try again shortly.');
        }
        return $handle;
    }

    public function log(string $message): void
    {
        openlog('nebula', LOG_PID, LOG_DAEMON);
        syslog(LOG_INFO, $message);
        closelog();
    }
}
