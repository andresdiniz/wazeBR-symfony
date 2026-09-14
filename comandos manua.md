═══════════════════════════════════════════════════════════════════════════
  WazeBR — Catálogo de comandos Symfony
  Atualizado em 2026-09-13
═══════════════════════════════════════════════════════════════════════════

ÍNDICE
  1.  Comandos de coleta (feeds externos)
  2.  Comandos de usuário / cadastro
  3.  Comandos de diagnóstico e debug
  4.  Comandos de manutenção (Doctrine / Messenger / Scheduler)
  5.  Agendamento (Scheduler)
  6.  Cron / hPanel Hostinger
  7.  Tabelas do banco — origem × destino
  8.  Troubleshooting rápido


═══════════════════════════════════════════════════════════════════════════
  1. COMANDOS DE COLETA (feeds externos)
═══════════════════════════════════════════════════════════════════════════

───────────────────────────────────────────────────────────────────────────
  app:fetch-waze-feed
───────────────────────────────────────────────────────────────────────────
  Descrição:  Busca e sincroniza alerts e jams dos links Waze ativos.

  Fonte:      https://www.waze.com/row-partnerhub-api/partners/.../waze-feeds/...

  Destino:    waze_alerts
              waze_jams

  Uso:
    php bin/console app:fetch-waze-feed [opções]

  Opções:
    -p, --partner=ID      Processa só esse partner. Sem isso, todos.
    -t, --type=Alerts     Tipo do link: Alerts | Jams (default: Alerts).
    -l, --limit=1000      Limite de registros por resposta.
    -d, --dry-run         Não grava nada (simulação).
    -f, --force           Ignora a frequência configurada no partner.
        --one-partner     Processa só 1 partner vencido por execução.

  Exemplos:
    php bin/console app:fetch-waze-feed --force -vvv
    php bin/console app:fetch-waze-feed --partner=1 -vvv
    php bin/console app:fetch-waze-feed --dry-run -vvv

  Frequência sugerida:  1 min (agendador) — o comando respeita
                        partner.fetchFrequency internamente.

───────────────────────────────────────────────────────────────────────────
  app:fetch:waze:tvt
───────────────────────────────────────────────────────────────────────────
  Descrição:  Coleta e sincroniza dados de rotas TVT (Waze Traffic View).

  Fonte:      https://www.waze.com/row-partnerhub-api/feeds-tvt/...?id=...

  Destino:    waze_tvt_route
              waze_tvt_sub_route
              waze_tvt_irregularity
              waze_tvt_route_snapshot
              waze_tvt_user_on_jam

  Uso:
    php bin/console app:fetch:waze:tvt [opções]

  Opções:
        --partner=ID      Processa só esse partner.
        --dry-run         Não grava nada.
    -f, --force           Ignora a frequência configurada.

  Exemplos:
    php bin/console app:fetch:waze:tvt --force -vvv
    php bin/console app:fetch:waze:tvt --partner=1 -vvv

  Frequência sugerida:  1 min (partner.lastTvtFetchAt controla o throttle).

───────────────────────────────────────────────────────────────────────────
  app:fetch:cemaden:hidro
───────────────────────────────────────────────────────────────────────────
  Descrição:  Coleta observações hidrológicas CEMADEN (nível do rio) e
              também a chuva da estação hidrológica.

  Fontes:
    MedidaResource.php    → nível do rio (valor, offset, cotas)
    AcumuladoResource.php → chuva acumulada do rio

  Destino:    cemaden_hidro_observation
              (observation_type = 'level' para nível,
               observation_type = 'rain'  para chuva)

  Uso:
    php bin/console app:fetch:cemaden:hidro [opções]

  Opções:
        --partner=ID          Processa só esse partner.
        --min-interval=N      Intervalo mínimo em minutos (default: 10).
        --force               Ignora lastFetchedAt.
        --dry-run             Não grava nada.

  Exemplos:
    php bin/console app:fetch:cemaden:hidro --force -vvv
    php bin/console app:fetch:cemaden:hidro --min-interval=10 -vvv

  Frequência sugerida:  10 min.

───────────────────────────────────────────────────────────────────────────
  app:fetch:cemaden:pluviometric
───────────────────────────────────────────────────────────────────────────
  Descrição:  Coleta observações pluviométricas CEMADEN (mm de chuva puros,
              sem nível de rio). Usa o endpoint do Mapa Interativo.

  Fonte:      https://mapservices.cemaden.gov.br/MapaInterativoWS/resources/
              horario/{idEstacao}/{horas}

  Destino:    cemaden_pluviometric_observation

  Uso:
    php bin/console app:fetch:cemaden:pluviometric [opções]

  Opções:
        --partner=ID          Processa só esse partner.
        --min-interval=N      Intervalo mínimo em minutos (default: 10).
        --force               Ignora lastFetchedAt.
        --dry-run             Não grava nada.

  Exemplos:
    php bin/console app:fetch:cemaden:pluviometric --force -vvv
    php bin/console app:fetch:cemaden:pluviometric --dry-run -vvv

  Frequência sugerida:  10 min.
  Nota:  retorna tipicamente 24 registros (1 por hora) por dia do CEMADEN.

───────────────────────────────────────────────────────────────────────────
  app:weather:fetch-observations
───────────────────────────────────────────────────────────────────────────
  Descrição:  Busca observações climáticas atuais para cada WeatherLocation
              ativa.

  Fonte:      https://api.open-meteo.com/v1/forecast
              (grátis, sem API key; provider 'open-meteo')

  Destino:    weather_observation

  Uso:
    php bin/console app:weather:fetch-observations

  Opções:     nenhuma

  Exemplos:
    php bin/console app:weather:fetch-observations -vvv

  Frequência sugerida:  10 min (Open-Meteo atualiza 'current' a cada ~15 min).


═══════════════════════════════════════════════════════════════════════════
  2. COMANDOS DE USUÁRIO / CADASTRO
═══════════════════════════════════════════════════════════════════════════

───────────────────────────────────────────────────────────────────────────
  app:create:user
───────────────────────────────────────────────────────────────────────────
  Descrição:  Cria um usuário. Pode ou não estar vinculado a um partner
              existente. Aplica as regras de negócio da entidade User:
                - ROLE_ADMIN           → partner opcional (admin global)
                - ROLE_PARTNER_ADMIN   → partner OBRIGATÓRIO
                - ROLE_OPERATOR        → partner OBRIGATÓRIO
                - ROLE_VIEWER          → partner opcional

  Destino:    user

  Uso:
    php bin/console app:create:user --email=... --password=... [opções]

  Opções:
        --email=EMAIL           (obrigatório)
        --password=SENHA        (obrigatório)
        --name=NOME             (opcional)
        --phone=TELEFONE        (opcional)
        --roles=LISTA           Vírgula (default: ROLE_VIEWER)
        --partner-id=ID         Vincula ao partner com esse ID
        --partner-code=CODE     Vincula ao partner com esse code
        --list-partners         Lista partners existentes e encerra

  Exemplos:
    php bin/console app:create:user --list-partners

    php bin/console app:create:user --email="admin@trafik.com" --password="senha123" --name="Admin Global" --roles="ROLE_ADMIN"

    php bin/console app:create:user --email="joao@lafaiete.mg.gov.br" --password="senha123" --name="João" --roles="ROLE_PARTNER_ADMIN" --partner-id=1

    php bin/console app:create:user --email="viewer@trafik.com" --password="senha123" --roles="ROLE_VIEWER"

  ⚠ Atenção: no PowerShell, NÃO use "\" para continuar a linha.
              Use tudo numa linha só, ou backtick (`) no fim de cada linha.

───────────────────────────────────────────────────────────────────────────
  app:create:partner-user  (legado)
───────────────────────────────────────────────────────────────────────────
  Descrição:  Cria partner + user em um passo. Mantido por compatibilidade.
              Para criar só usuário, prefira app:create:user.

  Destino:    partner (se novo) + user

  Uso:
    php bin/console app:create:partner-user \
      --partner-name=... --partner-code=... --email=... --password=... --roles=...

  Opções:
        --partner-name=NOME
        --partner-code=CODE
        --email=EMAIL
        --password=SENHA
        --roles=LISTA           (default: ROLE_ADMIN)


═══════════════════════════════════════════════════════════════════════════
  3. COMANDOS DE DIAGNÓSTICO E DEBUG
═══════════════════════════════════════════════════════════════════════════

  php bin/console debug:scheduler
      Lista todos os RecurringMessage agendados e a próxima execução.

  php bin/console debug:router
      Lista todas as rotas da aplicação (ex.: /dashboard, /alerts).

  php bin/console debug:container [Service]
      Mostra informações de um service (ex.: WeatherObservationFetcher).

  php bin/console debug:twig
      Lista extensões e filtros Twig registrados (ex.: 'sp_date').

  php bin/console debug:event-dispatcher
      Lista todos os listeners registrados.

  php bin/console doctrine:schema:validate
      Confere se as entidades estão em sincronia com o banco.

  php bin/console doctrine:schema:update --dump-sql
      Mostra o SQL que seria executado sem alterar nada.

  php bin/console doctrine:query:sql "SELECT 1"
      Executa SQL avulso no banco.

  php bin/console lint:twig templates/
      Valida sintaxe de todos os templates Twig.

  php bin/console lint:yaml config/
      Valida sintaxe de todos os arquivos YAML.

  php bin/console cache:pool:list
      Lista os pools de cache disponíveis.

  php bin/console about
      Informações sobre o projeto (PHP, Symfony, ambiente).


═══════════════════════════════════════════════════════════════════════════
  4. COMANDOS DE MANUTENÇÃO
═══════════════════════════════════════════════════════════════════════════

  php bin/console cache:clear
      Limpa o cache do Symfony. Use sempre que alterar:
        - entidades
        - controllers
        - templates
        - config/*.yaml

  php bin/console doctrine:schema:update --force
      Aplica mudanças de schema baseadas nas entidades (dev only).

  php bin/console doctrine:migrations:diff
  php bin/console doctrine:migrations:migrate
      Alternativa versionada ao schema:update (produção).

  php bin/console messenger:stop-workers
      Envia sinal para o worker parar no próximo ciclo.

  php bin/console messenger:consume scheduler_default -vv
      Inicia o worker que processa os RecurringMessages do scheduler.
      (Ctrl+C para parar)

  php bin/console messenger:failed:show
      Lista mensagens que falharam no transporte.

  php bin/console messenger:failed:retry
      Reenvia mensagens falhadas.


═══════════════════════════════════════════════════════════════════════════
  5. AGENDAMENTO (Scheduler)
═══════════════════════════════════════════════════════════════════════════

  Arquivo:  src/Scheduler/MainSchedule.php

  Triggers configurados:

    every  1 min   app:fetch-waze-feed --no-interaction
    every  1 min   app:fetch:waze:tvt --no-interaction
    every 10 min   app:fetch:cemaden:hidro --no-interaction
    every 10 min   app:fetch:cemaden:pluviometric --no-interaction
    every 10 min   app:weather:fetch-observations --no-interaction

  Como funciona:
    O agendador do Symfony é processado pelo worker do Messenger
    ('scheduler_default'). O worker precisa estar rodando para os
    comandos dispararem.

  Dev (local):
    php bin/console messenger:consume scheduler_default -vv

  Prod (Hostinger): veja seção 6.


═══════════════════════════════════════════════════════════════════════════
  6. CRON / hPanel HOSTINGER
═══════════════════════════════════════════════════════════════════════════

  Em hospedagem compartilhada, o worker persistente não roda. Use Cron Job
  para "acordar" o scheduler periodicamente.

  hPanel → Avançado → Cron Jobs → Criar novo

  Comando (a cada minuto):
    /usr/bin/php /home/USER/domains/DOMINIO/public_html/bin/console \
      messenger:consume scheduler_default \
      --time-limit=55 --memory-limit=128M --no-interaction \
      >> /home/USER/domains/DOMINIO/public_html/var/log/messenger.log 2>&1

  Frequência:
    * * * * *        (a cada minuto)

  Substitua:
    USER    → seu usuário Hostinger (ex.: u629736858)
    DOMINIO → seu domínio (ex.: trafik.com)

  Verificar logs:
    tail -f ~/domains/DOMINIO/public_html/var/log/messenger.log

  Alternativa (sem messenger, chamando os comandos direto):
    * * * * *  cd /home/USER/domains/DOMINIO/public_html && php bin/console app:fetch-waze-feed --no-interaction
    * * * * *  cd /home/USER/domains/DOMINIO/public_html && php bin/console app:fetch:waze:tvt --no-interaction
    */10 * * * *  cd /home/USER/domains/DOMINIO/public_html && php bin/console app:fetch:cemaden:hidro --no-interaction
    */10 * * * *  cd /home/USER/domains/DOMINIO/public_html && php bin/console app:fetch:cemaden:pluviometric --no-interaction
    */10 * * * *  cd /home/USER/domains/DOMINIO/public_html && php bin/console app:weather:fetch-observations --no-interaction


═══════════════════════════════════════════════════════════════════════════
  7. TABELAS DO BANCO — ORIGEM × DESTINO
═══════════════════════════════════════════════════════════════════════════

  ┌──────────────────────────────────┬───────────────────────────────────────┐
  │ COMANDO                          │ TABELAS                               │
  ├──────────────────────────────────┼───────────────────────────────────────┤
  │ app:fetch-waze-feed              │ waze_alerts                           │
  │                                  │ waze_jams                             │
  ├──────────────────────────────────┼───────────────────────────────────────┤
  │ app:fetch:waze:tvt               │ waze_tvt_route                        │
  │                                  │ waze_tvt_sub_route                    │
  │                                  │ waze_tvt_irregularity                 │
  │                                  │ waze_tvt_route_snapshot               │
  │                                  │ waze_tvt_user_on_jam                  │
  ├──────────────────────────────────┼───────────────────────────────────────┤
  │ app:fetch:cemaden:hidro          │ cemaden_hidro_station_link (config)   │
  │                                  │ cemaden_hidro_observation             │
  │                                  │   → type 'level' = nível do rio       │
  │                                  │   → type 'rain'  = chuva local        │
  ├──────────────────────────────────┼───────────────────────────────────────┤
  │ app:fetch:cemaden:pluviometric   │ cemaden_station_link (config)         │
  │                                  │ cemaden_pluviometric_observation      │
  ├──────────────────────────────────┼───────────────────────────────────────┤
  │ app:weather:fetch-observations   │ weather_location (config)             │
  │                                  │ weather_observation                   │
  ├──────────────────────────────────┼───────────────────────────────────────┤
  │ app:create:user                  │ user                                  │
  │ app:create:partner-user          │ partner + user                        │
  └──────────────────────────────────┴───────────────────────────────────────┘

  Nota sobre chuva no dashboard:
    O painel "Chuva" do dashboard UNE duas fontes:
      - cemaden_pluviometric_observation.accumulated_rainfall
      - cemaden_hidro_observation.rain (type = 'rain')
    Cada linha é marcada com badge "Auto" ou "Hidro".


═══════════════════════════════════════════════════════════════════════════
  8. TROUBLESHOOTING RÁPIDO
═══════════════════════════════════════════════════════════════════════════

  ─── Erro: "Undefined method..." em entity ────────────────────────────────
      Um método foi chamado mas não existe. Rodar:
        php bin/console cache:clear
      Se persistir, confira a entity real em src/Entity.

  ─── Erro: "has no field X" em repositório ────────────────────────────────
      Método inexistente no repository. Criar o método ou usar findOneBy.

  ─── Erro: timezone / horários adiantados ─────────────────────────────────
      Verificar:
        php -r "echo date_default_timezone_get();"
      Esperado: UTC. Se aparecer Europe/Berlin etc., corrigir php.ini.

  ─── Erro: 500 no dashboard ───────────────────────────────────────────────
      Rodar com -vvv e verificar:
        php bin/console cache:clear -vvv
        php bin/console doctrine:schema:validate -vvv

  ─── Erro: scheduler não dispara em prod ──────────────────────────────────
      Verificar Cron Job no hPanel → Cron Jobs.
      Ver logs: var/log/messenger.log

  ─── Erro: "Column not found" no banco ────────────────────────────────────
      Faltou aplicar migration/schema update:
        php bin/console doctrine:schema:update --dump-sql
        php bin/console doctrine:schema:update --force

  ─── Erro: "Invalid URL: scheme is missing" ───────────────────────────────
      Alguma URL configurada em partner/station/link está vazia ou sem
      http(s)://. Verificar:
        SELECT id, name, url FROM partner_api_link WHERE url NOT LIKE 'http%';
        SELECT id, station_name, base_url FROM cemaden_station_link;
        SELECT id, station_name, base_url, level_base_url FROM cemaden_hidro_station_link;

  ─── Verificar se os comandos estão todos registrados ─────────────────────
        php bin/console list app
      Deve mostrar:
        app:create:partner-user
        app:create:user
        app:fetch:cemaden:hidro
        app:fetch:cemaden:pluviometric
        app:fetch:waze:tvt
        app:fetch-waze-feed
        app:weather:fetch-observations


═══════════════════════════════════════════════════════════════════════════
  FLUXO RECOMENDADO DE TESTE (do zero)
═══════════════════════════════════════════════════════════════════════════

  # 1. Sanidade
  php bin/console cache:clear -vvv
  php bin/console doctrine:schema:validate -vvv
  php bin/console debug:scheduler

  # 2. Coleta de cada fonte
  php bin/console app:fetch-waze-feed --force -vvv
  php bin/console app:fetch:waze:tvt --force -vvv
  php bin/console app:fetch:cemaden:hidro --force -vvv
  php bin/console app:fetch:cemaden:pluviometric --force -vvv
  php bin/console app:weather:fetch-observations -vvv

  # 3. Usuário de teste
  php bin/console app:create:user --list-partners
  php bin/console app:create:user --email="teste@exemplo.com" --password="teste123" --roles="ROLE_ADMIN"

  # 4. Worker (deixe 2 min e Ctrl+C)
  php bin/console messenger:consume scheduler_default -vv

  # 5. Verificar dados no banco
  mysql -u root -p
  USE u629736858_trafik;
  SELECT 'alerts' AS src, COUNT(*) FROM waze_alerts
  UNION ALL SELECT 'jams', COUNT(*) FROM waze_jams
  UNION ALL SELECT 'weather', COUNT(*) FROM weather_observation
  UNION ALL SELECT 'hydro', COUNT(*) FROM cemaden_hidro_observation
  UNION ALL SELECT 'pluvio', COUNT(*) FROM cemaden_pluviometric_observation;


═══════════════════════════════════════════════════════════════════════════
  FIM
═══════════════════════════════════════════════════════════════════════════
