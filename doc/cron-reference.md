# Cron Reference — WazeBR Symfony

## Hospedagem compartilhada (Hostinger) — caminho principal

Nesta hospedagem **não há Supervisor** rodando, então o Symfony Scheduler
(`WazeFeedSchedule` / transport `scheduler_waze_feed`) nunca é consumido —
as mensagens ficam paradas na fila. Por isso, aqui a coleta é feita
**diretamente pelo `cron.php`**, que dispara cada comando via
`bin/console`, com lock próprio, timeout e log — sem depender do
Messenger nem do Supervisor.

Configure **uma entrada por job** no Agendador de Tarefas da Hostinger,
todas chamando o mesmo arquivo, mudando apenas o argumento:

```cron
# Alertas e congestionamentos Waze (PartnerHub) — a cada 5 min
*/5 * * * * /usr/local/bin/php8.5 /home/uXXXXXXXXX/domains/SEUDOMINIO/public_html/trafik/cron.php waze_feed >> /home/uXXXXXXXXX/logs/cron_dispatcher.log 2>&1

# Tempos de rota e irregularidades Waze — a cada 5 min
*/5 * * * * /usr/local/bin/php8.5 /home/uXXXXXXXXX/domains/SEUDOMINIO/public_html/trafik/cron.php waze_routes >> /home/uXXXXXXXXX/logs/cron_dispatcher.log 2>&1

# Snapshots de rotas TVT — a cada 5 min
*/5 * * * * /usr/local/bin/php8.5 /home/uXXXXXXXXX/domains/SEUDOMINIO/public_html/trafik/cron.php waze_tvt >> /home/uXXXXXXXXX/logs/cron_dispatcher.log 2>&1

# Pluviométrico CEMADEN — a cada 15 min
*/15 * * * * /usr/local/bin/php8.5 /home/uXXXXXXXXX/domains/SEUDOMINIO/public_html/trafik/cron.php cemaden >> /home/uXXXXXXXXX/logs/cron_dispatcher.log 2>&1

# Hidrológico CEMADEN (nível de rios, todos os parceiros) — a cada 30 min
*/30 * * * * /usr/local/bin/php8.5 /home/uXXXXXXXXX/domains/SEUDOMINIO/public_html/trafik/cron.php cemaden_hydro >> /home/uXXXXXXXXX/logs/cron_dispatcher.log 2>&1

# Notificações de alertas críticos + CEMADEN, multi-tenant — a cada 10 min
*/10 * * * * /usr/local/bin/php8.5 /home/uXXXXXXXXX/domains/SEUDOMINIO/public_html/trafik/cron.php notify >> /home/uXXXXXXXXX/logs/cron_dispatcher.log 2>&1

# Notificações legadas de alto risco (single-tenant) — a cada 10 min
*/10 * * * * /usr/local/bin/php8.5 /home/uXXXXXXXXX/domains/SEUDOMINIO/public_html/trafik/cron.php notify_high_risk >> /home/uXXXXXXXXX/logs/cron_dispatcher.log 2>&1

# Relatório diário por e-mail — todo dia às 06:00
0 6 * * * /usr/local/bin/php8.5 /home/uXXXXXXXXX/domains/SEUDOMINIO/public_html/trafik/cron.php report >> /home/uXXXXXXXXX/logs/cron_dispatcher.log 2>&1
```

> Ajuste `/usr/local/bin/php8.5` e o caminho do projeto conforme o painel
> da Hostinger. Se quiser trocar o binário do PHP sem editar `cron.php`,
> defina a variável de ambiente `CRON_PHP_BINARY` no painel (Agendador
> de Tarefas → Variáveis de ambiente, se disponível) ou direto na linha
> de cron: `CRON_PHP_BINARY=/usr/bin/php8.3 php8.5 .../cron.php waze_feed`.

### O que o `cron.php` garante em cada chamada

- **Lock por job** (`var/cron-locks/<job>.lock`): se a execução anterior
  do mesmo job ainda estiver rodando (ex.: feed lento), a nova chamada
  é pulada silenciosamente — nunca roda em paralelo consigo mesma.
- **Timeout por job**: cada job tem um limite de tempo (ex.: 50s para
  coletas de 5 em 5 min); se passar disso, o processo é encerrado
  (SIGTERM, depois SIGKILL) para nunca travar a próxima execução.
- **Log individual**: `var/log/cron_<job>.log`, com rotação simples
  quando passa de 2MB (mantém 1 arquivo anterior, `.log.1`).
- **Status agregado**: `var/log/cron_status.json` é atualizado a cada
  execução (status, exit code, duração) e é exposto por
  `GET /cron/run?token=SEU_CRON_TOKEN` — use como health-check externo
  (UptimeRobot, painel, etc).

### Debug manual

```bash
# Roda um job específico na hora, vendo a saída direto no terminal
php cron.php waze_feed

# Roda todos os jobs em sequência (não recomendado como rotina — é para debug)
php cron.php all
```

---

## Alternativa: disparo via URL (`/cron/trigger/{job}`)

Se preferir configurar o cron da Hostinger como "chamar uma URL" (ou usar
um serviço externo de ping, tipo cron-job.org / EasyCron) em vez de rodar
um binário PHP diretamente, use as URLs abaixo. **Antes de configurar em
produção, teste uma chamada real** — vários planos de hospedagem
compartilhada liberam `exec()` no PHP-CLI mas bloqueiam no PHP que roda
via Apache/LiteSpeed; se for o caso, o endpoint responde com erro
explicando isso, e você deve usar o modo CLI acima em vez deste.

A resposta HTTP retorna na hora (o job roda em background no servidor),
então não sofre com o timeout do servidor web mesmo em jobs mais longos
como `report`.

Substitua `SEUDOMINIO.com.br` e `SEU_CRON_TOKEN` (valor de `CRON_TOKEN`
no `.env`):

```
https://SEUDOMINIO.com.br/cron/trigger/waze_feed?token=SEU_CRON_TOKEN        (a cada 5 min)
https://SEUDOMINIO.com.br/cron/trigger/waze_routes?token=SEU_CRON_TOKEN      (a cada 5 min)
https://SEUDOMINIO.com.br/cron/trigger/waze_tvt?token=SEU_CRON_TOKEN         (a cada 5 min)
https://SEUDOMINIO.com.br/cron/trigger/cemaden?token=SEU_CRON_TOKEN          (a cada 15 min)
https://SEUDOMINIO.com.br/cron/trigger/cemaden_hydro?token=SEU_CRON_TOKEN    (a cada 30 min)
https://SEUDOMINIO.com.br/cron/trigger/notify?token=SEU_CRON_TOKEN           (a cada 10 min)
https://SEUDOMINIO.com.br/cron/trigger/notify_high_risk?token=SEU_CRON_TOKEN (a cada 10 min)
https://SEUDOMINIO.com.br/cron/trigger/report?token=SEU_CRON_TOKEN           (diário às 06:00)
```

Pra acompanhar o resultado de cada disparo, use o health-check (mostra a
última execução de cada job, vinda do mesmo `cron_status.json` que o
modo CLI gera):

```
https://SEUDOMINIO.com.br/cron/run?token=SEU_CRON_TOKEN
```

**Importante:** escolha CLI **ou** URL para cada job, nunca as duas —
configurar as duas formas pro mesmo job dispara a coleta em dobro.

---

## VPS / hospedagem com Supervisor — caminho alternativo

Se em algum momento o projeto for para uma VPS com Supervisor
disponível, o caminho recomendado passa a ser o Symfony Scheduler +
Messenger (ver `supervisor/waze_scheduler.conf`), **não** o `cron.php`.
Os dois caminhos não devem rodar ao mesmo tempo para os mesmos jobs —
isso causaria coleta duplicada. Escolha um ou outro por ambiente.

| Comando (`bin/console`)     | Equivalente antigo         | Frequência sugerida |
|------------------------------|-----------------------------|----------------------|
| `app:waze:collect-feed`      | `wazejob.php`               | A cada 5 min         |
| `app:waze:collect-routes`    | —                            | A cada 5 min         |
| `app:waze:collect-tvt`       | `wazejobtraficc.php`        | A cada 5 min         |
| `cemaden:collect`            | `dadoscemadem.php`          | A cada 15 min        |
| `cemaden:collect-hydro`      | `hidrologicocemadem*.php`   | A cada 30 min        |
| `notifications:dispatch`     | —                            | A cada 10 min        |
| `waze:notify:high-risk`      | `worker_notifications.php`  | A cada 10 min        |
| `waze:report:daily`          | `send_daily_report.php`     | Diário às 06:00      |

## Geração de JSON/XML sob demanda

```bash
# Gerar JSON
php bin/console waze:collect:alerts --json=/var/www/wazebr/public/export/alertas.json

# Gerar XML
php bin/console waze:collect:alerts --xml=/var/www/wazebr/public/export/alertas.xml
```
