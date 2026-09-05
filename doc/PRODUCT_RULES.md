# WazeBR Monitor — Regras de Produto e Usuário

> **Este arquivo é a fonte da verdade do projeto.** Toda decisão nova de
> produto, papel de usuário ou regra de acesso deve ser refletida aqui.
> Se o código e este documento divergirem, isso é um bug a corrigir —
> não uma licença para o código "vencer por padrão".
>
> Roadmap de funcionalidades futuras (webhooks, incidentes em lote,
> analytics avançado, Audit Log expandido, `ROLE_LEITOR`, stack de
> libs) está em `doc/ROADMAP_FUNCIONALIDADES.md`. Este arquivo aqui
> descreve o **estado atual**; aquele descreve o que ainda **não foi
> implementado**. Ao implementar algo de lá, mova a decisão pra cá.
>
> Gerado a partir de análise completa do repositório em
> `andresdiniz/wazeBR-symfony` (entidades, controllers, security.yaml,
> templates, commands). Onde o código está inconsistente ou incompleto,
> isso está marcado explicitamente na seção **Dívida técnica / gaps
> conhecidos** — não foi "arredondado" silenciosamente.

---

## 1. O que é o produto

Plataforma de monitoramento de mobilidade urbana para prefeituras e
operações de trânsito/defesa civil, multi-tenant (multi-parceiro).
Cada **Partner** (parceiro — tipicamente uma prefeitura/cidade) tem
seus próprios dados isolados de:

- Alertas de trânsito (Waze)
- Congestionamentos / lentidões (Waze Traffic Jams)
- Rotas monitoradas e tempos de viagem (TVT — Time Value of Travel)
- Ocorrências de defesa civil (CIFS)
- Dados hidrológicos/pluviométricos (CEMADEN)
- Notificações e alertas de risco

## 2. Multi-tenancy (modelo de dados)

- **`Partner`** é o tenant. Quase toda entidade operacional
  (`WazeAlert`, `WazeTrafficJam`, `WazeRoute`, `CemadenData`,
  `MonitoredCity`, `MonitoredLink`, `WazeCount`) tem `partner` como
  campo obrigatório de escopo.
- **`User.partner`** pode ser `null` — isso é o caso do super admin
  "global", sem vínculo fixo a nenhum parceiro.
- **Resolução do tenant ativo** (qual `Partner` filtra os dados da
  requisição) é feita por `App\Service\TenantContext`:
  1. Usuário comum → sempre o `Partner` fixo do próprio cadastro.
  2. Admin (`ROLE_ADMIN`/`ROLE_SUPER_ADMIN`) → **não** fica preso a um
     parceiro; usa o que estiver escolhido na sessão
     (`PartnerController::switchTo()`, rota
     `/admin/parceiros/{id}/visualizar`), com fallback pro primeiro
     parceiro ativo.
  3. Token externo (`X-Api-Token`) → `resolveFromToken()`.
  4. Command/job → `setPartner()` manual.
- **Regra explícita de produto**: não existe (ainda) uma "visão
  agregada de todos os parceiros ao mesmo tempo" para o operacional
  (alertas/jams/rotas/mapa). O admin vê **um parceiro por vez**,
  podendo trocar. Construir uma visão agregada de verdade exigiria
  reescrever as queries que hoje são todas filtradas por um único
  `Partner`. **Não implemente isso "de leve" em uma tela sem
  atualizar esta regra primeiro.**
- `DashboardController` **não usa `TenantContext`** — resolve o
  partner sozinho, inline, com `method_exists($user, 'getPartner')`.
  Isso é uma duplicação de responsabilidade (ver Dívida Técnica #1) e
  é o motivo de o dashboard não ter sido afetado pelo bug do
  `TenantContext` abaixo.

## 3. Papéis de usuário (roles)

### 3.1 Papéis que existem hoje no código

| Papel | Onde é usado | Escopo pretendido |
|---|---|---|
| `ROLE_SUPER_ADMIN` | Dashboard, Home, gestão de parceiros/usuários/links | Dono da plataforma. Sem parceiro fixo. Acesso a **qualquer** parceiro. |
| `ROLE_ADMIN` | Gestão de usuários, links, dashboard | Equivalente operacional ao super admin em várias telas (ver `AccountUserController`: "vê e gerencia usuários de QUALQUER parceiro, sem restrição — decisão explícita do projeto, perfil de suporte"). |
| `ROLE_ACCOUNT_ADMIN` | Usuários, Links, Configurações da conta, Logs | Admin **do próprio parceiro apenas**. Não vê outros parceiros. |
| `ROLE_MANAGER` | Dashboard (rótulo "Gestão do parceiro"), menu (Rotas) | Gestor de um parceiro, nível abaixo de admin. |
| `ROLE_PARTNER` | `PartnerAdminController`, `PartnerRegistrationController` | Papel de "dono do cadastro de parceiro" — pode criar/editar/excluir parceiros. **Nome colide conceitualmente com a entidade `Partner`** — cuidado ao ler código antigo. |
| `ROLE_PARTNER_ADMIN` | `PartnerRegistrationController` | Usado em só 1 arquivo. Relação com `ROLE_PARTNER`/`ROLE_ACCOUNT_ADMIN` não está clara — **investigar antes de usar em código novo**. |
| `ROLE_OPERATOR` | `PartnerAdminController`, `/operador` | Papel operacional "de leitura básica". |
| `ROLE_FIELD_AGENT` | `AccountUserController` (só isso e mais nada) | Agente de campo, tem "permissões" (`$perms`) próprias além do papel. Papel atribuível por `ROLE_ACCOUNT_ADMIN` junto com `ROLE_USER`. |
| `ROLE_USER` | Base — todo usuário autenticado tem, sempre (`User::getRoles()` sempre acrescenta) | Usuário comum de um parceiro. |

### 3.2 Hierarquia declarada em `security.yaml`

```yaml
role_hierarchy:
    ROLE_ADMIN: [ROLE_MANAGER, ROLE_USER]
    ROLE_MANAGER: [ROLE_USER]
```

**Isso está incompleto.** `ROLE_SUPER_ADMIN`, `ROLE_ACCOUNT_ADMIN`,
`ROLE_PARTNER`, `ROLE_PARTNER_ADMIN`, `ROLE_OPERATOR` e
`ROLE_FIELD_AGENT` **não herdam nada e não são herdados por nada**.
Na prática isso significa, por exemplo, que um usuário só com
`ROLE_SUPER_ADMIN` **não** ganha automaticamente `ROLE_ADMIN` nem
`ROLE_ACCOUNT_ADMIN` pela hierarquia — se algum controller checa
`ROLE_ACCOUNT_ADMIN` especificamente, um super admin "puro" (sem
esse papel também atribuído na lista de roles do usuário) seria
barrado. Isso provavelmente não é a intenção, mas é o comportamento
real hoje. **Regra a seguir a partir de agora: todo novo papel
introduzido precisa ser adicionado explicitamente a
`role_hierarchy`, com a herança decidida por escrito nesta seção
antes de codar.**

### 3.3 Papéis atribuíveis por tela (regra de negócio confirmada)

- Em `/account/users` (gestão de usuários), só é possível atribuir
  **`ROLE_USER` ou `ROLE_FIELD_AGENT`** a alguém. Não dá para
  promover ninguém a admin por essa tela.
- Editar/desativar/remover contas com `ROLE_SUPER_ADMIN` ou
  `ROLE_ACCOUNT_ADMIN` é **proibido** nessa tela, mesmo por um admin.
- Criar parceiro novo é **exclusivo de `ROLE_SUPER_ADMIN`**
  (`Admin/PartnerController`).

### 3.4 Modelo canônico proposto (a seguir daqui pra frente)

Para não seguir crescendo de forma orgânica, a hierarquia de
autoridade **pretendida** é:

```
ROLE_SUPER_ADMIN   → dono da plataforma, todos os parceiros, tudo liberado
     ↓
ROLE_ADMIN         → suporte/operação da plataforma, todos os parceiros,
                      mas não mexe em contas SUPER_ADMIN/ACCOUNT_ADMIN
     ↓
ROLE_ACCOUNT_ADMIN → admin de UM parceiro só (gestão de usuários,
                      links, configurações, logs — escopo travado)
     ↓
ROLE_MANAGER       → gestor operacional de UM parceiro (sem acesso a
                      configurações de conta)
     ↓
ROLE_FIELD_AGENT   → usuário de campo de UM parceiro, com permissões
                      granulares adicionais (`$perms`)
     ↓
ROLE_USER          → usuário comum de UM parceiro, leitura operacional
```

`ROLE_PARTNER`, `ROLE_PARTNER_ADMIN` e `ROLE_OPERATOR` ficam **fora**
dessa cadeia até serem reconciliados (ver Dívida Técnica #4) — não
introduza dependência nova neles sem antes decidir onde encaixam.

## 4. Regras de acesso por funcionalidade

| Funcionalidade | Rota base | Acesso mínimo | Observação |
|---|---|---|---|
| Dashboard | `/dashboard` | `ROLE_USER` | Conteúdo varia por papel (ver §5) |
| Alertas | `/alertas` | `ROLE_USER` | Escopado por `TenantContext` |
| Congestionamentos | (via `TrafficJamController`) | `ROLE_USER` | Escopado por `TenantContext` |
| Ocorrências CIFS | `/cifs` | `ROLE_USER` | Escopado por `TenantContext` |
| Hidrológico/CEMADEN | `/hidrologico` | `ROLE_USER` | Escopado por `TenantContext` |
| Notificações | `/notificacoes` | `ROLE_USER` | Escopado por `TenantContext` |
| Painel do operador | `/operador` | `ROLE_USER` | — |
| Rotas (admin) | `/admin/routes` (`admin_routes_*`) | `ROLE_ADMIN` ou `ROLE_MANAGER` | — |
| Usuários da conta | `/account/users` | `ROLE_ACCOUNT_ADMIN` (próprio parceiro) / `ROLE_ADMIN`+`ROLE_SUPER_ADMIN` (qualquer parceiro) | Regras de §3.3 valem aqui |
| Links da conta | `/account/links` | `ROLE_ACCOUNT_ADMIN` | — |
| Configurações da conta / Logs | `/account/settings`, `/account/logs` | `ROLE_ACCOUNT_ADMIN` | — |
| Gestão de parceiros (oficial) | `/admin/parceiros` (`Admin\PartnerController`) | `ROLE_SUPER_ADMIN` | Único lugar oficial para criar parceiro |
| Gestão de parceiros (legado) | `/partner-admin` (`PartnerAdminController`) | **Ver Dívida Técnica #2 — hoje listagem/detalhe é aberta a qualquer autenticado** | Criar/editar/excluir exige `ROLE_PARTNER` ou `ROLE_ADMIN` |
| Cadastro/registro de parceiro | `Admin\PartnerRegistrationController` | `ROLE_PARTNER`/`ROLE_PARTNER_ADMIN` | Relação com o fluxo oficial acima não está clara — ver Dívida Técnica #4 |
| Cron / coleta automatizada | `/cron/trigger/{job}` | **Deveria ser `PUBLIC_ACCESS` + token — hoje NÃO está liberado (ver Dívida Técnica #3)** | Modo principal de produção é CLI (`cron.php`), não a URL |

## 5. O que cada papel vê no Dashboard

- **`ROLE_SUPER_ADMIN` sem parceiro em contexto** → painel
  "Administração da plataforma": estatísticas do sistema, features
  ativas, próxima fatura, últimos logs, atalhos para
  Parceiros/Usuários/Logs/Configurações. **Não mostra dados
  operacionais** (alertas, jams, mapas, rotas) de propósito — isolamento
  de dados entre clientes é uma decisão de produto explícita.
- **`ROLE_SUPER_ADMIN` com um parceiro escolhido** (via troca de
  contexto) → mesmo dashboard operacional do usuário comum, mas para
  o parceiro selecionado.
- **`ROLE_ADMIN`/`ROLE_MANAGER`** → dashboard operacional do próprio
  parceiro + bloco de ações administrativas (`_admin_actions`).
- **`ROLE_USER` comum** → dashboard operacional do próprio parceiro:
  KPIs clicáveis, "Ao vivo" (24h/3h), gráficos (alertas por subtipo,
  jams por nível), ranking de ruas, mapa, tabelas de recentes.

## 6. Funcionalidades / módulos do sistema

- **Alertas Waze** — coleta (`WazeCollectFeedCommand`), listagem,
  exportação CSV, visão "ao vivo".
- **Congestionamentos (Jams) Waze** — mesma origem de coleta que
  alertas (feed Waze).
- **Rotas monitoradas / TVT** — duas gerações convivendo:
  - **Legada, em uso real**: `WazeTvtRouteDefinition` →
    `WazeTvtRouteExecution.routeDefinition` (populada por
    `WazeCollectTvtCommand` e `MigrateTvtRoutesCommand`).
  - **Nova, ainda não conectada à coleta**: `WazeTvtRoute` →
    `WazeTvtRouteExecution.tvtRoute` — existe para permitir filtrar
    execuções por parceiro (`WazeTvtRoute.partner`), mas
    **nada hoje popula esse campo** (ver Dívida Técnica #5).
- **CIFS** — ocorrências de defesa civil, com tipos configuráveis
  (`CifsEventType`), geocodificação reversa, feed JSON público por
  parceiro.
- **CEMADEN** — dados pluviométricos/hidrológicos por estação
  (`CemadenCollectCommand`, `CemadenCollectHydroCommand`), histórico
  exportável.
- **Notificações** — despacho (`NotificationDispatchCommand`) e
  alertas de alto risco (`NotifyHighRiskCommand`).
- **Relatório diário** — `SendDailyReportCommand`.
- **Sincronização WME UR** — `WmeUrSyncController` /
  `WmeUpdateRequest`: integração com o editor de mapas do Waze
  (Update Requests), separada do fluxo de tráfego/CIFS/CEMADEN.

## 7. Jobs automatizados (cron)

Fonte detalhada: `doc/cron-reference.md`. Resumo:

- Hospedagem atual (Hostinger, compartilhada) **não roda Supervisor**
  → o Symfony Scheduler nunca consome a fila. Por isso a coleta é
  feita **direto por `cron.php`**, um job por linha de crontab.
- Jobs: `waze_feed`, `waze_routes`, `waze_tvt`, `cemaden`,
  `cemaden_hydro`, `notify`, `notify_high_risk`, `report`.
- Nunca configurar CLI **e** URL para o mesmo job ao mesmo tempo
  (duplicaria a coleta).
- Modo URL (`/cron/trigger/{job}`) é fallback para painéis que só
  aceitam "chamar uma URL" — autenticado por `CRON_TOKEN`
  (`hash_equals()`), **nunca** por login.

## 8. Dívida técnica / gaps conhecidos

Lista viva — ao resolver um item, mova para um changelog em vez de
apagar (para não perder o histórico de decisão).

1. **`DashboardController` não usa `TenantContext`.** Resolve o
   partner com `method_exists($user, 'getPartner')` inline, em vez
   de reusar o serviço central. Duplica lógica e pode divergir do
   resto do sistema silenciosamente.

2. **Vazamento entre parceiros em `PartnerAdminController`
   (`/partner-admin`).** `index()` e `show()` só exigem
   `IS_AUTHENTICATED_FULLY` — **qualquer** usuário logado de
   **qualquer** parceiro consegue listar todos os parceiros e ver
   detalhes (nome, e-mail, etc.) de parceiros que não são o dele.
   Criar/editar/excluir estão corretamente restritos a
   `ROLE_PARTNER`/`ROLE_ADMIN`, mas leitura não. **Precisa de
   correção de segurança.**

3. **Rota de cron sem `PUBLIC_ACCESS`.** O próprio docblock de
   `CronController` diz que `^/cron` precisa estar liberado em
   `security.yaml` antes do catch-all `^/`, mas essa regra **não
   existe** no `access_control` atual. Se o modo URL (wget) estiver
   configurado em produção, ele está caindo numa tela de login em
   vez de rodar o job. Verificar se produção usa modo CLI (que não
   é afetado) antes de tratar como urgente — mas corrigir de
   qualquer forma.

4. **Papéis `ROLE_PARTNER`, `ROLE_PARTNER_ADMIN` e `ROLE_OPERATOR`
   não reconciliados.** Usados em poucos arquivos, fora da
   hierarquia declarada, com nomes que colidem conceitualmente com a
   entidade `Partner`. Decidir: são sinônimos de papéis já
   existentes, papéis novos de verdade, ou código morto de uma
   tentativa anterior?

5. **`WazeTvtRouteExecution.tvtRoute` nunca é populado.** O campo
   existe (para permitir contar execuções TVT por parceiro no
   dashboard), mas `WazeCollectTvtCommand` — o único lugar que cria
   execuções de verdade hoje — ainda grava em `routeDefinition`, que
   não tem vínculo com `Partner`. Resultado prático: o contador de
   "Execuções TVT" do dashboard é **global**, não filtrado por
   parceiro, ao contrário de todas as outras métricas.

6. **`src/Service/MenuBuilder.php` é código morto e desatualizado.**
   Não é chamado em lugar nenhum (`grep` confirma). Referencia rotas
   que não existem no projeto (`admin_alerts_index`,
   `partner_routes_index`, `admin_users_index`). O menu real está em
   `templates/partials/_navbar_menu.html.twig`, que por sua vez só
   linka Dashboard/Rotas/Links/Cidades — a maioria das funcionalidades
   (Alertas, Jams, CIFS, Hidrológico, Notificações, Usuários,
   Configurações, painel Super Admin) **não tem link visível no
   menu**, só é alcançável por URL direta.

7. **Template órfão `templates/dashboard/_partner_stats.html.twig`.**
   Não é incluído por nenhum controller/template. Verificar se é
   rascunho de algo em andamento antes de apagar.

## 9. Convenções a seguir a partir de agora

- Toda query DQL que referencia um campo de entidade deve ser
  conferida contra o nome exato da propriedade (case-sensitive) antes
  do commit — já tivemos dois incidentes de produção por isso
  (`timestamp`/campo removido sem querer, `subType` vs `subtype`).
- Mudança de entidade (adicionar/remover campo) é **sempre aditiva**
  por padrão — nunca reescrever a classe inteira quando a intenção é
  só adicionar uma relação nova. Isso já causou perda silenciosa de
  8 campos em produção.
- Todo novo papel (`ROLE_*`) precisa: (a) ser adicionado à
  `role_hierarchy` com a herança decidida, (b) ser registrado na
  tabela da seção 3.1 deste arquivo, (c) ter o escopo de dados
  (todos os parceiros vs. um parceiro só) declarado explicitamente.
- Toda funcionalidade nova de tela protegida precisa aparecer em
  `templates/partials/_navbar_menu.html.twig` (ou seu sucessor) — não
  deixar acessível só por URL direta.
- Ao popular/filtrar dados por parceiro, usar `TenantContext` — não
  reimplementar resolução de tenant inline em um controller novo.
