# Guia de Atualizacao de Controllers e Repositories

## Visao Geral

Este documento descreve como atualizar todos os controllers e repositories do projeto para usar o sistema de validacao por partner implementado no `PartnerVoter`.

## Principios

### 1. Controllers
- **Sempre** verificar permissao com `PartnerVoter` antes de acessar dados
- **Sempre** filtrar listas por partner do usuario (exceto admin global)
- Usar metodos helper do User: `isGlobalAdmin()`, `getPartner()`

### 2. Repositories
- Adicionar metodos que aceitam `?Partner $partner = null`
- Admin global passa `null` para ver tudo
- Usuarios com partner filtram pelo seu partner

## Lista de Arquivos para Atualizar

### Controllers Prioritarios (entidades com partner)

1. `AlertController.php` - Entidade Alert com partner
2. `CifsEventController.php` - Eventos Cifs com partner
3. `DashboardController.php` - Dashboard com dados por partner
4. `HydroController.php` - Dados hidrologicos por partner/city
5. `NotificationController.php` - Notificacoes por usuario/partner
6. `TrafficJamController.php` - Traffic jams com partner
7. `RouteAdminController.php` - Rotas por partner
8. `PartnerAdminController.php` - Gestao de usuarios do partner
9. `OperatorController.php` - Operadores por partner

### Controllers Secundarios (sem partner ou admin)

10. `AccountUserController.php` - Dados do proprio usuario
11. `AccountSettingsController.php` - Settings do usuario
12. `AccountLinkController.php` - Links do usuario
13. `AuthController.php` - Autenticacao
14. `SecurityController.php` - Login/logout
15. `HomeController.php` - Home publica
16. `HealthController.php` - Health check
17. `CronController.php` - Jobs agendados
18. `ApiController.php` - API externa
19. `CemadenController.php` - Dados externos (sem partner)
20. `MonitoredCityController.php` - Cidades monitoradas (global)
21. `WazeTrafficJamController.php` - Waze data (sem partner)
22. `WmeUrSyncController.php` - Sync WME (sem partner)

### Repositories Prioritarios

1. `WazeAlertRepository.php` - Alerts por partner
2. `WazeTrafficJamRepository.php` - Traffic jams
3. `WazeIrregularityRepository.php` - Irregularidades
4. `CifsEventRepository.php` - Eventos Cifs
5. `NotificationRepository.php` - Notificacoes
6. `UserRepository.php` - Usuarios por partner
7. `PartnerRepository.php` - Parceiros
8. `WazeRouteRepository.php` - Rotas
9. `WazeCountRepository.php` - Contadores

## Padroes de Implementacao

### Pattern 1: Controller Show/View

```php
#[Route('/{id}', name: 'app_entity_show', methods: ['GET'])]
public function show(Entity $entity): Response
{
    // Validar acesso por partner
    $this->denyAccessUnlessGranted(PartnerVoter::VIEW, $entity);
    
    return $this->render('entity/show.html.twig', [
        'entity' => $entity,
    ]);
}
```

### Pattern 2: Controller Index/List

```php
#[Route('', name: 'app_entity_index', methods: ['GET'])]
public function index(EntityRepository $repository): Response
{
    /** @var User $user */
    $user = $this->getUser();
    
    // Admin global ve tudo, outros veem apenas do seu partner
    if ($user->isGlobalAdmin()) {
        $entities = $repository->findAll();
    } else {
        $entities = $repository->findByPartner($user->getPartner());
    }
    
    return $this->render('entity/index.html.twig', [
        'entities' => $entities,
    ]);
}
```

### Pattern 3: Controller Edit

```php
#[Route('/{id}/edit', name: 'app_entity_edit', methods: ['GET', 'POST'])]
public function edit(Entity $entity, Request $request): Response
{
    // Validar permissao de edicao por partner
    $this->denyAccessUnlessGranted(PartnerVoter::EDIT, $entity);
    
    // ... logica de edicao
}
```

### Pattern 4: Controller Delete

```php
#[Route('/{id}', name: 'app_entity_delete', methods: ['POST'])]
public function delete(Entity $entity, Request $request): Response
{
    // Validar permissao de delete por partner
    $this->denyAccessUnlessGranted(PartnerVoter::DELETE, $entity);
    
    // ... logica de delete
}
```

### Pattern 5: Repository findByPartner

```php
public function findByPartner(Partner $partner): array
{
    return $this->createQueryBuilder('e')
        ->where('e.partner = :partner')
        ->setParameter('partner', $partner)
        ->orderBy('e.createdAt', 'DESC')
        ->getQuery()
        ->getResult();
}
```

### Pattern 6: Repository findAllWithPartnerFilter

```php
public function findAllWithPartnerFilter(?Partner $partner = null): array
{
    $qb = $this->createQueryBuilder('e');
    
    if ($partner !== null) {
        $qb->where('e.partner = :partner')
           ->setParameter('partner', $partner);
    }
    
    return $qb->orderBy('e.createdAt', 'DESC')
              ->getQuery()
              ->getResult();
}
```

## Checklist de Implementacao

### Para cada Controller:

- [ ] Importar `PartnerVoter`
- [ ] Adicionar `denyAccessUnlessGranted(PartnerVoter::VIEW, $entity)` em actions de visualizacao
- [ ] Adicionar `denyAccessUnlessGranted(PartnerVoter::EDIT, $entity)` em actions de edicao
- [ ] Adicionar `denyAccessUnlessGranted(PartnerVoter::DELETE, $entity)` em actions de delete
- [ ] Filtrar listas por partner do usuario
- [ ] Usar `isGlobalAdmin()` para verificar se admin global
- [ ] Testar com usuario de partner diferente

### Para cada Repository:

- [ ] Adicionar metodo `findByPartner(Partner $partner)`
- [ ] Adicionar metodo `findAllWithPartnerFilter(?Partner $partner = null)`
- [ ] Garantir que queries usam WHERE e.partner = :partner quando necessario
- [ ] Testar queries com e sem partner

## Exemplo Completo: AlertController

Veja o arquivo atualizado `src/Controller/AlertController.php` para um exemplo completo.

## Exemplo Completo: WazeAlertRepository

Veja o arquivo atualizado `src/Repository/WazeAlertRepository.php` para um exemplo completo.

## Testes

### Teste Manual

1. Logar como admin global (sem partner) -> deve ver todos os dados
2. Logar como usuario Partner A -> deve ver apenas dados do Partner A
3. Tentar acessar dado do Partner B -> deve receber erro 403

### Teste Automatizado

```php
public function testPartnerAccess(): void
{
    $user1 = $this->createUserWithPartner('partner1');
    $user2 = $this->createUserWithPartner('partner2');
    $admin = $this->createGlobalAdmin();
    
    $alert = $this->createAlert('partner1');
    
    // User1 pode ver (mesmo partner)
    $this->assertTrue($this->isGranted(PartnerVoter::VIEW, $alert, $user1));
    
    // User2 NAO pode ver (partner diferente)
    $this->assertFalse($this->isGranted(PartnerVoter::VIEW, $alert, $user2));
    
    // Admin pode ver (global)
    $this->assertTrue($this->isGranted(PartnerVoter::VIEW, $alert, $admin));
}
```

## Referencias

- `doc/PRODUCT_RULES.md` - Regras de negocio
- `src/Entity/User.php` - Metodos helper
- `config/packages/security.yaml` - Hierarquia de roles
- `src/Security/PartnerVoter.php` - Voter implementation
- `src/Security/README_PARTNER_VOTER.md` - Guia de uso do voter
