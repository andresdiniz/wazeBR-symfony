# Sobreposição na lista de rotas + aba Previsão da página de detalhes

Extraia na raiz do projeto (mesmos caminhos; estes arquivos já incluem as mudanças do zip anterior) e rode:

    php bin/console cache:clear
    php bin/console asset-map:compile

## Lista de rotas: colisão de elementos
Causa: o painel de filtros (`.routes-filter-panel`) é `position: sticky` e ficava ACIMA dos cartões no HTML,
então grudava por cima deles ao rolar; além disso os painéis internos do Leaflet (z-index 200–1000) vazavam
para fora dos mapas dos cartões e passavam por cima do painel.
- Filtros movidos para logo acima da lista/mapa que eles filtram (os cartões não respondem a eles).
- Cada mapa Leaflet vira um bloco isolado (`isolation: isolate`) e o painel de filtros ganha z-index 30.

## Página de detalhes: nova aba "Previsão" (antes era um bloco solto de texto no topo)
- 4 cartões objetivos: Agora · Em 1 hora · Pico previsto · Lentidão (cor = distância do histórico).
- "Confiabilidade da previsão": erro médio, viés, janelas avaliadas e % dentro da tolerância (com barra);
  RMSE saiu (era ruído); faixas de horizonte viram chips. Explicações viram tooltip, não parágrafo.
- Gráfico: mesma informação, 4 séries com legenda curta, pontos só no hover, futuro sombreado.
- "Diferenças da previsão e contexto" → "Maiores diferenças recentes": recolhido por padrão, só as 5 maiores,
  em tabela (horário · previsto · medido · diferença). O texto repetido "Nenhum sinal relevante..." foi
  removido; o contexto só aparece quando diz algo.
- Irregularidades TVT: só aparece (como chips) quando existe alguma ativa; some o título vazio + aviso.
- Textos corrigidos com acentuação e traduzidos em pt-BR, en e es.
