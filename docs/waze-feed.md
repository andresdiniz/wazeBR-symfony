# Coleta de Alertas Waze (Feed)

## Arquitetura

```
MonitoredLink (type=feed, url=https://waze.com/...)
    │
    └─► WazeFetchAlertsCommand  (php bin/console app:waze:fetch-alerts)
            │
            └─► WazeFeedService::fetchAndPersist()
                    │
                    ├── GET {url}  →  JSON {alerts:[], startTimeMillis, endTimeMillis}
                    └── upsert WazeAlert (chave: wazeId/uuid)
```

## Cadastrar um feed

1. Acesse `/admin/partners` → selecione o parceiro
2. Adicione um `MonitoredLink` com:
   - **type**: `feed`
   - **url**: `https://www.waze.com/row-partnerhub-api/partners/{ID}/waze-feeds/{UUID}?format=1`
   - O sistema extrai automaticamente `feedUuid` e `wazePartnerId` da URL

## Executar manualmente

```bash
# Todos os parceiros
php bin/console app:waze:fetch-alerts

# Só um parceiro
php bin/console app:waze:fetch-alerts --partner=pbh

# Simular sem salvar
php bin/console app:waze:fetch-alerts --dry-run
```

## Cron (a cada 2 minutos)

```cron
*/2 * * * * cd /var/www/wazeBR-symfony && php bin/console app:waze:fetch-alerts >> var/log/waze_fetch.log 2>&1
```

## Campos salvos por alerta

| Campo JSON Waze | Coluna banco       | Observação                      |
|-----------------|--------------------|---------------------------------|
| `uuid`          | `waze_id`          | Chave única de deduplicação     |
| `type`          | `type`             |                                 |
| `subtype`       | `subtype`          |                                 |
| `location.y`    | `latitude`         |                                 |
| `location.x`    | `longitude`        |                                 |
| `street`        | `street`           |                                 |
| `city`          | `city`             |                                 |
| `country`       | `country`          |                                 |
| `reliability`   | `reliability`      |                                 |
| `confidence`    | `confidence`       |                                 |
| `reportRating`  | `report_rating`    |                                 |
| `nThumbsUp`     | `n_thumbs_up`      | **novo**                        |
| `reportDescription` | `report_description` | **novo**                  |
| `magvar`        | `magvar`           | **novo** — direção (0-359°)     |
| `roadType`      | `road_type`        | **novo** — tipo de via          |
| `additionalInfo`| `additional_info`  | **novo**                        |
| `comments`      | `comments`         | **novo** — array JSON           |
| `pubMillis`     | `pub_millis`       |                                 |
| `startTimeMillis` | `feed_start_millis` | **novo** — timestamp do feed |
| —               | `source_link_id`   | **novo** — FK para MonitoredLink|

## Deduplicação

O `wazeId` (uuid) tem `UNIQUE INDEX` no banco. O service faz `findOneBy(['wazeId' => $uuid])` antes de inserir — se já existir, **atualiza** os campos (thumbs up, reliability etc podem mudar). Se não existir, insere novo.
