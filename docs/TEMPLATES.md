# Templates Twig

Este documento lista todos os templates Twig do projeto, organizados por controller.

## Estrutura de Diretóº¡²rios

```
templates/
├── base.html.twig              # Layout base (Bootstrap 5)
├── layouts/
│   ├── dashboard.html.twig     # Layout para áæ¡²rea do usuáº¡rio
│   └── admin.html.twig         # Layout para admin
├── partials/
│   ├── _navbar.html.twig       # Barra de navegaç°µo superior
│   └── _sidebar.html.twig      # Menu lateral
├── auth/
│   ├── login.html.twig
│   ├── reset_password_check_email.html.twig
│   ├── reset_password_request.html.twig
│   └── reset_password_reset.html.twig
├── dashboard/
│   └── index.html.twig         # Dashboard principal
├── home/
│   └── index.html.twig         # Páº¡gina inicial
├── alert/
│   └── index.html.twig         # Lista de alertas
├── traffic_jam/
│   └── index.html.twig         # Lista de engarrafamentos
├── notification/
│   └── index.html.twig         # Lista de notificaç°µes
├── operator/
│   └── index.html.twig         # Lista de operadores
├── account/
│   └── settings.html.twig      # Configuraç°µes da conta
├── admin/
│   └── dashboard.html.twig     # Dashboard admin
├── api/
│   └── docs.html.twig          # Documentaç°µo da API
└── health/
    └── index.html.twig         # Health check
```

## Templates por Controller

### AuthController
- `templates/auth/login.html.twig` - Formuláº¡rio de login
- `templates/auth/reset_password_*.html.twig` - Fluxo de recuperaç°µo de senha

### DashboardController
- `templates/dashboard/index.html.twig` - Dashboard com cards e listas de alertas, engarrafamentos e rotas

### HomeController
- `templates/home/index.html.twig` - Páº¡gina inicial páº¡blica

### AlertController
- `templates/alert/index.html.twig` - Lista de alertas em tabela

### TrafficJamController
- `templates/traffic_jam/index.html.twig` - Lista de engarrafamentos em tabela

### NotificationController
- `templates/notification/index.html.twig` - Lista de notificaç°µes

### OperatorController
- `templates/operator/index.html.twig` - Lista de operadores

### AccountSettingsController
- `templates/account/settings.html.twig` - Formuláº¡rios de perfil e senha

### AdminDashboardController
- `templates/admin/dashboard.html.twig` - Dashboard administrativo com métricas

### ApiController
- `templates/api/docs.html.twig` - Documentaç°µo da API

### HealthController
- `templates/health/index.html.twig` - Páº¡gina de health check

## Layouts

### base.html.twig
Layout base com Bootstrap 5.3 e Bootstrap Icons. Define estrutura HTML e carrega CSS/JS.

### layouts/dashboard.html.twig
Extende `base.html.twig`, inclui navbar e sidebar para áæ¡²rea logada.

### layouts/admin.html.twig
Similar ao dashboard, mas para áæ¡²rea administrativa.

## Partials

### _navbar.html.twig
Navbar superior com logo e dropdown do usuáº¡rio (email, configuraç°µes, logout).

### _sidebar.html.twig
Menu lateral com links para dashboard, alertas, engarrafamentos e admin.

## Controllers sem Templates

Alguns controllers podem năo ter templates próº¡²prios:
- `CemadenController` - Prováº¡elmente retorna JSON
- `CifsEventController` - Webhook/API
- `CronController` - Comandos CLI
- `HydroController` - API interna
- `MonitoredCityController` - CRUD admin
- `PartnerAdminController` - CRUD admin
- `RouteAdminController` - CRUD admin
- `SecurityController` - Redirecionamentos
- `WazeTrafficJamController` - Webhook
- `WmeUrSyncController` - Sync CLI
- `ApiDocsController` - Pode usar `api/docs.html.twig`

## Convenç°µes

1. **Nomes de rotas**: Seguem padrăo `{controller}_{action}` (ex: `alert_index`)
2. **Variáº¡veis**: Plural para listas (ex: `alerts`, `jams`, `notifications`)
3. **Blocks**: `{% block title %}` e `{% block body %}`
4. **Layouts**: Extensăo via `{% extends 'layouts/dashboard.html.twig' %}`
5. **Bootstrap 5**: Classes utilitáº¡rias e componentes

## Como Adicionar Novo Template

1. Crie arquivo em `templates/{controller}/{action}.html.twig`
2. Extenda layout apropriado:
   ```twig
   {% extends 'layouts/dashboard.html.twig' %}
   ```
3. Defina título e conteúdo:
   ```twig
   {% block title %}Tíº¡tulo{% endblock %}
   {% block body %}...{% endblock %}
   ```
4. No controller:
   ```php
   return $this->render('controller/action.html.twig', [
       'dados' => $variavel,
   ]);
   ```
