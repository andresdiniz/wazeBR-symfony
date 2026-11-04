# Ativação da previsão de jams no agendador

MainSchedule registra app:jams:forecast --no-interaction a cada 2 minutos no schedule default. O boletim diário às 07h e todas as rotinas anteriores permanecem. A coleta e a previsão têm gatilhos independentes: a previsão usa a última observação persistida, não há promessa de execução sequencial.

## Verificação após atualizar o código

```shell
php bin/console cache:clear --env=prod
php bin/console lint:container --env=prod
php bin/console debug:scheduler --env=prod
php bin/console app:jams:forecast --dry-run --env=prod
```

O último comando simula cálculo sem publicar um novo histórico. Para inicializar:

```shell
php bin/console app:jams:forecast --env=prod
```

Acesse /jams/previsao autenticado. Na primeira execução não haverá histórico de episódios encerrados suficiente para estimativa. O modelo não reconstrói velocidades passadas a partir do valor atual. Consulte docs/jam-duration.md para limites e definições.

## Worker

O registro no schedule não executa tarefas sozinho. Deve existir consumo do transporte scheduler_default, pelo mecanismo operacional já utilizado no projeto. Para uma sessão manual de diagnóstico:

```shell
php bin/console messenger:consume scheduler_default --env=prod -vv --time-limit=300
```

Não mantenha esse comando junto de outro consumidor sem avaliar concorrência. Se já há serviço Supervisor, reinicie-o pelo procedimento do servidor após o deploy para carregar a nova definição. Não foi criado outro cron nem alterada a configuração do servidor por este commit.

## Validação do histórico

Confirme que var/jam-duration permite escrita pelo usuário do comando e leitura pelo PHP web. É armazenamento persistente de histórico: não o remova como se fosse cache descartável. Inclua-o no backup e preserve-o no deploy. Não exponha o diretório em public.

Repita o comando depois de uma nova coleta. last_seen_at deve avançar para gerar uma nova amostra; repetir a execução sobre a mesma observação não adiciona leitura. Se a coleta parar, o módulo sinaliza desatualização ou censura, não normalização.

O baseline é Kaplan-Meier condicional e não foi validado operacionalmente. A integração do agendador não aumenta sua precisão nem garante entrega de uma previsão para cada jam. Há exigências mínimas de amostra e restrições geográficas. O ciclo pode ser mais lento quando outros comandos bloqueiam o mesmo worker; monitore o tempo real de execução.
