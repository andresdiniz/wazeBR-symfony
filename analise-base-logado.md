# Análise do layout base para usuários logados — wazeBR-symfony

Análise de `templates/layouts/dashboard.html.twig` (o "base" das páginas logadas), seus parciais (`sidebar`, `topbar`, `footer`), e os assets `assets/css/dashboard.css` / `assets/js/dashboard.js`, comparados classe a classe / id a id com o que o HTML realmente renderiza.

## Resumo executivo

O layout logado (sidebar, topbar, notificações, menu de conta, footer) está **rodando sem nenhum CSS próprio de fato aplicado**, e o `dashboard.js` **não interage com nenhum elemento real da página**. Isso não é um problema de "polimento visual" — é um mismatch estrutural: em algum refactor o HTML dos parciais foi migrado para um novo padrão de nomenclatura (prefixo `dashboard-*` + atributos `data-dashboard-*`), mas o CSS/JS ficaram na versão anterior (classes/IDs sem prefixo, ex.: `.sidebar`, `#sidebar-toggle`, `.nav-link`). O resultado: sidebar, topbar, dropdown de notificações e menu de conta renderizam essencialmente sem estilo e sem nenhuma interação funcionando (nem abrir/fechar, nem colapsar, nem toggle mobile).

Isso é o mesmo padrão de bug já visto e corrigido antes na página `dashboard/index.html.twig` (registrado no histórico do projeto) — só que aqui está no **base layout**, então afeta *todas* as páginas logadas (admin, parceiro, agente de campo, usuário comum).

---

## 1. Achados críticos (bloqueiam qualquer "polimento" até serem resolvidos)

### 1.1 `dashboard.css` não estiliza o layout base atual

Comparei todas as classes usadas em `templates/layouts/dashboard.html.twig` + `partials/dashboard/{sidebar,topbar,footer}.html.twig` contra `assets/css/dashboard.css`. Resultado: **0 de ~28 classes estruturais do layout têm regra correspondente**, exceto `.dashboard-wrapper` (que só define `display:flex; height:100vh`).

Não existe no CSS nenhuma regra para:
`dashboard-sidebar`, `dashboard-sidebar-header`, `dashboard-brand`, `dashboard-nav`, `dashboard-nav-link`, `dashboard-nav-label`, `dashboard-nav-badge`, `dashboard-sidebar-footer`, `dashboard-user-summary`, `dashboard-user-avatar`, `dashboard-logout-link`, `dashboard-shell`, `dashboard-topbar`, `dashboard-topbar-start/end`, `dashboard-page-heading`, `dashboard-search`, `dashboard-notifications`, `dashboard-icon-button`, `dashboard-notification-panel`, `dashboard-account-menu`, `dashboard-account-dropdown`, `dashboard-main`, `dashboard-footer`, `dashboard-sidebar-toggle`, `dashboard-sidebar-close`.

O que o `dashboard.css` de fato estiliza (`.sidebar`, `.top-bar`, `.nav-link`, `.main-content`, `.user-menu-btn`, `.search-box`, `.notification-btn`...) é uma nomenclatura de uma versão anterior do layout que não existe mais no HTML.

**Consequência prática:** sidebar sem largura fixa/definida via CSS específico, sem separador visual consistente, itens de navegação sem estados de hover/active reais (o `is-active` no link não tem regra dedicada), painel de notificações e dropdown de conta aparecem "crus" (list-style default, sem sombra/posicionamento), rodapé sem estilo.

### 1.2 O conteúdo de `dashboard/index.html.twig` também não bate com o CSS

Mesmo problema, um nível abaixo: o template usa `dashboard-hero`, `dashboard-hero-kicker`, `dashboard-period-filter`, `dashboard-grid`, `dashboard-card`, `dashboard-card-wide`, `dashboard-list`, `dashboard-list-item`, `dashboard-list-icon`, `dashboard-list-content`, `dashboard-list-meta`, `form-control` — **nenhuma dessas tem regra em `dashboard.css`**. Só `stats-grid`/`stat-card`/`stat-icon`/`stat-info` (de uma iteração anterior) têm cobertura.

### 1.3 `dashboard.js` não encontra nenhum elemento real — toda a interatividade do base está morta

O HTML atual usa `id="dashboard-sidebar"` e atributos `data-*` (`data-dashboard-sidebar-toggle`, `data-dashboard-sidebar-close`, `data-dashboard-notifications-toggle`, `data-dashboard-notifications-panel`, `data-dashboard-account-toggle`, `data-dashboard-account-panel`).

`assets/js/dashboard.js` procura por:
- `document.getElementById('sidebar')` → não existe (é `dashboard-sidebar`)
- `document.getElementById('sidebar-toggle')` → não existe
- `document.getElementById('mobile-menu-toggle')` → não existe
- `.notification-btn` → não existe (é `[data-dashboard-notifications-toggle]`)
- `.search-input` → não existe (é `.dashboard-search-input`)
- `.task-checkbox`, `.quick-action-btn`, `.stat-card` (esses dois últimos não existem mais na página atual)

**Resultado:** botão de abrir/fechar sidebar não faz nada, toggle mobile não faz nada, o sino de notificações não abre o painel, o menu de conta no topbar não abre o dropdown. Tudo isso é HTML "morto" hoje — os elementos existem visualmente (sem estilo) mas nenhum clique produz efeito.

### 1.4 Tokens de design duplicados e **conflitantes** entre arquivos

`global.css`, `dashboard.css` e `base-layout.css` (órfão, ver 1.5) cada um redefine seu próprio `:root` com nomes iguais e valores diferentes. Exemplo concreto:

| Variável | `global.css` | `dashboard.css` |
|---|---|---|
| `--radius` | `0.25rem` | `0.5rem` |
| `--primary` | `var(--primary-600)` (escala de 11 tons) | `#2563eb` fixo |
| `--sidebar-width` | já definida em `global.css` (280px) | redefinida de novo |

Como `dashboard.css` carrega depois de `global.css` (via `page_stylesheets`), ele **sobrescreve** silenciosamente os tokens globais só nas páginas do dashboard — então um `.btn` ou `.card` vindo do design system global pode ter raio de borda diferente dependendo da página. Isso é frágil e vai gerar inconsistência visual conforme o projeto cresce.

### 1.5 Código morto: `base-layout.css` (659 linhas) e `base-layout.js` (200 linhas)

Não há **nenhuma referência** a esses dois arquivos em nenhum `.twig` do projeto — nem em `base.html.twig`, nem em nenhum layout. Definem novamente header/navbar/footer com um terceiro conjunto de tokens (`--header-height`, etc.). São ~860 linhas de manutenção morta que confundem quem entra no projeto agora (parecem ser "o CSS do base" pelo nome, mas não são usados).

### 1.6 `global.js` sobrescreve APIs nativas do navegador

```js
window.sessionStorage = { get, set, remove };   // linha ~1035
window.performance = { start, end };            // linha ~1085
```

`sessionStorage` e `performance` são APIs nativas do browser. Sobrescrevê-las com objetos próprios (mesmo com `'use strict'` ativo no IIFE) é arriscado: qualquer lib de terceiros (Chart.js, Font Awesome, extensões do navegador, futuras integrações de analytics/RUM) que use `performance.now()`/`performance.mark()` ou o `sessionStorage` nativo pode quebrar ou lançar exceção silenciosa. Isso deveria se chamar `window.wazeStorage` / `window.perf` (nomes próprios), nunca reaproveitar o nome de uma API global do browser.

### 1.7 `data-dashboard-notifications-panel` / `data-dashboard-account-panel` usam `hidden`, mas nada os remove

Os dois painéis (notificações e menu de conta) nascem com o atributo HTML `hidden`. Como não existe JS ligado aos `data-dashboard-*-toggle` correspondentes (ver 1.3), esses painéis **nunca podem ser exibidos** pelo usuário — são inacessíveis via mouse ou teclado.

### 1.8 Meta `color-scheme` ausente (regressão)

O histórico do projeto registra que essa meta tag foi adicionada em `base.html.twig` para resolver o "fundo azul estranho" causado pelo force-dark do Chrome/Android. Hoje ela **não está presente** em `templates/base.html.twig` — o bug tende a voltar a aparecer para usuários Android com dark mode forçado pelo navegador.

---

## 2. Achados de qualidade / manutenibilidade (não quebram nada, mas valem a pena)

- **`localStorage` direto em `dashboard.js`** (`initSidebar`) em vez de usar o helper `window.storage` já criado em `global.js` — duas formas diferentes de persistir estado no mesmo projeto.
- **`console.log` de debug em produção**: `global.js` imprime um "branding" ASCII/colorido a cada carregamento de página (toda página do site, não só dashboard) e `dashboard.js` imprime `"wazeBR Dashboard initialized successfully! 🚀"`. Deveriam sair do build de produção (ou ficar atrás de uma flag de debug).
- **Funções duplicadas**: `debounce`/`throttle`/`isInViewport` existem tanto em `global.js` (expostas em `window`) quanto reimplementadas dentro de `dashboard.js`. `dashboard.js` deveria simplesmente reusar as versões globais.
- **`initResizeHandler()` roda fora do `DOMContentLoaded`**, na raiz do arquivo, e faz praticamente a mesma coisa que o listener de resize já registrado dentro de `initMobileMenu()` — dois handlers de resize concorrentes para o mesmo elemento.
- **Utilitários nunca chamados**: `formatNumber`, `throttle`, `isInViewport` em `dashboard.js` não são usados por nenhum outro trecho do arquivo.
- **Responsividade do sidebar não existe no layout atual**: as media queries de `dashboard.css` (`@media max-width: 992px`) manipulam `.sidebar`/`.mobile-menu-toggle`, que não existem mais — ou seja, hoje **não há nenhum comportamento mobile real** para a sidebar do base logado (ela provavelmente ocupa espaço fixo na tela mesmo em telas pequenas, competindo com o conteúdo).
- **Acessibilidade dos toggles**: os botões com `aria-expanded="false"` fixo no HTML (sidebar toggle, notificações, conta) nunca têm esse atributo atualizado dinamicamente — porque, de novo, não há JS ligado a eles.

---

## 3. Plano de correção recomendado

A ordem importa: estilizar em cima de um JS/HTML quebrado só esconde o problema por mais tempo.

### Fase 1 — Consertar a base (pré-requisito de qualquer polimento visual)
1. Reescrever `assets/js/dashboard.js` do zero usando os seletores reais: `#dashboard-sidebar`, `[data-dashboard-sidebar-toggle]`, `[data-dashboard-sidebar-close]`, `[data-dashboard-notifications-toggle]` + `[data-dashboard-notifications-panel]`, `[data-dashboard-account-toggle]` + `[data-dashboard-account-panel]`. Cada toggle deve: alternar `hidden`, atualizar `aria-expanded`, fechar ao clicar fora e ao pressionar `Esc`.
2. Reescrever `assets/css/dashboard.css` mirando exatamente as classes `dashboard-*` do layout atual (sidebar, topbar, painéis, footer) **e** as classes de conteúdo (`dashboard-hero`, `dashboard-grid`, `dashboard-card`, `dashboard-list-*`, `form-control`).
3. Remover as variáveis duplicadas de `dashboard.css`; consumir os tokens já existentes em `global.css` (`--radius-*`, `--primary-*`, `--shadow-*`, `--text-*`) em vez de redeclarar `:root`. Definir só o que é específico do dashboard (`--sidebar-width`, `--top-bar-height`, se ainda não existir em `global.css`).
4. Restaurar a `<meta name="color-scheme" content="dark">` (ou `dark light`, conforme o design) em `templates/base.html.twig`.
5. Excluir (ou arquivar fora do diretório de assets ativos) `assets/css/base-layout.css` e `assets/js/base-layout.js` — confirmar antes que realmente não são usados por nenhuma rota/branch, depois remover.
6. Renomear `window.sessionStorage`/`window.performance` em `global.js` para nomes que não colidam com APIs nativas (ex.: `window.wazeSession`, `window.wazePerf`).

### Fase 2 — Interatividade completa do base logado
1. Sidebar: toggle mobile (abrir/fechar por cima do conteúdo em telas pequenas) + opção de colapsar em desktop, com persistência via `window.storage` (o helper que já existe em `global.js`, e não `localStorage` direto).
2. Topbar: dropdown de conta e painel de notificações funcionais, fechando ao clicar fora, com foco tratado (igual ao padrão já usado em `openModal`/`closeModal` de `global.js`).
3. Estado ativo do item de navegação (`is-active`) com destaque visual real (hoje a classe existe no Twig mas não tem regra CSS dedicada).
4. Breakpoints reais: sidebar fixa em desktop, sidebar em overlay/drawer abaixo de ~992px, busca escondida em telas pequenas (isso já era a intenção original do `dashboard.css` antigo — só precisa ser refeito com os seletores certos).

### Fase 3 — Polimento visual
1. Unificar visualmente sidebar/topbar/footer com a identidade já estabelecida em `global.css` (cores, glassmorphism/gradientes usados na landing, cards com `--shadow-lg`, etc.) para que o dashboard pareça parte do mesmo produto que a home.
2. Badge de notificação, avatar do usuário e busca com estados de foco/hover consistentes com o resto do design system (`--transition-fast`, `--radius-full`).
3. Skeleton/loading state para o conteúdo de `page_content` enquanto os dados carregam (hoje não existe).
4. Dark/light: já que o color-scheme será restaurado, vale decidir se o dashboard também terá um modo claro ou se será dark-only assumido — isso muda a estratégia de tokens.

### Fase 4 — Limpeza geral
1. Remover `console.log` de debug de `global.js` e `dashboard.js` (ou envolver em `if (import.meta.env?.DEV)`/flag equivalente).
2. Consolidar `debounce`/`throttle`/`isInViewport` — usar só a versão de `global.js`, remover as duplicadas de `dashboard.js`.
3. Remover o `initResizeHandler()` duplicado/solto fora do `DOMContentLoaded`.

---

## 4. Referência rápida — arquivos envolvidos

| Arquivo | Papel | Situação |
|---|---|---|
| `templates/base.html.twig` | HTML raiz de todo o site | falta `color-scheme` |
| `templates/layouts/dashboard.html.twig` | "base" das páginas logadas | estrutura ok, sem CSS/JS correspondente |
| `templates/partials/dashboard/sidebar.html.twig` | Sidebar + navegação por papel (`ROLE_*`) | ok, sem estilo |
| `templates/partials/dashboard/topbar.html.twig` | Busca, notificações, menu de conta | ok, sem estilo nem JS |
| `templates/partials/dashboard/footer.html.twig` | Rodapé do dashboard | ok, sem estilo |
| `assets/css/global.css` | Design system (botões, cards, forms, badges, alerts, utilitários) | boa base, mas com tokens que colidem com `dashboard.css` |
| `assets/css/dashboard.css` | CSS específico do dashboard | **desatualizado**, não cobre o HTML atual |
| `assets/js/dashboard.js` | JS específico do dashboard | **desconectado** do HTML atual |
| `assets/js/global.js` | Utilitários globais (toast, modal, dropdown, storage...) | bom, mas sobrescreve `sessionStorage`/`performance` nativos |
| `assets/css/base-layout.css` | — | **órfão**, não referenciado em lugar nenhum |
| `assets/js/base-layout.js` | — | **órfão**, não referenciado em lugar nenhum |

---

*Análise baseada no estado atual do branch padrão de `andresdiniz/wazeBR-symfony`, comparando cada classe/id/atributo `data-*` usado nos templates do dashboard contra as regras definidas em `dashboard.css`/`dashboard.js`.*
