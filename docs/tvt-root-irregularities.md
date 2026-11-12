# Irregularidades independentes de rota

A tabela waze_tvt_irregularity aceita ocorrências com parceiro obrigatório e rota/subrota nulas após aplicar Version20261112210000. Não há nova migration nesta integração ao agendador.

## Agendamento automático
MainSchedule contém exatamente uma tarefa a cada 2 minutos:

```text
app:fetch:waze:tvt-irregularities --no-interaction
```

Sem --partner, o comando agrupa os links TVT por parceiro e processa somente parceiros ativos com links retornados por findAllByType('TVT'). A opção --partner=ID continua disponível para execução manual filtrada. Não é necessário um cron separado para cada parceiro quando o Scheduler estiver executando esta tarefa.

Cada parceiro tem sua própria trava e transação. A trava é a mesma da coleta TVT de rotas; a posição no Schedule não garante execução sequencial. Se estiver ocupada, aquele parceiro é ignorado e poderá ser processado no ciclo seguinte. Falhas independentes não impedem os demais parceiros, exceto quando o EntityManager é fechado por erro de persistência; nesse caso a execução termina com falha para evitar uso de estado inválido. A seleção de links ocorre uma vez por execução. O intervalo é o do Scheduler; não se usa lastTvtFetchAt para não conflitar com a frequência da coleta de rotas.

## Implantação e validação
Confira destino, backup e status antes de aplicar a migration anterior. Simule somente ela e revise o SQL:

```sh
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261112210000' --up --dry-run --env=dev
```

Se ainda não aplicada, execute sem --dry-run após revisar. Use o ambiente correto na hospedagem.

Valide todos os parceiros sem gravar:

```sh
php bin/console app:fetch:waze:tvt-irregularities --dry-run --no-interaction --env=dev
```

Para arquivo local, --partner é obrigatório; acrescente --file=caminho/feed-tvt.json. Arquivo nunca desativa registros ausentes. O modo sem --partner nunca reutiliza um arquivo para vários parceiros.

Depois do deploy, o processo do Scheduler já existente precisa ser reiniciado pelo procedimento habitual para carregar o código. Não inicie outro processo concorrente nem remova tarefas das outras fontes. Envio direto de e-mail não elimina o consumidor responsável pelo Scheduler. Esta alteração não modifica serviços ou processos no servidor.

## Persistência e limites
JSON completo preservado em payload. A identidade rootv1 usa tipo, nome, origem, destino e geometria arredondada a 5 casas, sem tempos, jamLevel ou id=0. Mudanças de geometria podem criar nova identidade. Travas serializam os comandos; não existe constraint UNIQUE para escopos nulos, e outros gravadores devem respeitar essa trava.

Lista vazia válida de todos os feeds pode desativar rootv1 ausentes. Campo ausente impede desativação. Resposta inválida interrompe o lote do parceiro. reported_time, severity e ponto representativo não são inventados. Telas que exigem JOIN com rota e usersOnJams não foram adaptados. Não foram executados benchmark nem testes contra a hospedagem. O rollback da migration anterior aborta se houver registros sem rota; não apaga dados para forçar NOT NULL.
