# Duração observada dos jams

Página autenticada: /jams/previsao. Template: templates/jam/forecast.html.twig. O escopo é por parceiro, exceto admin global. Sem migrations ou dependências Python.

Execute php bin/console app:jams:forecast --dry-run para simular e php bin/console app:jams:forecast para persistir em var/jam-duration, fora de public. Opção --partner-id=ID. Execute periodicamente após as coletas, idealmente a cada 2 minutos, usando o agendador existente. Este commit não altera o MainSchedule nem a página /jams existente.

Modelo: Kaplan-Meier condicional no conjunto T > idade. Não é AFT, XGBoost, previsão de formação de jam nem modelo validado. Não há estimativa antes de 20 comparáveis em risco e 10 encerramentos. Seleção por parceiro, cidade, tipo de via, proximidade de 1,5 km, sentido aproximado, velocidade inicial e horário/dia útil. Quartis não equivalem a cobertura calibrada.

O alvo é a desativação observada de um registro no feed. UUIDs diferentes não são unidos: alteração de UUID pode representar fragmentação e não recuperação. Não usar como previsão operacional garantida. Lacunas longas censuram e reiniciam episódios; bloqueios não recebem estimativa. O endpoint superior da desativação é uma aproximação da coleta, não tratamento exato de censura intervalar. A idade é observada pelo módulo, não início físico do congestionamento. Dados legados não são usados para fingir histórico de snapshots.

Últimas 30 leituras por episódio, 90 dias e máximo de 2000 episódios por parceiro; os limites devem ser avaliados antes de uso operacional, pois truncar histórico pode introduzir viés. O comando recusa mais de 10000 registros por parceiro sem gravar parcial. Mantém lock por parceiro, arquivo temporário e rename; requer permissões em var. Backups e remoção de dados de parceiros excluídos são responsabilidade operacional.

Validar antes de produção: php bin/console lint:container; php bin/console lint:twig templates/jam/forecast.html.twig; php bin/phpunit tests/Service/JamDurationModelTest.php. O recebimento de snapshots depende de last_seen_at avançar nas coletas.

Próximos passos científicos: validação temporal por episódio, avaliação de censura informativa e fragmentação espacial, calibração dos intervalos, comparação com AFT. Não assumir precisão antes desses testes.
