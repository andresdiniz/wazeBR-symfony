# Manutencao de snapshots

Rotina global: todos os parceiros do banco definido em DATABASE_URL. Nao usa nome fixo de banco e nao altera previsoes nem auditoria. Antes do deploy, backup e ensaio em copia do banco sao obrigatorios.

Diferencas intencionais do SQL original: conserva payloads recentes por 48h, pois o mapa recente usa payload.subRoutes; conserva 30 dias de traffic_light_snapshot; nao executa OPTIMIZE diariamente. A remocao e irreversivel sem backup. Nao apaga linhas de waze_tvt_route_snapshot, apenas payloads antigos; futuras funcionalidades que dependam do JSON antigo perderao essa fonte.

```bash
php bin/console app:db:maintain-snapshots --env=prod --no-debug
php bin/console app:db:maintain-snapshots --execute --env=prod --no-debug
```

Sem --execute e simulacao. A agenda usa --execute diariamente as 03:17 America/Sao_Paulo. Padroes: lote 1000, ate 50 lotes por tabela, pausa 100ms e orcamento total de 120s. O limite de tempo impede iniciar novos lotes, mas nao cancela uma SQL em andamento. Se o primeiro processamento consumir o orcamento, o segundo pode ficar para a proxima execucao; acompanhar o log e rodar manualmente fora do pico se necessario. Cada lote confirma separadamente, sem transacao gigante. Erros nao desfazem lotes ja concluidos.

GET_LOCK usa o mesmo servidor MySQL/MariaDB e a mesma conexao durante a execucao. Evita sobreposicao cooperativa, nao garante uma unica entrega diaria. A tarefa e idempotente; retries ou recuperacao de agenda podem repetir o processamento sem atingir linhas recentes.

O worker deve estar rodando:
```bash
php bin/console debug:scheduler --env=prod --no-debug
php bin/console messenger:consume scheduler_default --env=prod --no-debug
```

Use o supervisor/worker existente, nao crie um segundo consumidor sem revisar a topologia. A manutencao sincrona pode ocupar o worker por ate o orcamento e atrasar coletas; em alto volume, usar transporte/worker dedicado em uma proxima alteracao. Reiniciar workers existentes depois do deploy para ler a agenda nova. O cache stateful preserva estado da agenda; nao e uma garantia de exatamente uma execucao.

OPTIMIZE apenas manual, em janela com backup, espaco e privilegios suficientes:
```bash
php bin/console app:db:maintain-snapshots --execute --optimize --env=prod --no-debug
```
Essa opcao tambem executa a limpeza antes de otimizar e pode ultrapassar o limite de tempo. Nao usar como tratamento generico de consultas lentas.

Validacao:
```bash
php -l src/Command/MaintainSnapshotsCommand.php
php -l src/Scheduler/MainSchedule.php
php bin/console lint:container --env=prod --no-debug
```
Nao foram executados PHP, SQL, testes nem scheduler no ambiente de preparacao. Contagens de simulacao podem custar caro em tabelas grandes. Revisar indices e EXPLAIN em copia do banco; nao criar indices ou apagar orfaos automaticamente.
