import { spawn, spawnSync } from 'node:child_process';
import { createCipheriv, createDecipheriv, randomBytes } from 'node:crypto';
import { createReadStream, createWriteStream } from 'node:fs';
import { chmod, open, realpath, rename, rm, stat } from 'node:fs/promises';
import { dirname, isAbsolute, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { once } from 'node:events';
import { pipeline } from 'node:stream/promises';
import { Transform, Writable } from 'node:stream';

const SCRIPT_PATH = fileURLToPath(import.meta.url);
const PROJECT_ROOT = resolve(dirname(SCRIPT_PATH), '..');
const MAGIC = Buffer.from('BOTANICAL-DB-BACKUP');
const FORMAT_VERSION = 1;
const IV_LENGTH = 12;
const TAG_LENGTH = 16;
const HEADER_LENGTH = MAGIC.length + 1 + IV_LENGTH;
const READINESS_COMMAND_TIMEOUT_MS = 5000;
const DOCKER_COMMAND_TIMEOUT_MS = 90_000;
const DOCKER_CLEANUP_TIMEOUT_MS = 10_000;
const DEFAULT_DUMP_TIMEOUT_MS = 60 * 60 * 1000;
const MAX_DUMP_TIMEOUT_MS = 24 * 60 * 60 * 1000;
const RESTORE_TMPFS_SIZE = process.env.MYSQL_BACKUP_VERIFY_TMPFS_SIZE ?? '1g';
const RESTORE_MEMORY = process.env.MYSQL_BACKUP_VERIFY_MEMORY ?? '2g';
const WINDOWS_BACKUP_ACL_QUERY = `$ErrorActionPreference = 'Stop'
$target = $env:BOTANICAL_BACKUP_ACL_TARGET
$directory = [System.IO.DirectoryInfo]::new($target)
$acl = $directory.GetAccessControl()
$identity = [System.Security.Principal.WindowsIdentity]::GetCurrent()
function Get-AclRules($security) {
  @($security.GetAccessRules($true, $true, [System.Security.Principal.SecurityIdentifier]) | ForEach-Object {
    [pscustomobject]@{
      sid = $_.IdentityReference.Value
      type = $_.AccessControlType.ToString()
      rights = $_.FileSystemRights.ToString()
      appliesToObject = ($_.PropagationFlags.ToString() -notmatch 'InheritOnly')
    }
  })
}
$rules = Get-AclRules $acl
$ancestors = @()
$parent = $directory.Parent
while ($null -ne $parent) {
  $parentAcl = $parent.GetAccessControl()
  $ancestors += [pscustomobject]@{
    ownerSid = $parentAcl.GetOwner([System.Security.Principal.SecurityIdentifier]).Value
    rules = (Get-AclRules $parentAcl)
  }
  $parent = $parent.Parent
}
$result = [pscustomobject]@{
  ownerSid = $acl.GetOwner([System.Security.Principal.SecurityIdentifier]).Value
  currentSid = $identity.User.Value
  rules = $rules
  ancestors = $ancestors
}
[Console]::Out.WriteLine((ConvertTo-Json -InputObject $result -Depth 4 -Compress))`;
const WINDOWS_SYSTEM_SID = 'S-1-5-18';
const WINDOWS_ADMINISTRATORS_SID = 'S-1-5-32-544';
const WINDOWS_TRUSTED_INSTALLER_SID = 'S-1-5-80-956008885-3418522649-1831038044-1853292631-2271478464';
const WINDOWS_SID_FORMAT = /^S-\d-\d+(?:-\d+)+$/i;
const WINDOWS_FILESYSTEM_RIGHTS = new Set([
  'ReadData', 'ListDirectory', 'WriteData', 'CreateFiles', 'AppendData', 'CreateDirectories', 'ReadExtendedAttributes',
  'WriteExtendedAttributes', 'ExecuteFile', 'Traverse', 'DeleteSubdirectoriesAndFiles', 'Delete', 'ReadAttributes',
  'WriteAttributes', 'Write', 'Read', 'ReadAndExecute', 'Modify', 'FullControl', 'ReadPermissions', 'ChangePermissions',
  'TakeOwnership', 'Synchronize'
]);
const WINDOWS_FILESYSTEM_KNOWN_MASK = 0x001f01ffn | 0xf0000000n;
const WINDOWS_TARGET_WRITE_MASK = 0x00000002n | 0x00000004n | 0x00000010n | 0x00000040n | 0x00000100n
  | 0x00010000n | 0x00040000n | 0x00080000n | 0x10000000n | 0x40000000n;
const WINDOWS_ANCESTOR_DELETE_MASK = 0x00000040n | 0x00010000n | 0x00040000n | 0x00080000n | 0x10000000n;

function analyzeWindowsFilesystemRights(value) {
  const tokens = value.split(',').map((token) => token.trim());
  if (!tokens.length || tokens.some((token) => !token)) return null;
  let targetWrite = false;
  let ancestorDelete = false;
  for (const token of tokens) {
    if (WINDOWS_FILESYSTEM_RIGHTS.has(token)) {
      if (/^(?:Write|Modify|FullControl|AppendData|CreateFiles|CreateDirectories|WriteData|WriteAttributes|WriteExtendedAttributes|Delete|DeleteSubdirectoriesAndFiles|ChangePermissions|TakeOwnership)$/i.test(token)) targetWrite = true;
      if (/^(?:Modify|FullControl|Delete|DeleteSubdirectoriesAndFiles|ChangePermissions|TakeOwnership)$/i.test(token)) ancestorDelete = true;
      continue;
    }
    if (!/^-?\d+$/.test(token)) return null;
    const mask = BigInt.asUintN(32, BigInt(token));
    if ((mask & ~WINDOWS_FILESYSTEM_KNOWN_MASK) !== 0n) return null;
    targetWrite ||= (mask & WINDOWS_TARGET_WRITE_MASK) !== 0n;
    ancestorDelete ||= (mask & WINDOWS_ANCESTOR_DELETE_MASK) !== 0n;
  }
  return { targetWrite, ancestorDelete };
}

export function parseBackupKey(value) {
  if (!/^[a-f0-9]{64}$/i.test(value ?? '')) {
    throw new Error('MYSQL_BACKUP_KEY_HEX must be a 64-character hexadecimal key supplied by the approved secret manager.');
  }
  return Buffer.from(value, 'hex');
}

export function parseBackupDumpTimeout(value) {
  if (value === undefined || value === null || value === '') return DEFAULT_DUMP_TIMEOUT_MS;
  if (typeof value !== 'string' || !/^\d+$/.test(value)) {
    throw new Error('MYSQL_BACKUP_DUMP_TIMEOUT_MS must be an integer between 1000 and 86400000.');
  }
  const timeout = Number(value);
  if (!Number.isSafeInteger(timeout) || timeout < 1000 || timeout > MAX_DUMP_TIMEOUT_MS) {
    throw new Error('MYSQL_BACKUP_DUMP_TIMEOUT_MS must be an integer between 1000 and 86400000.');
  }
  return timeout;
}

export function createBackupEncryptor(key, iv = randomBytes(IV_LENGTH)) {
  if (!Buffer.isBuffer(key) || key.length !== 32 || !Buffer.isBuffer(iv) || iv.length !== IV_LENGTH) {
    throw new Error('Invalid key or initialization vector for encrypted backup.');
  }
  const header = Buffer.concat([MAGIC, Buffer.from([FORMAT_VERSION]), iv]);
  return { header, cipher: createCipheriv('aes-256-gcm', key, iv) };
}

export function createBackupDecryptor(header, tag, key) {
  if (!Buffer.isBuffer(header) || header.length !== HEADER_LENGTH
      || !header.subarray(0, MAGIC.length).equals(MAGIC)
      || header[MAGIC.length] !== FORMAT_VERSION
      || !Buffer.isBuffer(tag) || tag.length !== TAG_LENGTH
      || !Buffer.isBuffer(key) || key.length !== 32) {
    throw new Error('Backup format or encryption key is invalid.');
  }
  const iv = header.subarray(MAGIC.length + 1);
  const decipher = createDecipheriv('aes-256-gcm', key, iv);
  decipher.setAuthTag(tag);
  return decipher;
}

export function isInside(parent, candidate) {
  const pathFromParent = relative(parent, candidate);
  return pathFromParent === '' || (!pathFromParent.startsWith(`..${sep}`) && pathFromParent !== '..' && !isAbsolute(pathFromParent));
}

export function isPinnedMysqlImage(image) {
  return typeof image === 'string' && /^[a-z0-9./:_-]+@sha256:[a-f0-9]{64}$/i.test(image);
}

function windowsBackupAclFailure(descriptor) {
  if (!descriptor || typeof descriptor.ownerSid !== 'string' || !WINDOWS_SID_FORMAT.test(descriptor.ownerSid)
      || typeof descriptor.currentSid !== 'string' || !WINDOWS_SID_FORMAT.test(descriptor.currentSid)
      || !Array.isArray(descriptor.rules) || !Array.isArray(descriptor.ancestors)) return 'invalid-acl-descriptor';
  const trustedWriters = new Set([descriptor.currentSid, WINDOWS_SYSTEM_SID, WINDOWS_ADMINISTRATORS_SID]);
  const trustedOwners = new Set([...trustedWriters, WINDOWS_TRUSTED_INSTALLER_SID]);
  const scopes = [{ ownerSid: descriptor.ownerSid, rules: descriptor.rules, target: true }, ...descriptor.ancestors.map((ancestor) => ({ ...ancestor, target: false }))];
  for (const scope of scopes) {
    if (!scope || typeof scope.ownerSid !== 'string' || !WINDOWS_SID_FORMAT.test(scope.ownerSid)
        || !trustedOwners.has(scope.ownerSid) || !Array.isArray(scope.rules)) return scope?.target ? 'untrusted-destination-owner' : 'untrusted-ancestor-owner';
    for (const rule of scope.rules) {
      if (!rule || typeof rule.sid !== 'string' || !WINDOWS_SID_FORMAT.test(rule.sid)
          || !['Allow', 'Deny'].includes(rule.type) || typeof rule.rights !== 'string' || typeof rule.appliesToObject !== 'boolean') return 'invalid-acl-entry';
      const rights = analyzeWindowsFilesystemRights(rule.rights);
      if (!rights) return 'unknown-acl-right';
      const destructive = scope.target ? rights.targetWrite : rights.ancestorDelete;
      if (rule.appliesToObject && rule.type === 'Allow' && !trustedWriters.has(rule.sid) && destructive) {
        return scope.target ? 'untrusted-destination-write' : 'untrusted-ancestor-delete';
      }
    }
  }
  return null;
}

export function windowsBackupAclHasUnsafeWriteGrant(descriptor) {
  return windowsBackupAclFailure(descriptor) !== null;
}

function readWindowsBackupAcl(directory) {
  const result = spawnSync('powershell.exe', ['-NoLogo', '-NoProfile', '-NonInteractive', '-Command', WINDOWS_BACKUP_ACL_QUERY], {
    cwd: PROJECT_ROOT,
    encoding: 'utf8',
    windowsHide: true,
    timeout: READINESS_COMMAND_TIMEOUT_MS,
    maxBuffer: 1024 * 1024,
    env: { ...process.env, BOTANICAL_BACKUP_ACL_TARGET: directory }
  });
  if (result.error) throw new Error(`PowerShell ACL inspection could not start (${result.error.code ?? 'unknown'}).`);
  if (result.status !== 0) {
    const errorId = String(result.stderr ?? '').match(/FullyQualifiedErrorId\s*:\s*([^,\s]+)/i)?.[1] ?? 'unknown';
    throw new Error(`PowerShell ACL inspection failed (${errorId}).`);
  }
  try {
    const descriptor = JSON.parse(result.stdout.trim());
    if (!descriptor || typeof descriptor !== 'object' || Array.isArray(descriptor)) throw new Error('ACL response is not an object.');
    return descriptor;
  } catch {
    throw new Error('PowerShell ACL inspection returned malformed data.');
  }
}

export function mysqlInitializationIsComplete(logs) {
  return typeof logs === 'string' && /MySQL init process done\. Ready for start up\./i.test(logs);
}

export async function createAndVerifyBackup(createSnapshot, verifySnapshot) {
  const file = await createSnapshot();
  if (typeof file !== 'string' || file.length === 0) {
    throw new Error('Backup creation did not return an artifact path.');
  }
  try {
    await verifySnapshot(file);
  } catch (error) {
    const detail = error instanceof Error ? error.message : String(error);
    throw new Error(`Encrypted snapshot ${file} was created but isolated restore verification failed; the artifact is preserved. ${detail}`);
  }
  return file;
}

export async function validateBackupDirectory(directory, projectRoot = PROJECT_ROOT) {
  if (!directory || !isAbsolute(directory)) {
    throw new Error('MYSQL_BACKUP_DIR must be an existing absolute path outside the application workspace.');
  }
  const canonicalDirectory = await realpath(directory);
  const directoryInfo = await stat(canonicalDirectory);
  if (!directoryInfo.isDirectory() || isInside(await realpath(projectRoot), canonicalDirectory)) {
    throw new Error('Backup destination must be a directory outside the application workspace.');
  }
  if (process.platform !== 'win32' && (directoryInfo.mode & 0o022) !== 0) {
    throw new Error('Backup destination must not be writable by group or other users.');
  }
  if (process.platform === 'win32') {
    const windowsAcl = readWindowsBackupAcl(canonicalDirectory);
    const aclFailure = windowsBackupAclFailure(windowsAcl);
    if (aclFailure) {
      throw new Error(`Backup destination Windows ACL failed its privacy check (${aclFailure}); grant write access only to the backup process identity, SYSTEM, and local Administrators, and secure each parent directory against external deletion.`);
    }
  }
  return canonicalDirectory;
}

export async function writeEncryptedBackup(source, target, key, validateSource = async () => {}) {
  const temporary = join(dirname(target), `.${process.pid}.${randomBytes(12).toString('hex')}.partial`);
  const { header, cipher } = createBackupEncryptor(key);
  let plainBytes = 0;
  let published = false;
  const countBytes = new Transform({ transform(chunk, _encoding, callback) { plainBytes += chunk.length; callback(null, chunk); } });
  try {
    const output = createWriteStream(temporary, { flags: 'wx', mode: 0o600 });
    if (!output.write(header)) await once(output, 'drain');
    await pipeline(source, countBytes, cipher, output);
    if (plainBytes < 1) throw new Error('MySQL returned an empty dump; no backup was published.');
    await validateSource();
    await appendAndSync(temporary, cipher.getAuthTag());
    await rename(temporary, target);
    published = true;
    await syncDirectory(dirname(target));
    return (await stat(target)).size;
  } catch (error) {
    if (!published) {
      await rm(temporary, { force: true });
      throw error;
    }
    const detail = error instanceof Error ? error.message : String(error);
    throw new Error(`Encrypted snapshot ${target} was published but durability verification failed; the artifact is preserved. ${detail}`);
  }
}

export function checkedDocker(command, args, options = {}) {
  const result = spawnSync(command, args, {
    cwd: PROJECT_ROOT,
    encoding: 'utf8',
    windowsHide: true,
    maxBuffer: 1024 * 1024,
    timeout: DOCKER_COMMAND_TIMEOUT_MS,
    ...options
  });
  if (result.error) throw new Error(`Could not run ${command}: ${result.error.message}`);
  if (result.status !== 0) throw new Error(`${command} failed (exit ${result.status ?? 'unknown'})${result.stderr ? `: ${result.stderr.trim().slice(0, 1500)}` : '.'}`);
  return result.stdout;
}

export function mysqlComposeConfiguration() {
  const parsed = JSON.parse(checkedDocker('docker', ['compose', 'config', '--format', 'json']));
  const mysql = parsed.services?.mysql;
  const image = mysql?.image;
  const database = mysql?.environment?.MYSQL_DATABASE;
  if (!isPinnedMysqlImage(image)
      || typeof database !== 'string' || !/^[a-z0-9_]+$/i.test(database)) {
    throw new Error('Compose must define a pinned MySQL image and a simple configured database name.');
  }
  return { image, database };
}

function collectStderr(child) {
  const chunks = [];
  let length = 0;
  child.stderr?.on('data', (chunk) => {
    if (length >= 16_384) return;
    const part = Buffer.from(chunk).subarray(0, 16_384 - length);
    chunks.push(part);
    length += part.length;
  });
  return () => Buffer.concat(chunks).toString('utf8').trim();
}

function childResult(child, stderr) {
  return new Promise((resolveResult) => {
    child.once('error', (error) => resolveResult({ error, code: null, signal: null, stderr: stderr() }));
    child.once('close', (code, signal) => resolveResult({ error: null, code, signal, stderr: stderr() }));
  });
}

async function appendAndSync(path, bytes) {
  const handle = await open(path, 'a');
  try {
    await handle.write(bytes);
    await handle.sync();
  } finally {
    await handle.close();
  }
}

async function syncDirectory(directory) {
  if (process.platform === 'win32') return;
  const handle = await open(directory, 'r');
  try {
    await handle.sync();
  } finally {
    await handle.close();
  }
}

function timestamp() {
  return new Date().toISOString().replaceAll(':', '').replaceAll('.', '-');
}

function assertInnoDbSource(database) {
  const query = `SELECT COUNT(*), COALESCE(SUM(engine='InnoDB'),0), COALESCE(SUM(engine<>'InnoDB'),0) FROM information_schema.tables WHERE table_schema='${database}' AND table_type='BASE TABLE'`;
  const command = `export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql --user=root --batch --skip-column-names --execute "${query}"`;
  const counts = checkedDocker('docker', ['compose', 'exec', '-T', 'mysql', 'sh', '-lc', command])
    .trim().split(/\s+/).map(Number);
  if (counts.length !== 3 || counts.some((count) => !Number.isSafeInteger(count))
      || counts[0] < 1 || counts[1] !== counts[0] || counts[2] !== 0) {
    throw new Error('Encrypted snapshot requires at least one application table and all base tables must use InnoDB.');
  }
}

async function createEncryptedBackup() {
  const key = parseBackupKey(process.env.MYSQL_BACKUP_KEY_HEX);
  const dumpTimeoutMs = parseBackupDumpTimeout(process.env.MYSQL_BACKUP_DUMP_TIMEOUT_MS);
  const destination = await validateBackupDirectory(process.env.MYSQL_BACKUP_DIR);
  const { database } = mysqlComposeConfiguration();
  assertInnoDbSource(database);

  const filename = `botanical-mysql-${timestamp()}-${randomBytes(6).toString('hex')}.bdbk`;
  const target = join(destination, filename);
  const dumpCommand = 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysqldump --user=root --single-transaction --routines --events --triggers --hex-blob --no-tablespaces --default-character-set=utf8mb4 --databases "$MYSQL_DATABASE"';
  const dump = spawn('docker', ['compose', 'exec', '-T', 'mysql', 'sh', '-lc', dumpCommand], {
    cwd: PROJECT_ROOT,
    stdio: ['ignore', 'pipe', 'pipe'],
    windowsHide: true
  });
  const stderr = collectStderr(dump);
  const exit = childResult(dump, stderr);
  let dumpTimedOut = false;
  let forceKillTimer;
  const dumpTimer = setTimeout(() => {
    dumpTimedOut = true;
    dump.kill('SIGTERM');
    forceKillTimer = setTimeout(() => dump.kill('SIGKILL'), 5000);
    forceKillTimer.unref();
  }, dumpTimeoutMs);
  dumpTimer.unref();
  try {
    const size = await writeEncryptedBackup(dump.stdout, target, key, async () => {
      const result = await exit;
      if (dumpTimedOut) throw new Error(`MySQL dump exceeded MYSQL_BACKUP_DUMP_TIMEOUT_MS (${dumpTimeoutMs} ms).`);
      if (result.error || result.code !== 0) {
        throw new Error(`MySQL dump failed${result.code === null ? '' : ` (exit ${result.code})`}${result.stderr ? `: ${result.stderr.slice(0, 1500)}` : '.'}`);
      }
    });
    console.log(`Encrypted backup created: ${target} (${size} bytes).`);
    console.log('No plaintext dump was written to disk. Retention and off-site replication are not automated.');
    return target;
  } catch (error) {
    if (dump.exitCode === null && dump.signalCode === null) dump.kill('SIGKILL');
    await exit;
    if (dumpTimedOut) throw new Error(`MySQL dump exceeded MYSQL_BACKUP_DUMP_TIMEOUT_MS (${dumpTimeoutMs} ms).`);
    throw error;
  } finally {
    clearTimeout(dumpTimer);
    if (forceKillTimer) clearTimeout(forceKillTimer);
  }
}

async function openBackup(path, key) {
  const info = await stat(path);
  if (!info.isFile() || info.size <= HEADER_LENGTH + TAG_LENGTH) {
    throw new Error('Backup is missing, empty, or truncated.');
  }
  const handle = await open(path, 'r');
  try {
    const header = Buffer.alloc(HEADER_LENGTH);
    const tag = Buffer.alloc(TAG_LENGTH);
    const headerRead = await handle.read(header, 0, header.length, 0);
    const tagRead = await handle.read(tag, 0, tag.length, info.size - TAG_LENGTH);
    if (headerRead.bytesRead !== header.length || tagRead.bytesRead !== tag.length) {
      throw new Error('Backup is truncated.');
    }
    return { size: info.size, header, tag, decipher: createBackupDecryptor(header, tag, key) };
  } finally {
    await handle.close();
  }
}

function backupCiphertext(path, size) {
  return createReadStream(path, { start: HEADER_LENGTH, end: size - TAG_LENGTH - 1 });
}

function discardStream() {
  return new Writable({ write(_chunk, _encoding, callback) { callback(); } });
}

async function authenticateBackup(path, backup) {
  // Authenticate the complete GCM tag before any decrypted bytes are interpreted as SQL.
  await pipeline(backupCiphertext(path, backup.size), backup.decipher, discardStream());
}

function dockerExec(container, password, args, options = {}) {
  const interactive = options.stdio?.[0] === 'pipe' ? ['--interactive'] : [];
  return spawn('docker', ['exec', ...interactive, '--env', `MYSQL_PWD=${password}`, container, ...args], {
    cwd: PROJECT_ROOT,
    windowsHide: true,
    stdio: ['ignore', 'pipe', 'pipe'],
    ...options
  });
}

async function waitForValidationMysql(container, password) {
  const deadline = Date.now() + 90_000;
  let stableSuccessfulChecks = 0;
  let lastReadinessFailure = '';
  while (Date.now() < deadline) {
    const logs = spawnSync('docker', ['logs', '--tail', '100', container], {
      cwd: PROJECT_ROOT, windowsHide: true, encoding: 'utf8', maxBuffer: 32_768,
      timeout: READINESS_COMMAND_TIMEOUT_MS
    });
    if (mysqlInitializationIsComplete(`${logs.stdout ?? ''}\n${logs.stderr ?? ''}`)) {
      const ping = spawnSync('docker', [
        'exec', '--env', `MYSQL_PWD=${password}`, container,
        'mysql', '--protocol=socket', '--user=root', '--batch', '--skip-column-names', '--execute', 'SELECT 1'
      ], {
        cwd: PROJECT_ROOT, windowsHide: true, encoding: 'utf8', maxBuffer: 1024,
        timeout: READINESS_COMMAND_TIMEOUT_MS
      });
      if (ping.status === 0 && ping.stdout.trim() === '1') {
        stableSuccessfulChecks += 1;
        lastReadinessFailure = '';
      } else {
        stableSuccessfulChecks = 0;
        const detail = [ping.error?.message, ping.stderr?.trim(), ping.stdout?.trim()].filter(Boolean).join(' ').slice(0, 1000);
        lastReadinessFailure = `Readiness query failed (exit ${ping.status ?? 'unknown'})${detail ? `: ${detail}` : '.'}`
          .replaceAll(password, '[temporary secret redacted]');
      }
      if (stableSuccessfulChecks >= 3) return;
    } else {
      stableSuccessfulChecks = 0;
    }
    const state = spawnSync('docker', ['inspect', '--format', '{{.State.Running}}', container], {
      cwd: PROJECT_ROOT, windowsHide: true, encoding: 'utf8', maxBuffer: 1024,
      timeout: READINESS_COMMAND_TIMEOUT_MS
    });
    if (state.status === 0 && state.stdout.trim() === 'false') break;
    await new Promise((resolveDelay) => setTimeout(resolveDelay, 1000));
  }
  const state = spawnSync('docker', ['inspect', '--format', '{{.State.Status}} (exit {{.State.ExitCode}}): {{.State.Error}}', container], {
    cwd: PROJECT_ROOT, windowsHide: true, encoding: 'utf8', maxBuffer: 16_384,
    timeout: READINESS_COMMAND_TIMEOUT_MS
  });
  const logs = spawnSync('docker', ['logs', '--tail', '30', container], {
    cwd: PROJECT_ROOT, windowsHide: true, encoding: 'utf8', maxBuffer: 32_768,
    timeout: READINESS_COMMAND_TIMEOUT_MS
  });
  const diagnostics = [state.error?.message, state.stdout, logs.error?.message, logs.stderr, logs.stdout]
    .filter(Boolean).join('\n').replaceAll(password, '[temporary secret redacted]').trim();
  throw new Error(`Disposable MySQL did not become ready before the restore-validation timeout.${lastReadinessFailure ? `\n${lastReadinessFailure}` : ''}${diagnostics ? `\n${diagnostics}` : ''}`);
}

async function importAuthenticatedBackup(path, backup, container, password) {
  const importer = dockerExec(container, password, [
    'mysql', '--user=root', '--default-character-set=utf8mb4'
  ], { stdio: ['pipe', 'ignore', 'pipe'] });
  const stderr = collectStderr(importer);
  const exit = childResult(importer, stderr);
  try {
    await pipeline(backupCiphertext(path, backup.size), createBackupDecryptor(backup.header, backup.tag, parseBackupKey(process.env.MYSQL_BACKUP_KEY_HEX)), importer.stdin);
    const result = await exit;
    if (result.error || result.code !== 0) {
      throw new Error(`Restore into the disposable MySQL failed${result.code === null ? '' : ` (exit ${result.code})`}${result.stderr ? `: ${result.stderr.slice(0, 1500)}` : '.'}`);
    }
  } catch (error) {
    if (importer.exitCode === null && importer.signalCode === null) importer.kill();
    await exit;
    throw error;
  }
}

function assertDisposableMysql(container, password, database) {
  const tableQuery = `SELECT table_name FROM information_schema.tables WHERE table_schema='${database}' AND table_type='BASE TABLE' ORDER BY table_name`;
  const tables = checkedDocker('docker', [
    'exec', '--env', `MYSQL_PWD=${password}`, container,
    'mysql', '--user=root', '--batch', '--skip-column-names', '--execute', tableQuery
  ]).trim().split(/\r?\n/).filter(Boolean);
  const required = ['users', 'products', 'orders', 'rate_limits'];
  const missing = required.filter((name) => !tables.includes(name));
  if (missing.length || tables.length === 0) {
    throw new Error(`Restore validation is missing required application tables (${missing.length} missing).`);
  }

  const quoteIdentifier = (name) => `\`${name.replaceAll('`', '``')}\``;
  for (let index = 0; index < tables.length; index += 100) {
    const batch = tables.slice(index, index + 100);
    const identifiers = batch.map((name) => `${quoteIdentifier(database)}.${quoteIdentifier(name)}`);
    const output = checkedDocker('docker', [
      'exec', '--env', `MYSQL_PWD=${password}`, container,
      'mysql', '--user=root', '--batch', '--skip-column-names', '--execute', `CHECK TABLE ${identifiers.join(', ')}`
    ]);
    const healthyTables = output.split(/\r?\n/).filter((line) => /\tstatus\tOK$/.test(line)).length;
    if (healthyTables !== batch.length) throw new Error('Restore validation found an unhealthy or unreadable database table.');
  }
}

async function verifyBackup(file) {
  if (!file) throw new Error('Usage: node scripts/mysql-backup.mjs verify <encrypted-backup-file>');
  const key = parseBackupKey(process.env.MYSQL_BACKUP_KEY_HEX);
  const path = await realpath(resolve(file));
  const backup = await openBackup(path, key);
  await authenticateBackup(path, backup);

  const { image, database } = mysqlComposeConfiguration();
  const password = randomBytes(32).toString('hex');
  const name = `botanical-restore-verify-${process.pid}-${randomBytes(4).toString('hex')}`;
  const tmpfsSize = /^[1-9][0-9]?[mMgG]$/.test(RESTORE_TMPFS_SIZE) ? RESTORE_TMPFS_SIZE : '1g';
  const memory = /^[1-9][0-9]?[mMgG]$/.test(RESTORE_MEMORY) ? RESTORE_MEMORY : '2g';
  let creationAttempted = false;
  try {
    creationAttempted = true;
    checkedDocker('docker', [
      'run', '--detach', '--name', name, '--network', 'none',
      '--memory', memory, '--cpus', '2', '--pids-limit', '256',
      '--tmpfs', `/var/lib/mysql:rw,nosuid,nodev,noexec,size=${tmpfsSize}`,
      '--tmpfs', '/var/run/mysqld:rw,nosuid,nodev,noexec,size=64m',
      '--tmpfs', '/tmp:rw,nosuid,nodev,noexec,size=64m',
      '--env', `MYSQL_ROOT_PASSWORD=${password}`,
      '--env', 'MYSQL_DATABASE=restore_validation',
      image, '--skip-networking'
    ]);
    await waitForValidationMysql(name, password);
    await importAuthenticatedBackup(path, backup, name, password);
    assertDisposableMysql(name, password, database);
    console.log(`Restore validated from ${path} using a disposable, isolated MySQL instance.`);
    console.log('The validation instance had no network, ports, persistent volumes, or access to the production database.');
  } finally {
    if (creationAttempted) {
      const removed = spawnSync('docker', ['rm', '--force', name], {
        cwd: PROJECT_ROOT, windowsHide: true, encoding: 'utf8', maxBuffer: 16_384,
        timeout: DOCKER_CLEANUP_TIMEOUT_MS
      });
      const detail = `${removed.stdout ?? ''}\n${removed.stderr ?? ''}`;
      const alreadyAbsent = /no such container|container .+ not found/i.test(detail);
      if (removed.status !== 0 && !alreadyAbsent) {
        throw new Error(`Could not clean up disposable restore validator${removed.error ? `: ${removed.error.message}` : '.'}`);
      }
    }
  }
}

async function main(args) {
  const [command, ...rest] = args;
  if (command === 'create') {
    const file = await createAndVerifyBackup(createEncryptedBackup, verifyBackup);
    console.log(`Backup creation and isolated restore validation succeeded: ${file}`);
    return;
  }
  if (command === 'verify') return verifyBackup(rest[0]);
  throw new Error('Usage: node scripts/mysql-backup.mjs <create|verify [encrypted-backup-file]>');
}

if (process.argv[1] && pathToFileURL(resolve(process.argv[1])).href === import.meta.url) {
  main(process.argv.slice(2)).catch((error) => {
    console.error(`MySQL backup operation failed: ${error.message}`);
    process.exitCode = 1;
  });
}
