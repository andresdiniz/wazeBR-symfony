# Correcao do Erro RouteNotFoundException

## Erro

```
Unable to generate a URL for the named route "cemadenindex" as such route does not exist.
in base.html.twig at line 50
```

## Causa

O menu em `templates/base.html.twig` esta chamando a rota `cemadenindex`, que nao existe.

## Correcao

### Arquivo: `templates/base.html.twig`

**Linha ~50:**

```twig
<!-- ANTES (ERRADO) -->
<a href="{{ path('cemadenindex') }}" class="{{ r starts with 'cemaden' ? 'active' : '' }}">
    <i class="bi bi-droplet"></i>
    <span>Quantidade de Chuvas</span>
</a>

<!-- DEPOIS (CORRETO) -->
<a href="{{ path('app_cemaden_rainfall') }}" class="{{ app.request.attributes.get('_route') starts with 'app_cemaden_' ? 'active' : '' }}">
    <i class="bi bi-droplet"></i>
    <span>Quantidade de Chuva</span>
</a>
```

### Mudancas

1. **Rota:** `path('cemadenindex')` -> `path('app_cemaden_rainfall')`
2. **Condicao active:** `r starts with 'cemaden'` -> `app.request.attributes.get('_route') starts with 'app_cemaden_'`
3. **Texto:** "Quantidade de Chuvas" -> "Quantidade de Chuva" (opcional)

## Arquivos Ja Corrigidos

- ✅ `src/Controller/CemadenController.php`
- ✅ `templates/cemaden/rainfall.html.twig`
- ❌ `templates/base.html.twig` - **PENDENTE**

## Commits Realizados

- `4a71454` - CemadenController.php
- `c5d81e0` - rainfall.html.twig
