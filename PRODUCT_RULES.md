# 4smart WazeBR Monitor — Regras de Produto, Funcionalidades e Usuários

---

## 1. Visão Geral do Produto
O **4smart WazeBR Monitor** é uma plataforma de inteligência de tráfego e monitoramento em tempo real projetada para Centros de Controle Operacional (CCO), órgãos de tráfego e gestores de mobilidade urbana. O sistema integra dados do ecossistema Waze (Waze for Cities / WazeCCO) com camadas operacionais de infraestrutura urbana para análise de retenções, automação de alertas e tomada de decisão estratégica.

---

## 2. Funcionalidades e Módulos do Sistema

### 2.1. Alertas Avançados e Automações
* **Gatilhos Personalizados:** Configuração de regras parametrizáveis por administradores para disparo de alertas automáticos via Webhooks (Telegram, WhatsApp API, Discord ou sistemas corporativos de mensageria).
  * *Exemplo de Regra:* Retenção > 15 minutos **AND** Extensão do trecho > 2,0 km → Notificar CCO Regional.
* **Gestão de Incidentes em Lote:** Ação rápida no dashboard para selecionar múltiplos alertas correlacionados no mesmo trecho ou corredor viário e atualizar seu status em lote (ex.: *Em Atendimento*, *Analisado*, *Concluído*).

### 2.2. Analytics, Relatórios e Inteligência Histórica
* **Matriz Origem-Destino (OD) & Heatmaps de Gargalos:** Análise de padrões de congestionamento recorrentes por dia da semana e faixa horária, permitindo mapear gargalos crônicos e apoiar o planejamento de engenharia de tráfego.
* **Relatórios Automatizados (PDF/CSV):** Gerador de relatórios executivos e operacionais com filtros por jurisdição, tipo de evento, severidade ou período temporal, incluindo gráficos consolidados para prestação de contas governamental.
* **Análise Comparativa ("Antes x Depois"):** Estudo de impacto de intervenções viárias, obras, eventos ou mudanças de sinalização, comparando tempos de viagem e extensão de filas em cenários pré e pós-evento.

### 2.3. Experience (UX) e Visualização para CCO
* **Modo CCO (Dark Theme Dedicado):** Interface otimizada para videowalls e telas de monitoramento contínuo em Centros de Controle Operacional, com alto contraste e atualização de dados em tempo real sem impacto severo em renderização.
* **Agrupamento Dinâmico (Clustering):** Algoritmo visual no mapa que agrupa alertas e eventos próximos conforme o nível de zoom, evitando poluição visual e garantindo alto desempenho do mapa.
* **Camadas de Infraestrutura Urbana (GIS/Layers):** Capacidade de sobreposição de camadas adicionais no mapa, tais como:
  * Posição e status de câmeras de CFTV;
  * Malha de radares e sensores de contagem;
  * Limites de jurisdição municipal/estadual e áreas de concessão viária.

### 2.4. Gestão do Sistema, Auditoria e Segurança
* **Controle de Acesso Baseado em Funções (RBAC):** Estrutura hierárquica e granular de permissões por perfil de usuário.
* **Trilha de Auditoria (Audit Log):** Registro imutável das ações dos usuários no sistema (alteração de parâmetros de coleta, exportação de dados, modificação de status de alertas e alterações de usuários) para conformidade jurídica e segurança operacionais.

---

## 3. Matriz de Usuários e Validação de Regras de Permissão (RBAC)

A estrutura de acessos é organizada no modelo **RBAC (Role-Based Access Control)** para garantir que cada operador ou gestor atue exclusivamente dentro de seu escopo de responsabilidade.

### 3.1. Definição dos Perfis de Usuário

1. **Administrador (`ADMIN`)**
   * Acesso irrestrito a todas as funcionalidades do sistema.
   * Gestão de usuários, papéis, parâmetros globais de coleta e integrações de API/Webhooks.
2. **Engenheiro de Tráfego / Gestor (`GESTOR`)**
   * Acesso completo a relatórios, análises comparativas e matrizes OD.
   * Configuração e edição de regras de alertas e gatilhos de automação.
   * Sem permissão para alterar configurações globais de infraestrutura ou gerenciar usuários do sistema.
3. **Operador de CCO (`OPERADOR`)**
   * Acesso operacional ao mapa interativo (Modo CCO), painel em tempo real e visualização de camadas de infraestrutura.
   * Atualização e triagem de incidentes (inclusive alteração de status individual ou em lote).
   * Sem permissão para alterar parâmetros de coleta, gatilhos de automação ou exportar relatórios avançados sem autorização.
4. **Auditor / Leitor (`LEITOR`)**
   * Perfil estritamente de consulta (*read-only*).
   * Acesso a relatórios de prestação de contas, estatísticas consolidadas e à Trilha de Auditoria (*Audit Log*).
   * Sem acesso de edição em alertas, incidentes ou configurações.

---

### 3.2. Matriz de Validação de Permissões (CRUD / Funcionalidades)

| Módulo / Funcionalidade | Administrador (`ADMIN`) | Gestor / Eng. Tráfego (`GESTOR`) | Operador CCO (`OPERADOR`) | Auditor / Leitor (`LEITOR`) |
| :--- | :---: | :---: | :---: | :---: |
| **Mapa & Modo CCO (Tempo Real)** | Visualiza | Visualiza | Visualiza | Visualiza |
| **Gestão de Incidentes em Lote** | Executa | Executa | Executa | Somente Leitura |
| **Configuração de Gatilhos / Webhooks** | Cria / Edita / Deleta | Cria / Edita / Deleta | Sem Acesso | Sem Acesso |
| **Análise OD / Comparativa ("Antes x Depois")** | Total | Total | Visualiza | Visualiza |
| **Exportação de Relatórios (PDF/CSV)** | Total | Total | Limite Diário | Total |
| **Camadas de Infraestrutura (Layers/CFTV)** | Gerencia / Edita | Gerencia / Edita | Somente Leitura | Somente Leitura |
| **Gestão de Usuários & RBAC** | Total | Sem Acesso | Sem Acesso | Sem Acesso |
| **Consulta à Trilha de Auditoria (Audit Log)** | Total | Sem Acesso | Sem Acesso | Total |

---

## 4. Validação das Regras de Segurança e Conformidade
1. **Princípio do Menor Privilégio (PoLP):** Operadores de CCO possuem acesso focado nas rotinas diárias de monitoramento e tratamento de incidentes, impedindo alterações acidentais nas automações do sistema.
2. **Rastreabilidade Total:** Todas as ações executadas pelos perfis `ADMIN`, `GESTOR` e `OPERADOR` geram registros automáticos vinculados ao ID do usuário, IP de origem e timestamp no *Audit Log*.
3. **Isolamento de Configuração:** Regras de coleta do Waze e Webhooks de notificação são protegidas e restritas aos níveis executivos e de administração do sistema.
