# Exclusão de interdições

O comando app:jams:forecast aplica JamForecastInputPolicy antes do modelo: nível >=5 ou blocking_alert_uuid preenchido não participa da captura, tela nem amostras de previsão. Episódios de interdição já armazenados são retirados sem criar falsos encerramentos. Nenhum registro Waze é alterado.

A publicação da próxima execução regenera os cards e retira os anteriores de nível 5. Rodar --dry-run apenas simula; não altera os cards publicados.

```shell
php bin/console cache:clear --env=prod
php bin/console app:jams:forecast --partner-id=1 --env=prod
php bin/console debug:scheduler --env=prod
```

MainSchedule já registra app:jams:forecast a cada 2 minutos: não duplicar. Necessário worker scheduler_default em execução e reiniciado após deploy pelo mecanismo do servidor. O commit não configura processos externos.

Fonte antiga e cálculo recente são horários diferentes: recalcular não atualiza last_seen_at. O comando informa quantidade desatualizada e last_fetch_at do parceiro para diagnóstico. Verificar logs da coleta, links ativos, frequência e fuso no armazenamento antes de alterar limiares. Não adicionar 3 horas manualmente nem usar o horário do cálculo como se fosse da fonte. O campo last_fetch_at é diagnóstico do parceiro, não prova individual de atualização do jam.

Teste: php bin/phpunit tests/Service/JamForecastInputPolicyTest.php. Não há reenvio de e-mails nem coleta externa neste comando.
