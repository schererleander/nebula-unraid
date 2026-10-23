"""Exercise process recovery without a tunnel or root privileges."""

import fcntl
import os
from pathlib import Path
import signal
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[1]


class SupervisorTests(unittest.TestCase):
    def test_stop_detaches_slow_refresh_and_releases_service_lock(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for name in ['scripts', 'run']:
                (root / name).mkdir()
            watch = root / 'scripts/watch'
            watch.write_text('#!/bin/sh\necho $$ > "$NEBULA_RUN/refresh.pid"\nexec sleep 300\n')
            watch.chmod(0o755)
            (root / 'run/config.yml').write_text('runtime credentials')
            environment = dict(os.environ, NEBULA_ROOT=directory, NEBULA_RUN=str(root / 'run'),
                               NEBULA_LOG=str(root / 'log'))
            process = subprocess.Popen(['bash', str(ROOT / 'src/etc/rc.d/rc.nebula'), 'stop'],
                                       env=environment, start_new_session=True,
                                       stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            try:
                process.communicate(timeout=3)
                self.assertEqual(process.returncode, 0)
                deadline = time.monotonic() + 2
                while not (root / 'run/refresh.pid').exists() and time.monotonic() < deadline:
                    time.sleep(.05)
                refresh = int((root / 'run/refresh.pid').read_text())
                os.kill(refresh, 0)
                self.assertFalse((root / 'run/config.yml').exists())
                with (root / 'run/service.lock').open() as lock:
                    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    status = subprocess.run(['bash', str(ROOT / 'src/etc/rc.d/rc.nebula'), 'status'],
                                            env=environment, timeout=1, capture_output=True)
                    self.assertEqual(status.returncode, 1, 'status must not wait for the service lock')
            finally:
                try:
                    os.killpg(process.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                process.communicate(timeout=3)

    def test_recovers_each_process_and_cleans_up_on_stop(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for name in ['scripts', 'bin', 'run']:
                (root / name).mkdir()
            for name in ['scripts/watch', 'bin/nebula']:
                script = root / name
                script.write_text('#!/bin/sh\nexec sleep 300\n')
                script.chmod(0o755)
            environment = dict(os.environ, NEBULA_ROOT=directory, NEBULA_LIBRARY=directory,
                               NEBULA_RUN=str(root / 'run'), NEBULA_LOG=str(root / 'log'))
            process = subprocess.Popen(['bash', str(ROOT / 'src/usr/local/emhttp/plugins/nebula/scripts/supervise')],
                                       env=environment, start_new_session=True,
                                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

            def pid(name):
                try:
                    return int((root / 'run' / f'{name}.pid').read_text())
                except (FileNotFoundError, ValueError):
                    return None

            def until(condition):
                deadline = time.monotonic() + 8
                while time.monotonic() < deadline:
                    self.assertIsNone(process.poll(), 'supervisor exited unexpectedly')
                    if condition():
                        return
                    time.sleep(.05)
                self.fail('process did not start or recover within eight seconds')

            try:
                until(lambda: pid('nebula') and pid('watcher'))
                for failed, healthy in [('watcher', 'nebula'), ('nebula', 'watcher')]:
                    old_pid, healthy_pid = pid(failed), pid(healthy)
                    os.kill(old_pid, signal.SIGKILL)
                    until(lambda: pid(failed) and pid(failed) != old_pid)
                    self.assertEqual(pid(healthy), healthy_pid, 'healthy process was restarted')
                children = [pid('nebula'), pid('watcher')]
                process.terminate()
                self.assertEqual(process.wait(timeout=3), 0)
                for name, child in zip(['nebula', 'watcher'], children):
                    self.assertFalse((root / 'run' / f'{name}.pid').exists())
                    with self.assertRaises(ProcessLookupError):
                        os.kill(child, 0)
            finally:
                try:
                    os.killpg(process.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                process.wait(timeout=3)


if __name__ == '__main__':
    unittest.main()
