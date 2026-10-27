# Revisao de consistencia das previsoes

Este commit corrige duracao cronologica, janelas sem coleta no grafico, selecao unica de emissao por janela, MAE sem simbolo de intervalo, sinal do erro e consultas historicas de contexto. O endpoint usa consulta pequena de irregularidades TVT em vez de reconstruir todo o detalhe da rota. A validade visual expira apos 30 minutos sem nova emissao: ajustar o cron para uma frequencia menor que esse limite.

A selecao da auditoria agora e uma emissao avaliada por intervalo com horizonte mais proximo de 60 minutos, desempate por horizonte e ID. As faixas de horizonte descrevem essa selecao, nao todas as emissoes. A comparacao usa a media observada da auditoria. O grafico real mostra as medias correntes dos snapshots; dados tardios podem alterar essa serie.

Pendencias explicitas: o gerador CongestionForecastService nao e alterado neste commit. Continuam pendentes a correcao da janela historica na meia-noite, tendencia por unidade de tempo, ponderacao por dia, lock do gerador, aplicacao de vies, agregacoes em lote, versionamento persistido e benchmarks prospectivos. Nao foram implementados intervalos probabilisticos, inclusao de clima como variavel preditiva ou validacao walk-forward. Estas mudancas exigem uma segunda etapa com testes e historico; nao se deve alegar ganho de precisao por este commit.

Sem migration. As explicacoes ja gravadas na auditoria nao sao reescritas automaticamente; reavaliar explicitamente o periodo desejado para corrigir textos antigos. Reavaliacao altera auditoria existente e deve ser feita apos backup. Contexto meteorologico e agregado por parceiro, nao localizado na rota.

Antes do deploy: executar php -l nos tres arquivos PHP alterados, php bin/console lint:container --env=prod --no-debug, testes de integracao do endpoint com usuario de cada parceiro e administrador, geracao de assets com asset-map:compile. Testar episodio atravessando meia-noite, lacuna acima de 12 min, rota curta, ausencia de dados, emissao antiga, janela com varios horizontes e percentuais de tolerancia.

Nao foram executados PHP, banco nem navegador no ambiente de preparacao.
