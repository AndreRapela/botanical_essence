import { randomBytes } from 'node:crypto';
import { appendFile, lstat } from 'node:fs/promises';
import { isAbsolute, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export function disposableDatabaseCredentials() {
  const applicationPassword = randomBytes(32).toString('hex');
  let rootPassword;
  do {
    rootPassword = randomBytes(32).toString('hex');
  } while (rootPassword === applicationPassword);
  return { DB_PASSWORD: applicationPassword, DB_ROOT_PASSWORD: rootPassword };
}

/** Export only disposable database credentials through GitHub's runner-owned file. */
export async function prepareCIEnvironment({
  githubActions = process.env.GITHUB_ACTIONS,
  environmentFile = process.env.GITHUB_ENV,
  writeCommand = (command) => process.stdout.write(`${command}\n`)
} = {}) {
  if (githubActions !== 'true' || !environmentFile || !isAbsolute(environmentFile)) {
    throw new Error('Database credential export requires GitHub Actions and an absolute GITHUB_ENV path.');
  }
  const destination = await lstat(environmentFile);
  if (!destination.isFile() || destination.isSymbolicLink()) {
    throw new Error('GITHUB_ENV must be an existing regular file, not a symlink.');
  }

  const credentials = disposableDatabaseCredentials();
  // Register masks before any subsequent step can expose these values in logs.
  for (const value of Object.values(credentials)) writeCommand(`::add-mask::${value}`);
  await appendFile(environmentFile,
    `${Object.entries(credentials).map(([name, value]) => `${name}=${value}`).join('\n')}\n`,
    { encoding: 'utf8' });
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    await prepareCIEnvironment();
    console.log('Disposable CI database credentials configured and masked.');
  } catch {
    // Do not dump environment values, generated passwords, or filesystem diagnostics.
    console.error('Could not configure disposable CI database credentials. Check the GitHub Actions environment file.');
    process.exitCode = 1;
  }
}
