# Correções e melhorias das páginas de congestionamentos (jams)

Extraia o zip na raiz do projeto (mesmos caminhos) e depois rode:

    php bin/console cache:clear
    # DEV: apague public/assets (assets compilados antigos têm prioridade sobre assets/)
    # PROD: php bin/console asset-map:compile
    # Há arquivos JS novos (core/leaflet-loader.js, pages/jam-fetch-bridge.js, pages/jam-share.js)

## O que estava quebrado
1. Mapa da /jams/{id} não aparecia: o Leaflet era carregado por <script> inline, e o CSP
   (nelmio_security, script-src sem 'unsafe-inline') bloqueia. O botão Compartilhar também era inline.
2. /jams (index) mostrava só o texto "CONTEUDO_ORIGINAL_COM_TITULO_TROCAR_WAZE_BR_POR_TRAFIK":
   o commit ec4fdb1 (branding) apagou o template. Restaurado do commit c3a1b6b.
   O mesmo commit apagou templates/routes/compare_print.html.twig. Restaurado.
3. Selos de status da lista nunca apareciam: dependiam de um fetch interceptado em <script> inline (bloqueado).
4. Link público /jams/share/... exigia login (faltava PUBLIC_ACCESS no security.yaml).
5. history.html.twig tinha <script type="module"> inline bloqueado.

## Arquivos
templates/jam/{index,show,history,forecast,share}.html.twig
templates/routes/compare_print.html.twig
assets/js/core/leaflet-loader.js (novo)
assets/js/pages/{jam-show,jam-duration,jam-status}.js, jam-fetch-bridge.js (novo), jam-share.js (novo)
assets/css/pages/jam-show.css
config/packages/security.yaml
