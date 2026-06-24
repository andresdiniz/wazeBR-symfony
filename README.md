# WazePortalBR — Symfony 7.2 + PHP 8.2

> Reescrita completa do portal WazeBR em **Symfony 7.2** (LTS) com **PHP 8.2+**,
> usando Doctrine ORM, Symfony Messenger, Symfony Scheduler, Symfony Security e Mailer.

## Funcionalidades

- 🚨 **Coleta de alertas Waze CCP** — substitui `wazejob.php`
- 🚗 **Coleta de congestionamentos Waze** — substitui `wazejobtraficc.php`
- 🌧️ **Integração CEMADEN** — substitui `dadoscemadem.php` e `hidrologicocemadem*.php`
- 📊 **Dashboard interativo** com mapa Leaflet — substitui `index.php`
- 🔔 **Notificações** em tempo real — substitui `worker_notifications.php`
- 📧 **Relatório diário por e-mail** — substitui `send_daily_report.php`
- 📁 **Exportação JSON/XML** — substitui `gerar_json.php` e `gerar_xml.php`
- 🔐 **Autenticação completa** com reset de senha — substitui `login.php`, `logout.php`, `redefinir_senha.php`
- 🌐 **API interna** (`/api/*`) e **API externa** pública (`/api/v1/*`) — substitui `api.php` e `api_externa.php`
- 🩺 **Health check** — substitui `health_sys.php`
- 🗑️ **Purge automático** — substitui `limpar_tabelas.php`

## Stack

| Componente | Tecnologia |
|---|---|
| Framework | Symfony 7.2 LTS |
| PHP | 8.2+ |
| ORM | Doctrine ORM 3 |
| Filas | Symfony Messenger + Redis |
| Agendador | Symfony Scheduler |
| Auth | Symfony Security |
| Templates | Twig 3 |
| E-mail | Symfony Mailer |
| HTTP Client | Symfony HttpClient |
| Cache | Redis (Predis) |

## Instalação

```bash
# 1. Clonar e instalar dependências
git clone https://github.com/andresdiniz/wazebr-symfony.git
cd wazebr-symfony
composer install

# 2. Configurar ambiente
cp .env.example .env
# editar .env com suas credenciais

# 3. Criar banco e rodar migrations
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

# 4. Iniciar servidor
php bin/console server:start

# 5. Iniciar workers (em processo separado)
php bin/console messenger:consume async scheduler_default --time-limit=3600
```

## Comandos CLI

```bash
# Coleta manual de dados
php bin/console waze:collect
php bin/console waze:collect --only=alerts
php bin/console waze:collect --only=cemaden --state=SP

# Purge de dados antigos
php bin/console waze:purge --days=30
php bin/console waze:purge --dry-run

# Health check
php bin/console waze:health
```

## Estrutura

```
src/
├── Controller/       # AuthController, DashboardController, ApiController, ApiExternaController
├── Entity/           # User, WazeAlert, WazeTrafficJam, CemadenData, Notification, ActivityLog
├── Repository/       # Queries DQL otimizadas por domínio
├── Service/          # WazeApiService, CemadenService, NotificationService, DashboardService
├── Message/          # DTOs de mensagens para o Messenger
├── MessageHandler/   # Handlers assíncronos por domínio
├── Command/          # waze:collect, waze:purge, waze:health
├── Scheduler/        # AppScheduler — todos os cron jobs centralizados
└── EventSubscriber/  # LoginSubscriber — auditoria de acessos
```
