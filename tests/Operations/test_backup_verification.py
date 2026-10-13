"""Exercise the operator script with synthetic archives and a fake Docker CLI."""
import hashlib
import io
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class BackupVerificationTest(unittest.TestCase):
    def archive(self, directory, malicious=False):
        archive = directory / 'synthetic.tar.gz'
        storage_bytes = io.BytesIO()
        with tarfile.open(fileobj=storage_bytes, mode='w:gz') as storage:
            member = tarfile.TarInfo('storage')
            member.type = tarfile.DIRTYPE
            storage.addfile(member)
        files = {'database.sql': b'', 'storage.tar.gz': storage_bytes.getvalue(),
                 'environment.env': b'APP_ENV=testing\n'}
        files['SHA256SUMS'] = ''.join(
            f'{hashlib.sha256(data).hexdigest()}  {name}\n'
            for name, data in files.items()).encode()
        if malicious:
            files['../../escape'] = b'rejected'
        with tarfile.open(archive, 'w:gz') as outer:
            for name, data in files.items():
                member = tarfile.TarInfo(name)
                member.size = len(data)
                outer.addfile(member, io.BytesIO(data))
        return archive

    def invoke(self, directory, archive, fail=False):
        docker = directory / 'docker'
        docker.write_text('''#!/bin/sh
printf '%s\\n' "$*" >> "$DOCKER_LOG"
case "$1" in
run) exit 0 ;;
exec) if [ "$FAIL_RESTORE" = 1 ]; then
    case "$*" in *'SELECT 1'*) exit 0;; *) exit 1;; esac
fi ;;
rm) exit 0 ;;
*) exit 99 ;;
esac
''')
        docker.chmod(0o700)
        env = dict(os.environ, PATH=f'{directory}:{os.environ["PATH"]}',
                   DOCKER_LOG=str(directory / 'docker.log'), FAIL_RESTORE=str(int(fail)))
        result = subprocess.run(['bash', str(ROOT / 'scripts/verify-backup.sh'), str(archive)],
                                env=env, capture_output=True, text=True)
        log = directory / 'docker.log'
        return result, log.read_text() if log.exists() else ''

    def test_restores_only_in_disposable_server_without_network_or_host_mounts(self):
        with tempfile.TemporaryDirectory() as temp:
            directory = Path(temp)
            result, log = self.invoke(directory, self.archive(directory))
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertIn('--network none', log)
            self.assertIn('--tmpfs /var/lib/mysql:', log)
            self.assertNotIn('compose', log)
            self.assertNotIn('--volume', log)
            self.assertIn('rm -f contracts-backup-verify-', log)

    def test_failed_restore_removes_disposable_server(self):
        with tempfile.TemporaryDirectory() as temp:
            directory = Path(temp)
            result, log = self.invoke(directory, self.archive(directory), fail=True)
            self.assertNotEqual(0, result.returncode)
            self.assertIn('rm -f contracts-backup-verify-', log)
            self.assertNotIn('compose', log)

    def test_traversal_archive_is_rejected_before_starting_mysql(self):
        with tempfile.TemporaryDirectory() as temp:
            directory = Path(temp)
            result, log = self.invoke(directory, self.archive(directory, malicious=True))
            self.assertNotEqual(0, result.returncode)
            self.assertEqual('', log)


if __name__ == '__main__':
    unittest.main()
