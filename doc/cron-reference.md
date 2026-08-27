# Cron Reference — WazeBR Symfony

## Hospedagem compartilhada (Hostinger) — caminho principal

Nesta hospedagem **não há Supervisor** rodando, então o Symfony Scheduler
(`WazeFeedSchedule` / transport `scheduler_waze_feed`) nunca é consumido —
as mensagens ficam paradas na fila. Por isso, aqui a coleta é feita
**diretamente pelo `cron.php`**, que dispara cada comando via
`bin/console`, com lock próprio, timeout e log — sem depender do
Messenger nem do Supervisor.

Duas formas de disparar, dependendo do que o painel de cron permitir
configurar:

- **Modo CLI** — Agendador de Tarefas rodando `php cron.php <job>` direto.
- **Modo URL** — cron configurado como "chamar uma URL" (ex.: `wget`),
  batendo em `/cron/trigger/{job}`.

Nunca configure os dois modos para o **mesmo job** ao mesmo tempo —
duplica a coleta.

---

## Modo CLI

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
> da Hostinger.

### Debug manual (CLI)

```bash
# Roda um job específico na hora, vendo a saída direto no terminal
php cron.php waze_feed

# Roda TODOS os jobs em sequência (job especial "all")
# — não recomendado como entrada de cron regular, é só para debug
php cron.php all
```

---

## Modo URL (`/cron/trigger/{job}`) — via wget ou serviço externo de ping

Use se o painel da Hostinger só permitir configurar o cron como "chamar
uma URL", ou se preferir um serviço externo (cron-job.org, EasyCron, etc).

**Pré-requisito:** `CRON_PHP_BINARY` precisa estar definido no `.env`
com o caminho do PHP-**CLI** (não confunda com o PHP do Apache/CGI —
ver nota abaixo). Sem isso, o endpoint recusa disparar e retorna erro
explicando o motivo.

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

Exemplo de linha de cron usando `wget`:

```cron
*/5 * * * * wget -q -O /dev/null "https://SEUDOMINIO.com.br/cron/trigger/waze_feed?token=SEU_CRON_TOKEN"
```

A resposta HTTP retorna na hora (o job roda em background no servidor),
então não sofre com o timeout do servidor web mesmo em jobs mais longos
como `report`.

### Debug manual (URL)

```
# Roda um job específico
https://SEUDOMINIO.com.br/cron/trigger/waze_feed?token=SEU_CRON_TOKEN

# Roda TODOS os jobs em sequência (job especial "all")
# — não recomendado como entrada de cron regular, é só para debug
https://SEUDOMINIO.com.br/cron/trigger/all?token=SEU_CRON_TOKEN
```

Pra acompanhar o resultado de qualquer disparo (CLI ou URL), use o
health-check — mostra a última execução de cada job, vinda do
`var/log/cron_status.json`:

```
https://SEUDOMINIO.com.br/cron/run?token=SEU_CRON_TOKEN
```

### Nota sobre `CRON_PHP_BINARY` no Windows/XAMPP

Sob Apache/mod_php, `PHP_BINARY` **não** aponta pro PHP-CLI — resolve
pro binário do próprio SAPI web (por isso não é usado como fallback no
código). Descubra o caminho certo no terminal:

```cmd
where php
```

E defina no `.env.local`:
```
CRON_PHP_BINARY="C:\xampp\php\php.exe"
```

Na Hostinger, geralmente é algo como `/usr/local/bin/php8.5` — confirme
no painel qual caminho de PHP-CLI eles disponibilizam.

---

## VPS / hospedagem com Supervisor — caminho alternativo

Se em algum momento o projeto for para uma VPS com Supervisor
disponível, o caminho recomendado passa a ser o Symfony Scheduler +
Messenger (ver `supervisor/waze_scheduler.conf`), **não** o `cron.php`.
Os dois caminhos não devem rodar ao mesmo tempo para os mesmos jobs —
isso causaria coleta duplicada. Escolha um ou outro por ambiente.

| Comando (`bin/console`)     | Frequência sugerida |
|------------------------------|----------------------|
| `app:waze:collect-feed`      | A cada 5 min         |
| `app:waze:collect-routes`    | A cada 5 min         |
| `app:waze:collect-tvt`       | A cada 5 min         |
| `cemaden:collect`            | A cada 15 min        |
| `cemaden:collect-hydro`      | A cada 30 min        |
| `notifications:dispatch`     | A cada 10 min        |
| `waze:notify:high-risk`      | A cada 10 min        |
| `waze:report:daily`          | Diário às 06:00      |

## Geração de JSON/XML sob demanda

```bash
# Gerar JSON
php bin/console waze:collect:alerts --json=/var/www/wazebr/public/export/alertas.json

# Gerar XML
php bin/console waze:collect:alerts --xml=/var/www/wazebr/public/export/alertas.xml
```
