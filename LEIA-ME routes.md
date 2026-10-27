# Rotas: cache de estrutura separado das metricas

Base: RoutesRepository.php e RoutesController.php anexados na conversa.

## Arquivos completos
- src/Repository/RoutesRepository.php
- src/Controller/RoutesController.php
- config/packages/cache.yaml
- checks/routes-cache-smoke.php (teste isolado da montagem)

## Instalacao
1. Faca backup dos arquivos atuais.
2. Copie src/ e config/ para a raiz do projeto, preservando os caminhos.
3. Se seu cache.yaml possui outros pools ou aliases alem de tv.cache, preserve-os e acrescente routes.structure.cache. O arquivo fornecido preserva a configuracao tv.cache conhecida.
4. Na raiz, execute:

```powershell
php -l src/Repository/RoutesRepository.php
php -l src/Controller/RoutesController.php
php bin/console lint:yaml config/packages/cache.yaml
php bin/console lint:container --env=dev
php checks/routes-cache-smoke.php
php bin/console cache:clear --env=dev
```

## O que foi separado
- Cache de 600 segundos por parceiro (ou escopo global autorizado): geometrias normalizadas, cadastro das rotas, estrutura das sub-rotas e irregularidades.
- Sem esse cache: ultimo snapshot, tempos historicos usados na comparacao, tempos atuais, jam e elegibilidade de rotas/sub-rotas ativas.
- Porcentagens, cores, ranking e estatisticas sao recalculados a cada requisicao.
- As 200 rotas candidatas continuam sendo escolhidas na consulta atual, nao pelo ranking salvo no cache. O comportamento de limite/ranking e filtros existente foi preservado.
- O cache de estrutura abrange as rotas ativas do escopo inteiro. Em escopos globais grandes, monitore o custo da primeira carga e considere aquecimento em background.
- Uma rota ou geometria nova pode aparecer apenas quando a estrutura expirar (ate 10 minutos). Para refletir mudancas imediatamente, limpe o pool.
- Rotas desativadas deixam de aparecer porque as metricas atuais aplicam o filtro de ativos. Irregularidades sao deliberadamente um retrato de ate 10 minutos atras.
- O mapa continua embutido no payload inicial: este pacote NAO implementa lazy loading nem polling automatico.
- O metodo getRoutesDashboard foi mantido como compatibilidade para outros consumidores; fora deste controller ele funciona sem cache.
- Respostas HTML de index e JSON de apiData usam private, no-store para nao congelar as metricas por cache HTTP.

## Consulta corrigida
O ultimo snapshot usa latest.route_id = r.id e latest.partner_id = r.partner_id.
Confira no banco se existe o indice (route_id, recorded_at) declarado na entidade.
Nao foi incluida nenhuma migration nem alteracao em vendor/.

## Comparacao no profiler
A primeira abertura executa consultas de estrutura e de metricas.
Na segunda abertura dentro de 600 segundos, as consultas de geometria e irregularidades nao devem reaparecer.
As consultas de snapshots e metricas das sub-rotas continuam executando.
Use structureLoadedAt e metricsLoadedAt no JSON de /routes/api/data para conferir as datas.

```powershell
php bin/console cache:pool:clear routes.structure.cache
```

O terceiro argumento 0.0 de CacheInterface::get desativa a recomputacao antecipada. Expiracao nao impede remocao manual, limpeza de deploy ou perda do cache.
Nao foi adicionado fallback que repita uma consulta pesada se houver erro de cache: o erro deve ser diagnosticado em vez de mascarado.

## Validacao e limites
Os arquivos foram gerados a partir dos anexos, com verificacoes estruturais e teste PHP fornecido.
O ambiente de geracao nao possui PHP nem acesso ao seu banco. O lint PHP, o teste isolado, a injecao Symfony e as consultas SQL precisam ser executados localmente.
O teste isolado nao valida o SQL nem o backend do cache.
Nada foi enviado ao GitHub por este pacote.
