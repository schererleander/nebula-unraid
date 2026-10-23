<?php
declare(strict_types=1);

namespace Nebula;

use Closure;
use DateTimeImmutable;
use RuntimeException;

final class Notifications
{
    public function __construct(private readonly Utils $utils, private readonly ?Closure $runner = null) {}

    private function send(string $event, string $subject, string $description, string $severity): void
    {
        $command = [$this->utils->paths->notify, '-e', 'Nebula ' . $event,
            '-s', $subject, '-d', $description, '-i', $severity, '-l', '/Settings/Nebula', '-x'];
        try {
            $code = $this->runner !== null ? ($this->runner)($command) : $this->utils->run($command, 10)[0];
        } catch (\Throwable) {
            $code = 1;
        }
        if ($code !== 0) {
            $this->utils->log('Could not deliver an Unraid notification.');
        }
    }

    public function failure(string $kind): void
    {
        $messages = [
            'integration' => ['Nebula service integration failed', 'Unraid service listeners could not be updated. Check the system log.'],
            'nebula' => ['Nebula keeps exiting', 'Nebula has exited repeatedly. Check the connection log and saved profile.'],
            'watcher' => ['Nebula watcher keeps exiting', 'The Nebula service watcher has exited repeatedly. Check the system log.'],
            'startup' => ['Nebula could not start', 'Check Settings > Nebula and the connection log.'],
        ];
        if (!isset($messages[$kind])) {
            throw new RuntimeException('Unknown notification event.');
        }
        [$subject, $description] = $messages[$kind];
        $this->send($kind . ' ' . date('Y-m-d'), $subject, $description, 'alert');
    }

    public function expiry(?array $profile, ?DateTimeImmutable $now = null): void
    {
        if ($profile === null) {
            return;
        }
        $value = $profile['metadata']['expires'] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('The saved profile has no certificate expiry date.');
        }
        $expiry = new DateTimeImmutable($value);
        $remaining = $expiry->getTimestamp() - ($now ?? new DateTimeImmutable())->getTimestamp();
        if ($remaining > 30 * 86400) {
            return;
        }
        $severity = $remaining <= 7 * 86400 ? 'alert' : 'warning';
        $expired = $remaining <= 0;
        $identity = substr(hash('sha256', (string)($profile['config']['pki']['cert'] ?? '') . $value), 0, 16);
        $this->send('certificate ' . $identity . ' ' . ($expired ? 'expired' : $severity),
            $expired ? 'Nebula certificate expired' : 'Nebula certificate is expiring',
            'The host certificate ' . ($expired ? 'expired' : 'expires') . ' on ' . $expiry->format(DATE_ATOM) .
            '. Import a renewed profile in Settings > Nebula.', $severity);
    }
}
