# Pontos a melhorar — auditoria aprofundada

**Revisão:** 23/10/2026

**Código analisado:** branch main, commit 97ea48364bb6356ef2a33e50b64ce51636beb202

**Objetivo:** consolidar os riscos já registrados no projeto e achados adicionais numa lista acionável. Este documento é uma análise do código e da documentação; não altera o comportamento da aplicação.

## Resumo executivo

O projeto tem uma base técnica acima do protótipo comum: o servidor valida preços e estoque, o checkout usa idempotência e transações, e o fluxo Stripe possui assinatura de webhook, reconciliação, trilha financeira e recuperação de falhas. A auditoria de segurança existente e a cobertura de testes documentam bastante trabalho nessas áreas.

O principal gap agora é transformar essa base técnica em uma operação de marketplace pronta para vender: definir como pedidos de vários vendedores viram pacotes, como cada vendedor acompanha e recebe por seus pedidos, e como impostos e frete são cobrados. Também é preciso reduzir trabalho dentro do webhook, resolver o estado atual do audit de dependências e comprovar os pré-requisitos operacionais de produção.

### Prioridade sugerida

| Prioridade | Significado |
|---|---|
| P1 — antes de vendas públicas | Pode impedir fulfillment correto, deixar uma compra/operação financeira sem dono ou travar o gate de segurança. |
| P2 — antes de escalar | Pode causar fricção, informação incorreta, falha de acessibilidade ou operação manual crescente. |
| P3 — melhoria contínua | Reduz custo de manutenção e melhora a qualidade do produto ao longo do tempo. |

## Achados

### P1 — Antes de vendas públicas

#### 1. Pedidos de vários vendedores não têm fulfillment independente

**Evidência:** backend/src/OrderService.php reúne os produtos e calcula um único peso/frete para o pedido. Os itens preservam seller_id, mas as rotas em backend/public/index.php e o contrato documentado em README.md expõem ao vendedor apenas perfil e catálogo (dashboard, loja e produtos). Não encontrei uma fila de pedidos por vendedor, estado de expedição por vendedor nem repasse Stripe Connect em backend/src/StripeGateway.php.

**Impacto:** o carrinho aceita produtos do marketplace, mas um pedido com vendedores diferentes se comporta como uma única remessa e um único fluxo financeiro. Fica sem definição quem separa cada pacote, quem atualiza rastreio, como se calcula cada frete e quando cada loja recebe o dinheiro.

**Melhoria:** decidir o modelo comercial e decompor um checkout em subpedidos por vendedor; armazenar frete, status de fulfillment e rastreio por subpedido; fornecer ao vendedor uma fila autorizada por seller_id; definir comissão, titularidade dos fundos, KYC e repasses antes de ativar divisão de pagamento.

#### 2. Frete é uma estimativa local e ainda não cobre a expedição completa

**Evidência:** backend/src/ShippingTariffCalculator.php marca explicitamente quoteType=estimate, liveCarrierQuote=false, contractualRate=false, labelCreationAvailable=false e trackingAvailable=false. A referência é uma tabela pública bpost, revista em 04/10/2026 e válida até 31/12/2026. O valor usa o peso combinado do pedido.

**Impacto:** preço real do contrato, embalagem, dimensões, múltiplos pacotes e créditos podem divergir do valor cobrado. A partir de janeiro de 2027 a tabela atual expira e o calculador deve suspender novas cotações até revisão.

**Melhoria:** integrar cotação, compra de etiqueta e rastreio ou estabelecer um processo para confirmar e corrigir diferenças; suportar pacotes por vendedor e dimensões; revisar as tarifas antes da validade expirar; mostrar ao cliente que o valor é uma estimativa até haver cotação contratual.

#### 3. Impostos e responsabilidade comercial ainda dependem de decisão externa

**Evidência:** README.md e SECURITY_AUDIT.md dizem que VAT/BTW/TVA, categorias fiscais, preço com impostos, comerciante responsável, comissão, KYC e modelo de repasse não foram decididos. Não localizei cálculo tributário nem emissão de fatura fiscal em backend/src/OrderService.php.

**Impacto:** sem essas regras, o valor apresentado e a documentação da compra podem não refletir as obrigações do modelo de venda escolhido.

**Melhoria:** fechar as decisões com assessoria fiscal/jurídica para os países e papéis envolvidos; registrar regras fiscais por produto/destino; implementar cálculo, discriminação e documentos correspondentes antes de cobrança real. Este achado aponta trabalho pendente no produto e não é uma conclusão jurídica sobre conformidade.

#### 4. Webhook Stripe também tenta enviar e-mails antes de responder

**Evidência:** após confirmar a transação, backend/src/PaymentWebhookActions.php chama dispatchOutbox(20). backend/src/PaymentOperationsService.php processa a fila em sequência. backend/src/Mailer.php configura timeout SMTP de 10 segundos, enquanto docker/nginx/default.conf tem proxy_read_timeout de 30 segundos para API.

**Impacto:** uma entrega lenta ou um lote de notificações pode consumir o tempo da requisição do webhook. O provedor pode reenviar o evento após timeout; idempotência protege o processamento financeiro, mas o webhook continua acoplado à latência/disponibilidade do SMTP.

**Melhoria:** manter o webhook focado em validar, gravar a transação e enfileirar; fazer a entrega exclusivamente no worker; medir idade e volume da fila; alertar sobre mensagens acumuladas e vencidas. Revisar também outros fluxos de e-mail executados dentro de requisições.

#### 5. Dependências vulneráveis no lockfile e gate de CI bloqueado

**Evidência:** o npm audit executado nesta revisão apontou 8 vulnerabilidades: 4 altas, 3 moderadas e 1 baixa. O audit de produção isolou uma alta em @angular/router relacionada a advisory de DoS em SSR; a aplicação observada é client-side, então exposição direta ao cenário SSR não foi demonstrada. Outras altas atingem dependências do toolchain, incluindo Angular CLI/Vite e @modelcontextprotocol/server. O workflow .github/workflows/quality.yml executa npm audit --audit-level=high, portanto esse lockfile deve falhar nesse gate.

**Impacto:** ferramenta de desenvolvimento comprometida ou CI bloqueado; a dependência de produção também precisa de triagem mesmo que o cenário SSR não esteja habilitado neste app.

**Melhoria:** revisar cada advisory, atualizar Angular e toolchain em conjunto para versões suportadas, regenerar o lockfile e repetir o audit. Não aplicar npm audit fix às cegas: a correção sugerida para parte do toolchain pode sair da faixa Angular atual. Confirmar o resultado no workflow hospedado após a atualização.

#### 6. Pré-requisitos de produção e recuperação não estão comprovados por esta revisão

**Evidência:** README.md e SECURITY_AUDIT.md listam como pré-requisitos de staging/produção HTTPS, origens CORS finais, SMTP/TLS, DNS de e-mail, chaves e webhook live Stripe, capacidades habilitadas na conta, tarifas/contrato bpost e decisões do marketplace. Para o MySQL persistente, o README exige backup cifrado e restauração verificada antes de atualizar migrações financeiras.

**Impacto:** passar testes locais não comprova que DNS, Stripe real, entrega de e-mail, monitoração, cópias de segurança ou recuperação estão prontos no ambiente de clientes.

**Melhoria:** manter checklist de lançamento com responsável, evidência e data; agendar e alertar backups externos, exercitar restauração em ambiente isolado, medir disponibilidade/erros e verificar CI hospedado. Esta auditoria não acessou segredos, conta Stripe, cofre de backup nem infraestrutura pública e não afirma o estado atual desses recursos.

### P2 — Antes de escalar

#### 7. Painel administrativo não apresenta segundo fator de autenticação

**Evidência:** a busca por MFA/2FA/TOTP/WebAuthn/passkey não encontrou suporte no backend ou no frontend. Há autorização por papel e controles de sessão, mas não um segundo fator.

**Impacto:** uma credencial administrativa comprometida dá acesso a operações de alto impacto, incluindo catálogo, pedidos e filas financeiras.

**Melhoria:** oferecer MFA obrigatório para administradores, com códigos de recuperação e fluxo de recuperação auditado; considerar reforço de autenticação para operações financeiras críticas.

#### 8. Consentimento de termos não registra a versão aceita

**Evidência:** backend/src/AuthService.php valida acceptTerms como booleano. Não localizei persistência da versão dos termos, data/hora ou documento aceito associado ao usuário.

**Impacto:** fica difícil comprovar qual versão foi apresentada se os termos mudarem ou reconstruir a sequência de consentimento.

**Melhoria:** guardar versão imutável do documento aceito, data/hora e referência do fluxo, sem registrar conteúdo desnecessário; definir reconsentimento quando houver mudança material.

#### 9. Perfil sem fluxo de exportação ou encerramento de conta

**Evidência:** backend/public/index.php e backend/src/AccountService.php permitem consultar e atualizar perfil. Não localizei endpoint/fluxo para exportar dados ou solicitar encerramento; a política atual orienta a pessoa a contatar a empresa. Também não localizei regra de retenção ou descarte automático para mensagens de contato e inscrições.

**Impacto:** pedidos, perfil, mensagens e inscrições exigem atendimento manual para solicitações relacionadas a dados.

**Melhoria:** desenhar exportação e pedido de encerramento com verificações de identidade, tratamento de pedidos e retenções necessárias; definir prazo e responsável para atendimento. A revisão não avalia conformidade jurídica.

#### 10. Newsletter registra inscrição, mas não oferece descadastro no produto

**Evidência:** backend/src/MessageService.php grava ou reativa um endereço como subscribed. O schema em backend/src/Schema.php prevê unsubscribed, porém não encontrei rota pública de descadastro, confirmação em dois passos, registro da origem/consentimento ou rotina de envio de campanha. A mensagem exibida promete que a primeira newsletter chegará em breve.

**Impacto:** o fluxo aceita o endereço, mas não apresenta controle de saída nem uma operação de conteúdo correspondente; reenviar o formulário volta a marcar como inscrito.

**Melhoria:** implementar descadastro com token imprevisível e uso único, confirmação de inscrição se o modelo exigir, registro de consentimento e preferências; alinhar o texto da interface ao serviço de newsletter que realmente estiver operando.

#### 11. Busca e descoberta ficam limitadas ao catálogo já carregado

**Evidência:** src/app/core/catalog.service.ts carrega as primeiras 100 entradas e paginação adicional acontece por chamada explícita. searchStorefrontProducts em src/app/storefront-catalog.view.ts filtra apenas o array em memória; backend/src/CatalogService.php não expõe busca textual.

**Impacto:** com mais de 100 produtos, a busca global pode dizer que não há resultados para um item que ainda não foi carregado. A busca também não percorre origem, instruções de cuidado ou outros atributos catalogados.

**Melhoria:** mover consulta textual, filtros e ordenação para o servidor com paginação; manter contagem e estado vazio corretos e incluir campos de produto que ajudem a encontrar itens.

#### 12. A ficha de produto é traduzida, mas os dados de catálogo não

**Evidência:** interface e textos institucionais têm inglês/francês; backend/src/CatalogService.php devolve nome e descrição de uma única versão no banco, e os componentes exibem esses campos diretamente. A migração de catálogo prepara dados em inglês.

**Impacto:** quem escolhe francês continua vendo nomes, descrições, atributos e instruções de produto em inglês. O idioma da navegação não corresponde ao conteúdo principal de compra.

**Melhoria:** armazenar traduções por idioma ou escolher uma língua editorial única e deixar isso claro; cobrir nome, descrição, origem, dimensões, materiais, instruções, categorias e mensagens de indisponibilidade.

#### 13. Conteúdo destacado da home diverge do catálogo atual

**Evidência:** os três cartões de vendedores da home são estáticos em src/app/app.component.html e exibem nomes e cidades de São Paulo/Campinas/Curitiba. O catálogo inicial e a migração em backend/src/Schema.php usam lojas e cidades belgas em inglês.

**Impacto:** a seção de marketplace pode contradizer a localização e as lojas efetivamente carregadas, reduzindo confiança e tornando a demonstração inconsistente.

**Melhoria:** renderizar cartões a partir de vendedores e avaliações reais, ou identificar claramente dados editoriais/de demonstração; evitar duplicar cidade, nome e rating em HTML separado do catálogo.

#### 14. Itens sem estoque ainda parecem adicionáveis em cartões e busca

**Evidência:** a ficha de produto em src/app/app.component.html desativa os botões quando stock < 1, mas o botão de src/app/product-card.component.html e resultados de busca permanecem habilitados. addToCart em src/app/app.component.ts retorna sem mensagem quando o estoque é zero.

**Impacto:** o cliente pode clicar em adicionar, não ver mudança e não entender que o produto acabou. O servidor continua sendo a fonte de verdade e valida o estoque no pedido, mas a interface não dá retorno imediato.

**Melhoria:** exibir esgotado, desabilitar controles de adição e informar quando a quantidade no carrinho ultrapassar o estoque atualizado; manter a validação autoritativa no servidor.

#### 15. Semântica de filtro como abas não implementa o padrão completo

**Evidência:** categorias em src/app/app.component.html usam role=tablist e botões role=tab com aria-selected, mas não encontrei navegação por setas/roving tabindex nem tabpanel associado.

**Impacto:** tecnologias assistivas recebem a promessa de uma interface de abas, mas a interação é de filtros comuns; teclado e leitores de tela podem anunciar estado incompleto.

**Melhoria:** implementar o padrão de abas com foco e setas, painel associado e estado; ou retirar roles de aba e usar controles de filtro com semântica de botões/pressed correta.

#### 16. Metadados de busca são quase iguais em todas as páginas

**Evidência:** src/index.html define uma descrição geral estática; src/app/storefront.actions.ts troca document.title, mas não encontrei atualização de description por produto/página, URL canonical, Open Graph, sitemap, dados estruturados ou configuração SSR/prerender.

**Impacto:** compartilhamentos podem mostrar preview genérico e mecanismos de busca podem indexar apenas o shell da SPA, sem conteúdo útil da ficha de produto.

**Melhoria:** criar metadados por rota, URL canonical, Open Graph e schema de produto; gerar sitemap/robots e avaliar prerender/SSR para páginas públicas que precisam ser descobertas.

#### 17. Limites de taxa Nginx dependem do IP visto diretamente pelo container

**Evidência:** docker/nginx/default.conf limita requisições por $binary_remote_addr e encaminha $remote_addr; não localizei configuração real_ip_header/set_real_ip_from nesse Nginx.

**Impacto:** se o serviço estiver atrás de CDN ou proxy reverso sem normalizar o IP confiável, todos os visitantes podem compartilhar o bucket do proxy, ou o rate limit pode não refletir o cliente. É uma condição de implantação, não falha confirmada no ambiente público.

**Melhoria:** configurar apenas os endereços confiáveis da borda com real-IP, alinhar isso com os proxies confiáveis do backend e validar limites com a topologia real; nunca confiar em X-Forwarded-For arbitrário.

#### 18. Fila de operações precisa de orçamento, prioridade e alertas

**Evidência:** backend/bin/payment-operations-worker.php executa lotes sequenciais e dorme 60 segundos. Cada rodada percorre reconciliações, notificações, reembolsos e disputas. O healthcheck do docker-compose.yml considera vencido o heartbeat após 900 segundos. SECURITY_AUDIT.md mantém orçamento/fairness e alertas como trabalho em aberto.

**Impacto:** fila de baixa prioridade ou operação externa lenta pode atrasar tarefas urgentes, como reconciliação de pagamento ou disputa próxima do prazo, sem que o heartbeat sozinho explique a idade de cada fila.

**Melhoria:** adicionar métricas por fila (quantidade, idade do item mais antigo, falhas, tentativas), limites de tempo por tarefa, prioridades e alertas por prazo financeiro; documentar reprocessamento e escalonamento manual.

### P3 — Melhoria contínua

#### 19. Shell principal ainda concentra muita coordenação

**Evidência:** apesar das extrações documentadas em SECURITY_AUDIT.md, src/app/app.component.ts ainda centraliza navegação e efeitos de interface; src/app/app.component.html tem 666 linhas e src/app/app.component.css tem 770 linhas. Parte dos fluxos depende de métodos delegadores do componente.

**Impacto:** mudanças de página têm maior risco de acoplamento visual e fica mais caro testar ou alterar regras sem tocar no shell.

**Melhoria:** continuar extraindo fronteiras por domínio/rota, manter estado e efeitos com contratos pequenos e medir tamanho/complexidade no quality gate. Fazer isso incrementalmente, preservando testes visuais existentes.

#### 20. A prova automatizada é extensa, mas não substitui carga e avaliação independente

**Evidência:** README.md e SECURITY_AUDIT.md documentam testes unitários, API, E2E, varreduras axe e fluxos financeiros em ambientes descartáveis; a própria auditoria registra que ainda faltam teste de carga e pentest/DAST independente.

**Impacto:** testes funcionais não medem capacidade sob concorrência, comportamento de filas em alta carga nem falhas que dependem de configuração pública real.

**Melhoria:** antes de aumentar tráfego ou expor operações financeiras, medir catálogo/checkout/webhook/worker sob carga representativa e executar avaliação externa autorizada; registrar achados, correções e reteste.

## Plano de execução recomendado

1. Fechar o modelo comercial: pedidos por vendedor, pacotes, titularidade de fundos, comissão, impostos, KYC e repasses.
2. Atualizar e fazer triagem do lockfile até npm audit --audit-level=high passar; verificar o CI hospedado.
3. Remover envio SMTP da requisição do webhook e estabelecer métricas/alertas do worker e das filas.
4. Integrar o fluxo de expedição ao modelo multi-vendedor e atualizar o frete antes da expiração de 31/12/2026.
5. Revisar consentimento, retenção, exportação/encerramento de conta e descadastro com assessoria apropriada.
6. Completar UX de estoque, busca paginada, conteúdo da home e tradução do catálogo.
7. Preparar SEO/acessibilidade, MFA administrativa e verificações de carga/pentest.
8. Só declarar lançamento pronto depois de comprovar em ambiente real configurado: DNS/TLS, SMTP, Stripe live, backups/restauração, monitoramento e operação de fulfillment.

## O que já está bem encaminhado

- Preço, estoque e total vêm do servidor; o navegador mantém apenas IDs/quantidades de carrinho não confiáveis.
- Criação de pedido usa validação, idempotência, transação e bloqueio de estoque.
- Fluxos Stripe documentam assinatura de webhook, modo de ambiente, reconciliação e trilha de operações financeiras.
- Configuração de produção falha fechada para SMTP/TLS, CORS, segredos de aplicação e valores de demonstração.
- Há testes unitários, backend, HTTP, E2E responsivo, acessibilidade e testes financeiros em banco descartável documentados.
- Backups do MySQL são tratados como sensíveis e o processo documentado exige cifra e prova de restauração.

## Método e limites desta revisão

- Inspecionei documentação, API, serviços de catálogo/checkout/pagamento/e-mail, worker, templates e estilos; usei busca estática e git no snapshot identificado no cabeçalho.
- O npm audit foi executado contra o lockfile e reportou os 8 achados resumidos acima. O quality gate estático local já havia passado para 151 arquivos de produção nesta revisão.
- Não executei testes, builds, navegação E2E, chamadas a Stripe/bpost/BCE, nem inicializei serviços ou banco nesta etapa.
- php -l não pôde ser concluído neste host porque o binário PHP/Composer não estava disponível e o daemon Docker não respondeu ao comando de lint; isso não é resultado de lint aprovado.
- Não conferi configuração privada, credenciais, conta Stripe, DNS, backups reais, estado do banco persistente ou execução hospedada do CI. Os itens externos estão marcados como não comprovados, não necessariamente ausentes.

Este roteiro é uma revisão técnica e de produto baseada no repositório. Ele não substitui validação de segurança independente, revisão fiscal/jurídica ou teste operacional no ambiente de produção.
