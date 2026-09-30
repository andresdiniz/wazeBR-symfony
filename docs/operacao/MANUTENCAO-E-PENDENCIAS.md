# Manutenção e pendências

## Rotina operacional

| Frequência sugerida | Atividade |
| --- | --- |
| A cada implantação | Verificar migrations, cron, logs, login e uma fonte de cada família. |
| Diariamente | Conferir atrasos de coleta e falhas por parceiro/fonte. |
| Semanalmente | Examinar crescimento de tabelas históricas, erros repetidos e backups. |
| Antes de mudanças no banco | Registrar DDL atual, backup e plano de reversão. |

A frequência acima é uma sugestão de operação; ajustar aos requisitos de
disponibilidade e ao intervalo real de cada integração.

## Pontos a investigar

1. `traffic_light_snapshot` e `waze_tvt_route_snapshot` já têm cardinalidades
   de índice elevadas na estrutura fornecida. Medir tamanho real, crescimento,
   tempo de consulta e necessidade de retenção/arquivamento antes de apagar
   qualquer histórico.
2. `partner_api_link` tem `active` e `is_active`. Identificar qual campo cada
   leitura usa e decidir se ambos são necessários.
3. Existem `camera` e `partner_camera_link`. Documentar diferença de uso e
   evitar cadastros duplicados ou telas inconsistentes.
4. `waze_tvt_route.route_id` é textual, enquanto `route_id` nas tabelas
   dependentes é inteiro. Padronizar nomes nas próximas alterações.
5. Verificar o comportamento de unicidade de
   `waze_tvt_irregularity` quando `sub_route_id` é `NULL`; o índice composto
   por si só não deve ser tratado como garantia de deduplicação nesse caso.
6. Confirmar as constraints FK reais em `information_schema`, não apenas
   índices e associações Doctrine.
7. Comparar migrations versionadas com o esquema implantado e investigar
   diferenças antes de gerar uma migration automática.
8. Confirmar se há duplicidade de execução entre `cron.php`,
   `MainSchedule.php` e consumidores de fila.
9. Revisar arquivos `.env*` presentes no repositório e, se necessário,
   rotacionar credenciais.
10. Definir retenção, backup e restauração para payloads e snapshots.

## Registro de incidente

| Campo | Preenchimento |
| --- | --- |
| Data e fuso | |
| Parceiro e recurso | |
| Sintoma | |
| Última coleta bem-sucedida | |
| Comando/processo afetado | |
| Erro ou log sanitizado | |
| Consulta de validação | |
| Causa confirmada | |
| Correção aplicada | |
| Evidência de recuperação | |
| Ação preventiva | |
