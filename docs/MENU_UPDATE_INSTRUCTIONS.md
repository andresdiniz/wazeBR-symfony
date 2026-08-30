# Atualizaçª£o do Menu - Cemaden para Quantidade de Chuva

## Resumo

O menu "Cemaden" foi atualizado para "Quantidade de Chuva" e agora aponta para a página de pluviô££´metros.

## Arquivos Criados

1. `templates/cemaden/rainfall.html.twig` - Página de quantidade de chuva
2. `src/Controller/CemadenController.php` - Método rainfall() adicionado
3. `docs/MENU_UPDATE_INSTRUCTIONS.md` - Este arquivo

## Como Atualizar o Menu

### Passo 1: Localizar o Menu no base.html.twig

Abra o arquivo `templates/base.html.twig` e procure por:

```twig
<a class="nav-link" href="{{ path('app_cemaden_index') }}">
    <i class="bi bi-cloud-rain"></i>
    Cemaden
</a>
```

### Passo 2: Atualizar para

```twig
<a class="nav-link" href="{{ path('app_cemaden_rainfall') }}">
    <i class="bi bi-droplet"></i>
    Quantidade de Chuva
</a>
```

### Mudançª£s

| Antes | Depois |
|-------|--------|
| `app_cemaden_index` | `app_cemaden_rainfall` |
| `bi bi-cloud-rain` | `bi bi-droplet` |
| "Cemaden" | "Quantidade de Chuva" |

## Rotas Disponí¡£veis

| Rota | Nome | Descriçª£o |
|------|------|-----------|
| `/cemaden/rainfall` | `app_cemaden_rainfall` | Lista de pluviô££´metros |
| `/cemaden/station/{id}` | `app_cemaden_station_show` | Detalhes da estaçª£o |
| `/cemaden` | `app_cemaden_index` | Página inicial Cemaden |

## Testar

Apó¡££s atualizar o menu, acesse:

```bash
http://localhost:8000/cemaden/rainfall
```

Deve exibir:
- Cards com pluviô££´metros cadastrados
- Ícone de gota (bi bi-droplet)
- Quantidade de chuva em mm
- Links para detalhes de cada estaçª£o

## Commits

- `f3a4c0e` - templates/cemaden/rainfall.html.twig
- `eb16a8f` - src/Controller/CemadenController.php (mé¡£todo rainfall)
