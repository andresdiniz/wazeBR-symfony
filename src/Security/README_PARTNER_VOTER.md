# PartnerVoter - Guia de Uso

## Visao Geral

O `PartnerVoter` valida o acesso a dados baseado no partner do usuario, garantindo que:

- **ROLE_ADMIN global** (sem partner): Acessa todos os dados de todos os partners
- **ROLE_PARTNER_ADMIN**: Gerencia apenas dados do seu partner
- **ROLE_OPERATOR**: Visualiza/edita apenas dados do seu partner
- **ROLE_VIEWER**: Visualiza apenas dados do seu partner

## Como Usar nos Controllers

### Exemplo 1: Visualizar um Alert

```php
#[Route('/alert/{id}', name: 'app_alert_show', methods: ['GET'])]
public function show(Alert $alert, Security $security): Response
{
    // Verifica se o usuario pode visualizar este alert
    $this->denyAccessUnlessGranted(PartnerVoter::VIEW, $alert);
    
    return $this->render('alert/show.html.twig', [
        'alert' => $alert,
    ]);
}
```

### Exemplo 2: Editar um Alert

```php
#[Route('/alert/{id}/edit', name: 'app_alert_edit', methods: ['GET', 'POST'])]
public function edit(Alert $alert, Request $request, Security $security): Response
{
    // Verifica se o usuario pode editar este alert
    $this->denyAccessUnlessGranted(PartnerVoter::EDIT, $alert);
    
    // ... logica de edicao
}
```

### Exemplo 3: Deletar um Alert

```php
#[Route('/alert/{id}', name: 'app_alert_delete', methods: ['POST'])]
public function delete(Alert $alert, Request $request, Security $security): Response
{
    // Verifica se o usuario pode deletar este alert
    $this->denyAccessUnlessGranted(PartnerVoter::DELETE, $alert);
    
    // ... logica de exclusao
}
```

### Exemplo 4: Listar Alerts (Query com Filtro por Partner)

```php
#[Route('/alerts', name: 'app_alert_index', methods: ['GET'])]
public function index(AlertRepository $alertRepository, Security $security): Response
{
    /** @var User $user */
    $user = $this->getUser();
    
    // Admin global ve todos os alerts
    if ($user->isGlobalAdmin()) {
        $alerts = $alertRepository->findAll();
    } else {
        // Usuarios com partner veem apenas alerts do seu partner
        $alerts = $alertRepository->findBy(['partner' => $user->getPartner()]);
    }
    
    return $this->render('alert/index.html.twig', [
        'alerts' => $alerts,
    ]);
}
```

## Atributos do Voter

| Atributo | Descricao | Uso |
|----------|-----------|-----|
| `PartnerVoter::VIEW` | Permite visualizacao | `denyAccessUnlessGranted(PartnerVoter::VIEW, $alert)` |
| `PartnerVoter::EDIT` | Permite edicao | `denyAccessUnlessGranted(PartnerVoter::EDIT, $alert)` |
| `PartnerVoter::DELETE` | Permite exclusao | `denyAccessUnlessGranted(PartnerVoter::DELETE, $alert)` |

## Objetos Suportados

O voter funciona com:

1. **Objetos `Partner` diretamente**
   ```php
   $this->denyAccessUnlessGranted(PartnerVoter::VIEW, $partner);
   ```

2. **Objetos com metodo `getPartner()`** (Alert, Camera, Route, etc.)
   ```php
   $this->denyAccessUnlessGranted(PartnerVoter::VIEW, $alert);
   $this->denyAccessUnlessGranted(PartnerVoter::VIEW, $camera);
   ```

## Exemplo Completo: AlertController

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Alert;
use App\Entity\User;
use App\Repository\AlertRepository;
use App\Security\PartnerVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/alerts')]
class AlertController extends AbstractController
{
    #[Route('', name: 'app_alert_index', methods: ['GET'])]
    public function index(AlertRepository $alertRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        
        // Admin global ve todos, outros veem apenas do seu partner
        if ($user->isGlobalAdmin()) {
            $alerts = $alertRepository->findAll();
        } else {
            $alerts = $alertRepository->findBy(['partner' => $user->getPartner()]);
        }
        
        return $this->render('alert/index.html.twig', [
            'alerts' => $alerts,
        ]);
    }

    #[Route('/{id}', name: 'app_alert_show', methods: ['GET'])]
    public function show(Alert $alert): Response
    {
        $this->denyAccessUnlessGranted(PartnerVoter::VIEW, $alert);
        
        return $this->render('alert/show.html.twig', [
            'alert' => $alert,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_alert_edit', methods: ['GET', 'POST'])]
    public function edit(Alert $alert, Request $request): Response
    {
        $this->denyAccessUnlessGranted(PartnerVoter::EDIT, $alert);
        
        // ... logica de edicao
    }

    #[Route('/{id}', name: 'app_alert_delete', methods: ['POST'])]
    public function delete(Alert $alert, Request $request): Response
    {
        $this->denyAccessUnlessGranted(PartnerVoter::DELETE, $alert);
        
        // ... logica de exclusao
    }
}
```

## Queries no Repository

### Exemplo: AlertRepository

```php
<?php

namespace App\Repository;

use App\Entity\Alert;
use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

class AlertRepository extends ServiceEntityRepository
{
    public function findByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
    
    public function findAllWithPartnerFilter(?Partner $partner = null): array
    {
        $qb = $this->createQueryBuilder('a');
        
        if ($partner !== null) {
            $qb->where('a.partner = :partner')
               ->setParameter('partner', $partner);
        }
        
        return $qb->orderBy('a.createdAt', 'DESC')
                  ->getQuery()
                  ->getResult();
    }
}
```

## Uso em Twig

```twig
{# Verificar permissao no template #}
{% if is_granted(constant('App\\Security\\PartnerVoter::EDIT'), alert) %}
    <a href="{{ path('app_alert_edit', {id: alert.id}) }}">Editar</a>
{% endif %}

{% if is_granted(constant('App\\Security\\PartnerVoter::DELETE'), alert) %}
    <form method="post" action="{{ path('app_alert_delete', {id: alert.id}) }}">
        <button type="submit">Excluir</button>
    </form>
{% endif %}
```

## Boas Praticas

1. **Sempre use o voter em controllers** antes de acessar dados
2. **Filtre queries no repository** pelo partner do usuario
3. **Admin global e excecao** - pode acessar tudo
4. **Teste sempre** com usuarios de partners diferentes
5. **Documente** quais rotas requerem validacao por partner

## Referencias

- `doc/PRODUCT_RULES.md` - Regras de negocio
- `src/Entity/User.php` - Entity User com metodos helper
- `config/packages/security.yaml` - Hierarquia de roles
