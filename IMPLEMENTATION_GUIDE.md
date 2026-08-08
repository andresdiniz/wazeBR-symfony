# Guia de Implementação das Melhorias
## wazeBR-symfony

**Data:** 07 de Agosto de 2026  
**Versão:** 1.0  
**Status:** Pronto para Implementação

---

## 📋 Resumo Executivo

Este guia detalha como implementar as melhorias de **layout**, **SEO**, **segurança** e **otimização de login** no projeto wazeBR-symfony. As mudanças foram estruturadas em 4 fases, com prioridades claras.

---

## 🚀 Fase 1: Segurança (CRÍTICO - Semana 1)

### 1.1 Implementar Headers de Segurança HTTP

**Arquivo:** `config/packages/security_headers.yaml`  
**Middleware:** `src/Middleware/SecurityHeadersMiddleware.php`

**Passos:**

1. Copie o arquivo `config/packages/security_headers.yaml` para seu projeto
2. Copie o middleware `src/Middleware/SecurityHeadersMiddleware.php`
3. Registre o middleware no `config/services.yaml`:

```yaml
services:
    App\Middleware\SecurityHeadersMiddleware:
        arguments:
            $environment: '%kernel.environment%'
        tags:
            - { name: 'kernel.event_listener', event: 'kernel.response', method: 'onKernelResponse', priority: -256 }
```

4. Teste os headers com:
```bash
curl -I https://seu-dominio.com/login
```

**Headers Implementados:**
- ✅ Content-Security-Policy (CSP)
- ✅ X-Frame-Options: DENY
- ✅ X-Content-Type-Options: nosniff
- ✅ X-XSS-Protection: 1; mode=block
- ✅ Referrer-Policy: strict-origin-when-cross-origin
- ✅ Permissions-Policy (geolocation, microphone, camera desabilitados)
- ✅ Strict-Transport-Security (HSTS) em produção

### 1.2 Implementar Rate Limiting Avançado

**Arquivo:** `src/Service/RateLimiterService.php`

**Passos:**

1. Copie o serviço `src/Service/RateLimiterService.php`
2. Registre no `config/services.yaml`:

```yaml
services:
    App\Service\RateLimiterService:
        arguments:
            $cache: '@cache.app'
```

3. Atualize o `AuthController.php`:

```php
use App\Service\RateLimiterService;

class AuthController extends AbstractController
{
    public function __construct(
        // ... existing dependencies ...
        private readonly RateLimiterService $rateLimiter,
    ) {}

    #[Route('/login', name: 'auth_login')]
    public function login(AuthenticationUtils $authUtils, Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('dashboard_index');
        }

        // Verificar rate limit
        if ($this->rateLimiter->isLoginRateLimited($request)) {
            $this->addFlash('error', 'Muitas tentativas de login. Tente novamente em 1 minuto.');
            return $this->render('auth/login.html.twig');
        }

        return $this->render('auth/login.html.twig', [
            'last_username' => $authUtils->getLastUsername(),
            'error'         => $authUtils->getLastAuthenticationError(),
        ]);
    }
}
```

4. Registre um listener para falhas de autenticação:

```php
// src/EventListener/AuthenticationFailureListener.php
namespace App\EventListener;

use App\Service\RateLimiterService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Event\AuthenticationFailureEvent;
use Symfony\Component\HttpFoundation\Request;

class AuthenticationFailureListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly RateLimiterService $rateLimiter,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            AuthenticationFailureEvent::class => 'onAuthenticationFailure',
        ];
    }

    public function onAuthenticationFailure(AuthenticationFailureEvent $event): void
    {
        $request = $event->getRequest();
        if ($request) {
            $this->rateLimiter->recordFailedLogin($request);
        }
    }
}
```

**Limites Configurados:**
- Login: 5 tentativas por minuto
- Esqueci Senha: 3 solicitações por hora
- Reset Senha: 5 tentativas por hora

### 1.3 Melhorar Validação de Input

**Arquivo:** `src/Controller/AuthController.php`

Adicione validação mais rigorosa:

```php
private function validateEmailFormat(string $email): bool
{
    // Validação básica
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    // Validação de domínio (opcional)
    $domain = substr(strrchr($email, "@"), 1);
    return checkdnsrr($domain, "MX");
}

private function sanitizeInput(string $input): string
{
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}
```

---

## 🎨 Fase 2: SEO e Metadados (IMPORTANTE - Semana 2)

### 2.1 Atualizar Template Base

**Arquivo:** `templates/base_guest_improved.html.twig`

1. Substitua `templates/base_guest.html.twig` pelo arquivo melhorado
2. Ou integre gradualmente os metadados:

```twig
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- SEO -->
    <title>{% block title %}WazeBR Monitor{% endblock %}</title>
    <meta name="description" content="{% block meta_description %}...{% endblock %}">
    <link rel="canonical" href="{% block canonical_url %}{{ app.request.uri }}{% endblock %}">
    
    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="{% block og_title %}...{% endblock %}">
    <meta property="og:description" content="{% block og_description %}...{% endblock %}">
    <meta property="og:url" content="{% block og_url %}{{ app.request.uri }}{% endblock %}">
    <meta property="og:image" content="{% block og_image %}{{ absolute_url(asset('images/og-image.png')) }}{% endblock %}">
    
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{% block twitter_title %}...{% endblock %}">
    <meta name="twitter:description" content="{% block twitter_description %}...{% endblock %}">
    
    <!-- Security -->
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="DENY">
</head>
```

### 2.2 Atualizar Página Inicial

**Arquivo:** `templates/home/index_improved.html.twig`

1. Substitua `templates/home/index.html.twig` ou integre as melhorias:

**Melhorias Principais:**
- ✅ Metadados OG e Twitter Card
- ✅ Schema.org FAQPage
- ✅ Hierarquia de headings corrigida (H1 → H2 → H3)
- ✅ Breadcrumb schema (adicionar se necessário)
- ✅ Atributos ARIA melhorados

### 2.3 Atualizar Página de Login

**Arquivo:** `templates/auth/login_improved.html.twig`

1. Substitua `templates/auth/login.html.twig` ou integre as melhorias:

**Melhorias Principais:**
- ✅ Meta description adicionada
- ✅ Schema.org Organization
- ✅ Validação client-side de força de senha
- ✅ Indicador visual de força de senha
- ✅ Acessibilidade melhorada (aria-describedby, aria-label)
- ✅ Toggle de senha com feedback visual
- ✅ Spinner de carregamento melhorado

### 2.4 Criar Sitemap e Robots.txt

**Arquivo:** `public/robots.txt`

```
User-agent: *
Allow: /
Disallow: /admin
Disallow: /dashboard
Disallow: /api

Sitemap: https://seu-dominio.com/sitemap.xml
```

**Arquivo:** `public/sitemap.xml`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc>https://seu-dominio.com/</loc>
        <lastmod>2026-08-07</lastmod>
        <changefreq>weekly</changefreq>
        <priority>1.0</priority>
    </url>
    <url>
        <loc>https://seu-dominio.com/login</loc>
        <lastmod>2026-08-07</lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.8</priority>
    </url>
</urlset>
```

---

## 🔐 Fase 3: UX/UI e Acessibilidade (IMPORTANTE - Semana 3)

### 3.1 Extrair CSS para Arquivos Externos

**Benefícios:**
- Melhor cache (CSS não é inline)
- Mais fácil de manter
- Melhor performance

**Passos:**

1. Crie `public/css/login.css` com o CSS do login
2. Crie `public/css/home.css` com o CSS da home
3. Atualize os templates:

```twig
{% block stylesheets %}
    <link rel="stylesheet" href="{{ asset('css/login.css') }}">
{% endblock %}
```

### 3.2 Implementar Validação Client-side

**Arquivo:** `public/js/form-validation.js`

```javascript
class FormValidator {
    constructor(formId) {
        this.form = document.getElementById(formId);
        this.setupListeners();
    }

    setupListeners() {
        const emailInput = this.form.querySelector('input[type="email"]');
        const passwordInput = this.form.querySelector('input[type="password"]');

        if (emailInput) {
            emailInput.addEventListener('blur', () => this.validateEmail(emailInput));
        }

        if (passwordInput) {
            passwordInput.addEventListener('input', () => this.validatePassword(passwordInput));
        }
    }

    validateEmail(input) {
        const isValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value);
        this.updateFieldState(input, isValid);
        return isValid;
    }

    validatePassword(input) {
        const strength = this.calculatePasswordStrength(input.value);
        this.updatePasswordStrength(input, strength);
    }

    calculatePasswordStrength(password) {
        let strength = 0;
        if (password.length >= 8) strength++;
        if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
        if (/[0-9]/.test(password)) strength++;
        if (/[^a-zA-Z0-9]/.test(password)) strength++;
        return strength;
    }

    updateFieldState(input, isValid) {
        const field = input.closest('.field');
        if (isValid) {
            field.classList.add('field-success');
            field.classList.remove('field-error');
        } else {
            field.classList.add('field-error');
            field.classList.remove('field-success');
        }
    }

    updatePasswordStrength(input, strength) {
        const strengthText = {
            0: 'Muito fraca',
            1: 'Fraca',
            2: 'Razoável',
            3: 'Forte',
            4: 'Muito forte'
        };

        const strengthElement = input.parentElement.querySelector('.password-strength');
        if (strengthElement) {
            strengthElement.textContent = strengthText[strength];
        }
    }
}

// Inicializar
document.addEventListener('DOMContentLoaded', () => {
    new FormValidator('login-form');
});
```

### 3.3 Melhorar Acessibilidade

**Checklist WCAG AA:**

- ✅ Contraste de cores (4.5:1 para texto)
- ✅ Navegação por teclado (Tab, Enter, Escape)
- ✅ Labels associados a inputs
- ✅ Mensagens de erro descritivas
- ✅ Atributos ARIA apropriados
- ✅ Sem dependência de cor apenas
- ✅ Suporte a leitores de tela

**Teste com:**
```bash
# Lighthouse
npm install -g lighthouse
lighthouse https://seu-dominio.com/login --view

# axe DevTools (extensão Chrome)
# WAVE (extensão Chrome)
```

---

## ⚡ Fase 4: Performance (DESEJÁVEL - Semana 4)

### 4.1 Implementar Preload e Prefetch

```twig
<head>
    <!-- Preload de recursos críticos -->
    <link rel="preload" as="font" href="{{ asset('fonts/inter.woff2') }}" type="font/woff2" crossorigin>
    <link rel="preload" as="style" href="{{ asset('css/base.css') }}">
    
    <!-- Prefetch de recursos secundários -->
    <link rel="prefetch" href="{{ asset('js/form-validation.js') }}">
    <link rel="dns-prefetch" href="https://fonts.googleapis.com">
</head>
```

### 4.2 Implementar Lazy Loading

```html
<img src="placeholder.png" data-src="image.png" loading="lazy" alt="Descrição">
```

### 4.3 Compressão de Assets

**Nginx:**
```nginx
gzip on;
gzip_types text/plain text/css text/javascript application/json application/javascript;
gzip_min_length 1000;
```

---

## 📊 Checklist de Implementação

### Semana 1 (Segurança)
- [ ] Headers de segurança HTTP implementados
- [ ] Rate limiting configurado
- [ ] Validação de input melhorada
- [ ] Testes de segurança executados

### Semana 2 (SEO)
- [ ] Template base atualizado com metadados
- [ ] Página inicial com Schema.org
- [ ] Página de login com metadados
- [ ] Sitemap e robots.txt criados
- [ ] Teste com Google Search Console

### Semana 3 (UX/UI)
- [ ] CSS extraído para arquivos externos
- [ ] Validação client-side implementada
- [ ] Acessibilidade testada (WCAG AA)
- [ ] Teste com Lighthouse

### Semana 4 (Performance)
- [ ] Preload/prefetch implementado
- [ ] Lazy loading ativado
- [ ] Compressão Gzip configurada
- [ ] Core Web Vitals otimizados

---

## 🧪 Testes Recomendados

### Segurança
```bash
# Teste de headers
curl -I https://seu-dominio.com/login

# Teste de CSP
# Use: https://csp-evaluator.withgoogle.com/

# Teste de SSL
# Use: https://www.ssllabs.com/ssltest/
```

### SEO
```bash
# Google Search Console
# Bing Webmaster Tools
# Lighthouse: npm install -g lighthouse
```

### Acessibilidade
```bash
# axe DevTools (Chrome Extension)
# WAVE (Chrome Extension)
# Lighthouse Accessibility
```

### Performance
```bash
# Lighthouse
lighthouse https://seu-dominio.com/login --view

# WebPageTest
# Use: https://www.webpagetest.org/
```

---

## 📝 Notas Importantes

1. **CSP Strict:** A CSP atual permite `'unsafe-inline'` para scripts e styles. Em produção, considere usar nonce ou hash.

2. **Rate Limiting:** Os limites podem ser ajustados conforme necessário no `RateLimiterService`.

3. **HSTS:** Ativado apenas em produção. Cuidado ao ativar em desenvolvimento.

4. **Cache:** Use `Cache-Control` headers para melhor performance.

5. **Monitoramento:** Configure alertas para tentativas de ataque (rate limit exceeded, CSP violations).

---

## 🔗 Referências

- [OWASP Top 10](https://owasp.org/Top10/)
- [Mozilla Web Security](https://developer.mozilla.org/en-US/docs/Web/Security)
- [Google Search Central](https://developers.google.com/search)
- [WCAG 2.1 Guidelines](https://www.w3.org/WAI/WCAG21/quickref/)
- [Web Vitals](https://web.dev/vitals/)

---

## 📞 Suporte

Para dúvidas ou problemas na implementação, consulte:
- Documentação oficial do Symfony: https://symfony.com/doc
- Comunidade Symfony: https://symfony.com/community
- Stack Overflow: tag `symfony`

---

**Última atualização:** 07 de Agosto de 2026  
**Próxima revisão:** Após implementação completa
