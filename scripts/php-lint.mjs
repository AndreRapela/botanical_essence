import { spawnSync } from 'node:child_process';

const daemon = spawnSync('docker', ['ps', '--quiet'], {
  encoding: 'utf8',
  timeout: 10000,
  windowsHide: true,
  stdio: ['ignore', 'ignore', 'pipe']
});

if (daemon.error || daemon.status !== 0) {
  const reason = daemon.error?.code === 'ETIMEDOUT' ? 'timed out' : 'is unavailable';
  console.error(`Unable to run PHP lint: Docker daemon ${reason}. Start Docker Desktop and retry.`);
  process.exit(1);
}

const command = [
  'compose', 'run', '--rm', '--no-deps', '--build', '--entrypoint', 'sh', 'backend', '-lc',
  "find /var/www/html -path /var/www/html/vendor -prune -o -name '*.php' -type f -print0 | xargs -0 -n1 php -l"
];
const result = spawnSync('docker', command, { stdio: 'inherit', shell: false });
if (result.error) {
  console.error(`Unable to run PHP lint through Docker: ${result.error.message}`);
  process.exit(1);
}
process.exit(result.status ?? 1);
