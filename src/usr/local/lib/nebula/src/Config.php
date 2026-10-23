<?php
declare(strict_types=1);

namespace Nebula;

use RuntimeException;

final class Config
{
    public const MAX_SIZE = 1048576;

    public function __construct(private readonly Utils $utils) {}

    public function import(string $yaml): array
    {
        if (trim($yaml) === '' || strlen($yaml) > self::MAX_SIZE) {
            throw new RuntimeException('Choose a profile smaller than 1 MiB.');
        }
        $path = $this->utils->temporary($yaml);
        try {
            [$code, $json] = $this->utils->run([$this->utils->tool('yq'),
                '-p', json_decode($yaml) !== null ? 'json' : 'yaml', '-o=json', '.', $path]);
            $input = $code === 0 ? json_decode($json, true) : null;
        } finally {
            unlink($path);
        }
        if (!is_array($input) || array_is_list($input)) {
            throw new RuntimeException('The file is not a valid YAML profile.');
        }
        return $this->normalize($input);
    }

    private function address(mixed $value): string
    {
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('Enter a valid mesh IP address.');
        }
        return $value;
    }

    private function endpoint(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 300 ||
            !preg_match('/^(?:[a-zA-Z0-9][a-zA-Z0-9.\-]*|\[[0-9a-fA-F:]+\]):([0-9]{1,5})$/D', $value, $match) ||
            (int)$match[1] < 1 || (int)$match[1] > 65535) {
            throw new RuntimeException('Use a public endpoint such as example.com:4242 or [2001:db8::1]:4242.');
        }
        return $value;
    }

    public function manual(array $fields): array
    {
        $host = $this->address(trim((string)($fields['lighthouse_ip'] ?? '')));
        $endpoint = $this->endpoint(trim((string)($fields['endpoint'] ?? '')));
        return $this->normalize([
            'pki' => ['ca' => $fields['ca'] ?? '', 'cert' => $fields['cert'] ?? '', 'key' => $fields['key'] ?? ''],
            'static_host_map' => [$host => [$endpoint]], 'lighthouse' => ['hosts' => [$host]],
            'relay' => ['use_relays' => isset($fields['use_relay']), 'relays' => isset($fields['use_relay']) ? [$host] : []],
            'punchy' => ['punch' => true],
            'firewall' => ['inbound' => [['port' => 'any', 'proto' => 'any', 'host' => 'any']],
                'outbound' => [['port' => 'any', 'proto' => 'any', 'host' => 'any']]],
        ]);
    }

    public function normalize(array $input): array
    {
        foreach (['pki', 'lighthouse', 'relay', 'tun', 'listen', 'firewall', 'logging', 'sshd', 'stats'] as $section) {
            if (isset($input[$section]) && !is_array($input[$section])) {
                throw new RuntimeException('Invalid profile section: ' . $section . '.');
            }
        }
        if (!empty($input['lighthouse']['am_lighthouse']) || !empty($input['relay']['am_relay'])) {
            throw new RuntimeException('This plugin supports client profiles, not lighthouse or relay-server mode.');
        }
        if (!empty($input['tun']['disabled'])) {
            throw new RuntimeException('Client profiles must enable the tunnel interface.');
        }
        if (!empty($input['tun']['unsafe_routes'])) {
            throw new RuntimeException('Subnet routing is not supported by this plugin yet. Remove unsafe_routes from this client profile.');
        }
        if (!empty($input['sshd']['enabled'])) {
            throw new RuntimeException('Nebula’s diagnostic SSH server is not supported. Use Unraid’s SSH server instead.');
        }
        if (!empty($input['lighthouse']['serve_dns'])) {
            throw new RuntimeException('Lighthouse DNS server mode is not supported by this client plugin.');
        }
        foreach (['ca', 'cert', 'key'] as $field) {
            $value = $input['pki'][$field] ?? null;
            $kind = $field === 'key' ? 'PRIVATE KEY' : 'CERTIFICATE';
            if (!is_string($value) || strlen($value) > 262144 ||
                !preg_match('/^-----BEGIN NEBULA [A-Z0-9 ]*' . $kind . '(?: V[0-9]+)?-----\r?\n/', trim($value))) {
                throw new RuntimeException('Embed the CA certificate, host certificate and host key in the profile. Local file paths are not portable.');
            }
            $input['pki'][$field] = trim($value) . "\n";
        }
        $hosts = $input['lighthouse']['hosts'] ?? [];
        $relays = $input['relay']['relays'] ?? [];
        foreach ([$hosts, $relays] as $list) {
            if (!is_array($list) || !array_is_list($list) || count($list) > 128) {
                throw new RuntimeException('Lighthouse and relay addresses must be lists of mesh IPs.');
            }
            foreach ($list as $ip) {
                $this->address($ip);
            }
        }
        $map = $input['static_host_map'] ?? [];
        if ($map instanceof \stdClass && get_object_vars($map) === []) {
            $map = [];
        }
        if (!is_array($map) || count($map) > 1024) {
            throw new RuntimeException('static_host_map must map mesh IPs to public endpoints.');
        }
        foreach ($map as $ip => $endpoints) {
            $this->address($ip);
            if (!is_array($endpoints) || !array_is_list($endpoints) || !$endpoints) {
                throw new RuntimeException('Each static peer needs a list of public endpoints.');
            }
            foreach ($endpoints as $endpoint) {
                $this->endpoint($endpoint);
            }
        }
        $input['tun']['dev'] = Paths::INTERFACE;
        $input['listen'] ??= ['host' => '0.0.0.0', 'port' => 0];
        $input['lighthouse']['hosts'] = $hosts;
        $input['static_host_map'] = $map ?: (object)[];
        return $input;
    }

    public function validate(array $config): array
    {
        $files = [];
        try {
            $files[] = $profile = $this->utils->temporary(Utils::json($config));
            $files[] = $cert = $this->utils->temporary($config['pki']['cert']);
            $files[] = $ca = $this->utils->temporary($config['pki']['ca']);
            [$code] = $this->utils->run([$this->utils->tool('nebula-cert'), 'verify', '-ca', $ca, '-crt', $cert]);
            if ($code !== 0) {
                throw new RuntimeException('The host certificate is expired or is not signed by the supplied CA.');
            }
            [$code] = $this->utils->run([$this->utils->tool('nebula'), '-test', '-config', $profile]);
            if ($code !== 0) {
                throw new RuntimeException('Nebula rejected the profile. Check the certificate, matching key and configuration values.');
            }
            [$code, $json] = $this->utils->run([$this->utils->tool('nebula-cert'), 'print', '-json', '-path', $cert]);
            $certificates = json_decode($json, true);
            $certificate = $certificates[0] ?? $certificates;
            $details = $certificate['details'] ?? null;
            if ($code !== 0 || !is_array($details) || !empty($details['isCa'])) {
                throw new RuntimeException('Use a host certificate and key, not the CA signing credentials.');
            }
            return ['host' => $details['name'], 'networks' => $details['networks'] ?? $details['ips'] ?? [],
                'expires' => $details['notAfter'], 'lighthouses' => $config['lighthouse']['hosts'] ?? []];
        } finally {
            foreach ($files as $path) {
                @unlink($path);
            }
        }
    }

    public function load(): ?array
    {
        $path = $this->utils->paths->state . '/profile.json';
        if (!is_file($path)) {
            return null;
        }
        $profile = json_decode((string)file_get_contents($path), true);
        if (!is_array($profile) || ($profile['schema'] ?? null) !== 1 ||
            !is_array($profile['config'] ?? null) || !is_array($profile['metadata'] ?? null)) {
            throw new RuntimeException('The saved profile is invalid or belongs to a newer plugin version.');
        }
        return $profile;
    }

    public function save(array $config, string $name, bool $autostart): void
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 80 || preg_match('/[\x00-\x1f]/', $name)) {
            throw new RuntimeException('Enter a profile name of 1 to 80 characters.');
        }
        $profile = ['schema' => 1, 'name' => $name, 'autostart' => $autostart,
            'config' => $config, 'metadata' => $this->validate($config)];
        $this->utils->atomic($this->utils->paths->state . '/profile.json', Utils::json($profile));
    }

    public function autostart(bool $enabled): void
    {
        $profile = $this->load();
        if ($profile === null) {
            throw new RuntimeException('Save a profile before enabling automatic startup.');
        }
        $profile['autostart'] = $enabled;
        $this->utils->atomic($this->utils->paths->state . '/profile.json', Utils::json($profile));
    }

    public function prepare(): void
    {
        $profile = $this->load();
        if ($profile === null) {
            throw new RuntimeException('No profile saved.');
        }
        $config = $this->normalize($profile['config']);
        $this->validate($config);
        $this->utils->atomic($this->utils->paths->run . '/config.yml', Utils::json($config));
    }
}
