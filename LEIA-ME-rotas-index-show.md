# Lista de rotas (/routes) e página de detalhes (/routes/{id})

Extraia na raiz do projeto (mesmos caminhos) e rode:

    php bin/console cache:clear
    php bin/console asset-map:compile      # há CSS novo e JS alterado; o repositório versiona public/assets

## Lista de rotas
- Painel "Situação agora": só as 5 PIORES rotas (gravidade → % acima do histórico → atraso) mais as
  favoritas (até 8 extras). Chips "Piores agora" / "★ Favoritas". A lista completa continua abaixo
  (link "Ver todas ↓"), com busca e filtro, e o mapa grande mostra todas.
- Mapa real (Leaflet) dentro de cada cartão: trajeto colorido por trecho + incidentes. Criado só quando o
  cartão aparece (cartões ocultos não carregam mapa). Clicar no mapa abre os detalhes.
- Busca sem travar: índice montado uma vez (antes cada tecla fazia uma busca no DOM por rota e
  adicionava/removia todas as linhas do mapa); só as camadas que mudaram são atualizadas; busca ignora
  acentos ("sao jose" acha "São José"); popups do mapa montados só ao abrir. O link "Detalhes →" não fica
  mais órfão quando o item é filtrado.
- Atualização automática mais leve: ?slim=1 devolve só indicadores e status (sem geometria/trechos).

## Página de detalhes
- Gráfico "Evolução do atraso": uma métrica por vez (Atraso em segundos OU % acima do histórico) em vez
  de duas linhas sobrepostas que andavam juntas. Cor da linha = gravidade, trecho tracejado = poucas
  amostras, zero destacado, tooltip com atraso + % + amostras. A escolha fica salva no navegador.
- Página dividida em 3 abas (Visão geral · Padrões e histórico · Trechos e ocorrências); sem JavaScript
  tudo continua visível. Navegação por teclado (← →).
- CSS novo (route-show-polish.css): cabeçalho em cartão único, KPIs com hierarquia (4 principais + 3
  secundários), painéis e espaçamentos consistentes, alturas de mapa/gráfico responsivas.
- Textos novos em pt-BR, en e es.
