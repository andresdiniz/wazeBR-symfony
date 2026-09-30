# Monitoramento e validação

## Estados propostos

| Estado | Critério |
| --- | --- |
| Saudável | Execução recente, sem erro e dados dentro da janela esperada. |
| Atrasado | Última execução ou observação excedeu a janela definida. |
| Com falha | Erro explícito, leitura sem sucesso ou comando com saída não zero. |
| Sem dados | Fonte ativa, mas sem registros; investigar antes de classificar como falha. |
| Desabilitado | Integração ou cadastro intencionalmente inativo. |

As janelas devem ser definidas por fonte e parceiro. Não usar um único prazo
para cron de semáforos, meteorologia e observações CEMADEN.

## Painel mínimo

- Última execução bem-sucedida por comando e parceiro.
- Idade do registro mais recente por fonte e parceiro.
- Quantidade de cadastros ativos sem coleta recente.
- Quantidade de erros de semáforos e duração das leituras.
- Divergências de `partner_id` entre observações e seus cadastros.
- Crescimento das tabelas históricas e espaço disponível no banco.
- Estado dos consumidores de fila, se houver dependência de Messenger.

## Consultas somente de leitura

```sql
SELECT partner_id, COUNT(*) AS total,
       MAX(collected_at) AS ultima_coleta,
       MAX(last_seen_at) AS ultima_visualizacao
FROM waze_alerts
GROUP BY partner_id;

SELECT partner_id, COUNT(*) AS total,
       MAX(collected_at) AS ultima_coleta,
       MAX(last_seen_at) AS ultima_visualizacao
FROM waze_jams
GROUP BY partner_id;

SELECT partner_id, COUNT(*) AS total,
       MAX(recorded_at) AS ultimo_snapshot
FROM waze_tvt_route_snapshot
GROUP BY partner_id;

SELECT l.partner_id, l.id AS local_id, l.name,
       l.active, MAX(o.observed_at) AS ultima_observacao
FROM weather_location l
LEFT JOIN weather_observation o ON o.weather_location_id = l.id
GROUP BY l.partner_id, l.id, l.name, l.active;

SELECT l.partner_id, l.id AS estacao_id, l.station_name,
       l.active, l.last_fetched_at,
       MAX(o.observed_at) AS ultima_observacao
FROM cemaden_station_link l
LEFT JOIN cemaden_pluviometric_observation o
  ON o.cemaden_station_link_id = l.id
GROUP BY l.partner_id, l.id, l.station_name,
         l.active, l.last_fetched_at;

SELECT l.partner_id, l.id AS estacao_id, l.station_name,
       l.active, l.last_fetched_at,
       MAX(o.observed_at) AS ultima_observacao
FROM cemaden_hidro_station_link l
LEFT JOIN cemaden_hidro_observation o
  ON o.cemaden_hidro_station_link_id = l.id
GROUP BY l.partner_id, l.id, l.station_name,
         l.active, l.last_fetched_at;

SELECT t.partner_id, t.id, t.code, t.is_active,
       t.last_read_at, t.last_read_status,
       COUNT(s.id) AS total_leituras,
       MAX(s.read_at) AS ultima_leitura
FROM traffic_light t
LEFT JOIN traffic_light_snapshot s ON s.traffic_light_id = t.id
GROUP BY t.partner_id, t.id, t.code, t.is_active,
         t.last_read_at, t.last_read_status;
```

## Checklist após implantação

- [ ] Migrations aplicadas sem diferenças inesperadas.
- [ ] Cron/agendador executou após o deploy.
- [ ] Cada fonte ativa gerou um registro ou erro diagnosticável.
- [ ] Login e acesso de parceiro funcionam.
- [ ] Usuário de um parceiro não vê dados de outro.
- [ ] Feeds e dashboards exibem dados coerentes com as consultas.
- [ ] Logs não expõem tokens, senhas ou payloads sensíveis.
- [ ] Backup foi testado quanto à possibilidade de restauração.
