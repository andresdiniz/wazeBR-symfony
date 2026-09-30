# Dicionário de dados

Fonte: estrutura de `u629736858_trafik` fornecida pelo proprietário.
Este é um dicionário funcional, não uma reprodução integral do DDL.

## Núcleo e acesso

| Tabela | Finalidade | Campos e regras relevantes |
| --- | --- | --- |
| `partner` | Parceiro/tenant e configuração de integrações | `id`, `name`, `code` único quando preenchido, credenciais de API, frequências, últimas coletas, `is_active`, `geo_region`. |
| `user` | Conta de acesso | `email` único, `roles`, `password`, `partner_id` opcional, `is_active`, `last_login_at`. |
| `reset_password_request` | Solicitações de redefinição de senha | `user_id`, `selector`, `hashed_token`, datas de solicitação e expiração. |
| `partner_api_link` | Endpoints vinculados ao parceiro | `partner_id`, `type`, `name`, `url`, `active` e `is_active`. Validar a semântica dos dois indicadores. |
| `doctrine_migration_versions` | Controle das migrations aplicadas | `version`, `executed_at`, `execution_time`. |

## Mobilidade e feeds

| Tabela | Finalidade | Identidade ou dado temporal |
| --- | --- | --- |
| `waze_alerts` | Alertas Waze por parceiro | Único `(partner_id, uuid)`; `pub_millis`, `collected_at`, `last_seen_at`, `is_active`, `deactivated_at`. |
| `waze_jams` | Congestionamentos Waze por parceiro | Único `(partner_id, uuid)`; `jam_id`, `speed_kmh`, `delay`, `level` e campos de ciclo de vida. |
| `waze_tvt_route` | Cadastro de rotas TVT | Único `(partner_id, route_id)`; atenção: `route_id` aqui é identificador externo textual. |
| `waze_tvt_sub_route` | Sub-rotas TVT | Identidade `(partner_id, route_id, sub_route_id)`; neste caso `route_id` é vínculo inteiro com a rota local. |
| `waze_tvt_route_snapshot` | Histórico das medições de rotas | `route_id` inteiro, `waze_route_id` textual, `recorded_at`, `time`, `historic_time`, `jam_level`. |
| `waze_tvt_irregularity` | Irregularidades de rotas/sub-rotas | `route_id`, `sub_route_id` opcional, `content_hash`, datas e estado ativo. |
| `waze_tvt_user_on_jam` | Registros de usuários em congestionamento | `route_id` opcional, `wazers_count`, `jam_level`, `recorded_at`. |
| `partner_feed_event` | Eventos publicados pelo parceiro | `uuid` único, `partner_id`, tipo/subtipo CIFS, localização, vigência e usuários de criação/atualização. |

## Infraestrutura urbana e mídia

| Tabela | Finalidade | Identidade ou dado temporal |
| --- | --- | --- |
| `traffic_light` | Cadastro de semáforos e protocolo de consulta | Único `(partner_id, code)`; `endpoint`, `options`, `last_read_at`, status e erro. |
| `traffic_light_snapshot` | Histórico de leituras dos semáforos | `traffic_light_id`, `read_at`, `success`, `state`, `error_message`, `duration_ms`. |
| `camera` | Câmeras do parceiro | `partner_id`, `url`, `url_type`, `active`, `sort_order`, posição. |
| `partner_camera_link` | Outro cadastro de links de câmera | `partner_id`, `url`, `url_type`, `is_active`, posição, provedor e metadados. |

## Ambiente e meteorologia

| Tabela | Finalidade | Identidade ou dado temporal |
| --- | --- | --- |
| `weather_location` | Local monitorado para meteorologia | Único `(partner_id, latitude, longitude)`; `provider`, `timezone`, `active`. |
| `weather_observation` | Série temporal meteorológica | Único `(weather_location_id, observed_at)`; temperaturas, chuva, vento, pressão e `payload`. |
| `cemaden_station_link` | Estação pluviométrica selecionada | Único `(partner_id, cemaden_station_id)`; `hours_to_fetch`, `active`, `last_fetched_at`. |
| `cemaden_pluviometric_observation` | Série temporal de chuva | Único `(cemaden_station_link_id, observed_at)`; `reference_date`, `hour_slot`, `accumulated_rainfall`. |
| `cemaden_hidro_station_link` | Estação hidrológica selecionada | Único `(partner_id, cemaden_transaction_id)`; `records_to_fetch`, `active`, `last_fetched_at`. |
| `cemaden_hidro_observation` | Série temporal hidrológica | Identidade `(cemaden_hidro_station_link_id, observed_at, observation_type)`; nível, vazão, chuva, cotas e valor bruto. |

## Convenções a observar

- `partner_id` representa o escopo do parceiro em grande parte do modelo.
- `created_at`, `collected_at`, `observed_at`, `recorded_at`, `read_at` e
  `last_seen_at` têm significados distintos; não devem ser intercambiados.
- `is_active`/`active` indicam estado de configuração ou ciclo de vida,
  dependendo da tabela.
- O banco contém payloads e URLs potencialmente sensíveis. Nunca publicar
  exports completos em issues, logs ou documentação.
