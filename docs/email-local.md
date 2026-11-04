# E-mails pelo Symfony Mailer

AlertNotificationService não utiliza mais worker HTTP, webhook nem WhatsApp. Os alertas são enviados individualmente pelo Mailer. O comando de coleta continua disparando apenas eventos novos ou reativados. Incluído o subtipo de alagamento.

A integração Gemini foi desativada. AiNarrativeService permanece apenas como adaptador de compatibilidade que retorna null. Não há chamadas à API ou necessidade de GEMINI_API_KEY/GEMINI_MODEL nesses serviços. O comando de briefing mantém --no-ai para compatibilidade; sem essa opção também envia somente dados. A mensagem IA indisponível do comando legado pode aparecer, mas não representa tentativa de API.

Configuração necessária em .env.local (não versionar credenciais): MAILER_DSN, MAILER_FROM_EMAIL, MAILER_FROM_NAME e APP_URL. Um servidor SMTP ou sendmail continua necessário para entregar mensagens; o Symfony não substitui o servidor de e-mail. Use null://null somente quando não quiser entrega.

Validação sem enviar:

```shell
php bin/console cache:clear
php bin/console lint:container
php bin/console waze:briefing:daily --no-ai --dry-run
php bin/console waze:briefing:daily --dry-run
```

Teste real (envia uma mensagem):

```shell
php bin/console app:test-email seu-email@dominio.com
```

Esta migração usa o Mailer diretamente. Não adiciona fila durável, confirmação de entrega, preferências ou reenvio automático: falhas dos alertas são registradas e não quebram a coleta, mas não são reenviadas automaticamente. O boletim legado ainda agrupa destinatários do parceiro em To. Essas melhorias exigem implementação adicional.

APCu: aviso de startup é configuração local do PHP, anterior ao Symfony. Localize o INI com php --ini e comente extension=apcu se a extensão não estiver instalada e não for necessária, ou instale uma DLL compatível com seu PHP. Não altere o php.ini de outro ambiente por engano.
