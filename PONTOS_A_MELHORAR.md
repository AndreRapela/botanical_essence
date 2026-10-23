# Pontos a melhorar — auditoria aprofundada

**Revisão:** 23/10/2026

**Base anterior do relatório:** branch main, commit b3cb9bc5b701e472cbec75adfbd75340bac4815b

**Código analisado:** branch main, commit 97ea48364bb6356ef2a33e50b64ce51636beb202. Os commits seguintes alteraram somente este relatório.

**Continuação da revisão:** 23/10/2026, de 14:27:41 a 15:27:41 UTC (11:27:41 a 12:27:41 em Brasília), com inspeção estática do código e da documentação.

**Objetivo:** consolidar os riscos já registrados no projeto e achados adicionais numa lista acionável. Este documento é uma análise do código e da documentação; não altera o comportamento da aplicação.

## Resumo executivo

O projeto tem uma base técnica acima do protótipo comum: o servidor valida preços e estoque, o checkout usa idempotência e transações, e o fluxo Stripe possui assinatura de webhook, reconciliação, trilha financeira e recuperação de falhas. A auditoria de segurança existente e a cobertura de testes documentam bastante trabalho nessas áreas.

O principal gap continua sendo transformar essa base em uma operação de marketplace pronta para vender: pedidos de vários vendedores, expedição e repasses ainda não têm um fluxo completo. A continuação encontrou também uma diferença entre o processo de retirada que a interface promete e os dados/ações disponíveis, uma prévia de checkout que pode divergir do preço recalculado pelo servidor e limites do catálogo/carrinho que podem ocultar itens após recarga. A newsletter atualmente só registra endereços, sem uma operação de envio ou descadastro no produto. Também é preciso reduzir trabalho síncrono de e-mail, resolver o estado atual do audit de dependências e comprovar os pré-requisitos operacionais de produção.

### Prioridade sugerida

| Prioridade | Significado |
|---|---|
| P1 — antes de vendas públicas | Pode impedir fulfillment correto, deixar uma compra/operação financeira sem dono ou travar o gate de segurança. |
| P2 — antes de escalar | Pode causar fricção, informação incorreta, falha de acessibilidade ou operação manual crescente. |
| P3 — melhoria contínua | Reduz custo de manutenção e melhora a qualidade do produto ao longo do tempo. |

## Achados

### P1 — Antes de vendas públicas

#### 1. Pedidos de vários vendedores não têm fulfillment independente

**Evidência:** backend/src/OrderService.php reúne os produtos e calcula um único peso/frete para o pedido. Os itens preservam seller_id, mas as rotas em backend/public/index.php e o contrato documentado em README.md expõem ao vendedor apenas perfil e catálogo (dashboard, loja e produtos). Não encontrei uma fila de pedidos por vendedor, estado de expedição por vendedor nem repasse Stripe Connect em backend/src/StripeGateway.php. O enum de pedidos inclui preparing/shipped/delivered, mas não há rota ou ação do app que marque essas etapas; o pagamento confirmado passa para confirmed.

**Impacto:** o carrinho aceita produtos do marketplace, mas um pedido com vendedores diferentes se comporta como uma única remessa e um único fluxo financeiro. Fica sem definição quem separa cada pacote, quem atualiza rastreio, como se calcula cada frete e quando cada loja recebe o dinheiro.

**Melhoria:** decidir o modelo comercial e decompor um checkout em subpedidos por vendedor; armazenar frete, status de fulfillment e rastreio por subpedido; fornecer ao vendedor uma fila autorizada por seller_id; definir comissão, titularidade dos fundos, KYC e repasses antes de ativar divisão de pagamento.

#### 2. Frete é uma estimativa local e ainda não cobre a expedição completa

**Evidência:** backend/src/ShippingTariffCalculator.php marca explicitamente quoteType=estimate, liveCarrierQuote=false, contractualRate=false, labelCreationAvailable=false e trackingAvailable=false. A referência é uma tabela pública bpost, revista em 04/10/2026 e válida até 31/12/2026. O valor usa somente destino e peso combinado: o país/origem de cada vendedor não é enviado ao calculador. O cadastro aceita peso de produto até 30.000 g, mas o calculador acrescenta 250 g de embalagem e rejeita pacotes acima de 30.000 g; portanto um produto isolado no limite aceito não recebe cotação.

**Impacto:** preço real do contrato, embalagem, dimensões, múltiplos pacotes e créditos podem divergir do valor cobrado. A partir de janeiro de 2027 a tabela atual expira e o calculador deve suspender novas cotações até revisão.

**Melhoria:** integrar cotação, compra de etiqueta e rastreio ou estabelecer um processo para confirmar e corrigir diferenças; suportar origem, pacotes por vendedor e dimensões; alinhar o limite de peso do cadastro com os 250 g de embalagem; revisar as tarifas antes da validade expirar; mostrar ao cliente que o valor é uma estimativa até haver cotação contratual.

#### 3. Impostos e responsabilidade comercial ainda dependem de decisão externa

**Evidência:** README.md e SECURITY_AUDIT.md dizem que VAT/BTW/TVA, categorias fiscais, preço com impostos, comerciante responsável, comissão, KYC e modelo de repasse não foram decididos. Não localizei cálculo tributário nem emissão de fatura fiscal em backend/src/OrderService.php.

**Impacto:** sem essas regras, o valor apresentado e a documentação da compra podem não refletir as obrigações do modelo de venda escolhido.

**Melhoria:** fechar as decisões com assessoria fiscal/jurídica para os países e papéis envolvidos; registrar regras fiscais por produto/destino; implementar cálculo, discriminação e documentos correspondentes antes de cobrança real. Este achado aponta trabalho pendente no produto e não é uma conclusão jurídica sobre conformidade.

#### 4. Webhook Stripe também tenta enviar e-mails antes de responder

**Evidência:** após confirmar a transação, backend/src/PaymentWebhookActions.php chama dispatchOutbox(20). backend/src/PaymentOperationsService.php processa a fila em sequência. backend/src/Mailer.php configura timeout SMTP de 10 segundos, enquanto docker/nginx/default.conf tem proxy_read_timeout de 30 segundos para API.

**Impacto:** uma entrega lenta ou um lote de notificações pode consumir o tempo da requisição do webhook. O provedor pode reenviar o evento após timeout; idempotência protege o processamento financeiro, mas o webhook continua acoplado à latência/disponibilidade do SMTP.

**Melhoria:** manter o webhook focado em validar, gravar a transação e enfileirar; fazer a entrega exclusivamente no worker; medir idade e volume da fila; alertar sobre mensagens acumuladas e vencidas. Aplicar o mesmo padrão a cadastro/verificação, recuperação de senha, contato e solicitações de retirada; hoje falhas ficam só no log, e a notificação de retirada envolve todos os destinatários em um único try/catch, de modo que a falha para um administrador interrompe os seguintes. A recuperação de senha também retorna imediatamente para e-mail desconhecido, mas pode esperar o envio SMTP para e-mail cadastrado; a resposta tem texto genérico, porém a diferença de tempo pode permitir inferir contas. Esse possível canal lateral não foi medido nesta revisão estática.

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

**Evidência:** backend/src/MessageService.php grava ou reativa um endereço como subscribed. O schema em backend/src/Schema.php prevê unsubscribed, porém não encontrei rota pública de descadastro, confirmação em dois passos, registro da origem/consentimento, tela/rota administrativa para consultar ou exportar os endereços, nem rotina de envio de campanha. A mensagem exibida promete que a primeira newsletter chegará em breve.

**Impacto:** o fluxo aceita e guarda o endereço, mas o usuário não tem controle de saída e a equipe não dispõe, no produto, de uma operação para selecionar destinatários e enviar conteúdo; reenviar o formulário volta a marcar como inscrito.

**Melhoria:** implementar descadastro com token imprevisível e uso único, confirmação de inscrição se o modelo exigir, registro de consentimento e preferências; alinhar o texto da interface ao serviço de newsletter que realmente estiver operando.

#### 11. Busca e descoberta ficam limitadas ao catálogo já carregado

**Evidência:** src/app/core/catalog.service.ts carrega as primeiras 100 entradas e paginação adicional acontece por chamada explícita. searchStorefrontProducts em src/app/storefront-catalog.view.ts filtra apenas o array em memória; backend/src/CatalogService.php não expõe busca textual. A contagem de vendedores da home também deriva dos produtos já carregados. A lista de favoritos é construída filtrando os IDs salvos localmente contra o mesmo array carregado e não oferece paginação própria. Itens do carrinho restaurados que ainda não estão nesse array ficam em restoredCart, fora do cartState usado pela interface, até que o catálogo carregue suas páginas.

**Impacto:** com mais de 100 produtos, a busca global pode dizer que não há resultados para um item que ainda não foi carregado, e a contagem de vendedores fica incompleta. Favoritos de itens fora das páginas carregadas deixam de aparecer após recarregar a aplicação, embora continuem contados no cabeçalho. Linhas de carrinho nas mesmas páginas ficam invisíveis; se houver outras linhas visíveis, o cliente pode iniciar o checkout apenas com esse subconjunto sem perceber que a sacola restaurada está incompleta. A busca também não percorre origem, instruções de cuidado ou outros atributos catalogados.

**Melhoria:** mover consulta textual, filtros e ordenação para o servidor com paginação; fazer favoritos e carrinho buscar diretamente os IDs persistidos ou manter suas próprias páginas; manter contagem e estado vazio corretos e incluir campos de produto que ajudem a encontrar itens.

#### 12. A ficha de produto é traduzida, mas os dados de catálogo não

**Evidência:** interface e textos institucionais têm inglês/francês; backend/src/CatalogService.php devolve nome e descrição de uma única versão no banco, e os componentes exibem esses campos diretamente. A migração de catálogo prepara dados em inglês.

**Impacto:** quem escolhe francês continua vendo nomes, descrições, atributos e instruções de produto em inglês. O idioma da navegação não corresponde ao conteúdo principal de compra.

**Melhoria:** armazenar traduções por idioma ou escolher uma língua editorial única e deixar isso claro; cobrir nome, descrição, origem, dimensões, materiais, instruções, categorias e mensagens de indisponibilidade.

#### 13. Conteúdo destacado da home diverge do catálogo atual

**Evidência:** os três cartões de vendedores e a nota média 4,9 da home são estáticos em src/app/app.component.html e nas traduções; os cartões exibem nomes e cidades de São Paulo/Campinas/Curitiba. O catálogo inicial e a migração em backend/src/Schema.php usam lojas e cidades belgas em inglês. Além disso, sellers.rating começa em 5,0 e não encontrei atualização derivada de avaliações; GET /api/sellers devolve e ordena por esse campo, embora o frontend não use esse endpoint.

**Impacto:** a seção de marketplace pode contradizer a localização e as lojas efetivamente carregadas, reduzindo confiança e tornando a demonstração inconsistente.

**Melhoria:** renderizar cartões e métricas a partir de vendedores e avaliações reais, ou identificar claramente dados editoriais/de demonstração; calcular a reputação a partir de avaliações publicadas; evitar duplicar cidade, nome e rating em HTML separado do catálogo.

#### 14. Itens sem estoque ainda parecem adicionáveis em cartões e busca

**Evidência:** a ficha de produto em src/app/app.component.html desativa os botões quando stock < 1, mas o botão de src/app/product-card.component.html e resultados de busca permanecem habilitados. addToCart em src/app/app.component.ts retorna sem mensagem quando o estoque é zero.

**Impacto:** o cliente pode clicar em adicionar, não ver mudança e não entender que o produto acabou. O servidor continua sendo a fonte de verdade e valida o estoque no pedido, mas a interface não dá retorno imediato.

**Melhoria:** exibir esgotado, desabilitar controles de adição e informar quando a quantidade no carrinho ultrapassar o estoque atualizado; manter a validação autoritativa no servidor.

#### 15. Semântica de filtro como abas não implementa o padrão completo

**Evidência:** categorias em src/app/app.component.html usam role=tablist e botões role=tab com aria-selected, mas não encontrei navegação por setas/roving tabindex nem tabpanel associado. O grupo de estrelas também se declara radiogroup/radio, mas cada botão não informa o valor numérico acessível nem implementa a navegação de teclado do padrão de rádio.

**Impacto:** tecnologias assistivas recebem a promessa de uma interface de abas, mas a interação é de filtros comuns; teclado e leitores de tela podem anunciar estado incompleto.

**Melhoria:** implementar o padrão de abas com foco e setas, painel associado e estado; ou retirar roles de aba e usar controles de filtro com semântica de botões/pressed correta.

#### 16. Metadados de busca são quase iguais em todas as páginas

**Evidência:** src/index.html define uma descrição geral estática; src/app/storefront.actions.ts troca document.title, mas não encontrei atualização de description por produto/página, URL canonical, Open Graph, sitemap, dados estruturados ou configuração SSR/prerender.

**Impacto:** compartilhamentos podem mostrar preview genérico e mecanismos de busca podem indexar apenas o shell da SPA, sem conteúdo útil da ficha de produto.

**Melhoria:** criar metadados por rota, URL canonical, Open Graph e schema de produto; gerar sitemap/robots e avaliar prerender/SSR para páginas públicas que precisam ser descobertas. Substituir o redirecionamento curinga para a home por uma página 404 real, preservando o endereço inválido e oferecendo recuperação.

#### 17. Limites de taxa Nginx dependem do IP visto diretamente pelo container

**Evidência:** docker/nginx/default.conf limita requisições por $binary_remote_addr e encaminha $remote_addr; não localizei configuração real_ip_header/set_real_ip_from nesse Nginx.

**Impacto:** se o serviço estiver atrás de CDN ou proxy reverso sem normalizar o IP confiável, todos os visitantes podem compartilhar o bucket do proxy, ou o rate limit pode não refletir o cliente. É uma condição de implantação, não falha confirmada no ambiente público.

**Melhoria:** configurar apenas os endereços confiáveis da borda com real-IP, alinhar isso com os proxies confiáveis do backend e validar limites com a topologia real; nunca confiar em X-Forwarded-For arbitrário.

#### 18. Fila de operações precisa de orçamento, prioridade e alertas

**Evidência:** backend/bin/payment-operations-worker.php executa lotes sequenciais e dorme 60 segundos. Cada rodada percorre reconciliações, notificações, reembolsos e disputas. O healthcheck do docker-compose.yml considera vencido o heartbeat após 900 segundos. SECURITY_AUDIT.md mantém orçamento/fairness e alertas como trabalho em aberto.

**Impacto:** fila de baixa prioridade ou operação externa lenta pode atrasar tarefas urgentes, como reconciliação de pagamento ou disputa próxima do prazo, sem que o heartbeat sozinho explique a idade de cada fila.

**Melhoria:** adicionar métricas por fila (quantidade, idade do item mais antigo, falhas, tentativas), limites de tempo por tarefa, prioridades e alertas por prazo financeiro; documentar reprocessamento e escalonamento manual.

### P3 — Melhoria contínua

#### 19. Notificações financeiras antigas precisam de resolução durável

**Evidência:** PaymentOperationsService tem validações específicas para algumas mensagens de reembolso, mas a SECURITY_AUDIT.md ainda lista como pendente o tratamento durável de mensagens cujo conteúdo deixou de representar o estado financeiro atual.

**Impacto:** uma mensagem pode continuar pendente enquanto conciliação, estorno ou reversão altera o estado da compra. Sem uma regra geral de cancelamento/substituição e uma fila visível de resolução, a operação depende de perceber o caso e agir manualmente.

**Melhoria:** antes de enviar, revalidar o estado e a versão da operação financeira a que a mensagem se refere; cancelar ou substituir mensagens superadas por uma comunicação correta; registrar o motivo e expor casos sem resolução no painel operacional.

#### 20. Shell principal ainda concentra muita coordenação

**Evidência:** apesar das extrações documentadas em SECURITY_AUDIT.md, src/app/app.component.ts ainda centraliza navegação e efeitos de interface; src/app/app.component.html tem 666 linhas e src/app/app.component.css tem 770 linhas. Parte dos fluxos depende de métodos delegadores do componente.

**Impacto:** mudanças de página têm maior risco de acoplamento visual e fica mais caro testar ou alterar regras sem tocar no shell.

**Melhoria:** continuar extraindo fronteiras por domínio/rota, manter estado e efeitos com contratos pequenos e medir tamanho/complexidade no quality gate. Fazer isso incrementalmente, preservando testes visuais existentes.

#### 21. A prova automatizada é extensa, mas não substitui carga e avaliação independente

**Evidência:** README.md e SECURITY_AUDIT.md documentam testes unitários, API, E2E, varreduras axe e fluxos financeiros em ambientes descartáveis; a própria auditoria registra que ainda faltam teste de carga e pentest/DAST independente. Também há uma consulta de catálogo que agrega todas as avaliações visíveis por produto a cada listagem; seu custo em produção depende do volume e ainda precisa de perfil/medição.

**Impacto:** testes funcionais não medem capacidade sob concorrência, comportamento de filas em alta carga nem falhas que dependem de configuração pública real.

**Melhoria:** antes de aumentar tráfego ou expor operações financeiras, medir catálogo/checkout/webhook/worker sob carga representativa e executar avaliação externa autorizada; registrar achados, correções e reteste.

## Achados adicionais da continuação de 60 minutos

### P1 — Antes de vendas públicas

#### 22. A retirada de apenas alguns itens prometida pela tela não pode ser executada pelo fluxo atual

**Evidência:** src/app/app.component.html orienta o cliente a identificar por texto livre os itens de uma retirada parcial. A API de pedidos não retorna as linhas no histórico do cliente. backend/src/WithdrawalService.php aceita número do pedido, tipo, mensagem e data; não persiste IDs/quantidades de itens nem valor pretendido. A fila administrativa vê as linhas do pedido inteiro, mas a ação de reembolso da solicitação calcula o saldo restante total. O ledger em backend/src/PaymentOperationsSchema.php associa o valor reembolsado ao pedido, sem product_id/order_item_id. Ao mesmo tempo, backend/src/PartialRefundActions.php bloqueia o reembolso parcial genérico enquanto houver uma solicitação ativa.

**Impacto:** em pedidos com vários produtos, não há como selecionar e calcular no portal o subconjunto que o cliente quer devolver. O processo da fila não tem uma ação item a item; o caminho conectado à retirada devolve o saldo restante do pedido. O texto promete uma capacidade que os dados e as ações atuais não representam.

**Melhoria:** exibir as linhas do pedido ao cliente e persistir itens/quantidades selecionados; calcular o montante de devolução com regras documentadas por linha e suportar essa operação na fila financeira. Até isso existir, ajustar o texto da interface para descrever apenas o escopo realmente suportado.

### P2 — Antes de escalar

#### 23. Chamado de problema aberto também bloqueia uma nova retirada formal

**Evidência:** WithdrawalService procura qualquer solicitação ativa por order_id, sem filtrar request_type. order.view.ts oculta tanto “retirada” quanto “relatar problema” quando existe qualquer status ativo. Os dois tipos também passam pela mesma fila e pelas mesmas transições administrativas.

**Impacto:** um chamado sobre dano, atraso ou item ausente pode impedir o cliente de registrar outra solicitação formal sobre o mesmo pedido enquanto o primeiro caso estiver aberto; a interface mostra apenas um estado/referência e não identifica o tipo do caso.

**Melhoria:** separar chamados de atendimento e notificações de retirada em fluxos/timelines próprios, ou permitir conversão explícita com histórico e sem perder a data original do pedido.

#### 24. O preço mostrado na revisão pode mudar antes do Stripe sem confirmação no site

**Evidência:** o total da tela é calculado a partir dos produtos em memória no navegador. A criação do pedido recalcula preço atual no banco; o hash de idempotência inclui IDs/quantidades, mas não uma versão/preço confirmado pelo cliente. Se o servidor devolver uma URL Stripe, checkout-workflow.service.ts redireciona imediatamente, sem comparar o total persistido com a prévia exibida. Tokens protegem taxa cambial e tarifa de frete, não a versão do preço dos produtos.

**Impacto:** uma alteração de preço depois que a loja foi carregada pode fazer o total no provedor divergir do botão “total final” apresentado pelo site. A página Stripe ainda mostra o valor antes da cobrança, mas o cliente só descobre a diferença depois de sair da revisão.

**Melhoria:** incluir versão/preço no token de prévia e rejeitar divergências com HTTP 409 contendo linhas/valores atualizados; atualizar a cesta e pedir confirmação explícita antes de criar ou retomar a sessão de pagamento.

#### 25. A avaliação pode ser publicada antes da entrega e já começa em cinco estrelas

**Evidência:** ReviewService permite avaliar após pagamento com status confirmed; esse status é definido na confirmação de pagamento, e não encontrei ação do app que marque preparação, envio ou entrega. A página pergunta se a pessoa “experimentou” o produto. product.actions.ts inicializa a nota em 5 e a submissão exige comentário, mas não uma seleção explícita de nota.

**Impacto:** o selo comprova uma compra paga, não que o produto chegou ou foi experimentado. Além disso, alguém que escreva o comentário sem tocar nas estrelas envia uma nota máxima por padrão.

**Melhoria:** habilitar avaliação após uma confirmação de entrega ou identificar claramente que só se verifica a compra; iniciar sem nota selecionada e exigir escolha deliberada antes de publicar.

#### 26. O marketplace não tem perfil público de vendedor nem imagem própria por produto

**Evidência:** GET /api/sellers existe, mas não é chamado pelo frontend; não há rota de perfil de vendedor, e o nome no cartão de produto é texto sem link. O formulário e a API de produto limitam o visual a quatro posições de um único sprite, sem arquivo/imagem por produto.

**Impacto:** compradores não conseguem abrir uma loja para ver sua história e catálogo, e os vendedores não conseguem mostrar a aparência real de cada item. Isso enfraquece descoberta e confiança em uma plataforma que se apresenta como marketplace.

**Melhoria:** criar páginas públicas de loja com localização, verificação, reputação calculada e catálogo; oferecer galeria de imagens com armazenamento, limites de tipo/tamanho e moderação.

#### 27. Telas exibem estados internos de pedido e pagamento sem tradução

**Evidência:** account-order-history.component.html imprime order.status diretamente; admin-financial-operations.component.html imprime order.status, paymentStatus, refundOutcome e estados do pedido no atendimento. O mapa localizado existente cobre estados de retirada, não todos esses enums. Vários date pipes e pipes numéricos não recebem locale, e a aplicação não registra um LOCALE_ID dinâmico.

**Impacto:** em francês, clientes e administradores podem ver valores como awaiting_payment ou partially_refunded; datas e números em algumas telas seguem o formato en-US apesar do idioma selecionado.

**Melhoria:** mapear os enums para rótulos por idioma sem alterar o contrato da API; aplicar locale selecionado a datas, números e moedas em todas as telas.

#### 28. A edição de estoque pode confundir unidades físicas com unidades disponíveis

**Evidência:** criar pedido pendente desconta quantidade de products.stock; cancelamento/expiração devolve a reserva. O formulário do vendedor chama o campo apenas de “Stock” e substitui o número no banco por um valor absoluto, sem expor reservado, disponível ou histórico de ajuste.

**Impacto:** se o vendedor contar também unidades já reservadas ao reconciliar o estoque físico e inserir esse total como “Stock”, o cancelamento posterior soma a reserva de novo e pode deixar quantidade disponível maior que a física. É um risco condicional à interpretação do campo, não um resultado inevitável de toda edição.

**Melhoria:** separar estoque físico, reservado e disponível, ou definir o campo como “disponível para venda” e registrar ajustes de estoque com motivo e saldo anterior.

### P3 — Melhoria contínua

#### 29. O histórico de eventos da devolução existe no banco, mas não está disponível na interface

**Evidência:** WithdrawalService grava mudanças de estado e notas em withdrawal_request_events. A resposta ao cliente contém apenas tipo, estado atual, referência e data; a consulta administrativa devolve admin_note atual, sobrescrito em cada transição. Não encontrei rota/leitor que exponha a linha do tempo e as notas anteriores.

**Impacto:** cliente não consegue acompanhar decisões e instruções anteriores no portal; a equipe depende de e-mails ou de consultar diretamente o banco para reconstruir o caso.

**Melhoria:** disponibilizar uma timeline autenticada por solicitação: eventos próprios para o cliente e eventos/notas completos para administradores, com acesso sempre limitado pelo dono/papel.

#### 30. Sessões PHP ficam locais ao container e não acompanham múltiplas réplicas

**Evidência:** Security.php usa session_start nativo sem handler compartilhado; docker-compose.yml configura um backend e não monta diretório de sessões nem serviço Redis/DB para esse estado.

**Impacto:** ao escalar backend atrás de balanceador, uma requisição pode chegar a uma réplica que não conhece a sessão iniciada em outra; recriar o container também descarta sessões que não estejam em armazenamento persistente.

**Melhoria:** antes de adicionar réplicas, adotar sessão compartilhada com expiração/invalidação central ou configurar afinidade e persistência explicitamente; validar rotação, logout e revogação no desenho escolhido.

#### 31. Migrações e processos de execução compartilham a mesma identidade de banco

**Evidência:** docker-entrypoint.sh executa bin/migrate.php com DB_USERNAME/DB_PASSWORD; o serviço backend e o worker payment-operations recebem as mesmas variáveis no docker-compose.yml, e não encontrei identidade separada para migração.

**Impacto:** qualquer processo comprometido ou uso indevido da credencial de runtime herda as permissões necessárias ao DDL de migração, ampliando o impacto em comparação com uma identidade limitada às operações do app.

**Melhoria:** executar migrações em etapa/job separado com credencial temporária e conceder ao backend/worker apenas as permissões de dados necessárias; rotacionar e auditar as identidades por ambiente.

#### 32. “Manter conectado” vem marcado e cria sessão persistente de 30 dias

**Evidência:** rememberMe começa como true na aplicação e no formulário. Security.php aplica REMEMBER_SECONDS=2.592.000 segundos quando essa opção é enviada; sem a opção, a sessão usa limites de inatividade e duração muito menores.

**Impacto:** em computador compartilhado, quem não percebe a caixa marcada pode deixar a conta autenticada por até 30 dias desde o login, dentro dos limites de inatividade configurados.

**Melhoria:** deixar a opção desmarcada por padrão e considerar limite menor para administradores, com expiração e revogação claramente explicadas.

#### 33. O worker de pagamentos executa como root dentro do container

**Evidência:** backend/Dockerfile não define USER. O serviço payment-operations no docker-compose.yml substitui o entrypoint por php e inicia o worker sem definir user; portanto o processo CLI herda o usuário padrão root da imagem.

**Impacto:** se uma dependência ou o próprio worker for comprometido, o processo pode alterar arquivos e permissões dentro do container e escrever no volume de storage com privilégios maiores que os necessários para heartbeat e operações financeiras.

**Melhoria:** executar o worker com um usuário dedicado sem privilégios e conceder somente acesso de escrita aos caminhos necessários; manter a migração em uma etapa separada que use sua própria identidade de banco.

#### 34. O carrinho deixa exceder o limite de linhas aceito pela API e corta a persistência local

**Evidência:** src/app/core/store.service.ts — StoreService.add acrescenta produtos sem impor limite de linhas distintas. backend/src/CartItems.php aceita até 50 entradas em CartItems::quantities; acima disso, rejeita o checkout com 422. Para persistir o carrinho, StoreService.setCart mantém somente os primeiros 50 IDs, sem avisar quando descarta os demais.

**Impacto:** com mais de 50 produtos diferentes, o cliente consegue montar uma sacola que o checkout rejeita. Ao salvar uma sacola acima do limite, o navegador persiste apenas os primeiros 50 IDs; depois de recarregar, as linhas que ficaram de fora desaparecem sem aviso.

**Melhoria:** aplicar o mesmo limite de 50 linhas ao adicionar itens, informar o limite na interface e evitar descarte silencioso; alternativamente, ampliar e validar o limite de forma consistente no cliente, API e persistência.

## Plano de execução recomendado

1. Fechar o modelo comercial: pedidos e pacotes por vendedor, origem/frete, titularidade de fundos, comissão, impostos, KYC e repasses.
2. Implementar linhas/quantidades de pedido no histórico e o fluxo de retirada item a item; separar chamados de problema e tornar a timeline de atendimento acessível.
3. Exigir confirmação explícita quando preço ou estoque mudarem entre a prévia e o pedido; mover e-mail para outbox/worker e medir filas.
4. Atualizar e fazer triagem do lockfile até npm audit --audit-level=high passar; verificar o CI hospedado.
5. Atualizar tarifa/limites bpost antes de 31/12/2026 e fechar cotação, etiquetas, rastreio e tratamento por origem.
6. Melhorar confiança do marketplace: perfil público, mídia real, reputação calculada e avaliações após entrega com nota escolhida.
7. Completar busca paginada e recuperação de favoritos/carrinho; alinhar o limite de linhas entre tela, armazenamento local e API; separar estoque físico/reservado, localizar estados, substituir conteúdo demonstrativo da home e implementar not-found/SEO.
8. Revisar consentimento, retenção, exportação/encerramento, operação/descadastro de newsletter, MFA, sessões, privilégios de banco e usuário do worker.
9. Só declarar lançamento pronto depois de comprovar em ambiente real configurado: DNS/TLS, SMTP, Stripe live, backups/restauração, monitoramento e operação de fulfillment.

## O que já está bem encaminhado

- Preço, estoque e total vêm do servidor; o navegador mantém apenas IDs/quantidades de carrinho não confiáveis.
- Criação de pedido usa validação, idempotência, transação e bloqueio de estoque.
- Fluxos Stripe documentam assinatura de webhook, modo de ambiente, reconciliação e trilha de operações financeiras.
- Configuração de produção falha fechada para SMTP/TLS, CORS, segredos de aplicação e valores de demonstração.
- Há testes unitários, backend, HTTP, E2E responsivo, acessibilidade e testes financeiros em banco descartável documentados.
- Backups do MySQL são tratados como sensíveis e o processo documentado exige cifra e prova de restauração.

## Método e limites desta revisão

- Inspecionei documentação, API, serviços de catálogo/checkout/pagamento/e-mail, worker, sessões, administração, templates e estilos; usei busca estática e git no snapshot identificado no cabeçalho.
- Na revisão-base, npm audit contra o lockfile reportou os 8 achados resumidos acima e o quality gate estático local havia passado para 151 arquivos de produção. Esses comandos não foram repetidos durante a continuação de 60 minutos.
- A continuação foi somente estática: não executei testes, build, lint, DAST, navegação no browser, chamadas à API, nem inicializei serviços ou banco. Os pontos condicionais e de latência não foram medidos em runtime.
- Na revisão-base, php -l não pôde ser concluído neste host porque o binário PHP/Composer não estava disponível e o daemon Docker não respondeu ao comando de lint; isso não é resultado de lint aprovado.
- Não conferi configuração privada, credenciais, conta Stripe, DNS, backups reais, estado do banco persistente ou execução hospedada do CI. Os itens externos estão marcados como não comprovados, não necessariamente ausentes.

Este roteiro é uma revisão técnica e de produto baseada no repositório. Ele não substitui validação de segurança independente, revisão fiscal/jurídica ou teste operacional no ambiente de produção.
