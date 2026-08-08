# Checklist de Validação e Testes
## wazeBR-symfony - Melhorias de Layout, SEO e Segurança

**Data:** 07 de Agosto de 2026  
**Status:** Pronto para Validação  
**Responsável:** Equipe de QA

---

## ✅ Validação de Segurança

### Headers HTTP
- [ ] **Content-Security-Policy** presente e configurado corretamente
  - Teste: `curl -I https://seu-dominio.com/login | grep -i content-security`
  - Esperado: Header presente com directives apropriadas

- [ ] **X-Frame-Options: DENY** configurado
  - Teste: `curl -I https://seu-dominio.com/login | grep -i x-frame`
  - Esperado: `X-Frame-Options: DENY`

- [ ] **X-Content-Type-Options: nosniff** configurado
  - Teste: `curl -I https://seu-dominio.com/login | grep -i x-content`
  - Esperado: `X-Content-Type-Options: nosniff`

- [ ] **Strict-Transport-Security** em produção
  - Teste: `curl -I https://seu-dominio.com/login | grep -i strict`
  - Esperado: Header presente com `max-age` apropriado

### Rate Limiting
- [ ] Login rate limiting funciona (5 tentativas/minuto)
  - Teste: Fazer 6 tentativas de login em menos de 1 minuto
  - Esperado: 6ª tentativa bloqueada com mensagem de erro

- [ ] Forgot password rate limiting funciona (3 solicitações/hora)
  - Teste: Fazer 4 solicitações de reset em menos de 1 hora
  - Esperado: 4ª solicitação bloqueada

- [ ] Reset password rate limiting funciona (5 tentativas/hora)
  - Teste: Fazer 6 tentativas em menos de 1 hora
  - Esperado: 6ª tentativa bloqueada

### CSRF Protection
- [ ] CSRF token presente no formulário de login
  - Teste: Inspecionar HTML do formulário
  - Esperado: Campo `_csrf_token` presente

- [ ] Submissão sem CSRF token é rejeitada
  - Teste: Remover token e tentar enviar
  - Esperado: Erro 400 ou mensagem de erro

### Validação de Input
- [ ] Email inválido é rejeitado
  - Teste: Tentar login com email inválido
  - Esperado: Erro de validação

- [ ] Senha vazia é rejeitada
  - Teste: Deixar senha em branco
  - Esperado: Erro de validação

- [ ] Caracteres especiais são tratados corretamente
  - Teste: Tentar email com caracteres especiais
  - Esperado: Validação apropriada

### Session Security
- [ ] Cookie de sessão tem HttpOnly flag
  - Teste: Verificar headers de resposta
  - Esperado: `Set-Cookie: ... HttpOnly; Secure; SameSite=Lax`

- [ ] Cookie de sessão tem Secure flag em produção
  - Teste: Em HTTPS, verificar headers
  - Esperado: Flag Secure presente

- [ ] SameSite=Lax configurado
  - Teste: Verificar headers de resposta
  - Esperado: `SameSite=Lax` presente

---

## 🎨 Validação de Layout e UX

### Responsividade
- [ ] Layout funciona em desktop (1920px+)
  - Teste: Abrir em navegador desktop
  - Esperado: Layout bem distribuído

- [ ] Layout funciona em tablet (768px-1024px)
  - Teste: Usar DevTools com viewport 768px
  - Esperado: Layout adaptado corretamente

- [ ] Layout funciona em mobile (320px-480px)
  - Teste: Usar DevTools com viewport 375px
  - Esperado: Layout mobile funcional

- [ ] Imagens são responsivas
  - Teste: Redimensionar janela
  - Esperado: Imagens se adaptam sem distorção

### Interatividade
- [ ] Toggle de senha funciona
  - Teste: Clicar no ícone de olho
  - Esperado: Senha fica visível/invisível

- [ ] Indicador de força de senha funciona
  - Teste: Digitar diferentes senhas
  - Esperado: Indicador muda de cor (vermelho → amarelo → verde)

- [ ] Botão de submit desabilita durante envio
  - Teste: Clicar em "Entrar"
  - Esperado: Botão fica desabilitado com spinner

- [ ] Animações funcionam suavemente
  - Teste: Abrir página e observar
  - Esperado: Animações fluidas sem travamentos

### Acessibilidade
- [ ] Navegação por teclado funciona
  - Teste: Usar Tab para navegar
  - Esperado: Todos os elementos focáveis são acessíveis

- [ ] Labels estão associados aos inputs
  - Teste: Clicar no label
  - Esperado: Foco vai para o input

- [ ] Atributos ARIA presentes
  - Teste: Inspecionar HTML
  - Esperado: `aria-label`, `aria-describedby` presentes

- [ ] Contraste de cores adequado (WCAG AA)
  - Teste: Usar ferramenta de contraste
  - Esperado: Razão de contraste ≥ 4.5:1

- [ ] Leitores de tela funcionam
  - Teste: Usar NVDA ou JAWS
  - Esperado: Conteúdo é lido corretamente

---

## 🔍 Validação de SEO

### Meta Tags
- [ ] Title tag presente e descritivo
  - Teste: Inspecionar `<title>`
  - Esperado: Título entre 30-60 caracteres

- [ ] Meta description presente
  - Teste: Inspecionar `<meta name="description">`
  - Esperado: Descrição entre 120-160 caracteres

- [ ] Canonical tag presente
  - Teste: Inspecionar `<link rel="canonical">`
  - Esperado: URL canônica correta

- [ ] Open Graph tags presentes
  - Teste: Inspecionar `<meta property="og:*">`
  - Esperado: og:title, og:description, og:image, og:url

- [ ] Twitter Card tags presentes
  - Teste: Inspecionar `<meta name="twitter:*">`
  - Esperado: twitter:card, twitter:title, twitter:description

### Structured Data
- [ ] Schema.org Organization presente
  - Teste: Inspecionar `<script type="application/ld+json">`
  - Esperado: JSON-LD com Organization schema

- [ ] FAQPage schema presente (home)
  - Teste: Inspecionar JSON-LD
  - Esperado: FAQPage com mainEntity

- [ ] Schema é válido
  - Teste: Usar https://schema.org/validator
  - Esperado: Nenhum erro de validação

### Heading Hierarchy
- [ ] Apenas um H1 por página
  - Teste: Contar tags H1
  - Esperado: Exatamente 1 H1

- [ ] Hierarquia H1 → H2 → H3 respeitada
  - Teste: Verificar ordem dos headings
  - Esperado: Sem saltos de nível

- [ ] Headings têm conteúdo descritivo
  - Teste: Ler headings
  - Esperado: Descrevem o conteúdo

### Sitemap e Robots.txt
- [ ] robots.txt presente e acessível
  - Teste: `curl https://seu-dominio.com/robots.txt`
  - Esperado: Arquivo retorna com status 200

- [ ] sitemap.xml presente e válido
  - Teste: `curl https://seu-dominio.com/sitemap.xml`
  - Esperado: XML válido com URLs

- [ ] Robots.txt aponta para sitemap
  - Teste: Verificar conteúdo de robots.txt
  - Esperado: Linha `Sitemap:` presente

---

## ⚡ Validação de Performance

### Core Web Vitals
- [ ] Largest Contentful Paint (LCP) < 2.5s
  - Teste: Lighthouse ou PageSpeed Insights
  - Esperado: Score verde (≥ 75)

- [ ] First Input Delay (FID) < 100ms
  - Teste: Lighthouse ou PageSpeed Insights
  - Esperado: Score verde (≥ 75)

- [ ] Cumulative Layout Shift (CLS) < 0.1
  - Teste: Lighthouse ou PageSpeed Insights
  - Esperado: Score verde (≥ 75)

### Lighthouse Score
- [ ] Performance ≥ 80
  - Teste: Executar Lighthouse
  - Esperado: Score ≥ 80

- [ ] Accessibility ≥ 90
  - Teste: Executar Lighthouse
  - Esperado: Score ≥ 90

- [ ] Best Practices ≥ 90
  - Teste: Executar Lighthouse
  - Esperado: Score ≥ 90

- [ ] SEO ≥ 90
  - Teste: Executar Lighthouse
  - Esperado: Score ≥ 90

### Compressão
- [ ] Gzip habilitado
  - Teste: `curl -I -H "Accept-Encoding: gzip" https://seu-dominio.com/login | grep -i content-encoding`
  - Esperado: `Content-Encoding: gzip`

- [ ] CSS é comprimido
  - Teste: Verificar tamanho do CSS
  - Esperado: Tamanho reduzido com Gzip

- [ ] JavaScript é comprimido
  - Teste: Verificar tamanho do JS
  - Esperado: Tamanho reduzido com Gzip

---

## 🧪 Testes Funcionais

### Fluxo de Login
- [ ] Login com credenciais corretas funciona
  - Teste: Usar credenciais válidas
  - Esperado: Redirecionamento para dashboard

- [ ] Login com credenciais incorretas mostra erro
  - Teste: Usar credenciais inválidas
  - Esperado: Mensagem de erro clara

- [ ] "Lembrar-me" funciona
  - Teste: Marcar checkbox e fazer login
  - Esperado: Cookie de sessão persistente

- [ ] Logout funciona
  - Teste: Clicar em logout
  - Esperado: Redirecionamento para login

### Fluxo de Reset de Senha
- [ ] Página "Esqueci a Senha" carrega
  - Teste: Acessar /esqueci-senha
  - Esperado: Formulário carrega

- [ ] Email de reset é enviado
  - Teste: Solicitar reset
  - Esperado: Email recebido em 1-2 minutos

- [ ] Link de reset funciona
  - Teste: Clicar no link do email
  - Esperado: Página de reset carrega

- [ ] Nova senha é aceita
  - Teste: Definir nova senha
  - Esperado: Redirecionamento para login

---

## 🔐 Testes de Segurança Avançados

### Injection Attacks
- [ ] SQL Injection não funciona
  - Teste: Tentar SQL injection em login
  - Esperado: Erro de validação

- [ ] XSS não funciona
  - Teste: Tentar injetar script
  - Esperado: Conteúdo escapado

- [ ] CSRF não funciona
  - Teste: Tentar submissão sem token
  - Esperado: Erro 400

### Brute Force
- [ ] Brute force é bloqueado
  - Teste: Múltiplas tentativas rápidas
  - Esperado: IP bloqueado após limite

- [ ] Mensagem de erro não revela informações
  - Teste: Tentar diferentes emails
  - Esperado: Mensagem genérica

### Session Hijacking
- [ ] Cookie de sessão não pode ser acessado via JavaScript
  - Teste: Tentar `document.cookie`
  - Esperado: Cookie não aparece (HttpOnly)

- [ ] Cookie não é transmitido em HTTP
  - Teste: Verificar em HTTP (dev)
  - Esperado: Cookie tem flag Secure em produção

---

## 📋 Testes de Compatibilidade

### Navegadores
- [ ] Chrome (últimas 2 versões)
  - Teste: Abrir em Chrome
  - Esperado: Funciona perfeitamente

- [ ] Firefox (últimas 2 versões)
  - Teste: Abrir em Firefox
  - Esperado: Funciona perfeitamente

- [ ] Safari (últimas 2 versões)
  - Teste: Abrir em Safari
  - Esperado: Funciona perfeitamente

- [ ] Edge (últimas 2 versões)
  - Teste: Abrir em Edge
  - Esperado: Funciona perfeitamente

### Dispositivos
- [ ] iPhone (iOS 14+)
  - Teste: Abrir em iPhone
  - Esperado: Layout responsivo

- [ ] Android (Android 10+)
  - Teste: Abrir em Android
  - Esperado: Layout responsivo

- [ ] iPad (iPadOS 14+)
  - Teste: Abrir em iPad
  - Esperado: Layout responsivo

---

## 📊 Relatório de Testes

### Resumo
| Categoria | Total | Passou | Falhou | Taxa de Sucesso |
|-----------|-------|--------|--------|-----------------|
| Segurança | 15 | - | - | - |
| Layout | 12 | - | - | - |
| SEO | 14 | - | - | - |
| Performance | 11 | - | - | - |
| Funcional | 8 | - | - | - |
| Compatibilidade | 8 | - | - | - |
| **TOTAL** | **68** | **-** | **-** | **-** |

### Observações
```
[Espaço para anotações do testador]
```

### Problemas Encontrados
```
[Lista de problemas encontrados durante os testes]
```

### Recomendações
```
[Recomendações para melhorias futuras]
```

---

## ✍️ Assinatura

**Testador:** ________________  
**Data:** ________________  
**Status Final:** ☐ Aprovado ☐ Aprovado com Ressalvas ☐ Reprovado

---

## 📞 Contato

Para dúvidas ou problemas durante os testes:
- Documentação: `/IMPLEMENTATION_GUIDE.md`
- Auditoria: `/AUDIT_REPORT.md`
- Suporte: [Contato do projeto]

---

**Última atualização:** 07 de Agosto de 2026
