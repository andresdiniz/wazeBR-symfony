# Coletas e agendamento

## Inventário observado

| Componente | Arquivo | Família de dados |
| --- | --- | --- |
| Feed Waze | `src/Command/FetchWazeFeedCommand.php` | `waze_alerts`, `waze_jams`. |
| TVT Waze | `src/Command/FetchWazeTvtCommand.php` | Rotas, sub-rotas, snapshots e irregularidades. |
| Feeds de parceiros | `src/Command/FetchPartnerFeedsCommand.php` | Integrações de parceiros. |
| Chuva CEMADEN | `src/Command/FetchCemadenPluviometricCommand.php` | Estações e observações pluviométricas. |
| Hidrologia CEMADEN | `src/Command/FetchCemadenHidroCommand.php` | Estações e observações hidrológicas. |
| Clima | `src/Command/FetchWeatherObservationsCommand.php` | Locais e observações meteorológicas. |
| Semáforos | `src/Command/PollTrafficLightsCommand.php` | Estado e snapshots de leitura. |
| Expiração de eventos | `src/Command/PartnerFeedExpireCommand.php` | Ciclo de vida de eventos. |
| Teste de links | `src/Command/TestApiLinksCommand.php` | Validação de links de API. |
| Atualização de JSON | `src/Command/UpdatePartnerFeedJsonCommand.php` | Saída do feed de parceiros. |
| Importação SensorThings | `src/Command/ImportSensorThingsCommand.php` | Integração a confirmar. |
| Briefing diário | `src/Command/WazeDailyBriefingCommand.php` | Geração de briefing. |

## Pontos de execução

- `cron.php`: conferir como invoca os comandos no ambiente hospedado.
- `src/Scheduler/MainSchedule.php`: conferir tarefas e intervalos configurados.
- `supervisor/`: conferir processos persistentes, se utilizados.
- `src/Message`, `src/MessageHandler` e `src/Messenger`: conferir se algum
  fluxo depende de consumidores de fila.
- Configuração real do servidor: conferir cron, diretório de trabalho,
  versão do PHP, variáveis de ambiente e política de logs.

Não há, neste documento, afirmação de periodicidade real: ela deve ser
copiada da configuração efetivamente implantada.

## Registro operacional por tarefa

Para cada tarefa em produção, preencher:

| Campo | Valor |
| --- | --- |
| Nome do comando Symfony | A confirmar com `php bin/console list`. |
| Fonte de dados | A confirmar. |
| Parceiros atendidos | A confirmar. |
| Frequência contratada | A confirmar. |
| Agendador efetivo | Cron, Scheduler ou outro: confirmar. |
| Tempo máximo esperado | Definir por medição. |
| Tabela/indicador de sucesso | Definir. |
| Log e local de consulta | Definir. |
| Responsável pelo alerta | Definir. |

## Teste controlado

1. Faça backup e identifique o ambiente antes de acionar uma coleta.
2. Confirme as opções com `php bin/console help NOME_DO_COMANDO`.
3. Registre horário, parceiro e quantidade de registros antes da execução.
4. Execute apenas o comando desejado, evitando concorrência com o cron.
5. Confira código de saída, log, timestamps e atualização das tabelas.
6. Repita a execução, quando seguro, para validar deduplicação/idempotência.
7. Verifique se registros desaparecidos da fonte seguem a regra de expiração.
