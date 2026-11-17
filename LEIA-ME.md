# Preferências de e-mail por usuário

Copie as pastas deste pacote para a raiz do projeto (mesmos caminhos) e rode:

    php bin/console doctrine:migrations:migrate
    php bin/console cache:clear

Página do usuário: /account/notifications (também no menu da conta → "Preferências").

## Arquivos novos
- migrations/Version20261117120000CreateUserNotificationPreferences.php
- src/Entity/UserNotificationPreference.php, UserNotificationSubscription.php
- src/Repository/UserNotificationPreferenceRepository.php
- src/Service/Notification/NotificationCatalog.php, NotificationPreferenceService.php
- src/Service/EventNotificationService.php
- src/Controller/NotificationPreferenceController.php
- templates/account/notifications.html.twig, _notification_types.html.twig
- templates/email/partner_event.html.twig
- public/css/pages/notifications.css, public/js/pages/notification-preferences.js

## Arquivos alterados (completos)
- src/Service/AlertNotificationService.php  (filtra destinatários pelas preferências)
- src/Command/WazeDailyBriefingCommand.php  (briefing só para quem quer)
- src/Controller/PartnerFeedController.php  (e-mail ao criar evento)
- templates/email/important_alert.html.twig (rodapé com link de preferências)
- templates/partials/header.html.twig       (menu "Preferências" aponta para a página)
- config/packages/routing.yaml              (default_uri p/ links absolutos em e-mails do scheduler)

## Regras
- Usuário sem registro recebe o PADRÃO: exatamente o filtro antigo de alertas
  (ACCIDENT, ROAD_CLOSED, HAZARD_ON_ROAD, HAZARD_ON_ROAD_OBJECT, HAZARD_WEATHER_FLOOD) e briefing; eventos só por escolha.
- subtype '*' = todos os subtipos do tipo.
