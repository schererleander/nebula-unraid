<?php
declare(strict_types=1);

namespace Nebula;

final class Watcher
{
    private bool $running = true;

    public function __construct(private readonly System $system, private readonly Utils $utils) {}

    public function run(bool $once = false): void
    {
        $failures = 0;
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void { $this->running = false; });
        pcntl_signal(SIGINT, function (): void { $this->running = false; });
        do {
            try {
                // A detached stop refresh and a new watcher may overlap.
                $lock = $this->utils->lock('integration', true);
                try {
                    if (is_file('/var/local/emhttp/var.ini')) {
                        $interfaces = net_get_interfaces();
                        if ($interfaces === false) {
                            throw new \RuntimeException('Cannot read network interfaces.');
                        }
                        $addresses = array_column($interfaces[Paths::INTERFACE]['unicast'] ?? [], 'address');
                        $this->system->sync($addresses);
                        $failures = 0;
                    }
                } finally {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
            } catch (\Throwable $error) {
                $this->utils->log('Service integration: ' . $error->getMessage());
                if (++$failures === 3) {
                    (new Notifications($this->utils))->failure('integration');
                }
                if ($once) {
                    throw $error;
                }
            }
            if (!$once && $this->running) {
                sleep(5);
            }
        } while (!$once && $this->running);
    }
}
