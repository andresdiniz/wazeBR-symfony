# WazeBR Monitor — Roadmap de Funcionalidades e Stack Técnica

> **Como este documento se relaciona com `doc/PRODUCT_RULES.md`:**
> `PRODUCT_RULES.md` é a fonte da verdade do **estado atual** do
> sistema (o que existe, como os papéis funcionam hoje, dívida
> técnica conhecida). Este arquivo é o **roadmap de evolução** —
> funcionalidades novas propostas, validadas contra o código real
> deste repositório, não contra um sistema genérico.
>
> Uma primeira versão deste roadmap foi rascunhada em outra
> ferramenta (Gemini) a partir de uma ideia genérica de "sistema de
> CCO" — nomes de papéis, bibliotecas e até features que ela sugeriu
> **não bateram** com o que já existe aqui. Este documento corrige
> isso: cada proposta foi checada contra as roles, entidades e
> hospedagem reais antes de entrar aqui. As diferenças estão
> marcadas explicitamente em **⚠️ Divergência da proposta original**
> ao longo do texto — não foram silenciadas.
>
> Ao aprovar um item deste roadmap para execução, mova-o para
> `PRODUCT_RULES.md` (seção correspondente) e marque aqui como
> `[Implementado — ver PRODUCT_RULES.md §X]`.

---

## 1. Visão geral do que está sendo proposto

Evoluir o WazeBR Monitor de um painel de leitura de dados
(alertas/jams/CIFS/CEMADEN/TVT por parceiro) para uma plataforma de
**operação ativa**: automações sobre os dados que já chegam,
analytics histórico, um modo de visualização dedicado a Centro de
Controle Operacional (CCO), e um RBAC mais granular com trilha de
auditoria completa.

Nenhuma dessas features substitui o que já existe — elas se
encaixam nos módulos atuais listados em `PRODUCT_RULES.md §6`
(Alertas Waze, Jams, TVT, CIFS, CEMADEN, Notificações, WME).

---

## 2. Novas funcionalidades propostas

### 2.1 Alertas avançados e automação

- **Gatilhos personalizados → Webhooks.** Regras configuráveis por
  parceiro (ex.: `retenção > 15 min AND extensão > 2 km`) disparando
  para Discord/Telegram/WhatsApp Business API/webhook genérico.
  - Já existe uma base pronta para isso: `NotificationService` e o
    comando `NotifyHighRiskCommand` já avaliam "alertas de alto
    risco" e despacham notificação. A automação nova é uma
    **generalização** desse mecanismo (regras configuráveis em vez
    de lógica fixa no código), não um módulo do zero.
  - Nova entidade sugerida: `AlertTriggerRule` (partner, condições
    em JSON, canal, destino/webhook URL, ativo/inativo).
- **Gestão de incidentes em lote.** Selecionar múltiplos
  `WazeAlert`/`WazeTrafficJam` correlacionados (mesmo corredor) e
  mudar status de uma vez (`Em Atendimento`, `Analisado`,
  `Concluído`).
  - **Pré-requisito que não existe hoje**: nem `WazeAlert` nem
    `WazeTrafficJam` têm campo de status/triagem — são hoje só dados
    de leitura, replicados do feed do Waze. Precisa de um campo novo
    (`status`, `triagedBy`, `triagedAt`) antes de qualquer UI de
    lote. Decidir: status vive na própria entidade (fica junto do
    dado, mas o feed pode recriar/atualizar o registro) ou numa
    tabela separada de triagem (`AlertTriage`) referenciando o
    `wazeId`/`jamUuid` (mais seguro contra sobrescrita pelo próximo
    ciclo de coleta). **Recomendação: tabela separada.**

### 2.2 Analytics, relatórios e inteligência histórica

- **Matriz de gargalos recorrentes (heatmap dia×hora).** Já dá pra
  construir isso com os dados que existem
  (`WazeTrafficJamRepository`/`WazeAlertRepository` + `pubMillis`) —
  é uma nova query de agregação (`GROUP BY DAYOFWEEK, HOUR`), não uma
  fonte de dado nova.
- **Exportação personalizada (PDF/CSV).** CSV **já existe**
  (`AlertController::export()`, `fputcsv` puro, sem lib) — o padrão a
  seguir para novos exports CSV é esse mesmo, sem introduzir
  dependência nova. PDF ainda não existe — ver §3.3 para a lib.
- **Comparador "Antes × Depois".** Requer marcar um período/trecho
  como "linha de base" e outro como "pós-intervenção" e comparar
  agregados (tempo médio, nº de jams, severidade) — entidade nova
  sugerida: `RouteIntervention` (partner, rota/trecho, data de
  início, descrição), usada só para definir a data de corte da
  comparação.

### 2.3 UX, mapa e visualização

- **Modo CCO / dark theme dedicado.** O dashboard já usa tokens de
  tema escuro (`--bg-secondary`, `--text-primary` em
  `assets/styles/app.css`) — um "Modo CCO" é uma variação de
  densidade/contraste desse tema já existente para uso em telão,
  **não** um tema novo do zero. Ver §3.1 para a lib de gráfico com
  bom suporte a isso.
- **Agrupamento dinâmico de marcadores (clustering).**
  ⚠️ **Divergência da proposta original**: isso **já está
  implementado**. `assets/js/dashboard.js` já usa
  `leaflet.markercluster` para jams e alertas no mapa
  (`L.markerClusterGroup`). Não precisa de nova lib nem nova
  arquitetura de mapa — só extender a mesma clusterização pros novos
  tipos de camada abaixo.
- **Camadas de infraestrutura (CFTV, radares, jurisdição).** Novo
  tipo de camada opcional no mesmo mapa Leaflet já existente
  (`GeoJSON` ou marcadores simples por cima do `L.tileLayer` atual).
  Não exige trocar de biblioteca de mapa — ver §3.1.

### 2.4 Gestão do sistema, RBAC e auditoria

- **RBAC mais granular** — ver §4 (reconciliação completa com os
  papéis que já existem, não uma lista nova do zero).
- **Trilha de auditoria (Audit Log).**
  ⚠️ **Divergência da proposta original**: isso **já existe em
  parte**. A entidade `ActivityLog` (partner, user, action,
  description, context JSON, ipAddress, createdAt) já está pronta
  para servir de audit log — hoje ela só é **usada** para erros de
  coleta agendada (`fetch_error`, `parse_error`, `schedule_error`,
  ver `ActivityLogRepository::findErrorsByPartner`). O trabalho aqui
  é **expandir o vocabulário de `action`** (ex.:
  `BATCH_STATUS_UPDATE`, `REPORT_EXPORTED`, `USER_ROLE_CHANGED`,
  `TRIGGER_RULE_CHANGED`) e chamar
  `ActivityLog` a partir dos novos controllers/ações — não criar uma
  tabela nova.

---

## 3. Stack técnica recomendada

**Restrição real do projeto, que muda a recomendação genérica:**
este projeto usa **Symfony AssetMapper** (sem Node, sem bundler, sem
`npm run build`) e hospedagem **compartilhada (Hostinger)** sem
Supervisor nem binários customizados disponíveis (ver
`doc/cron-reference.md`). Isso descarta, de cara, qualquer lib que
dependa de um passo de build em Node (React com JSX compilado,
Deck.gl com bundler) ou de binário headless no servidor (Puppeteer/
Playwright/Chrome headless) — não vão rodar nesse hosting sem
infraestrutura adicional que hoje não existe.

### 3.1 Mapas

| Necessidade | Recomendação | Por quê |
|---|---|---|
| Mapa base + clustering | **Manter Leaflet + Leaflet.markercluster** (já em uso) | Já funciona, já importado, zero migração |
| Heatmap dia×hora / densidade | **`leaflet.heat`** | Plugin leve do Leaflet, mesmo import via CDN/AssetMapper, sem trocar de stack de mapa |
| Camadas de infraestrutura (CFTV, radares) | **`L.geoJSON()` nativo do Leaflet** | Já incluso no Leaflet, não é lib nova |

⚠️ **Divergência da proposta original**: a sugestão de migrar para
**MapLibre GL JS + Deck.gl** foi descartada. São ótimas libs, mas
troca de stack de mapa é uma reescrita grande do `dashboard.js` e do
`_map.html.twig` só para ganhar renderização WebGL — o volume de
pontos hoje (mapa já limita a 100 registros, ver
`_map.html.twig: mapJamsTruncated`) não justifica isso. Reavaliar só
se o volume de dados por parceiro crescer muito (milhares de pontos
simultâneos).

### 3.2 Gráficos

| Necessidade | Recomendação | Por quê |
|---|---|---|
| Gráficos do dashboard (doughnut, barra) | **Manter Chart.js** (já em uso, v4.5.1) | Já funciona, leve, importmap-friendly |
| Heatmap dia×hora (matriz) | **`chartjs-chart-matrix`** (plugin oficial do Chart.js) | Mantém tudo na mesma lib em vez de somar uma segunda |
| Modo CCO (telão, alta densidade de dados) | Avaliar **Apache ECharts** *só* para esse painel específico, se o Chart.js não aguentar bem várias séries ao vivo | Não é para substituir o Chart.js do dashboard comum — é opcional, isolado no Modo CCO |

Ambas (`chartjs-chart-matrix` e, se necessário, `echarts`) são
registráveis via `php bin/console importmap:require`, sem precisar
de bundler.

### 3.3 Exportação (PDF / CSV / XLSX)

| Formato | Recomendação | Por quê |
|---|---|---|
| CSV | **Manter `fputcsv` nativo do PHP** (já em uso) | Já funciona, zero dependência |
| XLSX | **PhpSpreadsheet** | Lib PHP padrão de mercado, roda em qualquer hosting PHP, sem Node |
| PDF | **Dompdf** (via `dompdf/dompdf` ou `barryvdh/laravel-dompdf`-style para Symfony, ou `knplabs/knp-snappy-bundle` **apenas se** `wkhtmltopdf` puder ser instalado no servidor) | Gera PDF a partir de HTML/Twig renderizado em PHP puro — roda em hosting compartilhado sem binário externo |

⚠️ **Divergência da proposta original**: **Puppeteer/Playwright para
PDF no backend foram descartados.** Exigem Node + Chrome headless
rodando no servidor — inviável no hosting compartilhado atual (sem
Supervisor, sem controle de processo). `jsPDF` + `html2canvas` no
**cliente** (browser) é viável como alternativa leve para exports
simples, mas para relatório institucional com gráficos/tabelas bem
formatados, **Dompdf server-side a partir de um template Twig** dá
resultado mais confiável e consistente com o resto do projeto
(Twig já é a camada de view de tudo aqui).

### 3.4 CSS / UI

- **Manter o sistema de design atual** (`app.css` com CSS custom
  properties — `--bg-secondary`, `--primary`, `--radius`, etc.,
  usado em `dashboard.css`, `auth.css`, `home.css`). Já é consistente
  e não depende de build step.
- ⚠️ **Divergência da proposta original**: **Tailwind CSS não é
  recomendado agora.** Adotar Tailwind exigiria um pipeline de
  purge/build (PostCSS) que este projeto não tem hoje (AssetMapper
  não roda PostCSS nativamente sem pacotes adicionais) — trocaria uma
  base de CSS já funcional por uma migração grande sem ganho
  imediato. Reavaliar só se o time crescer e precisar de
  padronização de componentes em escala.
- **Ícones**: `bootstrap-icons` já está no `importmap.php` — usar o
  que já está registrado em vez de somar `lucide-icons`.

### 3.5 Nota sobre autenticação

⚠️ **Divergência da proposta original**: a sugestão de **JWT +
Refresh Token (RS256)** não se aplica hoje. A aplicação web usa
sessão do Symfony Security (`form_login`, cookie de sessão) — é
assim que login/logout/remember-me funcionam
(`config/packages/security.yaml`). A integração máquina-a-máquina já
tem seu próprio mecanismo: **token estático por parceiro**
(`Partner.apiToken`, cabeçalho `X-Api-Token`, resolvido por
`TenantContext::resolveFromToken()`). JWT só faria sentido se um dia
existir um app mobile ou SPA separado consumindo a API — não é o
caso hoje. Não introduzir JWT só para seguir um template genérico.

---

## 4. RBAC — reconciliação com os papéis reais

⚠️ **Divergência da proposta original, a mais importante deste
documento**: a primeira versão propôs um RBAC do zero
(`ADMIN`/`GESTOR`/`OPERADOR`/`LEITOR`) que **ignora os 9 papéis que
já existem no código** (ver `PRODUCT_RULES.md §3.1`). Adotar esses
4 nomes como estão criaria um **segundo sistema de papéis paralelo**
ao que já roda em produção — exatamente o tipo de inconsistência que
`PRODUCT_RULES.md` foi criado para impedir. A tabela abaixo mapeia
a intenção de cada papel proposto para o papel real equivalente, ou
declara papel novo de verdade quando não há equivalente.

| Papel proposto (genérico) | Papel real equivalente | Ação |
|---|---|---|
| `ADMIN` | `ROLE_SUPER_ADMIN` (dono da plataforma) + `ROLE_ADMIN` (suporte/operação, qualquer parceiro) | Nenhum papel novo — usar os que já existem |
| `GESTOR` (Eng. de Tráfego) | `ROLE_MANAGER` | Já existe; hoje só aparece no rótulo do dashboard ("Gestão do parceiro") e no menu (link "Rotas") — precisa ganhar de fato as permissões de configurar gatilhos/regras de automação (§2.1) e ver analytics completo (§2.2) |
| `OPERADOR` (CCO) | `ROLE_OPERATOR` | Já existe, mas hoje é usado só em `PartnerAdminController` (visão básica de parceiro) e na rota `/operador`. Precisa ganhar as permissões de: ver Modo CCO, tratar incidentes (individual e em lote), **sem** acesso a gatilhos/automação nem exportação avançada — exatamente como descrito na proposta original, só usando o nome de papel que já existe |
| `LEITOR` / Auditor | **Não existe — papel novo de verdade** | Criar `ROLE_LEITOR`: leitura de relatórios, estatísticas consolidadas e Audit Log; zero permissão de escrita em qualquer módulo |

### 4.1 `role_hierarchy` atualizada (proposta)

```yaml
role_hierarchy:
    ROLE_SUPER_ADMIN: [ROLE_ADMIN]
    ROLE_ADMIN: [ROLE_ACCOUNT_ADMIN, ROLE_MANAGER, ROLE_USER]
    ROLE_ACCOUNT_ADMIN: [ROLE_MANAGER, ROLE_USER]
    ROLE_MANAGER: [ROLE_OPERATOR, ROLE_USER]
    ROLE_OPERATOR: [ROLE_USER]
    ROLE_FIELD_AGENT: [ROLE_USER]
    ROLE_LEITOR: [ROLE_USER]
```

Isso resolve o gap já registrado em `PRODUCT_RULES.md §3.2` (papéis
que hoje não herdam nada). `ROLE_PARTNER` e `ROLE_PARTNER_ADMIN`
continuam **de fora** — não usar em código novo até a Dívida Técnica
#4 ser resolvida (decidir se são sinônimos ou papéis legados a
aposentar).

### 4.2 Matriz de permissões (módulos novos + existentes)

| Módulo / Funcionalidade | `SUPER_ADMIN` | `ADMIN` | `ACCOUNT_ADMIN` | `MANAGER` (Gestor) | `OPERATOR` (CCO) | `FIELD_AGENT` | `USER` | `LEITOR` |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| Mapa / Modo CCO (tempo real) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Tratar incidente (individual) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — | — |
| Gestão de incidentes em lote | ✅ | ✅ | ✅ | ✅ | ✅ | — | — | — |
| Gatilhos / Webhooks de automação | ✅ | ✅ | — | ✅ | — | — | — | — |
| Heatmap / Matriz de gargalos / Antes-Depois | ✅ | ✅ | ✅ | ✅ | leitura | — | leitura | leitura |
| Exportação PDF/CSV/XLSX | ✅ | ✅ | ✅ | ✅ | leitura¹ | — | — | ✅ |
| Camadas de infraestrutura (gerenciar) | ✅ | ✅ | ✅ | ✅ | leitura | — | leitura | leitura |
| Gestão de usuários (próprio parceiro) | ✅ | ✅ | ✅ | — | — | — | — | — |
| Gestão de usuários (qualquer parceiro) | ✅ | ✅ | — | — | — | — | — | — |
| Gestão de parceiros (criar/editar/excluir) | ✅ | — | — | — | — | — | — | — |
| Audit Log (consultar) | ✅ | ✅ | ✅ (próprio parceiro) | — | — | — | — | ✅ |

¹ Sugestão: limite diário de exportação para `ROLE_OPERATOR`, igual
à proposta original — evita uso indevido de um papel operacional
para extração massiva de dados.

---

## 5. Auditoria — o que muda no que já existe

Não é uma tabela nova. É expandir o uso de `ActivityLog`:

- **Vocabulário de `action` a padronizar** (string curta, maiúscula,
  snake_case, seguindo o padrão que já existe: `fetch_error`,
  `parse_error`, `schedule_error`): `batch_status_update`,
  `report_exported`, `user_role_changed`, `trigger_rule_created`,
  `trigger_rule_updated`, `trigger_rule_deleted`,
  `partner_switched` (troca de contexto pelo admin, via
  `TenantContext::switchTo()` — hoje isso **não é logado**, e
  deveria ser, por ser uma ação sensível).
- **`context` (json) guarda o "antes/depois"** quando fizer sentido
  — ex., para `batch_status_update`: `affected_ids`,
  `previous_status`, `new_status`, `reason`.
- **Imutabilidade**: hoje nada impede um `UPDATE`/`DELETE` em
  `activity_log` a nível de aplicação (não há rota pra isso, mas
  também não há proteção explícita no banco). Antes de expor Audit
  Log para o papel `LEITOR`, adicionar:
  - Nenhum `Repository`/`Controller` deve expor `remove()` para essa
    entidade — nem para `ROLE_SUPER_ADMIN`.
  - Considerar revogar `DELETE`/`UPDATE` no usuário de banco da
    aplicação para a tabela `activity_log`, se a hospedagem permitir
    (proteção na camada de banco, não só na de aplicação).

---

## 6. Ordem de implementação sugerida

Não é obrigatório seguir esta ordem, mas ela minimiza retrabalho:

1. **`role_hierarchy` atualizada** (§4.1) — base para tudo que
   depende de permissão.
2. **Expandir uso do `ActivityLog`** (§5) — assim toda feature nova
   já nasce logada, em vez de logar depois.
3. **Campo de status/triagem em alertas e jams** (§2.1) — pré-
   requisito da gestão em lote.
4. **Gestão de incidentes em lote** (individual → lote).
5. **Heatmap dia×hora + `leaflet.heat`/`chartjs-chart-matrix`**
   (§2.2, §3.1, §3.2).
6. **Exportação PDF via Dompdf** (§3.3) — depende de já ter os dados
   agregados prontos do item 5.
7. **Gatilhos/Webhooks** (§2.1) — o mais arriscado (integração
   externa, credenciais de terceiros) — deixar por último.
8. **Modo CCO dedicado + camadas de infraestrutura** (§2.3) —
   incremento visual, não bloqueia nada.

---

## 7. Checklist de validação (antes de aprovar cada item)

- [ ] O papel usado no código é um dos já existentes ou do §4.1 —
      não um nome novo inventado na hora.
- [ ] Toda tabela nova tem `partner` como campo de escopo (ou uma
      justificativa explícita por que não precisa).
- [ ] Toda ação sensível nova chama `ActivityLog` com uma `action`
      do vocabulário padronizado (§5).
- [ ] Toda lib nova é instalável via `importmap:require` (JS) ou
      Composer puro-PHP (backend) — nada que exija Node/bundler/
      binário externo no servidor, a não ser que a hospedagem mude.
- [ ] Toda tela nova protegida por role tem link em
      `templates/partials/_navbar_menu.html.twig` (ver
      `PRODUCT_RULES.md §9`).
