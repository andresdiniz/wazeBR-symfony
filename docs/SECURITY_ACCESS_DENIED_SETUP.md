# Configuraçª£o de Acesso Negado

## Visã££o Geral

Este documento descreve como configurar a página de acesso negado no wazeBR-symfony.

## Arquivos Criados

### Controller
- `src/Controller/SecurityController.php` - Controller com rota `/access-denied`

### Templates
- `templates/security/access_denied.html.twig` - Página de acesso negado
- `templates/partner_admin/index.html.twig` - Lista de parceiros
- `templates/partner_admin/show.html.twig` - Detalhes do parceiro
- `templates/partner_admin/new.html.twig` - Criar parceiro
- `templates/partner_admin/edit.html.twig` - Editar parceiro
- `templates/partner_admin/_form.html.twig` - Formulá¡£xio reutilizá¡£vel

## Configuraçª£o do security.yaml

### Opçª£o 1: Usando access_denied_url (Recomendado)

```yaml
# config/packages/security.yaml
security:
    # ... outras configuraçµ£es ...
    
    access_denied_url: /security/access-denied
    
    firewalls:
        main:
            # ... configuraçµ£es do firewall ...
```

### Opçª£o 2: Usando access_denied_handler

```yaml
# config/packages/security.yaml
security:
    # ... outras configuraçµ£es ...
    
    firewalls:
        main:
            # ... configuraçµ£es do firewall ...
            access_denied_handler: App\Controller\SecurityController::accessDeniedHandler
```

### Opçª£o 3: Custom Error Pages do Symfony

```yaml
# config/packages/framework.yaml
framework:
    error_controller: App\Controller\SecurityController::accessDenied
```

## Regras de Acesso no PartnerAdminController

| Ação | ROLE_PARTNER | ROLE_ADMIN | ROLE_OPERATOR | ROLE_USER |
|------|--------------|------------|---------------|-----------|
| `GET /` (index) | ✅ Completo | ✅ Completo | ⚠️ Básico | ⚠️ Básico |
| `GET /{id}` (show) | ✅ Completo | ✅ Completo | ⚠️ Básico | ⚠️ Básico |
| `GET/POST /new` | ✅ | ✅ | ❌ | ❌ |
| `GET/POST /{id}/edit` | ✅ | ✅ | ❌ | ❌ |
| `POST /{id}` (delete) | ✅ | ✅ | ❌ | ❌ |
| `GET /api/{id}` | ✅ Completo | ✅ Completo | ⚠️ Básico | ⚠️ Básico |

**Legenda:**
- ✅ = Acesso total (criar, editar, deletar, ver tudo)
- ⚠️ = Apenas visualizaçª£o de dados básicos
- ❌ = Acesso negado (redireciona para /security/access-denied)

## Como Testar

### 1. Testar como ROLE_OPERATOR ou ROLE_USER

```bash
# Acessar rota de ediçª£o (deve ser negado)
http://localhost:8000/partner-admin/1/edit

# Resultado: Redireciona para /security/access-denied
# Mensagem: "Apenas parceiros podem editar parceiros."
```

### 2. Testar como ROLE_PARTNER ou ROLE_ADMIN

```bash
# Acessar rota de ediçª£o (deve permitir)
http://localhost:8000/partner-admin/1/edit

# Resultado: Exibe formulá¡£xio de ediçª£o
```

### 3. Debug de Roles

```bash
# No controller ou template
{{ app.user.roles }}  # Exibe: ["ROLE_USER", "ROLE_OPERATOR"]

# No controller PHP
dump($this->getUser()->getRoles());
```

### 4. Verificar Permissõ£££es

```bash
# No Twig
{% if is_granted('ROLE_PARTNER') %}
    <p>Voc ê é um PARTNER</p>
{% endif %}

{% if is_granted('ROLE_ADMIN') %}
    <p>Voc ê é um ADMIN</p>
{% endif %}

{% if is_granted('ROLE_PARTNER') or is_granted('ROLE_ADMIN') %}
    <p>Voc ê pode editar</p>
{% endif %}
```

## Variá¡£veis do Template

O template `access_denied.html.twig` recebe:

- `exception_message`: Mensagem de erro da exceçª£o
- `missing_role`: Role necessá¡£ria (extraí¡£da da mensagem)
- `app.user`: Usuá¡£rio atual (disponí¡£vel via global)

## Exemplo de Mensagens

### ROLE_PARTNER
```
"Apenas parceiros podem editar parceiros."
→ missing_role: ROLE_PARTNER
```

### ROLE_ADMIN
```
"Acesso negado. Apenas ROLE_PARTNER e ROLE_ADMIN podem editar parceiros."
→ missing_role: ROLE_PARTNER
```

### IS_AUTHENTICATED_FULLY
```
"Voc ê precisa estar autenticado para acessar esta página."
→ missing_role: null (usuá¡£rio nã££o autenticado)
```

## Comandos Úteis

```bash
# Verificar usuá¡£rio logado
php bin/console debug:security

# Listar firewalls
php bin/console debug:security:firewalls

# Verificar access control
php bin/console debug:security:access-decision-manager
```

## Troubleshooting

### Página de acesso negado nã££o aparece

1. Verifique se `access_denied_url` está configurado no `security.yaml`
2. Verifique se a rota `app_security_access_denied` existe
3. Verifique se o template `security/access_denied.html.twig` existe

### Mensagem de erro nã££o aparece

1. Verifique se a mensagem está sendo passada via session
2. Verifique se o `access_denied_handler` está configurado corretamente
3. Use `dump($exception->getMessage())` para debug

### Roles nã££o estão funcionando

1. Verifique se o usuá¡£rio tem as roles corretas no banco de dados
2. Verifique se o `User` entity implementa `UserInterface` corretamente
3. Use `dump($this->getUser()->getRoles())` para debug

## Links Úteis

- [Symfony Security Documentation](https://symfony.com/doc/current/security.html)
- [Symfony Access Denied](https://symfony.com/doc/current/security/access_denied.html)
- [Symfony Voters](https://symfony.com/doc/current/security/voters.html)
