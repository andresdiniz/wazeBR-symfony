# Segurança e isolamento por parceiro

## Princípio

O `partner_id` aparece nas principais tabelas operacionais. Consultas,
controladores, serviços e exports precisam aplicar o contexto do parceiro;
ter a coluna no banco, isoladamente, não garante separação de dados.

## Pontos de revisão no código

- `src/Service/TenantContext.php`: identificação do parceiro corrente.
- `src/Security/` e `config/packages/security.yaml`: autenticação e autorização.
- Controladores em `src/Controller/` e `src/Controller/Admin/`: acesso por papel.
- Repositórios: filtros por parceiro, inclusive em buscas por `id` ou `uuid`.
- Comandos e sincronizadores: uso do parceiro correto durante gravação.
- `user.partner_id` opcional: definir explicitamente o alcance do usuário
  sem parceiro.
- `partner_feed_event.created_by_user_id` e `updated_by_user_id`: validar
  se o usuário pertence ao parceiro do evento.

## Segredos e exposição

O esquema inclui `partner.api_key`, `api_token`, `api_secret`,
`reverse_geocoding_token` e `weather_location.api_token`. Há também arquivos
`.env`, `.env.dev` e `.env.local` listados na raiz do repositório. A presença
desses arquivos pede auditoria do conteúdo e do histórico do Git; não prova,
por si só, que um segredo esteja exposto.

Ações recomendadas:

1. Inspecionar arquivos versionados e histórico em busca de credenciais reais.
2. Se houver vazamento, revogar/rotacionar as credenciais antes de limpar
   qualquer histórico.
3. Manter exemplos sem valores reais; guardar segredos em configuração segura
   do ambiente de implantação.
4. Impedir que logs de erro e payloads de API exibam tokens.
5. Restringir acesso a exports do banco e a URLs internas de administração.

## Testes de isolamento

- Autenticar como usuário do parceiro A e solicitar IDs conhecidos do B.
- Testar listagens, detalhes, endpoints JSON, mapas, históricos e feeds.
- Repetir o teste com usuário administrativo e com usuário sem `partner_id`.
- Verificar criação e edição de recursos pertencentes a outro parceiro.
- Confirmar que paginação, totais e filtros não revelem dados cruzados.
