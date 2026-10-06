import { readdirSync, readFileSync, statSync } from 'node:fs';
import { extname, join, relative, resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..');
const productionRoots = ['src/app', 'backend/src', 'backend/public'];
const sourceExtensions = new Set(['.ts', '.html', '.css', '.php']);
const findings = [];

function filesBelow(directory) {
  return readdirSync(directory).flatMap((entry) => {
    const path = join(directory, entry);
    return statSync(path).isDirectory() ? filesBelow(path) : [path];
  });
}

const files = productionRoots
  .flatMap((directory) => filesBelow(join(root, directory)))
  .filter((path) => sourceExtensions.has(extname(path)) && !path.endsWith('.spec.ts'));

const forbidden = [
  [/\bTODO\b|\bFIXME\b/, 'marcador de dívida técnica'],
  [/\bdebugger\b/, 'debugger em código de produção'],
  [/@ts-ignore|@ts-nocheck/, 'supressão do TypeScript'],
  [/(?:\b:\s*|\bas\s+)any\b/, 'tipo any explícito'],
];

for (const path of files) {
  const file = relative(root, path).replaceAll('\\', '/');
  const content = readFileSync(path, 'utf8');
  const lines = content.split(/\r?\n/).length;

  for (const [pattern, description] of forbidden) {
    if (pattern.test(content)) findings.push(`${file}: ${description}`);
  }

  const legacyBudget = {
    'src/app/app.component.ts': 1500,
    'src/app/app.component.html': 900,
    'src/app/app.component.css': 1200,
    'backend/src/Schema.php': 800,
  }[file];
  const defaultBudget = extname(path) === '.php' ? 600 : 400;
  const budget = legacyBudget ?? defaultBudget;
  if (lines > budget) findings.push(`${file}: ${lines} linhas excedem o limite de ${budget}`);

  if (extname(path) === '.php' && !content.includes('declare(strict_types=1);')) {
    findings.push(`${file}: strict_types ausente`);
  }
}

const publicIndex = readFileSync(join(root, 'backend/public/index.php'), 'utf8');
if (/->(?:query|prepare|exec)\s*\(/.test(publicIndex)) {
  findings.push('backend/public/index.php: acesso direto ao banco fora da camada de serviço');
}

const dockerFiles = ['Dockerfile', 'backend/Dockerfile'];
for (const file of dockerFiles) {
  const content = readFileSync(join(root, file), 'utf8');
  for (const [index, line] of content.split(/\r?\n/).entries()) {
    const match = line.match(/^\s*FROM\s+(?:--platform=\S+\s+)?([^\s]+)/i);
    if (match && !/@sha256:[a-f0-9]{64}$/i.test(match[1]) && match[1] !== 'scratch') {
      findings.push(`${file}:${index + 1}: imagem base Docker precisa estar fixada por digest SHA-256`);
    }
  }
}

const compose = readFileSync(join(root, 'docker-compose.yml'), 'utf8');
for (const [index, line] of compose.split(/\r?\n/).entries()) {
  const match = line.match(/^\s*image:\s*([^\s#]+)/i);
  if (match && !/@sha256:[a-f0-9]{64}$/i.test(match[1])) {
    findings.push(`docker-compose.yml:${index + 1}: imagem Docker precisa estar fixada por digest SHA-256`);
  }
}
if (!/^  TRUSTED_PROXY_IPS: \$\{TRUSTED_PROXY_IPS:-\}$/m.test(compose)
  || !/^  TRUSTED_PROXY_HOSTS: \$\{TRUSTED_PROXY_HOSTS:-frontend\}$/m.test(compose)) {
  findings.push('docker-compose.yml: proxy trust must default to no CIDR ranges and the explicit frontend service only');
}

for (const path of files.filter((path) => path.includes(`${join('src', 'app', 'core')}\\`) || path.includes('/src/app/core/'))) {
  const file = relative(root, path).replaceAll('\\', '/');
  const content = readFileSync(path, 'utf8');
  if (/from ['"]\.\.\/app\.component/.test(content)) {
    findings.push(`${file}: serviço depende do componente de interface`);
  }
}

if (findings.length > 0) {
  console.error(`Quality gate failed (${findings.length}):`);
  for (const finding of findings) console.error(`- ${finding}`);
  process.exit(1);
}

console.log(`Quality gate passed for ${files.length} production source files.`);
