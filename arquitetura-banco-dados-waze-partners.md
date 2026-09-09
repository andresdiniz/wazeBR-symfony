# Arquitetura de banco de dados — Integração Waze por parceiro

## Objetivo

Organizar a coleta de dois tipos de feed Waze, isolados por parceiro:

1. **Feed operacional**: alertas, irregularidades e congestionamentos (`alerts` e `jams`).
2. **Feed TVT**: estado de tráfego de rotas monitoradas, com tempo, velocidade e atraso.

O modelo deve evitar duplicações, manter o estado atual dos eventos, desativar registros que deixam de ser retornados pelo Waze e armazenar somente dados temporais no histórico de rotas. Geometrias e metadados completos da rota ficam separados do histórico.

> O projeto já possui entidades para `Partner`, `WazeAlert`, `WazeIrregularity`, `WazeTrafficJam`, `WazeRoute`, `WazeRouteLink`, `WazeRouteSnapshot`, `WazeTvtRoute`, `WazeTvtRouteDefinition`, `WazeTvtRouteExecution`, `WazeTvtRouteHistory` e tabelas de coordenadas. A proposta abaixo consolida e define a responsabilidade de cada camada, evitando que a mesma rota seja gravada integralmente a cada coleta.

---

## Princípios de modelagem

- Todo dado vindo do Waze pertence a um **parceiro**.
- A configuração do feed é separada dos dados coletados.
- `uuid`/`id` externo do Waze é a deduplicação principal quando disponível.
- Para casos sem identificador confiável ou para duplicidades semânticas, usar uma segunda deduplicação por tipo, localização, via normalizada e janela de tempo.
- Alertas, irregularidades e congestionamentos são registros de **estado atual**, com `is_active`, `first_seen_at` e `last_seen_at`.
- A ausência em uma única coleta não deve desativar imediatamente um evento: usar uma tolerância de coletas ou tempo sem ser visto.
- Rotas possuem uma definição persistente e versionada; o histórico armazena somente medidas de tráfego.
- A geometria é atualizada ou recebe nova versão apenas quando seu hash mudar.
- Preservar o payload bruto opcionalmente, preferencialmente com retenção curta, para auditoria e depuração.

---

## Visão das relações

```text
Partner
  └── WazeFeed
        ├── WazeAlert
        ├── WazeIrregularity
        ├── WazeTrafficJam
        └── WazeTvtRoute
              └── WazeTvtRouteDefinition (versões de rota/geometria)
                    └── WazeTvtRouteHistory (tempo, velocidade e atraso)
```

Separar `WazeFeed` de `Partner` permite que o mesmo parceiro tenha mais de uma área/feed operacional, múltiplas rotas TVT e futuras integrações sem alterar a modelagem.

---

## Tabelas de configuração

### `partner`

Tabela já existente. Representa a organização, prefeitura, cliente ou operação responsável pelos feeds.

Campos relevantes:

| Campo | Tipo sugerido | Regra |
|---|---:|---|
| `id` | bigint/uuid | PK interna |
| `name` | varchar(150) | Nome do parceiro |
| `slug` | varchar(100) | Único, útil em URLs e filtros |
| `is_active` | boolean | Habilita/desabilita coleta do parceiro |
| `created_at` | datetime | Auditoria |
| `updated_at` | datetime | Auditoria |

Índices:

```sql
UNIQUE KEY uq_partner_slug (slug)
INDEX idx_partner_active (is_active)
```

### `waze_feed`

Armazena os links e credenciais lógicas dos feeds, sem misturar o endpoint diretamente em commands ou `.env` global.

| Campo | Tipo sugerido | Regra |
|---|---:|---|
| `id` | bigint/uuid | PK interna |
| `partner_id` | bigint/uuid | FK para `partner` |
| `feed_type` | enum/string | `EVENTS` ou `TVT` |
| `provider` | varchar(30) | `WAZE` |
| `external_partner_id` | varchar(80) nullable | Ex.: `11682863520`, quando aplicável |
| `feed_uuid` | char(36) | UUID presente na URL Waze |
| `external_route_id` | varchar(80) nullable | Ex.: parâmetro `id` do endpoint TVT |
| `endpoint_url` | text | URL completa ou template do endpoint |
| `label` | varchar(150) | Ex.: `Feed operacional CL`, `TVT Centro -> BR-040` |
| `is_active` | boolean | Define se será coletado |
| `last_success_at` | datetime nullable | Última coleta bem-sucedida |
| `last_error_at` | datetime nullable | Último erro |
| `last_error_message` | text nullable | Diagnóstico resumido |
| `created_at` | datetime | Auditoria |
| `updated_at` | datetime | Auditoria |

Restrições:

```sql
UNIQUE KEY uq_waze_feed_partner_type_route
    (partner_id, feed_type, feed_uuid, external_route_id)

INDEX idx_waze_feed_collection
    (partner_id, feed_type, is_active)
```

Exemplos:

| Parceiro | Tipo | `feed_uuid` | `external_route_id` |
|---|---|---|---|
| Prefeitura/Operação CL | `EVENTS` | `9bb3e551-76f2-4fc6-a32e-ad078a285f2e` | `NULL` |
| Prefeitura/Operação CL | `TVT` | `9bb3e551-76f2-4fc6-a32e-ad078a285f2e` | `12699055487` |

> Não expor a URL do Partner Hub em páginas públicas ou logs completos: ela funciona como URL de acesso ao feed. Caso seja necessário exibir a configuração administrativa, mascarar o UUID.

### `waze_feed_collection`

Auditoria de cada execução do command. Ajuda a saber se a ausência de eventos foi real ou causada por falha de integração.

| Campo | Tipo sugerido | Regra |
|---|---:|---|
| `id` | bigint/uuid | PK |
| `waze_feed_id` | bigint/uuid | FK para `waze_feed` |
| `started_at` | datetime | Início |
| `finished_at` | datetime nullable | Fim |
| `status` | enum/string | `RUNNING`, `SUCCESS`, `FAILED`, `PARTIAL` |
| `http_status` | smallint nullable | Código HTTP |
| `alerts_received` | int | Quantidade recebida |
| `jams_received` | int | Quantidade recebida |
| `routes_received` | int | Quantidade TVT recebida |
| `payload_hash` | char(64) nullable | SHA-256 do payload bruto normalizado |
| `error_message` | text nullable | Erro técnico resumido |
| `created_at` | datetime | Auditoria |

Índices:

```sql
INDEX idx_feed_collection_feed_started (waze_feed_id, started_at DESC)
INDEX idx_feed_collection_status (status, started_at DESC)
```

**Regra crítica:** só executar a desativação de eventos depois de uma coleta com `status = SUCCESS`. Um timeout, HTTP 500 ou JSON inválido não pode desativar alertas existentes.

---

## Eventos do feed operacional

O endpoint operacional possui coleções independentes: `alerts` e `jams`. Elas devem ter tabelas separadas, pois os ciclos de vida e os campos são diferentes.

### `waze_alert`

Registra alertas identificados pelo Waze: acidentes, interdições, perigos, polícia, obras e outros tipos retornados em `alerts`.

Campos principais:

| Campo | Tipo sugerido | Regra |
|---|---:|---|
| `id` | bigint/uuid | PK |
| `partner_id` | bigint/uuid | FK para `partner` |
| `waze_feed_id` | bigint/uuid | FK para `waze_feed` |
| `external_uuid` | char(36) nullable | `alerts[].uuid` |
| `type` | varchar(80) | Ex.: `ACCIDENT`, `ROAD_CLOSED`, `HAZARD` |
| `subtype` | varchar(120) nullable | Ex.: `HAZARD_ON_ROAD_POT_HOLE` |
| `dedup_key` | char(64) | Hash determinístico auxiliar |
| `semantic_cluster_key` | char(64) nullable | Chave de agrupamento por proximidade/rodovia |
| `latitude` | decimal(10,7) | Vem de `location.y` |
| `longitude` | decimal(10,7) | Vem de `location.x` |
| `geohash` | varchar(12) | Ex.: precisão aproximada de 25 a 50 m |
| `street` | varchar(255) nullable | Via retornada |
| `street_normalized` | varchar(255) nullable | Usado para comparação |
| `city` | varchar(150) nullable | Cidade |
| `country` | char(2) nullable | País |
| `road_type` | smallint nullable | `roadType` Waze |
| `description` | text nullable | `reportDescription` |
| `confidence` | smallint nullable | Valor Waze |
| `reliability` | smallint nullable | Valor Waze |
| `report_rating` | smallint nullable | Valor Waze |
| `thumbs_up` | int nullable | `nThumbsUp` |
| `magvar` | smallint nullable | Direção/azimute, se aplicável |
| `reported_at` | datetime nullable | `pubMillis` convertido |
| `first_seen_at` | datetime | Primeira vez no sistema |
| `last_seen_at` | datetime | Última coleta em que apareceu |
| `last_seen_collection_id` | bigint/uuid | FK para `waze_feed_collection` |
| `missing_since_at` | datetime nullable | Início da ausência confirmada |
| `deactivated_at` | datetime nullable | Momento de inativação |
| `is_active` | boolean | Estado operacional atual |
| `raw_payload` | json nullable | Payload individual, opcional |
| `created_at` | datetime | Auditoria |
| `updated_at` | datetime | Auditoria |

Restrições e índices:

```sql
UNIQUE KEY uq_waze_alert_external
    (partner_id, waze_feed_id, external_uuid)

INDEX idx_waze_alert_active_seen
    (partner_id, is_active, last_seen_at DESC)

INDEX idx_waze_alert_type_geo
    (partner_id, type, subtype, geohash)

INDEX idx_waze_alert_street_geo
    (partner_id, street_normalized, latitude, longitude)
```

> Se `external_uuid` vier preenchido, ele é a identificação canônica. A deduplicação geográfica serve para agrupamento, relatório e proteção contra eventuais UUIDs novos para o mesmo problema físico; ela não deve apagar automaticamente um registro com UUID externo diferente sem regra explícita de consolidação.

### `waze_irregularity`

Pode ser mantida como tabela separada para o domínio da aplicação, principalmente se a operação tratar buracos, obstáculos e danos como chamados acompanháveis. Ela pode ser alimentada a partir de `waze_alert` ou substituir `waze_alert` para tipos classificados como irregularidade.

Classificação sugerida:

| Tipo/subtipo Waze | Destino operacional |
|---|---|
| `HAZARD` + `HAZARD_ON_ROAD_POT_HOLE` | `waze_irregularity` com categoria `POTHOLE` |
| `HAZARD` + outro subtipo de pista | `waze_irregularity` com categoria correspondente |
| `ROAD_CLOSED` | `waze_alert`/interdição |
| `ACCIDENT` | `waze_alert`/acidente |

Campos adicionais recomendados para irregularidade:

| Campo | Tipo sugerido | Regra |
|---|---:|---|
| `waze_alert_id` | bigint/uuid nullable | FK de origem, se a tabela for derivada |
| `category` | varchar(80) | Ex.: `POTHOLE`, `OBJECT_ON_ROAD` |
| `status` | enum/string | `OPEN`, `IN_ANALYSIS`, `RESOLVED`, `DISMISSED` |
| `is_active` | boolean | Espelha a presença atual no Waze |
| `operational_notes` | text nullable | Anotações internas |
| `resolved_at` | datetime nullable | Controle operacional |

**Alternativa recomendada:** manter uma única tabela física `waze_alert` com coluna `domain_kind` (`ALERT` ou `IRREGULARITY`) e criar filtros/telas separados. Isso reduz sincronização duplicada. Se a área administrativa já depende fortemente da entidade `WazeIrregularity`, manter a tabela separada é aceitável, desde que haja uma única fonte de verdade.

### `waze_traffic_jam`

Registra o estado atual de congestionamentos retornados em `jams`.

| Campo | Tipo sugerido | Regra |
|---|---:|---|
| `id` | bigint/uuid | PK |
| `partner_id` | bigint/uuid | FK para `partner` |
| `waze_feed_id` | bigint/uuid | FK para `waze_feed` |
| `external_id` | varchar(80) nullable | `jams[].id` |
| `external_uuid` | varchar(80) nullable | `jams[].uuid` |
| `dedup_key` | char(64) | Hash de fallback |
| `street` | varchar(255) nullable | Rua/rodovia |
| `street_normalized` | varchar(255) nullable | Comparação |
| `city` | varchar(150) nullable | Cidade |
| `country` | char(2) nullable | País |
| `road_type` | smallint nullable | Tipo Waze |
| `start_latitude` | decimal(10,7) nullable | Primeiro ponto de `line` |
| `start_longitude` | decimal(10,7) nullable | Primeiro ponto de `line` |
| `end_latitude` | decimal(10,7) nullable | Último ponto de `line` |
| `end_longitude` | decimal(10,7) nullable | Último ponto de `line` |
| `geometry_hash` | char(64) | SHA-256 da linha normalizada |
| `geometry` | json | Array de coordenadas da linha |
| `length_meters` | int nullable | `length` |
| `speed_kmh` | decimal(8,2) nullable | `speedKMH` |
| `speed_mps` | decimal(8,3) nullable | `speed` |
| `delay_seconds` | int nullable | `delay` |
| `level` | smallint nullable | Nível Waze |
| `turn_type` | varchar(40) nullable | `turnType` |
| `blocking_alert_uuid` | char(36) nullable | Vínculo lógico com alerta |
| `published_at` | datetime nullable | `pubMillis` |
| `first_seen_at` | datetime | Primeira ocorrência |
| `last_seen_at` | datetime | Última vez visto |
| `last_seen_collection_id` | bigint/uuid | FK para coleta |
| `missing_since_at` | datetime nullable | Início da ausência |
| `deactivated_at` | datetime nullable | Fim lógico |
| `is_active` | boolean | Estado atual |
| `raw_payload` | json nullable | Opcional |
| `created_at` | datetime | Auditoria |
| `updated_at` | datetime | Auditoria |

Restrições:

```sql
UNIQUE KEY uq_waze_jam_external
    (partner_id, waze_feed_id, external_id)

INDEX idx_waze_jam_active_seen
    (partner_id, is_active, last_seen_at DESC)

INDEX idx_waze_jam_blocking_alert
    (partner_id, blocking_alert_uuid)

INDEX idx_waze_jam_street_geo
    (partner_id, street_normalized, start_latitude, start_longitude)
```

### Geometria de congestionamento

Para poucos pontos, o JSON em `waze_traffic_jam.geometry` é suficiente. Se a consulta geoespacial e mapas forem frequentes, usar MySQL/MariaDB com tipo espacial:

```sql
geometry LINESTRING SRID 4326 NULL
SPATIAL INDEX sp_idx_waze_jam_geometry (geometry)
```

A opção espacial permite detectar duplicidade por interseção/proximidade com precisão melhor do que comparar somente o primeiro ponto.

---

## Deduplicação de alertas e irregularidades

### Regra 1 — UUID externo: prioridade máxima

Ao receber um item com `alerts[].uuid`:

1. Buscar por `(partner_id, waze_feed_id, external_uuid)`.
2. Se existir, atualizar campos mutáveis e `last_seen_at`.
3. Se não existir, criar o registro.
4. Marcar `is_active = 1`, limpar `missing_since_at` e `deactivated_at`.

```sql
SELECT id
FROM waze_alert
WHERE partner_id = :partnerId
  AND waze_feed_id = :feedId
  AND external_uuid = :externalUuid
LIMIT 1;
```

### Regra 2 — chave determinística de fallback

Quando não houver UUID, calcular `dedup_key` com campos estáveis:

```text
SHA-256(
  partner_id + "|" + feed_id + "|" +
  type + "|" + subtype + "|" +
  street_normalized + "|" +
  geohash_8 + "|" +
  data_publicacao_arredondada_em_5_minutos
)
```

A chave não deve incluir campos que mudam frequentemente, como `confidence`, `reliability`, votos, velocidade ou descrição livre.

### Regra 3 — deduplicação semântica por proximidade

Esse passo é adicional e deve ser aplicado principalmente a irregularidades persistentes, como buracos, quando o Waze gerar UUID novo para o mesmo local.

Critérios sugeridos para considerar o mesmo problema físico:

- Mesmo `partner_id`.
- Mesmo grupo de classificação (`HAZARD/POTHOLE`, por exemplo).
- Mesma rua/rodovia normalizada, quando disponível.
- Distância entre coordenadas menor que o limiar configurado.
- Evento ativo ou desativado recentemente.
- Janela de tempo compatível, quando o tipo for transitório.

Limiar inicial recomendado:

| Caso | Raio inicial | Observação |
|---|---:|---|
| Buraco/irregularidade pontual em via urbana | 30 m | Pode subir para 50 m conforme GPS/Waze |
| Irregularidade em rodovia | 75 m | Rodovia longa exige comparação com direção e trecho |
| Acidente | 50 m | Usar janela curta de tempo |
| Interdição | 100 m | Pode cobrir mais de um ponto/segmento |
| Congestionamento | Sobreposição da linha | Não usar apenas raio pontual |

Exemplo de consulta usando aproximação em MySQL/MariaDB:

```sql
SELECT id,
       ST_Distance_Sphere(
           POINT(longitude, latitude),
           POINT(:longitude, :latitude)
       ) AS distance_meters
FROM waze_alert
WHERE partner_id = :partnerId
  AND type = :type
  AND subtype = :subtype
  AND street_normalized = :streetNormalized
  AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
HAVING distance_meters <= :radiusMeters
ORDER BY distance_meters ASC
LIMIT 1;
```

Caso a extensão espacial não esteja habilitada, usar bounding box antes da fórmula de Haversine, nunca uma varredura completa da tabela.

### Normalização de rua/rodovia

Persistir um valor normalizado para comparação:

```text
"BR-040", "BR 040" e "Rodovia BR-040" -> "BR 040"
"R. João José Ferreira" -> "R JOAO JOSE FERREIRA"
```

Processo sugerido:

1. Converter para maiúsculas.
2. Remover acentos.
3. Trocar pontuação por espaço.
4. Colapsar espaços.
5. Padronizar abreviações como `RUA`/`R`, `AVENIDA`/`AV`, `RODOVIA`/`ROD`.
6. Aplicar regra específica para rodovias (`BR-040`, `MGC-482`, `MG-129`).

### Não fundir automaticamente eventos diferentes

Dois alertas próximos na mesma rodovia não são necessariamente duplicados. Por exemplo, dois buracos a 40 m de distância podem ser problemas distintos. Por isso:

- UUID igual: atualizar o mesmo registro.
- UUID diferente + proximidade: marcar como **possível duplicidade** ou usar `semantic_cluster_key`.
- Só consolidar automaticamente se houver regra comprovada para o tipo de evento.
- Registrar a decisão, por exemplo em `waze_event_merge_log`, se houver consolidação manual/automática.

---

## Ativação e desativação

### Campos obrigatórios de ciclo de vida

Em `waze_alert`, `waze_irregularity` e `waze_traffic_jam`:

```text
is_active
first_seen_at
last_seen_at
missing_since_at
deactivated_at
last_seen_collection_id
```

### Processo por coleta bem-sucedida

1. Criar `waze_feed_collection` com `RUNNING`.
2. Baixar e validar o JSON.
3. Inserir/atualizar os itens recebidos, definindo:

```text
is_active = 1
last_seen_at = agora
last_seen_collection_id = coleta atual
missing_since_at = NULL
deactivated_at = NULL
```

4. Marcar a coleta como `SUCCESS` apenas depois de processar completamente o payload.
5. Identificar ativos daquele feed que não foram vistos na coleta atual.
6. Iniciar `missing_since_at` para eles, sem desativar ainda.
7. Desativar somente os que excederem a política de ausência.

### Política de ausência recomendada

Não alterar `is_active` para `0` ao faltar em apenas uma resposta. O feed pode falhar parcialmente, sofrer atraso ou retornar dado temporariamente incompleto.

Configuração inicial:

| Tipo | Desativar após | Motivo |
|---|---:|---|
| `ACCIDENT` e alertas transitórios | 2 coletas bem-sucedidas ausentes ou 15 min | Eventos rápidos |
| `ROAD_CLOSED` | 3 coletas bem-sucedidas ausentes ou 30 min | Evita reabrir cedo demais |
| Buraco/irregularidade | 6 coletas bem-sucedidas ausentes ou 24 h | Pode reaparecer/oscilar no feed |
| `JAM` | 2 coletas bem-sucedidas ausentes ou 10 min | Fenômeno de curta duração |

Consulta conceitual:

```sql
UPDATE waze_alert
SET is_active = 0,
    deactivated_at = NOW()
WHERE waze_feed_id = :feedId
  AND is_active = 1
  AND missing_since_at IS NOT NULL
  AND missing_since_at <= :cutoff;
```

Use a regra baseada em **tempo**, ou acrescente um contador `consecutive_missed_collections` para controlar ausências consecutivas. O contador deve ser incrementado somente em coletas `SUCCESS`.

### Reativação

Se um item anteriormente inativo voltar a ser recebido com o mesmo UUID ou mesma correspondência confirmada:

```text
is_active = 1
missing_since_at = NULL
deactivated_at = NULL
last_seen_at = agora
```

Isso preserva o mesmo registro e o histórico de sua vida útil.

---

## Rotas TVT: definição e histórico

A coleta TVT não deve gravar todas as coordenadas, nomes e metadados da rota em todas as execuções. O modelo precisa separar:

1. **Identidade da rota monitorada**.
2. **Versões da definição da rota**, incluindo geometria e dados estáveis.
3. **Histórico temporal**, contendo somente métricas que variam com o tempo.

### `waze_tvt_route`

Representa a identidade estável da rota configurada para um parceiro.

| Campo | Tipo sugerido | Regra |
|---|---:|---|
| `id` | bigint/uuid | PK interna |
| `partner_id` | bigint/uuid | FK para `partner` |
| `waze_feed_id` | bigint/uuid | FK para feed TVT |
| `external_route_id` | varchar(80) | ID TVT da URL/parâmetro |
| `external_uuid` | varchar(80) nullable | UUID retornado pelo Waze, se existir |
| `label` | varchar(200) nullable | Nome administrativo |
| `is_active` | boolean | Rota monitorada |
| `current_definition_id` | bigint/uuid nullable | FK para versão atual |
| `first_seen_at` | datetime | Primeira coleta |
| `last_seen_at` | datetime | Última coleta |
| `created_at` | datetime | Auditoria |
| `updated_at` | datetime | Auditoria |

```sql
UNIQUE KEY uq_tvt_route_external
    (partner_id, waze_feed_id, external_route_id)

INDEX idx_tvt_route_active
    (partner_id, is_active)
```

### `waze_tvt_route_definition`

Guarda dados estruturais e relativamente estáveis da rota. Uma nova versão só é criada quando houver mudança relevante.

| Campo | Tipo sugerido | Regra |
|---|---:|---|
| `id` | bigint/uuid | PK |
| `waze_tvt_route_id` | bigint/uuid | FK para rota base |
| `version_number` | int | Crescente por rota |
| `definition_hash` | char(64) | SHA-256 dos dados estruturais normalizados |
| `name` | varchar(255) nullable | Nome retornado/configurado |
| `origin_name` | varchar(255) nullable | Origem |
| `destination_name` | varchar(255) nullable | Destino |
| `distance_meters` | int nullable | Distância estrutural |
| `geometry` | json/spatial | Todas as coordenadas da rota |
| `geometry_hash` | char(64) | SHA-256 da geometria normalizada |
| `segment_count` | int nullable | Quantidade de segmentos |
| `metadata` | json nullable | Campos estáveis adicionais |
| `is_current` | boolean | Apenas uma versão atual por rota |
| `valid_from` | datetime | Início da versão |
| `valid_until` | datetime nullable | Fim da versão |
| `created_at` | datetime | Auditoria |

```sql
UNIQUE KEY uq_tvt_route_definition_hash
    (waze_tvt_route_id, definition_hash)

INDEX idx_tvt_route_definition_current
    (waze_tvt_route_id, is_current)
```

### Hash da definição da rota

Montar um payload canônico somente com dados que definem a rota, por exemplo:

```json
{
  "externalRouteId": "12699055487",
  "name": "origem-destino normalizado",
  "distanceMeters": 12345,
  "geometry": [
    [-43.795482, -20.681956],
    [-43.794727, -20.681175]
  ],
  "segments": ["id-1", "id-2"]
}
```

Em seguida:

```php
$definitionHash = hash(
    'sha256',
    json_encode($canonicalDefinition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);
```

Cuidados:

- Ordenar chaves do array antes de gerar o JSON.
- Arredondar coordenadas, por exemplo a 6 casas decimais, para evitar uma nova versão por ruído mínimo.
- Não incluir tempo de viagem, velocidade, atraso, data de coleta ou outros campos dinâmicos.
- Se só mudar o tráfego, manter a mesma `waze_tvt_route_definition`.

### O que fazer quando a rota mudar

| Alteração detectada | Ação |
|---|---|
| Apenas velocidade, tempo ou atraso | Inserir apenas histórico |
| Nome alterado, mesma geometria | Criar nova definição ou atualizar o rótulo, conforme necessidade de auditoria |
| Distância alterada significativamente | Criar nova versão da definição |
| Coordenadas/segmentos alterados | Criar nova versão da definição |
| Rota externa mantém o mesmo UUID/ID | Manter o mesmo `waze_tvt_route` e trocar `current_definition_id` |
| Rota externa recebe outro ID/UUID | Criar nova `waze_tvt_route`, mantendo relação com o mesmo parceiro/feed |

**Recomendação:** não sobrescrever a versão antiga quando houver mudança estrutural. Fechar a versão atual com `valid_until`, criar outra e apontar `current_definition_id` para ela. Isso mantém a consistência histórica: cada leitura temporal continua ligada à geometria válida no momento da medição.

### `waze_tvt_route_history`

Tabela de alto volume. Deve salvar somente dados temporais e chaves estrangeiras.

| Campo | Tipo sugerido | Regra |
|---|---:|---|
| `id` | bigint/uuid | PK |
| `waze_tvt_route_id` | bigint/uuid | FK para rota base |
| `waze_tvt_route_definition_id` | bigint/uuid | FK para versão usada na coleta |
| `waze_feed_collection_id` | bigint/uuid | FK para auditoria da execução |
| `observed_at` | datetime | Data/hora da observação Waze ou da coleta |
| `travel_time_seconds` | int nullable | Tempo total |
| `travel_time_minutes` | decimal(10,2) nullable | Opcional para leitura rápida |
| `speed_kmh` | decimal(8,2) nullable | Velocidade média |
| `delay_seconds` | int nullable | Atraso estimado |
| `length_meters` | int nullable | Caso o feed reporte medida dinâmica |
| `status` | varchar(40) nullable | Estado retornado pelo feed |
| `raw_metrics` | json nullable | Somente campos dinâmicos adicionais |
| `created_at` | datetime | Auditoria |

Índices essenciais:

```sql
INDEX idx_tvt_route_history_route_time
    (waze_tvt_route_id, observed_at DESC)

INDEX idx_tvt_route_history_definition_time
    (waze_tvt_route_definition_id, observed_at DESC)

UNIQUE KEY uq_tvt_route_history_observation
    (waze_tvt_route_id, observed_at)
```

A coluna `waze_tvt_route_definition_id` é importante: ela permite reconstruir a rota exibida em um gráfico histórico com a geometria correta daquela época.

---

## Fluxo de coleta operacional

### Command de eventos

Responsável pelo endpoint `waze-feeds`, que retorna `alerts` e `jams`.

```text
waze:collect-feed --partner=<id opcional> --feed=<id opcional>
```

Passo a passo:

1. Buscar feeds `EVENTS` ativos.
2. Criar uma linha `waze_feed_collection` em `RUNNING`.
3. Fazer a requisição HTTP com timeout, retentativa e log seguro.
4. Validar a estrutura do JSON: as chaves `alerts` e `jams` devem ser arrays.
5. Processar todos os alertas pelo UUID e pelas regras auxiliares de deduplicação.
6. Processar todos os congestionamentos por `id`/`uuid`, atualizando geometria e métricas atuais.
7. Salvar a coleta com `SUCCESS` e contadores recebidos.
8. Executar a rotina de ausência somente para o feed processado e somente após `SUCCESS`.
9. Registrar métricas: criados, atualizados, reativados, candidatos a duplicidade e desativados.

Pseudocódigo:

```php
$collection = $collectionService->start($feed);

try {
    $payload = $wazeClient->fetchEventsFeed($feed);
    $validator->validateEventsPayload($payload);

    foreach ($payload['alerts'] as $item) {
        $eventSynchronizer->upsertAlert($feed, $collection, $item);
    }

    foreach ($payload['jams'] as $item) {
        $eventSynchronizer->upsertJam($feed, $collection, $item);
    }

    $collectionService->succeed($collection, $payload);
    $eventLifecycleService->markMissingAndDeactivateExpired($feed, $collection);
} catch (\Throwable $exception) {
    $collectionService->fail($collection, $exception);
    throw $exception;
}
```

### Command TVT

Responsável pelo endpoint `feeds-tvt` e pelo histórico de rotas.

```text
waze:collect-tvt --partner=<id opcional> --feed=<id opcional>
```

Passo a passo:

1. Buscar feeds `TVT` ativos.
2. Iniciar `waze_feed_collection`.
3. Buscar o payload TVT.
4. Resolver a rota base por `(partner, feed, external_route_id)`.
5. Criar/atualizar `waze_tvt_route`.
6. Montar a definição canônica e calcular `definition_hash`.
7. Se o hash mudou, fechar a definição atual e criar uma nova versão.
8. Inserir uma linha em `waze_tvt_route_history` contendo somente as métricas temporais.
9. Finalizar a coleta como `SUCCESS`.

Pseudocódigo:

```php
$route = $routeRepository->findOrCreateByExternalId($feed, $externalRouteId);
$definition = $definitionFactory->fromPayload($payload);
$hash = $definitionHasher->hash($definition);

$currentDefinition = $route->getCurrentDefinition();

if ($currentDefinition === null || $currentDefinition->getDefinitionHash() !== $hash) {
    $currentDefinition?->close($observedAt);
    $currentDefinition = $routeDefinitionService->createVersion($route, $definition, $hash, $observedAt);
}

$historyRepository->insertMetrics(
    route: $route,
    definition: $currentDefinition,
    collection: $collection,
    observedAt: $observedAt,
    travelTimeSeconds: $metrics['travelTimeSeconds'],
    speedKmh: $metrics['speedKmh'],
    delaySeconds: $metrics['delaySeconds'],
);
```

---

## Política de atualização de dados

### Eventos e congestionamentos

| Campo | Atualizar a cada coleta? | Observação |
|---|---|---|
| UUID/ID externo | Não | Identidade externa |
| Tipo e subtipo | Sim, se Waze alterar | Preservar payload bruto para auditoria |
| Rua, cidade, país | Sim | Waze pode enriquecer dados depois |
| Coordenadas | Sim | Registrar alteração significativa se necessário |
| Confiabilidade, votos, rating | Sim | Métricas mutáveis |
| Velocidade, atraso, nível do jam | Sim | Estado atual |
| `last_seen_at` | Sempre | Item retornado com sucesso |
| `is_active` | Sempre que retornado | Forçar para `1` |

### Definição de rota

| Campo | Atualizar ou versionar? |
|---|---|
| Tempo, velocidade, atraso | Inserir em histórico |
| Geometria | Nova versão se hash mudou |
| Segmentos | Nova versão se hash mudou |
| Distância | Nova versão se mudança acima de tolerância definida |
| Nome | Pode versionar; recomendado se necessário para auditoria |
| Rótulo interno da operação | Atualizar direto em `waze_tvt_route` |

---

## Retenção e desempenho

### Dados de estado

- Manter alertas, irregularidades e congestionamentos inativos para auditoria.
- Não excluir automaticamente eventos inativos recentes.
- Permitir arquivamento após prazo de negócio, por exemplo 12 ou 24 meses.

### Histórico TVT

O histórico cresce rapidamente. Exemplo: 1 rota coletada a cada 5 minutos gera 288 linhas por dia e aproximadamente 105 mil linhas por ano.

Recomendações:

- Índice composto por rota e data (`route_id`, `observed_at`).
- Agregação após retenção: manter granularidade de 5 minutos por 90 dias, hora por 1 ano e dia para longo prazo, se necessário.
- Particionar por mês/ano quando houver alto volume e o banco suportar a estratégia operacional.
- Nunca salvar a geometria completa no histórico de cada coleta.
- Armazenar `raw_payload` com retenção curta ou em tabela/armazenamento separado, pois JSON bruto pode crescer muito.

### Snapshots opcionais

Se houver necessidade de auditoria completa, criar `waze_feed_payload_snapshot`:

| Campo | Tipo sugerido |
|---|---:|
| `id` | bigint/uuid |
| `waze_feed_collection_id` | bigint/uuid |
| `payload_compressed` | longblob/text |
| `payload_hash` | char(64) |
| `expires_at` | datetime nullable |

Isso evita poluir as tabelas de domínio com um JSON grande repetido em cada registro.

---

## Migração sugerida no projeto atual

O projeto já possui entidades de eventos, congestionamentos, rotas, snapshots e TVT. Antes de criar novas tabelas, mapear as existentes para estas responsabilidades:

| Entidade existente | Responsabilidade recomendada |
|---|---|
| `Partner` | Proprietário lógico dos feeds e dados |
| `MonitoredLink` | Pode evoluir para configuração de `WazeFeed` ou coexistir como vínculo administrativo |
| `WazeAlert` | Estado atual de alertas Waze |
| `WazeIrregularity` | Domínio operacional de buracos/perigos, se mantido separado |
| `WazeTrafficJam` | Estado atual dos congestionamentos |
| `WazeRoute` / `WazeRouteLink` | Rota de navegação/configuração administrativa |
| `WazeRouteSnapshot*` | Pode servir para snapshot de rota, mas não deve duplicar geometria sem necessidade |
| `WazeTvtRoute` | Identidade estável da rota TVT |
| `WazeTvtRouteDefinition` | Versão estrutural da rota com hash/geometria |
| `WazeTvtRouteHistory` | Métricas temporais leves |
| `WazeTvtRouteExecution*` | Auditoria de execução; avaliar unificar com `WazeFeedCollection` |
| `WazeTvtSnapshot` | Snapshot técnico, opcional e com retenção |

### Ordem de implementação

1. Auditar migrations e entidades atuais para evitar tabelas duplicadas com nomes diferentes.
2. Criar ou adaptar `waze_feed` e `waze_feed_collection`.
3. Garantir FKs de parceiro/feed em alertas, irregularidades, jams e TVT.
4. Adicionar campos de ciclo de vida: `first_seen_at`, `last_seen_at`, `missing_since_at`, `deactivated_at`, `is_active`.
5. Criar índices únicos por identificador externo e parceiro/feed.
6. Adicionar `street_normalized`, `geohash`, `dedup_key` e `geometry_hash`.
7. Adaptar o command de eventos para executar upsert e rotina de ausência segura.
8. Adaptar o command TVT para separar definição versionada de histórico de métricas.
9. Criar dashboards administrativos: feeds com falha, eventos ativos, eventos desativados, rotas sem coleta e possíveis duplicidades.
10. Adicionar testes de integração para UUID, deduplicação geográfica, ausência, reativação e versionamento de rota.

---

## Casos de teste mínimos

### Alerta com mesmo UUID

- Primeira coleta: inserir alerta, `is_active = 1`, preencher `first_seen_at` e `last_seen_at`.
- Segunda coleta: mesmo UUID, atualizar campos e apenas `last_seen_at`.
- Resultado esperado: uma única linha.

### Buraco com UUID diferente, mesma via e 20 m de distância

- Encontrar candidato por tipo/subtipo, via normalizada e distância.
- Marcar como `possible_duplicate`/cluster ou aplicar a regra de consolidação configurada.
- Resultado esperado: não criar duplicidade operacional sem evidência; preservar rastreabilidade dos UUIDs.

### Alerta ausente por uma coleta

- Coleta bem-sucedida sem o UUID.
- Definir `missing_since_at`, mantendo `is_active = 1`.
- Resultado esperado: não desativar imediatamente.

### Alerta ausente além do limite

- Após o limite de coletas bem-sucedidas ou tempo de tolerância.
- Alterar `is_active = 0` e preencher `deactivated_at`.

### Alerta reaparece

- Mesmo UUID volta a constar no feed.
- Restaurar `is_active = 1`, limpar campos de ausência e atualizar `last_seen_at`.

### TVT sem alteração estrutural

- Mesmo `definition_hash` em coletas seguidas.
- Criar apenas linhas em `waze_tvt_route_history`.
- Resultado esperado: uma definição e muitas métricas leves.

### TVT com geometria alterada

- `geometry_hash`/`definition_hash` muda.
- Fechar versão atual, criar nova `waze_tvt_route_definition` e inserir o histórico vinculado à nova versão.
- Resultado esperado: histórico antigo continua ligado à geometria antiga.

---

## Resultado esperado

Com esse modelo, cada parceiro possui seus próprios links e dados Waze; alertas, irregularidades e congestionamentos permanecem atualizados sem duplicação indevida; eventos que desaparecem são desativados de forma segura; e o TVT mantém uma base histórica leve, sem replicar coordenadas e metadados completos a cada execução.
