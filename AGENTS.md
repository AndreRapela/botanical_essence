# Botanical Essence — acordo de entrega

Preferência do usuário confirmada em 15/10/2026: finalizar alterações com commit e push para o repositório existente. **Não fazer deploy**; a solicitação de deploy foi retirada pelo usuário.

- Ao concluir uma tarefa que altera arquivos, validar em proporção ao risco, revisar o diff e commitar somente as alterações da tarefa. Preservar alterações não relacionadas.
- Fazer push normal para o remoto/branch correspondente, sem force push. Se o remoto avançou, preservar o trabalho de terceiros; não sobrescrever o histórico.
- Confirmar que o commit chegou ao remoto e informar o hash e os testes realizados. Commit/push não significam CI aprovado ou implantação em produção.
- Em tarefas somente de análise, não criar commit vazio nem fazer alterações para justificar um commit.
- Não publicar `.env`, credenciais, chaves privadas, dados de banco, backups, logs de e-mail/sessão, capturas locais ou artefatos de teste. Alertar o usuário quando algo importante existir somente no computador e orientar preservação segura fora do Git.
- Não forçar a inclusão de arquivos ignorados. Dependências, caches, builds e relatórios de teste devem ser reproduzidos no outro computador, não enviados como código-fonte.
- Não migrar, substituir ou iniciar o banco persistente como efeito de um push. As migrações financeiras anteriores exigem backup cifrado e restauração de prova; consultar o README ao preparar mudanças de banco.
- Se testes, autenticação Git ou publicação estiverem bloqueados, informar exatamente o que passou, o que falta e a ação necessária. Não declarar a tarefa entregue ao remoto sem comprovação.

O inventário de arquivos locais e a orientação para outro computador estão na seção “Troca de computador e publicação” do README.
