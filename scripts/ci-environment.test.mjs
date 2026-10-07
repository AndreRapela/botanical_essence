import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdtemp, readFile, rm, symlink, writeFile } from 'node:fs/promises';
import { readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { disposableDatabaseCredentials, prepareCIEnvironment } from './ci-environment.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

async function withRunnerFile(operation) {
  const directory = await mkdtemp(join(tmpdir(), 'botanical-ci-env-test-'));
  const environmentFile = join(directory, 'github_env');
  const fixture = { directory, preserve: false };
  await writeFile(environmentFile, 'ALREADY_SET=yes\n', { mode: 0o600 });
  try {
    return await operation(environmentFile, fixture);
  } finally {
    if (fixture.preserve) {
      console.warn(`Disposable CI diagnostic files preserved at ${directory}.`);
    } else {
      if (dirname(resolve(directory)) !== resolve(tmpdir()) || !basename(directory).startsWith('botanical-ci-env-test-')) {
        throw new Error('Refusing to remove an unexpected CI fixture path.');
      }
      await rm(directory, { recursive: true, force: true });
    }
  }
}

test('database credentials are separate fresh 256-bit values, not placeholders', () => {
  const first = disposableDatabaseCredentials();
  const second = disposableDatabaseCredentials();
  assert.deepEqual(Object.keys(first), ['DB_PASSWORD', 'DB_ROOT_PASSWORD']);
  for (const value of [...Object.values(first), ...Object.values(second)]) {
    assert.match(value, /^[a-f0-9]{64}$/);
  }
  assert.equal(new Set([...Object.values(first), ...Object.values(second)]).size, 4);
});

test('GitHub export masks both secrets before appending and preserves existing variables', async () => {
  await withRunnerFile(async (environmentFile) => {
    const commands = [];
    await prepareCIEnvironment({ githubActions: 'true', environmentFile, writeCommand: (command) => {
      assert.equal(readFileSync(environmentFile, 'utf8'), 'ALREADY_SET=yes\n');
      commands.push(command);
    } });
    const lines = (await readFile(environmentFile, 'utf8')).trim().split('\n');
    assert.equal(lines[0], 'ALREADY_SET=yes');
    assert.equal(lines.length, 3);
    const values = Object.fromEntries(lines.slice(1).map((line) => line.split('=')));
    assert.deepEqual(commands, Object.values(values).map((value) => `::add-mask::${value}`));
    assert.match(values.DB_PASSWORD, /^[a-f0-9]{64}$/);
    assert.match(values.DB_ROOT_PASSWORD, /^[a-f0-9]{64}$/);
    assert.notEqual(values.DB_PASSWORD, values.DB_ROOT_PASSWORD);
  });
});

test('export outside GitHub Actions never writes to the supplied file', async () => {
  await withRunnerFile(async (environmentFile) => {
    await assert.rejects(prepareCIEnvironment({ githubActions: 'false', environmentFile }), /requires GitHub Actions/);
    assert.equal(await readFile(environmentFile, 'utf8'), 'ALREADY_SET=yes\n');
  });
});

test('export refuses missing or relative runner paths', async () => {
  for (const environmentFile of ['', 'github_env']) {
    await assert.rejects(prepareCIEnvironment({ githubActions: 'true', environmentFile }), /absolute GITHUB_ENV/);
  }
});

test('export refuses nonexistent files and directories', async () => {
  await withRunnerFile(async (_environmentFile, fixture) => {
    await assert.rejects(prepareCIEnvironment({ githubActions: 'true', environmentFile: join(fixture.directory, 'missing') }));
    await assert.rejects(prepareCIEnvironment({ githubActions: 'true', environmentFile: fixture.directory }), /regular file/);
  });
});

test('export refuses a symlink without modifying its target', { skip: process.platform === 'win32' }, async () => {
  await withRunnerFile(async (environmentFile, fixture) => {
    const link = join(fixture.directory, 'env_link');
    await symlink(environmentFile, link);
    await assert.rejects(prepareCIEnvironment({ githubActions: 'true', environmentFile: link }), /regular file/);
    assert.equal(await readFile(environmentFile, 'utf8'), 'ALREADY_SET=yes\n');
  });
});

test('CLI fails closed outside Actions without printing or writing credentials', async () => {
  await withRunnerFile(async (environmentFile) => {
    const result = spawnSync(process.execPath, [join(root, 'scripts/ci-environment.mjs')], {
      env: { ...process.env, GITHUB_ACTIONS: 'false', GITHUB_ENV: environmentFile },
      encoding: 'utf8', windowsHide: true, timeout: 10_000,
    });
    assert.equal(result.status, 1);
    assert.equal(result.stdout, '');
    assert.match(result.stderr, /Could not configure disposable CI database credentials/);
    assert.doesNotMatch(result.stderr, /[a-f0-9]{64}/);
    assert.equal(await readFile(environmentFile, 'utf8'), 'ALREADY_SET=yes\n');
  });
});

test('CLI exports masked credentials to the runner file for subsequent workflow steps', async () => {
  await withRunnerFile(async (environmentFile) => {
    const result = spawnSync(process.execPath, [join(root, 'scripts/ci-environment.mjs')], {
      env: { ...process.env, GITHUB_ACTIONS: 'true', GITHUB_ENV: environmentFile },
      encoding: 'utf8', windowsHide: true, timeout: 10_000,
    });
    assert.equal(result.status, 0);
    assert.equal(result.stderr, '');
    const values = Object.fromEntries((await readFile(environmentFile, 'utf8')).trim().split('\n').slice(1).map((line) => line.split('=')));
    const expectedMasks = Object.values(values).map((value) => `::add-mask::${value}`);
    assert.deepEqual(result.stdout.trim().split(/\r?\n/), [
      ...expectedMasks, 'Disposable CI database credentials configured and masked.',
    ]);
  });
});

test('workflow generates credentials before Compose and includes the regression gate', async () => {
  const workflow = await readFile(join(root, '.github/workflows/quality.yml'), 'utf8');
  assert.doesNotMatch(workflow, /^\s*DB_(?:ROOT_)?PASSWORD\s*:/m);
  const generation = workflow.indexOf('run: node scripts/ci-environment.mjs');
  const startup = workflow.indexOf('run: docker compose up');
  assert.ok(generation >= 0 && startup > generation);
  assert.match(workflow, /RUN_DOCKER_CI_ENV_TEST: "true"/);
  const manifest = JSON.parse(await readFile(join(root, 'package.json'), 'utf8'));
  assert.match(manifest.scripts['verify:full'], /npm run test:ci/);
});

test('MySQL readiness uses TCP so the skip-networking bootstrap server cannot pass', async () => {
  const compose = await readFile(join(root, 'docker-compose.yml'), 'utf8');
  assert.match(compose, /mysql --protocol=TCP -h 127\.0\.0\.1 -uroot --connect-timeout=3/);
});

test('generated runner credentials start the actual Compose MySQL healthcheck', {
  skip: process.env.RUN_DOCKER_CI_ENV_TEST !== 'true', timeout: 240_000,
}, async () => {
  await withRunnerFile(async (environmentFile, fixture) => {
    await prepareCIEnvironment({ githubActions: 'true', environmentFile, writeCommand: () => {} });
    const suffix = randomBytes(6).toString('hex');
    const project = `botanical-ci-env-${suffix}`;
    const environment = {
      ...process.env, APP_ENV: 'development', MYSQL_PORT: '0',
      DB_DATABASE: `botanical_${suffix}_ci`, DB_USERNAME: `botanical_${suffix}`,
    };
    // Compose must consume the generated runner file, not credentials inherited
    // from a user's active stack or the surrounding CI application stack.
    delete environment.DB_PASSWORD;
    delete environment.DB_ROOT_PASSWORD;
    delete environment.COMPOSE_ENV_FILES;
    const composeArgs = ['compose', '--project-name', project, '--file', join(root, 'docker-compose.yml'), '--env-file', environmentFile];
    const credentials = Object.fromEntries((await readFile(environmentFile, 'utf8')).trim().split('\n').map((line) => line.split('=')));
    const docker = (args) => {
      const result = spawnSync('docker', args, { cwd: root, env: environment, encoding: 'utf8', windowsHide: true, timeout: 120_000 });
      if (result.status !== 0) {
        let diagnostic = String(result.stderr || result.error?.message || 'Docker operation failed.');
        for (const key of ['DB_PASSWORD', 'DB_ROOT_PASSWORD']) diagnostic = diagnostic.replaceAll(credentials[key], '[redacted]');
        throw new Error(`Disposable CI Docker check failed: ${diagnostic.slice(-2000)}`);
      }
      return result.stdout;
    };
    for (const [resource, args] of [
      ['container', ['ps', '--all', '--quiet']], ['network', ['network', 'ls', '--quiet']], ['volume', ['volume', 'ls', '--quiet']],
    ]) {
      assert.equal(docker([...args, '--filter', `label=com.docker.compose.project=${project}`]).trim(), '', `Refusing to reuse ${resource} resources.`);
    }

    try {
      docker([...composeArgs, 'up', '--detach', '--no-build', '--wait', '--wait-timeout', '90', 'mysql']);
      const containerId = docker([...composeArgs, 'ps', '--quiet', 'mysql']).trim();
      assert.match(containerId, /^[a-f0-9]{12,64}$/);
      assert.equal(docker(['inspect', '--format', '{{.State.Health.Status}}', containerId]).trim(), 'healthy');
      const probe = docker([...composeArgs, 'exec', '--no-TTY', 'mysql', 'sh', '-ec',
        'MYSQL_PWD="$MYSQL_PASSWORD" mysql --protocol=TCP --host=127.0.0.1 --user="$MYSQL_USER" --database="$MYSQL_DATABASE" --silent --skip-column-names -e "SELECT 1"']);
      assert.equal(probe.trim(), '1');
    } finally {
      try {
        docker([...composeArgs, 'down', '--volumes', '--remove-orphans']);
      } catch (error) {
        fixture.preserve = true;
        throw error;
      }
    }
  });
});
