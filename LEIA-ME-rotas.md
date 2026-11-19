# Melhorias da parte de Rotas

Extraia o zip na raiz do projeto (mesmos caminhos) e depois rode:

    php bin/console cache:clear
    php bin/console asset-map:compile      # há JS/CSS novos; o repositório versiona public/assets

## Erros corrigidos
1. /routes/compare/print dava erro 500: o template estendia `layouts/base.html.twig`, que não existe.
   Agora é uma página independente. O botão e a impressão automática usavam onclick/<script> inline,
   bloqueados pelo CSP; passaram para assets/js/pages/route-print.js.
2. Relatório da rota (/routes/{id}/report e link público): todo o JavaScript (mapa com ruas, botão
   Imprimir / PDF) ficava num <script type="module"> inline, bloqueado pelo CSP. Agora é módulo externo.
   O assets/js/pages/route-report.js estava desatualizado (sem as mensagens de mapa indisponível);
   foi substituído pela versão que estava embutida.
3. Lista de rotas: o "Atualizado em" do cabeçalho era um relógio do navegador (mostrava a hora atual
   mesmo com dados velhos). Agora mostra a hora da última atualização real.
4. Lista de rotas: a atualização automática só mexia nos cartões. Agora também atualiza os indicadores
   (total, com atraso, críticas, atraso médio), pausa com a aba oculta, atualiza ao voltar para a aba e
   avisa quando falha ("Exibindo dados de N min atrás").

## Melhorias
- Comparar rotas: barra com Imprimir/PDF e exportar CSV (evolução, por hora, por dia, mapa de calor).
  Esses endpoints existiam, mas não havia botão. Aviso quando as duas rotas escolhidas são iguais.
- Lista de rotas: estrela de favorita nos cartões (mesmo endpoint e CSRF da página de detalhes),
  favoritas sempre primeiro, filtro Todas/Favoritas (lembrado no navegador) e mensagem de lista vazia.
- Textos novos traduzidos em pt-BR, en e es.

## Arquivos sem uso (pode apagar; nenhum template/JS os referencia)
- templates/routes/_report_script.html.twig   (substituído por assets/js/pages/route-report.js)
- templates/routes/show_historico_snippet.html.twig
- assets/route-show-addition.js e assets/route-show-addition.css
