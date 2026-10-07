# Botanical Essence

Loja botânica responsiva com catálogo próprio e marketplace. O projeto usa Angular no frontend, uma API PHP modular, MySQL e Nginx.

## O que está implementado

- Catálogo e vendedores carregados da API, com paginação e seletor global de preços em USD ou EUR.
- Cadastro de cliente ou vendedor, confirmação de e-mail com token + senha, login, logout e recuperação de senha.
- Perfil pessoal editável com histórico de compras e acesso aos próprios anúncios.
- Qualquer conta de usuário pode criar um perfil vendedor, preparar rascunhos, publicar, editar estoque e arquivar seus produtos.
- Administração completa de contas, lojas e produtos, além de aprovação/revogação de vendedores, aviso por e-mail de novas lojas e trilha de auditoria.
- Sessão no servidor com cookie `HttpOnly`/`SameSite`, rotação, validade ociosa/absoluta, CSRF com recuperação automática e limitação de tentativas.
- Senhas com Argon2id, rehash automático, respostas anti-enumeração e limites separados por IP, conta e combinação conta/IP.
- JSON e campos validados de forma estrita, inclusive objetos internos do checkout; propriedades inesperadas são rejeitadas.
- Carrinho e favoritos locais reconciliados com preço e estoque atuais do servidor.
- Página de checkout com endereço europeu, cálculo estimativo de frete bpost no servidor, entrega e total em USD/EUR; cartão e Bancontact, além de débito direto SEPA em EUR quando a conta Stripe o permite.
- A API cria pedidos de forma idempotente, valida o payload e reserva estoque em transação; o checkout pode encaminhar a cobrança para o Stripe hospedado ou concluir uma simulação local explicitamente limitada ao desenvolvimento.
- Histórico de pedidos, formulário de contato e newsletter persistidos.
- CSP e demais headers de segurança, CORS por allowlist, segredos por ambiente e portas Docker locais.
- Assets WebP, cache para arquivos versionados, gzip, lazy-friendly routing e budgets de bundle.

Em desenvolvimento, a tela de login oferece acesso em um clique a contas de administrador, usuário e vendedor. Nenhuma senha é enviada ao navegador. As contas são marcadas no banco, protegidas contra edição administrativa e automaticamente desativadas — junto com seus anúncios — quando `APP_ENV` deixa de ser `development` ou `ENABLE_DEMO_ACCOUNTS` não está ativo. Contas antigas de catálogo continuam sem senha e desativadas para login.

## Executar com Docker

1. Crie o arquivo de ambiente:

```powershell
Copy-Item .env.example .env
```

2. Troque `DB_PASSWORD` e `DB_ROOT_PASSWORD` por valores longos e aleatórios.

3. Suba a aplicação:

```powershell
docker compose up --build -d
docker compose ps
```

- Site: `http://localhost:4300`
- API/healthcheck: `http://localhost:8180/api/health`
- MySQL: `127.0.0.1:3317`

Cada item público possui uma página própria em `/product/{slug}`. Os cartões abrem essa rota ao clicar na foto, no nome ou na avaliação.

As migrações são idempotentes e rodam antes do Apache. O MySQL não é exposto fora da máquina local.
O catálogo usa USD como moeda-base. As conversões USD/EUR usam a taxa diária de referência do BCE, guardada em cache no servidor, com a data e a taxa exata gravadas no pedido. Essa taxa é informativa — não é cotação em tempo real nem a taxa de liquidação Stripe. A API atualiza o valor periodicamente, aceita apenas observações recentes (até cinco dias) e suspende a criação de pedidos quando não consegue confirmar uma referência dentro desse prazo. O checkout também recusa uma prévia cambial que mudou desde que o comprador a viu. Fonte: [ECB Data Portal](https://data.ecb.europa.eu/help/api/data).

O frete continua sendo uma estimativa local baseada na tabela pública online de tarifas empresariais bpost de 2026, consultada em 04/10/2026. Essa tabela exclui IVA e créditos de expedição; não representa o contrato do vendedor, não consulta a transportadora, não compra etiqueta nem cria rastreio. A versão é registada em cada pedido, mostrada na revisão final e deixa de ser aceita após 31/12/2026, data limite para revisão manual. Antes de vender, confirme os valores do contrato bpost e atualize as tarifas/revisão do calculador. Fonte: [bpost — tarifas empresariais](https://parcel.bpost.be/en/pricing/business).

### E-mails em desenvolvimento

Com `APP_ENV=development` e `MAIL_TRANSPORT=log`, mensagens de confirmação, recuperação, pedido e aviso de nova loja são gravadas em `backend/storage/mail.log`. A interface também mostra o link de confirmação/recuperação somente nesse ambiente. O aviso de nova loja é enviado a todas as contas administradoras ativas e com e-mail confirmado.

Em `staging` e produção, configure `APP_ENV`, `APP_URL` com HTTPS, origens exatas em `CORS_ALLOWED_ORIGINS` e `MAIL_TRANSPORT=smtp`. Defina `MAIL_HOST`, `MAIL_PORT` e `MAIL_ENCRYPTION=tls` (STARTTLS, normalmente porta 587) ou `smtps` (TLS implícito, normalmente porta 465); se o relay exigir autenticação, forneça `MAIL_USERNAME` e `MAIL_PASSWORD` juntos pelo cofre de segredos. A API e o worker recusam iniciar se esses valores estiverem ausentes ou inválidos. O envio usa PHPMailer com validação TLS e não depende de MTA/sendmail local no container. `MAIL_TRANSPORT=log` só pode ser usado em development/test, pois grava links de uso único no arquivo local; `mail()` nativo não é um transporte suportado.

`ENABLE_DEMO_ACCOUNTS=true` deve ser usado somente com `APP_ENV=development`. A API exige as duas condições; em produção, os endpoints de acesso rápido ficam indisponíveis e as contas demo são desativadas pela inicialização.

Os tokens de confirmação e recuperação são colocados no fragmento `#token=...`, que não é enviado ao Nginx nem aparece no access log. A confirmação também solicita a senha criada no cadastro, protegendo contra pré-cadastro indevido de um e-mail de terceiros.

Se a API ficar atrás de proxies, habilite `TRUST_PROXY_HEADERS=true` e configure `TRUSTED_PROXY_IPS` com os IPs/CIDRs exatos dos proxies de borda. `TRUSTED_PROXY_HOSTS` aceita somente nomes de serviço internos simples, como `frontend` quando essa for a origem de conexão da API; verifique que o Compose passe esse host explicitamente. Sem configuração, o PHP confia apenas em loopback. O endereço do cliente é resolvido da direita para a esquerda na cadeia `X-Forwarded-For`, parando no primeiro salto não confiável, o que ignora prefixos forjados pelo cliente quando os proxies confiáveis acrescentam os endereços de origem. Cadeias inválidas, acima de 32 saltos ou acima de 4 KiB falham para o peer TCP observado. Configure cada proxy para sobrescrever ou acrescentar corretamente esse header e nunca confie em headers encaminhados diretamente da internet.

### Primeiro administrador

Não existe cadastro público de administrador. Cadastre uma conta comum, confirme o e-mail e promova-a pelo terminal do servidor:

```powershell
docker compose exec backend php bin/promote-admin.php admin@example.com
```

O comando aceita somente conta ativa e verificada, invalida sessões existentes e registra a promoção em `admin_audit_log`.

## Desenvolvimento do frontend

Com a API Docker ativa na porta 8180:

```powershell
npm ci
npm start
```

O Angular abre em `http://localhost:4200` e encaminha `/api` para `http://localhost:8180` por `proxy.conf.json`.

## Testes e validações

```powershell
npm run verify:full
npm audit
docker compose config --quiet
```

`verify:full` executa o quality gate, lint PHP em imagem descartável construída do código atual, testes frontend nos dois alvos de cobertura, build de produção, regressões da configuração do CI, testes PHP, cenários HTTP e backup/restauração isolada. `npm run test:coverage` exige 75% de statements, functions e lines e 65% de branches nos módulos extraídos. `npm run test:coverage:shell` inclui o coordenador legado e suas regras extraídas; seu piso próprio (60/49/46/62%, respectivamente statements/branches/functions/lines) acompanha a dívida sem reduzir o limite principal. As contagens e os resultados datados de cada execução estão em `SECURITY_AUDIT.md`; nenhuma porcentagem comprova a ausência de falhas. Para rodar somente uma parte, use `npm run test:coverage`, `npm run test:coverage:shell`, `npm run test:ci`, `npm run test:backend`, `npm run test:api`, `npm run test:backup` ou `npm run quality`.

Os testes de navegador rodam com `npm run test:e2e` contra a aplicação que já estiver ativa; a suíte cobre viewport móvel de 320/390/768 px, varreduras axe em 11 rotas públicas e nos três perfis autenticados (desktop 1280 px e mobile 390 px), fluxos Chromium e percursos essenciais de storefront em WebKit. No CI, os mesmos percursos de storefront também rodam em Firefox. `npm run test:e2e:isolated` cria uma stack descartável independente e executa nela os testes PHP, os cenários HTTP e os percursos Chromium/WebKit antes de remover banco, contêineres e artefatos. Testes PHP que alteram dados só são executados com `ALLOW_DISPOSABLE_DB_TESTS=true` e um `DB_DATABASE` terminado em `_ci`, `_test` ou `_e2e`; em bancos normais essa parte é ignorada para evitar mutações acidentais. Instale os browsers locais necessários com `npx playwright install chromium webkit`; Firefox local é opcional (`npx playwright install firefox` e `RUN_FIREFOX_E2E=true`) quando o runtime do sistema for compatível. A criação de pedidos fica desativada por padrão no desenvolvimento.

### Credenciais e prontidão do CI

O workflow gera senhas de banco independentes de 256 bits em cada execução, registra máscaras de log antes de exportá-las por `GITHUB_ENV` e só então inicia o Compose. Não exige nem reutiliza senhas do banco real. A exportação segue os [comandos oficiais do GitHub Actions](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-commands#setting-an-environment-variable). O executor E2E usa o mesmo gerador criptográfico, mas mantém seu próprio arquivo de ambiente privado e descartável.

`npm run test:ci` valida geração, máscaras, exportação, recusa fora do Actions e a ordem do workflow. Para incluir a prova real de prontidão MySQL em um projeto Compose exclusivo, execute no PowerShell:

```powershell
$env:RUN_DOCKER_CI_ENV_TEST='true'
npm run test:ci
```

Esse teste cria somente um MySQL temporário com credenciais próprias e verifica autenticação por TCP; remove apenas seu próprio projeto e volume. Não reinicia nem altera o banco persistente. No CI, essa prova está habilitada por padrão. O healthcheck usa TCP para não aprovar o servidor temporário `--skip-networking` da inicialização antes de o servidor definitivo ficar disponível. Validações locais não comprovam uma execução hospedada: o resultado completo do workflow deve ser observado depois de publicar o repositório no GitHub.

Para executar também os fluxos de compra sem tocar na aplicação ou no banco persistente, use `npm run test:e2e:isolated`. Esse comando cria um projeto Compose com nome, portas, credenciais, volume MySQL e diretório temporário exclusivos; força simulação local, contas demo descartáveis e chaves Stripe vazias; permite pedidos apenas no processo de teste; e remove contêineres, rede, volume e arquivos temporários ao terminar. Se a remoção falhar, preserva os dados temporários e informa o nome exato do projeto para limpeza manual. Não habilite `ALLOW_TEST_ORDER_CREATION=true` no `.env` ou no Compose principal: use esse executor ou outro banco comprovadamente descartável.

Para otimizar novamente os PNGs de origem:

```powershell
npm run optimize:images
```

## API principal

Todas as rotas `POST` exigem o token recebido em `GET /api/auth/session` no header `X-CSRF-Token`, usando o mesmo cookie de sessão.

| Método | Rota | Função |
| --- | --- | --- |
| GET | `/api/health` | Saúde da API e do banco |
| GET | `/api/products` | Catálogo paginado; aceita `marketplace`, `page` e `limit` |
| GET | `/api/product-reviews` | Avaliações públicas, média e avaliação do usuário para um produto |
| POST | `/api/product-reviews` | Cria ou atualiza uma única avaliação autenticada por usuário/produto |
| GET | `/api/sellers` | Vendedores paginados |
| GET | `/api/auth/session` | Usuário atual e token CSRF |
| GET | `/api/auth/demo-accounts` | Lista acessos rápidos somente quando a demonstração local está habilitada |
| POST | `/api/auth/demo-login` | Inicia sessão demo por papel, sem expor credenciais |
| POST | `/api/auth/register` | Cadastro e envio de confirmação |
| POST | `/api/auth/verify-email` | Confirma a conta com token de uso único e a senha do cadastro |
| POST | `/api/auth/login` | Autenticação |
| POST | `/api/auth/logout` | Encerramento da sessão |
| POST | `/api/auth/forgot-password` | Solicitação genérica de recuperação |
| POST | `/api/auth/reset-password` | Troca de senha por token de uso único |
| POST | `/api/orders` | Criação autenticada e idempotente do pedido (`idempotencyKey`) |
| GET | `/api/orders?page=1&limit=50` | Histórico paginado do usuário autenticado (máximo 100 por página), com `total` e `hasMore` |
| GET | `/api/product-reviews?productId=1&page=1&limit=20` | Avaliações públicas paginadas; resumo considera todas as avaliações visíveis |
| GET | `/api/payments/session-status` | Confirma a sessão Stripe pertencente ao usuário autenticado |
| POST | `/api/payments/webhook` | Eventos Stripe com assinatura e tolerância temporal verificadas (sem CSRF; HMAC obrigatório) |
| GET | `/api/account/profile` | Perfil pessoal autenticado |
| POST | `/api/account/profile` | Atualiza nome, biografia e localização do perfil |
| POST | `/api/contact` | Mensagem de contato |
| POST | `/api/newsletter` | Inscrição ou reativação |
| GET | `/api/seller/dashboard` | Perfil de vendas e anúncios do usuário autenticado |
| POST | `/api/seller/store` | Cria ou atualiza o perfil de vendas do usuário |
| POST | `/api/seller/products` | Cria ou atualiza produto pertencente ao usuário |
| POST | `/api/seller/products/archive` | Arquiva produto pertencente ao usuário |
| GET | `/api/admin/sellers?page=1&limit=50` | Vendedores paginados para moderação administrativa |
| POST | `/api/admin/sellers/verification` | Aprova ou revoga uma loja e registra auditoria |
| GET | `/api/admin/overview?page=1&limit=50` | Contas, produtos (inclusive rascunhos/arquivados) e avaliações paginados |
| GET | `/api/admin/audit-log?page=1&limit=50` | Histórico paginado das alterações administrativas; acesso exclusivo de administradores |
| GET | `/api/admin/orders?page=1&limit=50` | Pedidos paginados, situação do pagamento e itens |
| GET | `/api/admin/withdrawals?page=1&limit=50` | Fila paginada de solicitações de retirada, devolução e reembolso |
| GET | `/api/admin/payment-disputes?page=1&limit=50` | Disputas Stripe paginadas, ordenadas primeiro por resposta e prazo de evidência |
| POST | `/api/admin/orders/cancel` | Cancela pedidos não enviados; reembolsa pagamentos Stripe e libera estoque |
| POST | `/api/admin/users` | Edita conta, papel, estado e verificação com proteções de último administrador |
| POST | `/api/admin/products` | Edita qualquer produto, preço, estoque, visibilidade e publicação |
| POST | `/api/admin/stores` | Edita qualquer perfil de loja |
| POST | `/api/admin/reviews/moderate` | Oculta ou restaura comentários com registro de auditoria |

## Arquitetura

O frontend separa shell/roteamento, estado de autenticação, catálogo, cartões de produto reutilizáveis, páginas informativas/legais, carrinho, comércio, políticas de checkout e traduções. Entrega, devoluções, termos, privacidade e contato são renderizados por um componente independente; o formulário emite dados tipados ao shell, que continua responsável por chamar o serviço e tratar sucesso/erro. A API separa HTTP, segurança, validação, autenticação, catálogo, inventário, normalização de carrinho, tarifas de entrega, pedidos, mensagens, e-mail e migração. O banco é sempre a fonte de verdade para usuário, preço, estoque e total do pedido; `localStorage` guarda somente IDs e quantidades não confiáveis, reconciliados com o catálogo antes de aparecerem.

## Backup e validação de restauração do MySQL

`scripts/mysql-backup.mjs create` produz um snapshot da base InnoDB com `mysqldump --single-transaction`, cifra o fluxo diretamente em AES-256-GCM sem gravar um dump em texto claro e, antes de informar sucesso, importa o arquivo em um MySQL descartável isolado para validar a restauração. Se essa verificação falhar, o artefato criptografado é preservado e o comando termina com erro — não é anunciado como backup operacionalmente válido. Antes de executar, crie um diretório de destino protegido fora deste checkout e forneça `MYSQL_BACKUP_DIR` (caminho absoluto existente) e `MYSQL_BACKUP_KEY_HEX` (64 dígitos hexadecimais obtidos de um cofre de segredos aprovado). A chave não deve estar no `.env`, em scripts, no Git ou na mesma localização do arquivo criptografado. No Windows, o comando lê a DACL real (incluindo ACEs herdadas) do destino e das pastas ancestrais, e falha fechado se proprietário/permissões não puderem ser verificados, se houver escrita não confiável no destino ou se uma conta não confiável puder excluir/substituir o diretório por uma pasta ancestral; a escrita fica limitada à identidade que executa o backup, SYSTEM e Administradores locais. O comando nunca apaga snapshots antigos nem escolhe sozinho um destino.

Valide um snapshot com `node scripts/mysql-backup.mjs verify <arquivo.bdbk>`. A autenticação GCM é verificada antes de qualquer SQL ser executado; em seguida, o dump é importado para uma instância MySQL temporária da mesma imagem fixada por digest, com rede desabilitada, sem portas, volumes persistentes ou mounts, limites de recursos e diretório de dados em `tmpfs`. A rotina verifica as tabelas essenciais e executa `CHECK TABLE` em cada tabela base, depois remove o contêiner. Esse comando não restaura nem escreve no MySQL persistente. Para bases maiores, `MYSQL_BACKUP_VERIFY_TMPFS_SIZE` e `MYSQL_BACKUP_VERIFY_MEMORY` ajustam o teto temporário (por exemplo `4g` para ambos).

O destino, a chave, retenção, cópia off-site, agendamento e alertas continuam responsabilidade operacional: configure-os antes de depender do backup e execute `create` na rotina agendada para criar e testar cada snapshot; faça também restaurações isoladas periódicas. O verificador Windows examina DACLs do destino e de todos os ancestrais para restringir escrita e impedir substituição do diretório por usuários não confiáveis; o CI tem teste dedicado em runner Windows. Suspenda migrações DDL durante a captura. `npm run test:backup` testa cifragem, integridade, a sequência criação→validação e o processo de restauração em uma base fixture descartável; `npm run verify:full` inclui essa verificação. A aplicação não executa restauração destrutiva no banco ativo.

## Pagamentos de teste e Stripe

Há três modos explícitos, selecionados por `PAYMENT_EXECUTION_MODE`:

- `local_simulation`: somente em `APP_ENV=development`. O produto indicado por `PAYMENT_TEST_PRODUCT_SLUG` custa €0,01/US$0,01, o frete de teste é grátis e a compra é marcada como simulada. Não chama a Stripe e não testa Checkout, autenticação, webhooks ou reembolsos.
- `stripe_test`: usa a chave `sk_test_...` e percorre o Checkout hospedado, a confirmação/webhook e o fluxo de teste do PSP. Os preços e o frete normais são usados; o total precisa atender aos limites definidos para a conta. A Stripe não movimenta dinheiro real em test mode.
- `stripe_live`: somente em `APP_ENV=production`, com chave `sk_live_...`; cobranças reais ficam habilitadas.

Para testar a integração sem simulação local, use `PAYMENT_EXECUTION_MODE=stripe_test`, chave e segredo de webhook do sandbox (`STRIPE_SECRET_KEY=sk_test_...`, `STRIPE_WEBHOOK_SECRET=whsec_...`) e configure `STRIPE_ACCOUNT_ID`, `STRIPE_SETTLEMENT_CURRENCY` e `STRIPE_PAYMENT_LIMITS_JSON` com os dados da conta de teste. A API consulta `/v1/account` antes de criar o pedido e bloqueia se o ID ou a moeda de liquidação padrão não corresponderem à configuração. Faça o fluxo interativo no Checkout da Stripe com cartões de teste oficiais — por exemplo, Visa belga `4000 0005 6000 0004` ou o cartão de autenticação 3DS `4000 0000 0000 3220` — e teste também os eventos do webhook usando Stripe CLI ou o endpoint de sandbox. Cartões de teste nunca devem ser usados com chaves live.

Os mínimos não são uma constante global: a Stripe calcula o mínimo com base na moeda de liquidação padrão e exige que cobranças convertidas satisfaçam o equivalente nessa moeda. Por isso, a aplicação exige um mínimo explícito para cada par método/moeda e não tenta adivinhar a conversão cambial. O exemplo em `.env.example` assume liquidação EUR; seus valores devem ser confirmados e ajustados no Dashboard da conta antes do modo integrado. O teto de oito dígitos em centavos para EUR/USD respeita o limite geral de valor da Stripe. O teto Bancontact do exemplo (€10.000) é um limite comercial conservador da aplicação, não um limite universal publicado pela Stripe: bancos emissores ainda podem aplicar limites inferiores ao cliente. Revise os limites sempre que mudar conta, moeda de liquidação, método ou configuração de risco.

Registre `POST https://SEU-DOMÍNIO/api/payments/webhook` e habilite `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `refund.created`, `refund.updated`, `refund.failed` e os eventos `charge.dispute.created`, `charge.dispute.updated`, `charge.dispute.closed`, `charge.dispute.funds_withdrawn` e `charge.dispute.funds_reinstated`. `development`, `test` e `staging` aceitam somente chaves de teste; `production` aceita somente chave live. As sessões, reembolsos e eventos precisam ter `livemode` compatível com o ambiente e com o pedido. Antes de produção, configure `APP_ENV=production`, `PAYMENT_EXECUTION_MODE=stripe_live`, `APP_URL` HTTPS, a conta/limites live e endpoint live de webhook. Stripe Checkout hospeda os dados de pagamento e gerencia autenticação SCA/3-D Secure quando exigida. Isso não substitui a configuração comercial/jurídica: VAT, incidência e apresentação de IVA, termos de venda, desistência, privacidade e dados empresariais belgas devem ser confirmados pela empresa.

No Checkout, `sepa_debit` é débito direto com mandato (não transferência bancária), só EUR; Checkout recolhe os dados e a aplicação aguarda `checkout.session.async_payment_succeeded` antes de confirmar o pedido. A capacidade `sepa_debit_payments`, os limites do método e sua disponibilidade precisam estar ativos no Stripe Dashboard. Apple Pay/Google Pay não são contratos de pagamento separados na API deste site: podem surgir dentro do Checkout como opções elegíveis da carteira, conforme conta, configuração, dispositivo e navegador.

Uma tentativa Stripe sem ID de sessão é ambígua, nunca expira automaticamente nem libera estoque. A aplicação persiste o instante da primeira chamada e um `expires_at` estável para repetir exatamente a mesma requisição/idempotency key; a repetição é bloqueada após 23 horas (margem anterior ao prazo mínimo de retenção da chave Stripe). Casos antigos sem sessão exigem reconciliação manual no Dashboard da Stripe antes de cancelar ou iniciar outra cobrança; a fila administrativa os sinaliza.

Toda confirmação (webhook, retorno/status do cliente, worker ou simulação local) atualiza o pedido e enfileira o e-mail na mesma transação; uma confirmação repetida também repara uma eventual lacuna antiga sem duplicar a notificação. O worker também procura pedidos pagos sem uma notificação no outbox, recuperando automaticamente lacunas antigas. O outbox durável é processado pelo worker `payment-operations`, que tenta novamente falhas com backoff. A cada minuto ele libera reservas expiradas sem sessão Stripe em lotes idempotentes e consulta sessões Stripe pendentes antigas; pedidos com sessão Stripe só liberam estoque depois de uma expiração/falha terminal confirmada, e pagamentos assíncronos em processamento mantêm a reserva até sucesso ou falha. O worker recupera reembolsos `pending`/`unknown` pela API Stripe (limitando cada pedido a uma consulta automática por cinco minutos), reconcilia disputas recentes por API e atualiza disputas abertas. Se o evento `refund.created` ou `refund.updated` não chegar, a reconciliação procura o reembolso pela combinação exata de PaymentIntent, pedido e tentativa; ausência temporária não é tratada como falha nem dispara um novo reembolso. Reembolsos parciais e os da fila de devoluções usam tentativas idempotentes com valores acumulados, saldo máximo, referência Stripe e auditoria; resultados desconhecidos não liberam uma nova tentativa. Devoluções processadas pela fila reembolsam somente o saldo ainda não devolvido. O admin vê disputas com prazo, abre o caso no Dashboard Stripe e pode pesquisar pedidos paginados (50 por página; até 100 por consulta).

A recuperação de disputas usa a [paginação por cursor da Stripe](https://docs.stripe.com/api/pagination) dentro de um intervalo de criação fechado e persistido. Cada rodada lê no máximo três páginas de 100 casos; o limite superior permanece o mesmo entre reinícios e rodadas. Ao terminar, a próxima busca parte desse limite, com cinco minutos de sobreposição, não do horário de conclusão: casos criados durante uma busca demorada continuam incluídos na próxima. Falhas conservam a página para repetição, e lock MySQL por banco mais atualização condicional do cursor impedem regressão por workers concorrentes. A migração `2026-10-07-dispute-backfill-window-v21` adiciona `scan_created_lte` e reinicia uma única vez a recuperação de todo o histórico, para buscar também possíveis lacunas antigas; disputas já registradas são atualizadas sem duplicação.

Essa migração não deve ser aplicada ao banco persistente sem um backup operacional validado. As migrações rodam na inicialização do backend: antes de atualizar/recriar esse serviço sobre dados existentes, configure o destino/cofre aprovados e execute a captura com restauração de prova descrita em “Backup e validação de restauração do MySQL”. Os testes isolados podem aplicar a migração livremente em seu próprio banco descartável.

A busca de reembolsos de uma tentativa também percorre todas as [páginas da Stripe](https://docs.stripe.com/api/refunds/list), não somente os primeiros 100 resultados. Ela mantém o mesmo PaymentIntent e limite de criação durante a busca, entrega uma página validada por vez e para ao encontrar a tentativa correspondente. Uma página inválida, incompleta, sobreposta ou sem avanço de cursor não significa “reembolso inexistente”: worker e consulta administrativa preservam o estado financeiro pendente, registram o erro e não enviam outra tentativa. Uma consulta que não encontre a tentativa só termina normalmente após uma página válida com `has_more=false`; ausência momentânea também não autoriza reenvio.

O relatório de segurança, incluindo os riscos corrigidos e as dependências restantes para produção, está em [`SECURITY_AUDIT.md`](SECURITY_AUDIT.md).

A referência Stripe de uma tentativa de reembolso parcial é imutável depois do primeiro vínculo. Metadados iguais não autorizam outro `re_...` a substituir esse vínculo, mesmo em estado terminal ou após resolução manual: a comparação é feita contra a tentativa bloqueada na transação, antes de alterar valores, estados, auditoria ou notificações. Eventos conflitantes são recusados com `stripe_refund_attempt_mismatch` e sua marca de processamento também é revertida; uma repetição não é aceita como duplicata bem-sucedida. Eventos legítimos da referência original continuam idempotentes. Isso não associa automaticamente reembolsos criados fora do aplicativo ou sem os metadados da tentativa.
