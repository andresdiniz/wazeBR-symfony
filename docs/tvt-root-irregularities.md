# Irregularidades independentes de rota

A importação usa a tabela waze_tvt_irregularity com parceiro obrigatório e rota/subrota nulas. O coletor app:fetch:waze:tvt não foi modificado: execute o comando complementar após ele ou agende-o separadamente, por parceiro, sem worker.

## Implantação
Faça backup e confira o banco/ambiente. Simule somente esta migration:

```sh
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261112210000' --up --dry-run --env=dev
```

Após revisar o SQL, execute sem --dry-run. Não execute todas as migrations indiscriminadamente. Use o ambiente correto na hospedagem.

## Importação
Substitua ID pelo ID interno do parceiro:

```sh
php bin/console app:fetch:waze:tvt-irregularities --partner=ID --dry-run --env=dev
php bin/console app:fetch:waze:tvt-irregularities --partner=ID --env=dev
```

Para o JSON salvo, acrescente --file=caminho/feed-tvt.json. Arquivos locais nunca desativam registros ausentes, evitando que uma amostra antiga substitua o estado atual.

Lista vazia válida de todos os feeds consultados pode desativar irregularidades rootv1 ausentes; campo ausente impede essa desativação. Falha HTTP, JSON inválido ou geometria inválida interrompe o lote. O comando não respeita a frequência da coleta de rotas: configure explicitamente o intervalo no cron. A trava é compartilhada com a coleta TVT do mesmo parceiro.

A identidade usa tipo, nome, origem, destino e geometria arredondada a 5 casas, ignorando tempos, jamLevel e id=0. Mudanças de geometria podem gerar nova identidade; não há garantia de identidade perfeita sem ID estável da fonte. Não foi criada uma constraint UNIQUE para escopos nulos; os comandos deste importador são serializados pela trava compartilhada. Outros gravadores também devem respeitar essa trava.

Horário do feed não é horário da ocorrência. reported_time, severity e ponto representativo não são inventados; os campos originais e a linha completa ficam no payload. Atualizações variáveis de tempo/jamLevel atualizam o mesmo registro quando a identidade é igual. Esta entrega não adapta telas que exigem JOIN com rota, nem corrige usersOnJams. Não inclui benchmark ou teste executado contra o banco da hospedagem.

O rollback da migration aborta se houver registros sem rota: não apaga dados para forçar NOT NULL. Após aplicar, valide a tabela por partner_id e route_id IS NULL. Não use doctrine:schema:update --force.
