# Revisão dos templates e assets WazeBR

## Correções aplicadas

- Corrigidos caminhos CSS quebrados nas telas de alertas ao vivo e CIFS (`css/pages/...`).
- Corrigido o caminho ausente do CSS CIFS.
- CIFS foi conectado ao carregador modular existente por meio do seletor `#cifs-main`; o módulo agora encontra a raiz real da página.
- Corrigido o hash SRI do Leaflet na tela CIFS para coincidir com o valor usado na dashboard e habilitada a política `crossorigin`/`referrerpolicy` explícita.
- Removida a primeira inicialização duplicada do tema no template base; mantida a inicialização antecipada necessária para evitar flash visual.
- Registrada a inicialização do seletor de tema no carregador modular.
- Tornada a persistência do tema tolerante a bloqueio do `localStorage`, sem impedir a troca de tema na página atual.
- Removidos `console.log` de depuração do tema e do login.
- Adicionados `rel="noopener noreferrer"` aos links CIFS que abrem nova aba.
- Conferidas referências relativas de importações JS/CSS e referências explícitas `asset()` para CSS/JS: sem caminhos ausentes depois dos ajustes.

## Limitação restante

O pacote contém apenas um módulo CIFS mínimo (`js/pages/cifs.js`), que emite o evento `cifs:ready`; a tela expõe controles para mapa, geocodificação, salvar e desativar eventos. O código de interação correspondente e as rotas/API CIFS não estão presentes nos ZIPs revisados. Portanto, esta entrega corrige o carregamento e a integração disponíveis, mas não implementa essas operações. Para concluir essa parte, é necessário incluir o código funcional do controlador/API e os requisitos do fluxo CIFS.

## Verificação

Foi feita uma checagem estática de caminhos e imports. Não executei a aplicação nem testes automatizados.
