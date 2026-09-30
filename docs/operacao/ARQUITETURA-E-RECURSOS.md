# Arquitetura e recursos

## Visão geral

Aplicação Symfony organizada principalmente em `src/Controller`,
`src/Entity`, `src/Repository`, `src/Service`, `src/Command` e
`src/Scheduler`. Há também `src/Security`, `src/Message`,
`src/MessageHandler` e `src/Messenger`.

A presença de um arquivo ou classe comprova implementação no repositório,
mas não comprova que o recurso está habilitado ou funcionando em produção.

## Mapa funcional

| Recurso | Código identificado | Dados principais | Verificação inicial |
| --- | --- | --- | --- |
| Autenticação e usuários | `AuthController`, `ResetPasswordController`, controladores administrativos | `user`, `reset_password_request`, `partner` | Login, redefinição de senha e escopo do usuário. |
| Parceiros | `AdminPartnerController`, `Partner`, `TenantContext` | `partner`, `partner_api_link` | Acesso restrito aos dados do parceiro. |
| Alertas Waze | `AlertController`, `WazeFeedSynchronizer` | `waze_alerts` | Recência, atualização e ativação dos alertas. |
| Congestionamentos Waze | `JamController`, `JamHistoryController`, `WazeFeedSynchronizer` | `waze_jams` | Recência, histórico e ativação dos congestionamentos. |
| Rotas TVT | `RoutesController`, `WazeTvtSynchronizer` | `waze_tvt_route`, `waze_tvt_sub_route`, snapshots e irregularidades | Recência e vínculo com rotas. |
| Eventos de parceiros | `PartnerFeedController`, `CifsFeedController`, serviços `PartnerFeed*` | `partner_feed_event`, `partner` | Publicação, validade e desativação dos eventos. |
| Câmeras e TV | `TvController`, `TvStreamController`, serviços `Tv` | `camera`, `partner_camera_link` | URL ativa, permissão e reprodução. |
| Semáforos | `TrafficLightController`, serviços `TrafficLight` | `traffic_light`, `traffic_light_snapshot` | Leituras recentes, sucesso e erros. |
| Clima | `WeatherController`, `WeatherObservationFetcher` | `weather_location`, `weather_observation` | Observações recentes por local ativo. |
| CEMADEN pluviométrico | Comando `FetchCemadenPluviometricCommand` | `cemaden_station_link`, `cemaden_pluviometric_observation` | Observações recentes por estação ativa. |
| CEMADEN hidrológico | Comando `FetchCemadenHidroCommand` | `cemaden_hidro_station_link`, `cemaden_hidro_observation` | Observações recentes por estação ativa. |
| Dashboard | `DashboardController`, `HomeController` | Conjunto de fontes acima | Comparar indicadores exibidos com dados persistidos. |
| Briefing | `WazeDailyBriefingCommand`, serviços `Briefing*` e `AiNarrativeService` | Confirmar armazenamento e saídas no código | Executar em ambiente controlado e revisar logs. |

## Fluxo conceitual

Fonte externa → comando/serviço de coleta → validação e sincronização →
tabelas do parceiro → repositório/controlador → dashboard ou feed.

O agendamento e a execução efetiva desse fluxo precisam ser confrontados com
a configuração do servidor, além de `cron.php` e `src/Scheduler/MainSchedule.php`.

## Pontos de manutenção

- Novas integrações devem declarar proprietário do dado (`partner_id`),
  identificador externo, regra de atualização e critério de expiração.
- Recursos que exibem dados históricos devem separar registro atual de
  snapshots/observações.
- APIs e telas precisam aplicar o mesmo isolamento por parceiro.
