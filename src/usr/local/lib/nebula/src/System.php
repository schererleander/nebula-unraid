<?php
declare(strict_types=1);

namespace Nebula;

use Closure;
use RuntimeException;

final class System
{
    public function __construct(
        private readonly Utils $utils,
        private ?Closure $runner = null,
    ) {}

    public function register(bool $include): bool
    {
        $networkFile = $this->utils->paths->network;
        $interface = Paths::INTERFACE;
        $original = is_file($networkFile) ? file_get_contents($networkFile) : '';
        if ($original === false) {
            throw new RuntimeException('Cannot read network-extra.cfg.');
        }
        $values = $original === '' ? [] : @parse_ini_string($original, false, INI_SCANNER_RAW);
        if (!is_array($values)) {
            throw new RuntimeException('network-extra.cfg is invalid; leaving it unchanged.');
        }
        $interfaces = preg_split('/\s+/', trim($values['include_interfaces'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $excluded = preg_split('/\s+/', trim($values['exclude_interfaces'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if ($include && in_array($interface, $excluded, true)) {
            throw new RuntimeException($interface . ' is explicitly excluded in Unraid Network Settings.');
        }
        $new = array_values(array_diff($interfaces, [$interface]));
        if ($include) {
            if (in_array($interface, $interfaces, true)) {
                return false;
            }
            $new[] = $interface;
        } elseif ($new === $interfaces) {
            return false;
        }
        $line = 'include_interfaces="' . implode(' ', $new) . '"';
        if (preg_match('/^[\t ]*include_interfaces[\t ]*=/m', $original)) {
            $updated = preg_replace_callback('/^[\t ]*include_interfaces[\t ]*=[^\r\n]*/m', fn() => $line, $original);
        } else {
            $updated = rtrim($original, "\r\n") . ($original !== '' ? "\n" : '') . $line . "\n";
        }
        $this->utils->atomic($networkFile, $updated);
        return true;
    }

    public function refresh(): void
    {
        $failed = [];
        foreach (['rpc', 'nfsd', 'ntpd', 'nginx', 'sshd', 'avahidaemon', 'samba'] as $service) {
            $path = $this->utils->paths->services . '/rc.' . $service;
            if (!is_executable($path)) {
                continue;
            }
            $code = $this->runner !== null ? ($this->runner)([$path, 'update']) : $this->utils->run([$path, 'update'], 20)[0];
            if ($code !== 0) {
                $failed[] = $service;
            }
        }
        if ($failed) {
            throw new RuntimeException('Unraid service update failed: ' . implode(', ', $failed));
        }
    }

    public function sync(array $addresses): void
    {
        $addresses = array_values(array_filter($addresses,
            fn($ip) => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)));
        sort($addresses);
        $signature = implode(' ', $addresses);
        $cache = $this->utils->paths->run . '/service-addresses';
        $previous = is_file($cache) ? file_get_contents($cache) : '';
        $changed = $addresses ? $this->register(true) : false;
        if ($changed || $previous !== $signature) {
            $this->refresh();
            $this->utils->atomic($cache, $signature);
            $this->utils->log('Services refreshed: ' . ($signature ?: 'disconnected'));
        }
    }
}
