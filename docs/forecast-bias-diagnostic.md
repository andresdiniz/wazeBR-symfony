# Inicio da calibracao de vies

Comando somente leitura, sem migration, sem alteracao do gerador e sem ativacao em producao.

```bash
php bin/console app:tvt:diagnose-forecast-bias --route=68 --train-days=30 --test-days=7 --env=prod --no-debug
php bin/console app:tvt:diagnose-forecast-bias --route=68 --json --env=prod --no-debug
```

Trocar 68 pela rota desejada. O comando usa o banco configurado; deve ser executado somente por operador autorizado com acesso CLI. Nao existe endpoint web.

Treino: janelas encerradas e avaliadas antes do corte. Teste: previsoes emitidas depois do corte. Seleciona uma emissao por rota, janela e faixa de horizonte, a mais proxima do limite superior da faixa; desempata pelo menor horizonte e maior ID. Nao filtra somente os erros fora da tolerancia.

Erro = previsto - observado. Candidato = max(0, previsto - correcao). Correcao = vies de treino * n/(n+30), limitada por previsao a 30 segundos e 20% do previsto. Minimos padrao: 20 janelas e 3 dias distintos. Sao parametros experimentais, nao resultado de otimizacao. Sem suporte suficiente o candidato e igual a base e o motivo e explicitado.

As janelas historicas do gerador foram corrigidas no commit cd3b1ea. Esta etapa nao muda os 30 dias do gerador nem implementa ponderacao por dia.

Limitacoes: a auditoria antiga nao versiona modelo nem observacao original. Um evaluated_at regravado depois do corte elimina aquela linha do treino; nao restaura valores antigos. O relatorio nao comprova causalidade nem valida uma versao especifica do modelo. Uma unica divisao temporal e exploratoria. Nao habilitar calibracao automatica apenas com esse relatorio.

Validar localmente com php -l nos arquivos novos e lint:container. Nao foram executados PHP, testes ou SQL no ambiente de preparacao. Proxima etapa: testes automatizados, persistencia de previsao base/versao/correcao e coleta shadow prospectiva.
