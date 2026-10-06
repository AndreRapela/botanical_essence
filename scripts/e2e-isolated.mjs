import { randomBytes } from 'node:crypto';
import { spawn } from 'node:child_process';
import { chmod, mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { dirname, join, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const composeFile = join(root, 'docker-compose.yml');
const outputDir = join(root, 'node_modules', '@playwright', 'test', 'cli.js');
const suffix = randomBytes(6).toString('hex');
const project = `botanical-e2e-${suffix}`;
const reservedPorts = new Set();
const frontendPort = await allocatePort(reservedPorts);
const backendPort = await allocatePort(reservedPorts);
const mysqlPort = await allocatePort(reservedPorts);
const appUrl = `http://127.0.0.1:${frontendPort}`;
const tempDir = await mkdtemp(join(tmpdir(), `botanical-essence-e2e-${suffix}-`));
const envFile = join(tempDir, '.env');
const resultsDir = join(tempDir, 'playwright-results');
const storageDir = join(tempDir, 'storage');
const database = `botanical_${suffix}_e2e`;
const username = `botanical_${suffix}`;
const databasePassword = randomBytes(32).toString('hex');
const rootPassword = randomBytes(32).toString('hex');

let stackMayExist = false;
let taskError = null;
let cleanupError = null;

try {
  await assertProjectIsUnused(project);
  if (process.platform !== 'win32') await chmod(tempDir, 0o700);
  await mkdir(storageDir, { recursive: true });
  await writeFile(envFile, makeEnvironment(), { encoding: 'utf8', mode: 0o600, flag: 'wx' });
  if (process.platform !== 'win32') await chmod(envFile, 0o600);

  console.log(`Starting disposable E2E stack ${project} at ${appUrl}. Stripe credentials are disabled.`);
  await runCompose(['config', '--quiet']);
  stackMayExist = true;
  await runCompose(['up', '--detach', '--build', '--wait', '--wait-timeout', '240']);

  await runCompose(['exec', '--no-TTY', 'backend', 'php', '/var/www/html/tests/run.php']);
  await runNode(join(root, 'scripts', 'api-smoke.mjs'), {
    API_BASE_URL: appUrl,
    REQUIRE_DEMO_AUTH_TESTS: 'true',
    ALLOW_TEST_ORDER_CREATION: 'true',
    PAYMENT_EXECUTION_MODE: 'local_simulation',
    PAYMENT_TEST_MODE: 'true',
    STRIPE_SECRET_KEY: '',
    STRIPE_WEBHOOK_SECRET: '',
  });
  await mkdir(resultsDir, { recursive: true });
  await runNode(outputDir, [
    'test',
    '--project=chromium',
    '--project=webkit-storefront',
  ], {
    E2E_BASE_URL: appUrl,
    REQUIRE_DEMO_AUTH_TESTS: 'true',
    ALLOW_TEST_ORDER_CREATION: 'true',
    PAYMENT_EXECUTION_MODE: 'local_simulation',
    PAYMENT_TEST_MODE: 'true',
    STRIPE_SECRET_KEY: '',
    STRIPE_WEBHOOK_SECRET: '',
    PLAYWRIGHT_OUTPUT_DIR: resultsDir,
  });
} catch (error) {
  taskError = error;
  if (stackMayExist) {
    try {
      const logs = await capture('docker', composeArgs(['logs', '--no-color', '--tail=120', 'backend']));
      const safeLogs = logs
        .replaceAll(databasePassword, '[redacted]')
        .replaceAll(rootPassword, '[redacted]')
        .replaceAll(username, '[redacted]')
        .replaceAll(database, '[redacted]')
        .replace(/((?:password|secret|token|authorization)["'=:\s]+)[^\s,;]+/gi, '$1[redacted]');
      const failures = safeLogs.split(/\r?\n/)
        .filter((line) => /fatal|exception|error|failed|denied|warning/i.test(line))
        .slice(-30);
      if (failures.length) console.error(`Disposable backend diagnostics:\n${failures.join('\n')}`);
    } catch {
      console.error('Could not retrieve logs from the disposable backend before cleanup.');
    }
  }
} finally {
  if (stackMayExist) {
    try {
      await runCompose(['down', '--volumes', '--remove-orphans']);
    } catch (error) {
      cleanupError = error;
    }
  }

  if (!cleanupError) {
    await removeTemporaryDirectory(tempDir);
  }
}

if (cleanupError) {
  console.error(`Cleanup failed; isolated credentials and artifacts were preserved in ${tempDir}.`);
  console.error(`After checking the exact project name, clean only this stack with: docker compose --project-name ${project} --file "${composeFile}" --env-file "${envFile}" down --volumes --remove-orphans`);
  if (taskError) throw new AggregateError([taskError, cleanupError], 'E2E run and isolated-stack cleanup both failed.');
  throw cleanupError;
}

if (taskError) throw taskError;
console.log('Disposable E2E stack, database volume and temporary artifacts were removed.');

function makeEnvironment() {
  const values = {
    APP_ENV: 'development',
    APP_URL: appUrl,
    FRONTEND_PORT: String(frontendPort),
    BACKEND_PORT: String(backendPort),
    MYSQL_PORT: String(mysqlPort),
    BACKEND_STORAGE_PATH: storageDir,
    CORS_ALLOWED_ORIGINS: appUrl,
    DB_DATABASE: database,
    DB_USERNAME: username,
    DB_PASSWORD: databasePassword,
    DB_ROOT_PASSWORD: rootPassword,
    ENABLE_DEMO_ACCOUNTS: 'true',
    ALLOW_TEST_ORDER_CREATION: 'true',
    ALLOW_DISPOSABLE_DB_TESTS: 'true',
    TRUST_PROXY_HEADERS: 'true',
    TRUSTED_PROXY_IPS: '',
    TRUSTED_PROXY_HOSTS: 'frontend',
    MAIL_TRANSPORT: 'log',
    MAIL_FROM: 'no-reply@botanicalessence.be',
    MAIL_HOST: '',
    MAIL_PORT: '587',
    MAIL_ENCRYPTION: 'tls',
    MAIL_USERNAME: '',
    MAIL_PASSWORD: '',
    PAYMENT_EXECUTION_MODE: 'local_simulation',
    PAYMENT_TEST_MODE: 'true',
    PAYMENT_TEST_PRODUCT_SLUG: 'monstera-essencial',
    PAYMENT_INSTRUCTIONS: '',
    STRIPE_SECRET_KEY: '',
    STRIPE_WEBHOOK_SECRET: '',
    STRIPE_ACCOUNT_ID: '',
    STRIPE_SETTLEMENT_CURRENCY: '',
    STRIPE_PAYMENT_LIMITS_JSON: '',
  };

  for (const [key, value] of Object.entries(values)) {
    if (/[\r\n]/.test(value)) throw new Error(`Unsafe newline in generated ${key}.`);
  }
  return `${Object.entries(values).map(([key, value]) => `${key}=${JSON.stringify(value)}`).join('\n')}\n`;
}

async function allocatePort(reserved) {
  for (let attempt = 0; attempt < 10; attempt += 1) {
    const server = await new Promise((resolveServer, reject) => {
      const candidate = createServer();
      candidate.once('error', reject);
      candidate.listen(0, '127.0.0.1', () => resolveServer(candidate));
    });
    const { port } = server.address();
    await new Promise((resolveClose, reject) => server.close((error) => error ? reject(error) : resolveClose()));
    if (!reserved.has(port)) {
      reserved.add(port);
      return port;
    }
  }
  throw new Error('Could not allocate three distinct temporary ports.');
}

async function assertProjectIsUnused(name) {
  for (const resource of ['container', 'network', 'volume']) {
    const args = resource === 'container'
      ? ['ps', '--all', '--quiet', '--filter', `label=com.docker.compose.project=${name}`]
      : resource === 'network'
        ? ['network', 'ls', '--quiet', '--filter', `label=com.docker.compose.project=${name}`]
        : ['volume', 'ls', '--quiet', '--filter', `label=com.docker.compose.project=${name}`];
    const existing = await capture('docker', args);
    if (existing.trim()) throw new Error(`Refusing to reuse existing Docker ${resource} resources named for ${name}.`);
  }
}

function composeArgs(args) {
  return [
    'compose',
    '--project-name', project,
    '--file', composeFile,
    '--env-file', envFile,
    ...args,
  ];
}

function runCompose(args) {
  return run('docker', composeArgs(args));
}

function runNode(entry, argsOrEnvironment = {}, maybeEnvironment = undefined) {
  const args = Array.isArray(argsOrEnvironment) ? argsOrEnvironment : [];
  const extraEnvironment = Array.isArray(argsOrEnvironment) ? maybeEnvironment : argsOrEnvironment;
  return run(process.execPath, [entry, ...args], {
    cwd: root,
    env: {
      ...process.env,
      ...extraEnvironment,
    },
  });
}

function run(command, args, options = {}) {
  return new Promise((resolveRun, reject) => {
    const child = spawn(command, args, {
      cwd: options.cwd ?? root,
      env: options.env ?? process.env,
      stdio: 'inherit',
      windowsHide: true,
    });
    child.once('error', reject);
    child.once('close', (code, signal) => {
      if (code === 0) resolveRun();
      else reject(new Error(`${command} exited with ${signal ? `signal ${signal}` : `status ${code}`}.`));
    });
  });
}

function capture(command, args) {
  return new Promise((resolveCapture, reject) => {
    let stdout = '';
    let stderr = '';
    const child = spawn(command, args, { cwd: root, windowsHide: true });
    child.stdout.setEncoding('utf8');
    child.stderr.setEncoding('utf8');
    child.stdout.on('data', (chunk) => { stdout += chunk; });
    child.stderr.on('data', (chunk) => { stderr += chunk; });
    child.once('error', reject);
    child.once('close', (code) => {
      if (code === 0) resolveCapture(stdout);
      else reject(new Error(`${command} resource preflight failed (${code}): ${stderr.trim()}`));
    });
  });
}

async function removeTemporaryDirectory(path) {
  const resolvedTempRoot = resolve(tmpdir());
  const resolvedTarget = resolve(path);
  if (dirname(resolvedTarget) !== resolvedTempRoot
    || !resolvedTarget.startsWith(`${resolvedTempRoot}${sep}botanical-essence-e2e-${suffix}-`)) {
    throw new Error('Refusing to remove an unexpected temporary artifact path.');
  }
  await rm(resolvedTarget, { recursive: true, force: true, maxRetries: 3, retryDelay: 100 });
}
