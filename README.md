# WazeBR

Plataforma de monitoramento de trânsito e alertas hidrológicos para a comunidade brasileira do Waze, construída com **Symfony 7.4** e **PHP 8.5**.

## Funcionalidades

- **Feeds de parceiros Waze**: coleta de alertas e congestionamentos (Partner Feed) e de tempos de viagem (TVT)
- **Previsão de congestionamento** a partir dos dados TVT
- **CEMADEN**: coleta de dados hidrológicos e pluviométricos
- **Semáforos**: integração via Modbus TCP, NTCIP e SensorThings
- **Painel do operador** com mapa ao vivo (Leaflet) e atualização em tempo real (Mercure)
- **Notificações e relatórios** por e-mail
- **Agendamento** de coletas com Symfony Scheduler, executado via Supervisor

## Requisitos

- PHP **8.5.12 ou superior** (exigido pelo `composer.json`)
- Extensões PHP: `json`, `simplexml`, `mbstring`, `intl`, `curl`, `pdo_mysql`
- MariaDB 10.4+ ou MySQL 8.0+ (produção: MariaDB 10.4.32)
- Composer 2

## Instalação

```bash
git clone https://github.com/andresdiniz/wazeBR-symfony.git
cd wazeBR-symfony

composer install

# Variáveis de ambiente (segredos reais vão em .env.local)
cp .env.example .env

php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

symfony server:start
# ou: php -S localhost:8000 -t public
```

Atalho: `make setup` instala, cria o banco, roda as migrations e carrega as fixtures. Rode `make help` para ver todos os alvos.

## Variáveis de ambiente

Veja `.env.example`. As principais:

`APP_ENV`, `APP_SECRET`, `DATABASE_URL`, `MESSENGER_TRANSPORT_DSN`, `MAILER_DSN`, `SENDER_EMAIL`, `APP_NAME`, `WAZEBR_NOTIFICATIONS_WORKER_URL`, `WAZEBR_NOTIFICATIONS_WEBHOOK_TOKEN`, `CRON_TOKEN`, `SCHEDULER_LOG_LEVEL`, `CRON_PHP_BINARY`

O `DATABASE_URL` deve informar a versão do servidor, por exemplo:
`mysql://usuario:senha@127.0.0.1:3306/wazebr?serverVersion=mariadb-10.4.32&charset=utf8mb4`

## Comandos

```bash
php bin/console app:fetch-partner-feeds              # feeds de parceiros
php bin/console app:fetch-waze-feed                  # feed Waze
php bin/console app:fetch:waze:tvt                   # tempos de viagem (TVT)
php bin/console app:fetch:cemaden:hidro              # CEMADEN hidrológico
php bin/console app:fetch:cemaden:pluviometric       # CEMADEN pluviométrico
php bin/console app:weather:fetch-observations       # observações meteorológicas
php bin/console app:tvt:refresh-congestion-forecast  # atualiza previsão de congestionamento
php bin/console app:tvt:evaluate-congestion-forecast # avalia a previsão
php bin/console app:routes:rollup-hourly             # consolida rotas por hora
php bin/console app:partner-feed:expire              # expira itens antigos dos feeds
php bin/console app:traffic-light:poll               # consulta semáforos
php bin/console app:create:user                      # cria usuário
php bin/console app:create:partner-user              # cria usuário parceiro
```

O agendamento fica em `src/Scheduler/MainSchedule.php`; as configurações do Supervisor estão em `supervisor/`.

## Qualidade e testes

```bash
make test      # PHPUnit (usa SQLite, ver .env.test)
make phpstan   # análise estática, nível 6
make lint      # YAML, Twig e container
make qa        # lint + PHPStan + testes
```

O CI (`.github/workflows/ci.yml`) roda em PHP 8.5: valida o `composer.json`, checa os requisitos de plataforma, faz o lint de sintaxe, e executa PHPUnit e PHPStan.

## Deploy

O workflow `.github/workflows/cd.yml` roda a cada push na `main`: confere se o servidor tem PHP 8.5.12+, envia os arquivos por FTP (Hostinger) e executa as migrations e o aquecimento de cache por SSH.

## Observação sobre Docker

O `compose.yaml` ainda é o gerado pelo Symfony Flex (PostgreSQL, Mercure e Mailpit). O banco usado pelo projeto é MariaDB/MySQL, então ajuste o serviço de banco antes de usá-lo.

## Licença

MIT. Veja [LICENSE](LICENSE).

## Contato

André Diniz — [@andresdiniz](https://github.com/andresdiniz)
