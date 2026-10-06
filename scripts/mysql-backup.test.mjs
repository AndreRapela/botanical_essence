import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { chmod, mkdtemp, mkdir, readdir, readFile, rm, stat } from 'node:fs/promises';
import { homedir, tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import test from 'node:test';
import { Readable } from 'node:stream';
import {
  checkedDocker,
  createBackupDecryptor,
  createBackupEncryptor,
  createAndVerifyBackup,
  isInside,
  isPinnedMysqlImage,
  mysqlInitializationIsComplete,
  mysqlComposeConfiguration,
  parseBackupDumpTimeout,
  parseBackupKey,
  validateBackupDirectory,
  windowsBackupAclHasUnsafeWriteGrant,
  writeEncryptedBackup
} from './mysql-backup.mjs';

const scriptPath = resolve('scripts/mysql-backup.mjs');

function restrictWindowsBackupFixture(directory) {
  const identity = spawnSync('whoami.exe', ['/user', '/fo', 'csv', '/nh'], { encoding: 'utf8', windowsHide: true });
  const sid = identity.status === 0 ? identity.stdout.match(/S-\d-\d+(?:-\d+)+/)?.[0] : null;
  if (!sid) return false;
  const result = spawnSync('icacls.exe', [directory, '/inheritance:r', '/grant:r',
    `*${sid}:(OI)(CI)F`, '*S-1-5-18:(OI)(CI)F', '*S-1-5-32-544:(OI)(CI)F'], { encoding: 'utf8', windowsHide: true });
  return result.status === 0;
}

test('backup creation is reported as successful only after isolated restore verification', async () => {
  const calls = [];
  const file = await createAndVerifyBackup(async () => {
    calls.push('create');
    return 'C:/protected/botanical-mysql-test.bdbk';
  }, async (createdFile) => {
    calls.push(`verify:${createdFile}`);
  });

  assert.equal(file, 'C:/protected/botanical-mysql-test.bdbk');
  assert.deepEqual(calls, ['create', 'verify:C:/protected/botanical-mysql-test.bdbk']);
});

test('backup verifier does not run after snapshot creation fails', async () => {
  let verified = false;
  await assert.rejects(createAndVerifyBackup(async () => {
    throw new Error('dump failed');
  }, async () => {
    verified = true;
  }), /dump failed/);
  assert.equal(verified, false);
});

test('failed isolated restore verification keeps the encrypted artifact for investigation', async () => {
  await assert.rejects(createAndVerifyBackup(async () => 'C:/protected/snapshot.bdbk', async () => {
    throw new Error('restore check failed');
  }), /snapshot\.bdbk was created but isolated restore verification failed; the artifact is preserved\. restore check failed/);
});

test('backup orchestration rejects a missing artifact path instead of claiming success', async () => {
  await assert.rejects(createAndVerifyBackup(async () => '', async () => {}), /did not return an artifact path/);
});

function seal(plaintext, key) {
  const { header, cipher } = createBackupEncryptor(key);
  return Buffer.concat([header, cipher.update(plaintext), cipher.final(), cipher.getAuthTag()]);
}

function unseal(backup, key) {
  const headerLength = Buffer.from('BOTANICAL-DB-BACKUP').length + 1 + 12;
  const decipher = createBackupDecryptor(
    backup.subarray(0, headerLength),
    backup.subarray(-16),
    key
  );
  return Buffer.concat([decipher.update(backup.subarray(headerLength, -16)), decipher.final()]);
}

test('encrypted stream format round-trips without exposing plaintext', () => {
  const secret = Buffer.from('personal and payment-related fixture data');
  const key = randomBytes(32);
  const encrypted = seal(secret, key);

  assert.notEqual(encrypted.includes(secret), true);
  assert.deepEqual(unseal(encrypted, key), secret);
  assert.equal(parseBackupKey(key.toString('hex')).length, 32);
});

test('snapshot writer encrypts the stream atomically and removes failed partial files', async (t) => {
  const directory = await mkdtemp(join(tmpdir(), 'botanical-backup-write-'));
  const target = join(directory, 'snapshot.bdbk');
  const failedTarget = join(directory, 'rejected.bdbk');
  const plaintext = Buffer.from('synthetic users and orders; never a live database dump');
  const key = randomBytes(32);
  t.after(() => rm(directory, { recursive: true, force: true }));

  await writeEncryptedBackup(Readable.from([plaintext.subarray(0, 16), plaintext.subarray(16)]), target, key);
  const ciphertext = await readFile(target);
  assert.equal(ciphertext.includes(plaintext), false);
  assert.deepEqual(unseal(ciphertext, key), plaintext);
  if (process.platform !== 'win32') assert.equal((await stat(target)).mode & 0o077, 0);
  await assert.rejects(writeEncryptedBackup(Readable.from([]), failedTarget, key), /empty dump/);
  await assert.rejects(writeEncryptedBackup(Readable.from([plaintext]), failedTarget, key, async () => {
    throw new Error('source command failed');
  }), /source command failed/);
  assert.deepEqual(await readdir(directory), ['snapshot.bdbk']);
});

test('encrypted backup rejects altered tags, wrong keys, and malformed key material', () => {
  const key = randomBytes(32);
  const original = seal(Buffer.from('consistent snapshot'), key);
  const changedTag = Buffer.from(original);
  changedTag[changedTag.length - 1] ^= 1;

  assert.throws(() => unseal(changedTag, key));
  assert.throws(() => unseal(original, randomBytes(32)));
  assert.throws(() => parseBackupKey('not-a-key'));
  assert.throws(() => createBackupDecryptor(Buffer.alloc(4), Buffer.alloc(16), key));
});

test('backup destination must be an existing directory outside the checkout', async (t) => {
  const temporaryParent = process.platform === 'win32' ? homedir() : tmpdir();
  const root = await mkdtemp(join(temporaryParent, 'botanical-backup-path-'));
  const workspace = join(root, 'workspace');
  const external = join(root, 'protected');
  await mkdir(workspace);
  await mkdir(external);
  t.after(() => rm(root, { recursive: true, force: true }));

  if (process.platform === 'win32' && !restrictWindowsBackupFixture(external)) {
    t.skip('The runner cannot secure its private temporary ACL fixture.');
    return;
  }

  assert.equal(isInside(workspace, join(workspace, 'backup')), true);
  assert.equal(isInside(workspace, external), false);
  await assert.rejects(validateBackupDirectory(workspace, workspace), /outside the application workspace/);
  assert.equal(await validateBackupDirectory(external, workspace), external);
  await assert.rejects(validateBackupDirectory(join(root, 'missing'), workspace), /ENOENT/);
});

test('backup destination rejects group- or world-writable directories on POSIX', { skip: process.platform === 'win32' }, async (t) => {
  const root = await mkdtemp(join(tmpdir(), 'botanical-backup-permissions-'));
  const workspace = join(root, 'workspace');
  const exposed = join(root, 'exposed');
  await mkdir(workspace);
  await mkdir(exposed);
  await chmod(exposed, 0o777);
  t.after(() => rm(root, { recursive: true, force: true }));

  if (((await stat(exposed)).mode & 0o022) === 0) {
    t.skip('Filesystem does not expose POSIX group/other write bits.');
    return;
  }
  await assert.rejects(validateBackupDirectory(exposed, workspace), /must not be writable by group or other users/);
});

test('Windows backup ACL policy rejects untrusted owners and broad write grants', () => {
  const privateAcl = {
    ownerSid: 'S-1-5-21-1000', currentSid: 'S-1-5-21-1000',
    rules: [
      { sid: 'S-1-5-21-1000', type: 'Allow', rights: 'Modify, Synchronize', appliesToObject: true },
      { sid: 'S-1-5-18', type: 'Allow', rights: 'FullControl', appliesToObject: true },
      { sid: 'S-1-5-32-544', type: 'Allow', rights: 'FullControl', appliesToObject: true },
      { sid: 'S-1-5-11', type: 'Allow', rights: 'ReadAndExecute, Synchronize', appliesToObject: true }
    ],
    ancestors: []
  };
  assert.equal(windowsBackupAclHasUnsafeWriteGrant(privateAcl), false);
  assert.equal(windowsBackupAclHasUnsafeWriteGrant({
    ...privateAcl, rules: [...privateAcl.rules, { sid: 'S-1-5-11', type: 'Allow', rights: '-1610612736', appliesToObject: true }]
  }), false, 'generic read/execute masks remain non-writable');
  assert.equal(windowsBackupAclHasUnsafeWriteGrant({
    ...privateAcl, rules: [...privateAcl.rules, { sid: 'S-1-5-11', type: 'Allow', rights: 'Modify, Synchronize', appliesToObject: true }]
  }), true);
  assert.equal(windowsBackupAclHasUnsafeWriteGrant({
    ...privateAcl, rules: [...privateAcl.rules, { sid: 'S-1-5-11', type: 'Allow', rights: '1073741824', appliesToObject: true }]
  }), true, 'generic write masks are denied');
  assert.equal(windowsBackupAclHasUnsafeWriteGrant({
    ...privateAcl,
    ancestors: [{ ownerSid: 'S-1-5-18', rules: [{ sid: 'S-1-5-11', type: 'Allow', rights: '64', appliesToObject: true }] }]
  }), true, 'delete-child masks on ancestors are denied');
  assert.equal(windowsBackupAclHasUnsafeWriteGrant({ ...privateAcl, ownerSid: 'S-1-5-32-545' }), true);
  assert.equal(windowsBackupAclHasUnsafeWriteGrant({ ...privateAcl, currentSid: '' }), true);
  assert.equal(windowsBackupAclHasUnsafeWriteGrant({ ...privateAcl, rules: [...privateAcl.rules, null] }), true);
  assert.equal(windowsBackupAclHasUnsafeWriteGrant({
    ...privateAcl,
    ancestors: [{ ownerSid: 'S-1-5-18', rules: [{ sid: 'S-1-5-11', type: 'Allow', rights: 'Modify, Synchronize', appliesToObject: true }] }]
  }), true);
  assert.equal(windowsBackupAclHasUnsafeWriteGrant({
    ...privateAcl,
    ancestors: [{ ownerSid: 'S-1-5-18', rules: [{ sid: 'S-1-5-11', type: 'Allow', rights: 'CreateDirectories', appliesToObject: true }] }]
  }), false);
  assert.equal(windowsBackupAclHasUnsafeWriteGrant(null), true);
});

test('Windows backup destination ACL is queried and broad inherited write access is rejected', { skip: process.platform !== 'win32' }, async (t) => {
  const root = await mkdtemp(join(homedir(), 'botanical-backup-acl-'));
  const workspace = join(root, 'workspace');
  const protectedDirectory = join(root, 'protected');
  const exposedDirectory = join(root, 'exposed');
  await mkdir(workspace);
  await mkdir(protectedDirectory);
  await mkdir(exposedDirectory);
  t.after(() => rm(root, { recursive: true, force: true }));

  if (!restrictWindowsBackupFixture(protectedDirectory) || !restrictWindowsBackupFixture(exposedDirectory)) {
    t.skip('The runner cannot secure its private temporary ACL fixtures.');
    return;
  }

  assert.equal(await validateBackupDirectory(protectedDirectory, workspace), protectedDirectory);
  const grant = spawnSync('icacls.exe', [exposedDirectory, '/grant', '*S-1-5-32-545:(OI)(CI)M'], { encoding: 'utf8', windowsHide: true });
  if (grant.error || grant.status !== 0) {
    t.skip('The runner cannot add a broad ACL to its private temporary fixture.');
    return;
  }
  await assert.rejects(validateBackupDirectory(exposedDirectory, workspace), /Windows ACL failed its privacy check/);
});

test('restore verifier requires an immutable MySQL image digest', () => {
  assert.equal(isPinnedMysqlImage(`mysql:8.4@sha256:${'a'.repeat(64)}`), true);
  assert.equal(isPinnedMysqlImage(`registry.example:5000/library/mysql@sha256:${'f'.repeat(64)}`), true);
  assert.equal(isPinnedMysqlImage('mysql:8.4'), false);
  assert.equal(isPinnedMysqlImage(`mysql:8.4@sha256:${'z'.repeat(64)}`), false);
  assert.equal(isPinnedMysqlImage(null), false);
});

test('backup dump timeout has a bounded safe default and rejects malformed overrides', () => {
  assert.equal(parseBackupDumpTimeout(undefined), 60 * 60 * 1000);
  assert.equal(parseBackupDumpTimeout('120000'), 120_000);
  for (const value of ['0', '999', '86400001', '1e6', '1000;whoami', '-1']) {
    assert.throws(() => parseBackupDumpTimeout(value), /MYSQL_BACKUP_DUMP_TIMEOUT_MS/);
  }
});

test('synchronous Docker checks have a deadline instead of hanging indefinitely', () => {
  assert.throws(() => checkedDocker(process.execPath, ['-e', 'setTimeout(() => {}, 5000)'], { timeout: 50 }), /ETIMEDOUT|timed out/i);
});

test('restore verifier waits until the temporary MySQL bootstrap has fully ended', () => {
  assert.equal(mysqlInitializationIsComplete('[Entrypoint] Temporary server started.'), false);
  assert.equal(mysqlInitializationIsComplete('[Entrypoint] MySQL init process done. Ready for start up.'), true);
  assert.equal(mysqlInitializationIsComplete(null), false);
});

test('restore verification imports an authenticated fixture only into isolated ephemeral MySQL', { timeout: 300_000 }, async (t) => {
  const { database } = mysqlComposeConfiguration();
  const temporary = await mkdtemp(join(tmpdir(), 'botanical-backup-verify-'));
  const file = join(temporary, 'fixture.bdbk');
  const key = randomBytes(32);
  const sql = Buffer.from(`
CREATE DATABASE IF NOT EXISTS \`${database}\`;
USE \`${database}\`;
CREATE TABLE users (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TABLE products (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TABLE orders (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TABLE rate_limits (id BIGINT UNSIGNED PRIMARY KEY);
INSERT INTO users VALUES (1);
INSERT INTO products VALUES (2);
INSERT INTO orders VALUES (3);
INSERT INTO rate_limits VALUES (4);
`, 'utf8');
  await writeEncryptedBackup(Readable.from([sql]), file, key);
  t.after(() => rm(temporary, { recursive: true, force: true }));

  const result = spawnSync(process.execPath, [scriptPath, 'verify', file], {
    cwd: resolve('.'),
    env: { ...process.env, MYSQL_BACKUP_KEY_HEX: key.toString('hex') },
    encoding: 'utf8',
    maxBuffer: 64 * 1024,
    timeout: 240_000,
    windowsHide: true
  });

  assert.equal(result.error, undefined, result.error?.message);
  assert.equal(result.status, 0, result.stderr || result.stdout);
  assert.match(result.stdout, /Restore validated from .* using a disposable, isolated MySQL instance/);
  assert.match(result.stdout, /no network, ports, persistent volumes/);
  assert.doesNotMatch(result.stdout, /personal|payment-related fixture data/);
});
