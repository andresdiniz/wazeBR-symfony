# TrafikHub — Symfony 7.4 / PHP 8.2

Plataforma de inteligência de tráfego urbano com monitoramento em tempo real, alertas, dados hidrológicos e envio de eventos para o mapa, integrada com a API Waze Data For Cities e CEMADEN.

## Stack

- **PHP 8.2+**
- **Symfony 7.4 LTS**
- **Doctrine ORM 3.x**
- **MySQL 8.0+**
- **Twig 3.x**
- **Leaflet.js** (mapas)
- **Symfony Mailer** (relatórios e reset de senha)
- **Symfony HttpClient** (integração com Waze e CEMADEN)

## Instalação

```bash
# 1. Clonar
git clone https://github.com/andresdiniz/wazeBR-symfony.git
cd wazeBR-symfony

# 2. Instalar dependências
composer install

# 3. Configurar ambiente
cp .env.example .env
# Edite .env com suas credenciais

# 4. Criar banco de dados e rodar migrations
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

# 5. Criar primeiro usuário (via Doctrine Fixtures ou console)
php bin/console doctrine:fixtures:load

# 6. Iniciar servidor de desenvolvimento
symfony server:start
# ou
php -S localhost:8000 -t public/
```

## Comandos disponíveis

| Comando | Descrição |
|---|---|
| `waze:collect:alerts` | Coleta alertas da API Waze |
| `waze:collect:traffic` | Coleta congestionamentos da API Waze |
| `cemaden:collect [UF]` | Coleta dados do CEMADEN por estado |
| `waze:notify:high-risk` | Gera notificações para alertas de alto risco |
| `waze:report:daily` | Envia relatório diário por e-mail |

Ver `doc/cron-reference.md` para configuração completa de crontab.

## Estrutura

```
src/
├── Controller/      AuthController, DashboardController, ApiController, ApiExternaController, CifsEventController, HealthController
├── Entity/          User, WazeAlert, WazeTrafficJam, CemadenData, Notification, ActivityLog
├── Repository/      6 repositories com queries customizadas
├── Service/         WazeApiService, CemadenService, NotificationService, DashboardService, HealthCheckService
└── Command/         5 console commands
config/
migrations/      6 migrations (users → activity_log)
templates/       Twig (base, auth, dashboard, mapas, CEMADEN)
doc/             cron-reference.md
```

## Variáveis de ambiente

Ver `.env.example` para a lista completa. As principais:

| Variável | Descrição |
|---|---|
| `DATABASE_URL` | DSN do banco MySQL |
| `MAILER_DSN` | DSN SMTP |
| `WAZE_API_URL` | URL da API Waze Data For Cities |
| `WAZE_API_KEY` | Chave da API Waze |
| `WAZE_BBOX` | Bounding box geográfica (`lat_min,lng_min,lat_max,lng_max`) |
| `CEMADEN_API_URL` | URL da API CEMADEN |
| `CEMADEN_API_KEY` | Chave da API CEMADEN |
| `API_EXTERNA_TOKEN` | Token para acesso à API externa (`X-Api-Token`) |

## Segurança

- Autenticação via form\_login com CSRF protection
- Reset de senha por e-mail com token temporário
- API externa protegida por `X-Api-Token` no header
- `.env` **não** é versionado (`.gitignore`)

## Licença

Proprietário — Todos os direitos reservados.
