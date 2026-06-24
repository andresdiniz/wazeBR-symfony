# Cron Reference — WazeBR Symfony

Este arquivo substitui os cron jobs que chamavam scripts PHP avulsos.
Todos os comandos usam `php bin/console`.

## Comandos disponíveis

| Comando | Equivalente antigo | Frequência sugerida |
|---|---|---|
| `waze:collect:alerts` | `wazejob.php` | A cada 5 min |
| `waze:collect:traffic` | `wazejobtraficc.php` | A cada 5 min |
| `cemaden:collect MG` | `dadoscemadem.php` | A cada 15 min |
| `cemaden:collect MG` (hidrológico) | `hidrologicocemadem*.php` | A cada 30 min |
| `waze:notify:high-risk` | `worker_notifications.php` | A cada 10 min |
| `waze:report:daily` | `send_daily_report.php` | Diário às 06:00 |

## Exemplo de crontab

```cron
# Coleta alertas Waze
*/5 * * * * /usr/bin/php /var/www/wazebr/bin/console waze:collect:alerts --env=prod >> /var/log/wazebr-alerts.log 2>&1

# Coleta congestionamentos Waze
*/5 * * * * /usr/bin/php /var/www/wazebr/bin/console waze:collect:traffic --env=prod >> /var/log/wazebr-traffic.log 2>&1

# Coleta CEMADEN
*/15 * * * * /usr/bin/php /var/www/wazebr/bin/console cemaden:collect MG --env=prod >> /var/log/wazebr-cemaden.log 2>&1

# Notificações de alto risco
*/10 * * * * /usr/bin/php /var/www/wazebr/bin/console waze:notify:high-risk --env=prod >> /var/log/wazebr-notify.log 2>&1

# Relatório diário às 06:00
0 6 * * * /usr/bin/php /var/www/wazebr/bin/console waze:report:daily --env=prod >> /var/log/wazebr-report.log 2>&1
```

## Geração de JSON/XML sob demanda

```bash
# Gerar JSON
php bin/console waze:collect:alerts --json=/var/www/wazebr/public/export/alertas.json

# Gerar XML
php bin/console waze:collect:alerts --xml=/var/www/wazebr/public/export/alertas.xml
```
