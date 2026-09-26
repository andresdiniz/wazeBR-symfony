# 🗺️ Mapa do Banco de Dados — wazeBR-symfony

> **Banco:** `u629736858_trafik` · **Engine:** MariaDB · **ORM:** Doctrine  
> Documentação gerada a partir do schema phpMyAdmin (set/2026)

---

## Visão Geral — Diagrama de Relações

```
┌─────────┐
│ partner │  (âncora central do sistema — todo dado pertence a um parceiro)
└────┬────┘
     │ id
     ├──────────────────────┬──────────────────────┬──────────────────────┐
     │                      │                      │                      │
     ▼                      ▼                      ▼                      ▼
┌────────────────┐  ┌───────────────────┐  ┌─────────────────┐  ┌──────────────────┐
│ partner_api    │  │ partner_camera    │  │ partner_feed    │  │ user             │
│ _link          │  │ _link             │  │ _event          │  │ (nullable fk)    │
└────────────────┘  └───────────────────┘  └─────────────────┘  └──────────────────┘
     │                                             │ created/updated_by_user_id
     │                                             ▼
     │                                       ┌──────────────────┐
     │                                       │ reset_password   │
     │                                       │ _request         │
     │                                       └──────────────────┘

     ├──────── Dados Waze ────────────────────────────────────────────────────────┐
     │                                                                            │
     ▼                                                                            ▼
┌─────────────┐  ┌─────────────┐  ┌─────────────────────┐                ┌──────────────────────┐
│ waze_alerts │  │ waze_jams   │  │ waze_tvt_route      │◄───────────────│ waze_tvt_route       │
└─────────────┘  └─────────────┘  └──────────┬──────────┘                │ _snapshot            │
                                             │ id                         └──────────────────────┘
                                             │
                          ┌──────────────────┼──────────────────┐
                          │                  │                  │
                          ▼                  ▼                  ▼
                  ┌──────────────────┐ ┌────────────────┐ ┌──────────────────────┐
                  │ waze_tvt         │ │ waze_tvt       │ │ waze_tvt             │
                  │ _sub_route       │ │ _irregularity  │ │ _user_on_jam         │
                  └──────────────────┘ └────────────────┘ └──────────────────────┘

     ├──────── Dados CEMADEN ─────────────────────────────────────────────────────┐
     │                                                                            │
     ▼                                                                            ▼
┌──────────────────────────┐           ┌────────────────────────────┐
│ cemaden_station_link     │◄──────────│ cemaden_pluviometric       │
│ (estações pluviométricas)│           │ _observation               │
└──────────────────────────┘           └────────────────────────────┘

┌──────────────────────────┐           ┌────────────────────────────┐
│ cemaden_hidro_station    │◄──────────│ cemaden_hidro              │
│ _link                    │           │ _observation               │
└──────────────────────────┘           └────────────────────────────┘

     ├──────── Câmeras ───────────────────────────────────────────────────────────┐
     │                                                                            ▼
     │                                                               ┌────────────────────┐
     │                                                               │ camera             │
     │                                                               └────────────────────┘

     └──────── Clima ─────────────────────────────────────────────────────────────┐
                                                                                  ▼
                                                               ┌──────────────────────────┐
                                                               │ weather_location         │◄──┐
                                                               └──────────────────────────┘   │
                                                               ┌──────────────────────────┐   │
                                                               │ weather_observation      │───┘
                                                               └──────────────────────────┘
```

---

## Tabelas

---

### `partner`

**Entidade central do sistema.** Todo dado coletado, toda câmera, todo usuário e toda integração pertence a um `partner`. Um parceiro representa um cliente/organização que contrata a plataforma — pode ser uma prefeitura, concessionária de rodovias, empresa de mobilidade, etc. O sistema é projetado como multi-tenant: cada query de dados leva um `partner_id` como filtro obrigatório.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | int PK | Identificador único |
| `name` | varchar(255) | Nome do parceiro |
| `code` | varchar(50) UNIQUE | Código curto (slug), identificador amigável |
| `city` / `state` | varchar | Localização principal do parceiro |
| `api_key` / `api_token` / `api_secret` | varchar | Credenciais para integração com APIs externas (Waze, etc.) |
| `fetch_frequency` + `fetch_frequency_unit` | int + varchar | Intervalo de coleta de dados (ex.: 5 minutos) |
| `last_fetch_at` | datetime | Última coleta de alertas/jams |
| `last_tvt_fetch_at` | datetime | Última coleta de dados TVT (Traffic, Travel Time) |
| `feed_url` | varchar | URL do feed de eventos CIFS do parceiro |
| `is_active` | tinyint | Ativa/desativa o parceiro na plataforma |
| `reverse_geocoding_token` | varchar | Token para geocodificação reversa (por parceiro) |
| `geo_region` | varchar(10) | Região geográfica de exibição no mapa (ex.: `ROW` = Rest of World) |

**Relações:** é referenciado por praticamente todas as outras tabelas via `partner_id`.

---

### `user`

**Usuários da plataforma.** Implementa a interface `UserInterface` do Symfony Security. Cada usuário é obrigatoriamente vinculado a um parceiro (com exceção de admins globais), e seu acesso é escopado pelos dados daquele parceiro.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | int PK | Identificador único |
| `email` | varchar(180) UNIQUE | Login e identificação do usuário |
| `roles` | longtext (JSON) | Array de roles: `ROLE_ADMIN`, `ROLE_PARTNER_ADMIN`, `ROLE_OPERATOR`, `ROLE_VIEWER` |
| `password` | varchar(255) | Hash bcrypt da senha |
| `name` | varchar(255) | Nome do usuário |
| `phone` | varchar(50) | Telefone de contato |
| `created_at` / `updated_at` | datetime | Timestamps de criação e atualização |
| `last_login_at` | datetime | Controle de último acesso |
| `partner_id` | int FK nullable | Vínculo com o parceiro (null = admin global) |
| `is_active` | tinyint | Ativa/desativa o acesso do usuário |

**Relações:** `partner_id → partner.id`; `reset_password_request.user_id → user.id`; `partner_feed_event.created_by_user_id` e `updated_by_user_id → user.id`.

---

### `reset_password_request`

**Fluxo de recuperação de senha.** Gerenciada pelo bundle `SymfonyCasts/reset-password-bundle`. Armazena tokens temporários de redefinição de senha com prazo de expiração.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | int PK | Identificador único |
| `selector` | varchar(20) | Parte pública do token (usada na URL) |
| `hashed_token` | varchar(100) | Hash do token completo (segurança contra timing attacks) |
| `requested_at` | datetime | Momento da solicitação |
| `expires_at` | datetime | Expiração do token |
| `user_id` | int FK | Usuário que solicitou o reset |

**Relações:** `user_id → user.id`.

---

### `partner_api_link`

**URLs de integração de APIs externas por parceiro.** Cada parceiro pode ter múltiplos endpoints configurados para consumo de dados (ex.: feed Waze, API de radares, API de câmeras). O campo `type` categoriza o tipo de integração.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | int PK | Identificador único |
| `type` | varchar(20) | Tipo da integração (ex.: `waze`, `camera`, `radar`) |
| `name` | varchar(255) | Nome descritivo do link |
| `url` | varchar(2048) | Endpoint completo da API |
| `active` / `is_active` | tinyint | Dois flags de ativação (legacy + atual) |
| `created_at` / `updated_at` | datetime | Timestamps |
| `partner_id` | int FK | Parceiro dono desta configuração |

**Relações:** `partner_id → partner.id`.

---

### `partner_camera_link`

**Câmeras externas vinculadas a parceiros.** Diferente da tabela `camera` (câmeras internas da plataforma), esta tabela armazena câmeras provenientes de integrações externas de parceiros — como câmeras de prefeituras ou concessionárias. Inclui metadados geográficos completos e suporte a metadados extras em JSON.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | int PK | Identificador único |
| `name` | varchar(255) | Nome/identificação da câmera |
| `url` | longtext | URL do stream ou snapshot |
| `url_type` | varchar(50) | Tipo da URL (ex.: `snapshot`, `stream`) |
| `latitude` / `longitude` | decimal(10,7) | Coordenadas geográficas |
| `city` / `state` / `country_code` | varchar | Localização |
| `provider` | varchar(100) | Fornecedor/origem da câmera |
| `is_active` | tinyint | Status de ativação |
| `metadata` | longtext (JSON) | Dados extras em formato livre |
| `notes` | longtext | Observações internas |
| `created_at` / `updated_at` | datetime | Timestamps |
| `partner_id` | int FK | Parceiro dono desta câmera |

**Relações:** `partner_id → partner.id`. Índice espacial composto `(partner_id, latitude, longitude)` para queries geográficas.

---

### `camera`

**Câmeras internas da plataforma.** Câmeras cadastradas diretamente no sistema (não via integração externa). Usadas no módulo de monitoramento ao vivo. O campo `url_type` distingue entre snapshots estáticos e streams de vídeo ao vivo.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | int PK | Identificador único |
| `name` | varchar(255) | Nome da câmera |
| `url` | longtext | URL do snapshot ou stream |
| `url_type` | varchar(20) | `snapshot` (padrão) ou tipo de stream |
| `latitude` / `longitude` | decimal(10,7) | Posição geográfica (nullable) |
| `city` / `state` | varchar | Localização |
| `notes` | longtext | Observações internas |
| `active` | tinyint | Status de ativação |
| `sort_order` | smallint | Ordem de exibição na listagem |
| `created_at` / `updated_at` | datetime | Timestamps |
| `partner_id` | int FK | Parceiro dono desta câmera |

**Relações:** `partner_id → partner.id`. Índices compostos `(partner_id, active)` e `(partner_id, sort_order)` para queries de listagem ordenada.

---

### `partner_feed_event`

**Eventos CIFS (Connected Citizens Feedback System) do parceiro.** Implementa o padrão Waze CIFS para envio de ocorrências ao Waze (obras, interdições, eventos, etc.). Cada evento tem um `uuid` único exigido pelo protocolo Waze, suporte a polyline para eventos lineares (ex.: obras em trecho de via), janela de vigência com `start_time`/`end_time` e rastreabilidade de quem criou/editou.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | int PK | Identificador interno |
| `uuid` | varchar(36) UNIQUE | UUID Waze CIFS do evento |
| `cifs_type` | varchar(50) | Tipo principal (ex.: `ROAD_CLOSED`, `HAZARD`) |
| `cifs_subtype` | varchar(100) | Subtipo detalhado |
| `street` | varchar(255) | Nome da via afetada |
| `reference` | varchar(255) | Referência/ponto de localização |
| `description` | longtext | Descrição pública do evento |
| `city` | varchar(100) | Cidade do evento |
| `polyline` | longtext | Geometria do evento em formato polyline |
| `direction` | varchar(50) | Sentido de impacto (padrão: `BOTH_DIRECTIONS`) |
| `start_time` / `end_time` | datetime | Vigência do evento |
| `creation_time` / `update_time` | datetime | Timestamps de sincronização Waze |
| `is_active` | tinyint | Evento ativo/encerrado |
| `deactivated_reason` | varchar(255) | Motivo do encerramento |
| `partner_id` | int FK | Parceiro responsável |
| `created_by_user_id` / `updated_by_user_id` | int FK nullable | Auditoria de usuário |

**Relações:** `partner_id → partner.id`; `created_by_user_id → user.id`; `updated_by_user_id → user.id`.

---

### `waze_alerts`

**Alertas Waze coletados em tempo real.** Armazena ocorrências reportadas por usuários Waze na área de cobertura do parceiro (acidentes, perigos, bloqueios, polícia, etc.). O sistema implementa lógica de upsert baseada no `uuid` do alerta: alertas já conhecidos têm `last_seen_at` atualizado; alertas que somem do feed têm `is_active` zerado e `deactivated_at` registrado.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | int PK | ID interno |
| `uuid` | varchar(100) | UUID único do alerta no Waze |
| `type` | varchar(50) | Tipo (ex.: `ACCIDENT`, `HAZARD`, `JAM`, `POLICE`) |
| `subtype` | varchar(100) | Subtipo detalhado |
| `pub_millis` | bigint | Timestamp de publicação em milissegundos (UTC) |
| `report_by_municipality_user` | tinyint | Alerta de usuário municipal oficial |
| `report_rating` | smallint | Avaliação do relato |
| `confidence` | smallint | Confiança Waze (0–10) |
| `reliability` | smallint | Confiabilidade do relato |
| `longitude` / `latitude` | decimal(10,7) | Posição geográfica |
| `street` | varchar(255) | Via onde ocorreu |
| `city` | varchar(100) | Cidade |
| `country` | varchar(2) | Código do país |
| `road_type` | smallint | Tipo de via (escala Waze) |
| `report_description` | longtext | Texto livre do relato |
| `n_thumbs_up` | smallint | Número de confirmações |
| `magvar` | smallint | Ângulo magnético (direção) |
| `collected_at` | datetime | Momento da coleta pelo sistema |
| `partner_id` | int FK | Parceiro dono da coleta |
| `is_active` | tinyint | Alerta ativo (1) ou encerrado (0) |
| `last_seen_at` | datetime | Última vez visto no feed |
| `deactivated_at` | datetime | Momento em que deixou de aparecer no feed |

**Relações:** `partner_id → partner.id`. Índice único `(partner_id, uuid)` garante unicidade por parceiro. Índices em `last_seen_at`, `pub_millis`, `collected_at`, `(city, type)` e `(longitude, latitude)` para queries analíticas e de mapa.

---

### `waze_jams`

**Congestionamentos Waze coletados em tempo real.** Análogo a `waze_alerts`, mas para dados de tráfego (jams). Cada jam tem geometria em polyline (`line`), métricas de velocidade/comprimento/atraso e nível de congestionamento (0–5). O sistema mantém o histórico de jams ativos e os desativa quando somem do feed, com a mesma lógica de `last_seen_at` / `deactivated_at`.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | int PK | ID interno |
| `uuid` | varchar(100) | UUID do jam no Waze |
| `jam_id` | bigint | ID numérico do jam (API Waze) |
| `line` | longtext | Geometria do congestionamento em polyline |
| `line_points` | smallint | Número de pontos na polyline |
| `speed` | decimal(10,3) | Velocidade em m/s |
| `speed_kmh` | decimal(10,3) | Velocidade em km/h |
| `length` | int | Comprimento do trecho congestionado (metros) |
| `delay` | int | Atraso em segundos em relação ao tempo livre |
| `level` | smallint | Nível do jam (0 = livre, 5 = parado) |
| `pub_millis` | bigint | Timestamp de publicação em ms (UTC) |
| `turn_type` | varchar(20) | Tipo de curva/trecho |
| `blocking_alert_uuid` | varchar(100) | UUID do alerta que está causando o jam |
| `segments` | longtext | Segmentos de via afetados (JSON) |
| `segment_count` | smallint | Número de segmentos |
| `street` | varchar(255) | Via principal afetada |
| `city` | varchar(100) | Cidade |
| `country` | varchar(2) | Código do país |
| `road_type` | smallint | Tipo de via |
| `end_node` | varchar(255) | Nó final do congestionamento |
| `collected_at` | datetime | Momento da coleta |
| `partner_id` | int FK | Parceiro dono da coleta |
| `is_active` | tinyint | Jam ativo ou encerrado |
| `last_seen_at` | datetime | Última vez visto no feed |
| `deactivated_at` | datetime | Momento de encerramento |

**Relações:** `partner_id → partner.id`. Índice único `(partner_id, uuid)`. Índice em `blocking_alert_uuid` para correlacionar jams com alertas causadores.

---

### `waze_tvt_route`

**Rotas TVT (Travel Time / Traffic) monitoradas.** Uma rota TVT é um corredor viário configurado pelo parceiro para monitoramento contínuo de tempo de viagem e tráfego. Cada rota é identificada pelo `route_id` da API Waze e pode conter múltiplos sub-trechos (`waze_tvt_sub_route`).

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | int PK | ID interno |
| `partner_id` | int FK | Parceiro dono da rota |
| `route_id` | varchar(100) | ID da rota na API Waze |
| `name` | varchar(255) | Nome amigável da rota |
| `from_name` / `to_name` | varchar(255) | Pontos de origem e destino |
| `length` | int | Comprimento total da rota (metros) |
| `geometry` | longtext | Geometria completa (polyline/GeoJSON) |
| `is_active` | tinyint | Rota ativa no monitoramento |
| `last_seen_at` | datetime | Última presença na API |
| `deactivated_at` | datetime | Data de desativação |

**Relações:** `partner_id → partner.id`. Índice único `(partner_id, route_id)`.  
**Filhos:** `waze_tvt_sub_route`, `waze_tvt_route_snapshot`, `waze_tvt_irregularity`, `waze_tvt_user_on_jam`.

---

### `waze_tvt_sub_route`

**Sub-trechos de uma rota TVT.** Uma rota pode ser dividida em segmentos menores para análise granular. Cada sub-rota tem suas próprias métricas de tempo atual vs. histórico, geometria e irregularidades.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | int PK | ID interno |
| `name` | varchar(255) | Nome do sub-trecho |
| `partner_id` | int FK | Parceiro |
| `waze_route_id` | varchar(100) | ID Waze da rota pai |
| `sub_route_id` | varchar(100) | ID Waze do sub-trecho |
| `from_name` / `to_name` | varchar(255) | Pontos do sub-trecho |
| `length` | int | Comprimento em metros |
| `time` | int | Tempo atual de viagem (segundos) |
| `historic_time` | int | Tempo histórico esperado (segundos) |
| `jam_level` | int | Nível de congestionamento atual |
| `line` | longtext | Geometria do sub-trecho |
| `bbox` | longtext | Bounding box geográfico |
| `irregularities` | longtext | Irregularidades neste trecho (JSON) |
| `is_active` / `last_seen_at` / `deactivated_at` | — | Ciclo de vida |
| `route_id` | int FK | FK para `waze_tvt_route.id` |

**Relações:** `partner_id → partner.id`; `route_id → waze_tvt_route.id`. Índice único `(partner_id, route_id, sub_route_id)`.

---

### `waze_tvt_route_snapshot`

**Snapshots históricos de tempo de viagem por rota.** A cada coleta TVT, um snapshot é salvo para cada rota, registrando tempo atual, tempo histórico e nível de jam naquele momento. É a tabela de séries temporais para análise de desempenho histórico dos corredores — pode crescer rapidamente (450k+ registros).

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | int PK | ID interno |
| `name` / `city` / `state` | varchar | Dados da rota no momento do snapshot |
| `partner_id` | int FK | Parceiro |
| `waze_route_id` | varchar(100) | ID Waze da rota |
| `time` | int | Tempo atual de viagem (segundos) |
| `historic_time` | int | Tempo histórico esperado (segundos) |
| `jam_level` | int | Nível de congestionamento (0–5) |
| `payload` | longtext | Payload completo da API (JSON bruto) |
| `recorded_at` | datetime | Timestamp do snapshot |
| `route_id` | int FK | FK para `waze_tvt_route.id` |

**Relações:** `partner_id → partner.id`; `route_id → waze_tvt_route.id`. Índices em `(partner_id, recorded_at)` e `(route_id, recorded_at)` para queries de série temporal.

---

### `waze_tvt_irregularity`

**Irregularidades de tráfego detectadas nas rotas TVT.** Registra incidentes detectados pelo Waze dentro dos corredores monitorados (alagamentos, obras, acidentes sinalizados pela plataforma TVT). Usa `content_hash` para deduplicação — a mesma irregularidade no mesmo local não é duplicada, apenas atualizada.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | int PK | ID interno |
| `type` / `subtype` | varchar | Tipo e subtipo da irregularidade |
| `severity` | varchar(255) | Severidade do impacto |
| `reported_time` | datetime | Momento do reporte original |
| `street` / `city` / `state` | varchar | Localização |
| `latitude` / `longitude` | double | Coordenadas |
| `partner_id` | int FK | Parceiro |
| `waze_route_id` | varchar(100) | ID Waze da rota associada |
| `waze_sub_route_id` | varchar(100) | ID Waze do sub-trecho associado |
| `content_hash` | varchar(64) | Hash de deduplicação do conteúdo |
| `description` | varchar(255) | Descrição da irregularidade |
| `payload` | longtext | Payload completo (JSON) |
| `is_active` / `recorded_at` / `last_seen_at` / `deactivated_at` / `updated_at` | — | Ciclo de vida completo |
| `route_id` | int FK | FK para `waze_tvt_route.id` |
| `sub_route_id` | int FK nullable | FK para `waze_tvt_sub_route.id` |

**Relações:** `partner_id → partner.id`; `route_id → waze_tvt_route.id`; `sub_route_id → waze_tvt_sub_route.id`. Índice único `(partner_id, route_id, sub_route_id, content_hash)`.

---

### `waze_tvt_user_on_jam`

**Usuários Waze detectados em congestionamentos nas rotas.** Registra snapshots de usuários parados em jams dentro dos corredores monitorados — útil para análise de impacto real sobre motoristas e para métricas de produtividade de tráfego.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | int PK | ID interno |
| `username` | varchar(255) | Nome do usuário Waze (quando disponível) |
| `jam_type` | varchar(255) | Tipo do jam onde o usuário está |
| `street` / `city` / `state` | varchar | Localização do usuário |
| `partner_id` | int FK | Parceiro |
| `waze_route_id` | varchar(100) | ID Waze da rota |
| `wazers_count` | int | Total de Wazers no jam |
| `jam_level` | int | Nível do congestionamento |
| `payload` | longtext | Dados completos (JSON) |
| `recorded_at` | datetime | Timestamp do registro |
| `route_id` | int FK nullable | FK para `waze_tvt_route.id` |

**Relações:** `partner_id → partner.id`; `route_id → waze_tvt_route.id`.

---

### `cemaden_station_link`

**Estações pluviométricas do CEMADEN vinculadas a parceiros.** O CEMADEN (Centro Nacional de Monitoramento e Alertas de Desastres Naturais) disponibiliza dados de estações de chuva. Esta tabela armazena as estações configuradas para cada parceiro, com metadados completos (localização, código IBGE, rede hidrológica) e controle de frequência de coleta.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | int PK | ID interno |
| `cemaden_station_id` | int | ID da estação no CEMADEN |
| `station_code` | varchar(100) | Código da estação |
| `station_name` | varchar(255) | Nome da estação |
| `latitude` / `longitude` | decimal(10,7) | Posição geográfica |
| `status` | varchar(30) | Status operacional da estação |
| `station_type` | varchar(100) | Tipo de estação |
| `municipality_id` | int | ID do município (IBGE) |
| `city` / `state` | varchar | Localização |
| `ibge_code` | varchar(20) | Código IBGE do município |
| `network_id` / `network_name` / `network_acronym` | — | Rede hidrológica |
| `base_url` | longtext | URL da API para coleta de dados |
| `hours_to_fetch` | smallint | Janela de horas a buscar (padrão: 24h) |
| `active` | tinyint | Estação ativa no monitoramento |
| `last_fetched_at` | datetime | Última coleta bem-sucedida |
| `created_at` / `updated_at` | datetime | Timestamps |
| `partner_id` | int FK | Parceiro dono da configuração |

**Relações:** `partner_id → partner.id`. Índice único `(partner_id, cemaden_station_id)`.  
**Filha:** `cemaden_pluviometric_observation`.

---

### `cemaden_pluviometric_observation`

**Observações pluviométricas acumuladas por hora.** Cada registro representa o acumulado de chuva em milímetros de uma estação para um slot horário específico (hora cheia). Os campos `reference_date` + `hour_slot` + `cemaden_station_link_id` formam a chave de unicidade para evitar duplicatas na coleta incremental.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | int PK | ID interno |
| `reference_date` | date | Data de referência da observação |
| `hour_slot` | smallint | Hora (0–23) do acumulado |
| `accumulated_rainfall` | decimal(8,3) | Chuva acumulada no período (mm) |
| `observed_at` | datetime | Timestamp exato da observação |
| `source_payload` | longtext | Payload bruto da API CEMADEN |
| `created_at` | datetime | Timestamp de inserção |
| `partner_id` | int FK | Parceiro |
| `cemaden_station_link_id` | int FK | Estação de origem |

**Relações:** `partner_id → partner.id`; `cemaden_station_link_id → cemaden_station_link.id`. Índice único `(cemaden_station_link_id, observed_at)`.

---

### `cemaden_hidro_station_link`

**Estações hidrológicas (réguas de nível d'água) vinculadas a parceiros.** Similar à `cemaden_station_link`, mas para estações de monitoramento de nível de rios e córregos. Inclui URLs separadas para dados de chuva (`base_url`) e nível d'água (`level_base_url`), e um `cemaden_transaction_id` para agrupamento por transação de configuração.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | int PK | ID interno |
| `cemaden_transaction_id` | int | ID da transação/grupo de configuração |
| `station_code` / `station_name` | varchar | Código e nome da estação |
| `city` / `state` | varchar | Localização |
| `base_url` | longtext | URL para dados de chuva |
| `level_base_url` | longtext | URL para dados de nível d'água |
| `records_to_fetch` | smallint | Quantidade de registros a buscar (padrão: 24) |
| `active` | tinyint | Estação ativa |
| `last_fetched_at` | datetime | Última coleta |
| `created_at` / `updated_at` | datetime | Timestamps |
| `partner_id` | int FK | Parceiro |

**Relações:** `partner_id → partner.id`. Índice único `(partner_id, cemaden_transaction_id)`.  
**Filha:** `cemaden_hidro_observation`.

---

### `cemaden_hidro_observation`

**Observações hidrológicas de nível de rios.** Cada registro representa uma leitura de nível d'água, vazão e chuva de uma estação hidrológica. O campo `observation_type` distingue o tipo de dado (nível, chuva, etc.). Os campos de cota (`cota_atencao`, `cota_alerta`, `cota_transbordamento`) armazenam os limiares de alerta da estação junto com a observação, permitindo comparação direta.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | int PK | ID interno |
| `station_code` / `station_name` | varchar | Dados da estação no momento |
| `city` / `state` | varchar | Localização |
| `offset_value` | double | Valor de offset da régua |
| `observed_at` | datetime | Timestamp da observação |
| `source_payload` | longtext | Payload bruto da API |
| `water_level` | double | Nível d'água (metros) |
| `flow` | double | Vazão (m³/s) |
| `rain` | double | Precipitação no período (mm) |
| `raw_value` | double | Valor bruto da régua sem offset |
| `cota_atencao` | double | Cota de atenção do órgão gestor |
| `cota_alerta` | double | Cota de alerta |
| `cota_transbordamento` | double | Cota de transbordamento/emergência |
| `observation_type` | varchar(10) | Tipo de observação |
| `created_at` | datetime | Timestamp de inserção |
| `partner_id` | int FK | Parceiro |
| `cemaden_hidro_station_link_id` | int FK | Estação de origem |

**Relações:** `partner_id → partner.id`; `cemaden_hidro_station_link_id → cemaden_hidro_station_link.id`. Índice único `(cemaden_hidro_station_link_id, observed_at, observation_type)`.

---

### `weather_location`

**Locais de monitoramento meteorológico.** Cada registro configura um ponto geográfico para coleta de dados climáticos. O campo `provider` indica o serviço meteorológico usado (ex.: Open-Meteo), e `api_token` armazena a chave de acesso por local, permitindo diferentes provedores por parceiro.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | int PK | ID interno |
| `name` | varchar(150) | Nome do local de monitoramento |
| `city` / `state` / `country_code` | varchar | Localização |
| `latitude` / `longitude` | decimal(10,7) | Coordenadas do ponto de coleta |
| `timezone` | varchar(64) | Fuso horário (ex.: `America/Sao_Paulo`) |
| `provider` | varchar(50) | Provedor meteorológico |
| `api_token` | longtext | Token de API do provedor |
| `active` | tinyint | Local ativo no monitoramento |
| `created_at` / `updated_at` | datetime | Timestamps |
| `partner_id` | int FK | Parceiro dono da configuração |

**Relações:** `partner_id → partner.id`. Índice único `(partner_id, latitude, longitude)`.  
**Filha:** `weather_observation`.

---

### `weather_observation`

**Observações meteorológicas horárias.** Série temporal de dados climáticos por local. Cada registro representa uma coleta com todas as variáveis meteorológicas relevantes para o contexto de tráfego e mobilidade — especialmente precipitação, visibilidade e vento, que impactam diretamente as condições de trânsito.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | int PK | ID interno |
| `temperature` | decimal(5,2) | Temperatura (°C) |
| `apparent_temperature` | decimal(5,2) | Sensação térmica (°C) |
| `relative_humidity` | smallint | Umidade relativa (%) |
| `precipitation` | decimal(6,2) | Precipitação total (mm) |
| `rain` | decimal(6,2) | Chuva (mm) |
| `showers` | decimal(6,2) | Pancadas de chuva (mm) |
| `snowfall` | decimal(6,2) | Neve (mm) |
| `weather_code` | smallint | Código WMO de condição do tempo |
| `cloud_cover` | smallint | Cobertura de nuvens (%) |
| `surface_pressure` | decimal(7,2) | Pressão atmosférica (hPa) |
| `wind_speed` | decimal(6,2) | Velocidade do vento (km/h) |
| `wind_direction` | smallint | Direção do vento (graus) |
| `wind_gusts` | decimal(6,2) | Rajadas de vento (km/h) |
| `visibility` | int | Visibilidade (metros) |
| `is_day` | tinyint | Flag diurno/noturno |
| `payload` | longtext | Payload completo da API (JSON) |
| `observed_at` | datetime | Timestamp da observação |
| `created_at` | datetime | Timestamp de inserção |
| `partner_id` | int FK | Parceiro |
| `weather_location_id` | int FK | Local de monitoramento de origem |

**Relações:** `partner_id → partner.id`; `weather_location_id → weather_location.id`. Índice único `(weather_location_id, observed_at)`.

---

### `doctrine_migration_versions`

**Controle de migrações do Doctrine.** Tabela gerenciada automaticamente pelo Doctrine Migrations. Registra cada versão de migração executada, garantindo que o schema do banco esteja sempre sincronizado com as entidades da aplicação.

| Coluna | Tipo | Descrição |
|---|---|---|
| `version` | varchar(191) PK | Nome da classe de migração (ex.: `DoctrineMigrations\Version20240101000000`) |
| `executed_at` | datetime | Data/hora de execução |
| `execution_time` | int | Tempo de execução em milissegundos |

---

## Resumo das Relações

| Tabela | FK para | Via |
|---|---|---|
| `user` | `partner` | `partner_id` |
| `reset_password_request` | `user` | `user_id` |
| `partner_api_link` | `partner` | `partner_id` |
| `partner_camera_link` | `partner` | `partner_id` |
| `partner_feed_event` | `partner`, `user` (×2) | `partner_id`, `created_by_user_id`, `updated_by_user_id` |
| `camera` | `partner` | `partner_id` |
| `waze_alerts` | `partner` | `partner_id` |
| `waze_jams` | `partner` | `partner_id` |
| `waze_tvt_route` | `partner` | `partner_id` |
| `waze_tvt_sub_route` | `partner`, `waze_tvt_route` | `partner_id`, `route_id` |
| `waze_tvt_route_snapshot` | `partner`, `waze_tvt_route` | `partner_id`, `route_id` |
| `waze_tvt_irregularity` | `partner`, `waze_tvt_route`, `waze_tvt_sub_route` | `partner_id`, `route_id`, `sub_route_id` |
| `waze_tvt_user_on_jam` | `partner`, `waze_tvt_route` | `partner_id`, `route_id` |
| `cemaden_station_link` | `partner` | `partner_id` |
| `cemaden_pluviometric_observation` | `partner`, `cemaden_station_link` | `partner_id`, `cemaden_station_link_id` |
| `cemaden_hidro_station_link` | `partner` | `partner_id` |
| `cemaden_hidro_observation` | `partner`, `cemaden_hidro_station_link` | `partner_id`, `cemaden_hidro_station_link_id` |
| `weather_location` | `partner` | `partner_id` |
| `weather_observation` | `partner`, `weather_location` | `partner_id`, `weather_location_id` |

---

## Notas de Arquitetura

- **Multi-tenant via `partner_id`:** todo dado da aplicação é escopado por parceiro. Praticamente todas as queries de negócio devem filtrar por `partner_id` — os índices compostos existentes refletem este padrão.
- **Padrão ativo/inativo com `last_seen_at`:** tabelas de dados em tempo real (`waze_alerts`, `waze_jams`, `waze_tvt_route`, `waze_tvt_sub_route`, `waze_tvt_irregularity`) implementam soft-delete baseado em presença no feed, não em deleção real. Isso preserva o histórico para análise.
- **Payloads brutos:** tabelas de observação armazenam o JSON completo da API no campo `payload`/`source_payload`, permitindo reprocessamento sem nova coleta.
- **Timestamps UTC + conversão:** `pub_millis` é armazenado em UTC (milissegundos epoch). Queries analíticas devem usar `CONVERT_TZ()` para exibição em `America/Sao_Paulo`.
- **Série temporal pesada:** `waze_tvt_route_snapshot` é a tabela de maior crescimento (450k+ registros). Considerar particionamento por `recorded_at` se a retenção histórica crescer além de 6 meses.
- **Deduplicação:** `waze_tvt_irregularity` usa `content_hash` (SHA-256 do conteúdo) como chave de unicidade, evitando inserções duplicadas sem depender de IDs externos.
