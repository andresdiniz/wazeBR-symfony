<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CemadenHidroStationLink;
use App\Entity\CemadenStationLink;
use App\Entity\Partner;
use App\Entity\PartnerApiLink;
use App\Entity\PartnerCameraLink;
use App\Entity\WeatherLocation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/admin/partners/{id}/data', name: 'admin_partner_data_')]
#[IsGranted('ROLE_PARTNER_ADMIN')]
final class AdminPartnerDataController extends AbstractController
{
    private const TYPES = [
        'api' => PartnerApiLink::class,
        'camera' => PartnerCameraLink::class,
        'weather' => WeatherLocation::class,
        'cemaden' => CemadenStationLink::class,
        'hydro' => CemadenHidroStationLink::class,
    ];

    private const FIELDS = [
        'api' => [
            ['name', 'Nome', 'text', true], ['type', 'Categoria', 'select', true], ['url', 'URL do endpoint/feed', 'url', true], ['active', 'Ativo', 'checkbox', false],
        ],
        'camera' => [
            ['name', 'Nome da câmera', 'text', true], ['url', 'URL da câmera', 'url', true], ['latitude', 'Latitude', 'number', true], ['longitude', 'Longitude', 'number', true], ['city', 'Cidade', 'text', false], ['state', 'UF', 'text', false], ['countryCode', 'País (código)', 'text', false], ['provider', 'Provedor', 'text', false], ['notes', 'Observações', 'textarea', false], ['isActive', 'Ativa', 'checkbox', false],
        ],
        'weather' => [
            ['name', 'Nome da estação/local', 'text', true], ['latitude', 'Latitude', 'number', true], ['longitude', 'Longitude', 'number', true], ['city', 'Cidade', 'text', false], ['state', 'Estado', 'text', false], ['countryCode', 'País (código)', 'text', false], ['timezone', 'Fuso horário', 'text', false], ['provider', 'Provedor', 'text', true], ['apiToken', 'Token da API', 'password', false], ['active', 'Ativa', 'checkbox', false],
        ],
        'cemaden' => [
            ['cemadenStationId', 'ID da estação Cemaden', 'number', true], ['stationCode', 'Código', 'text', false], ['stationName', 'Nome da estação', 'text', true], ['latitude', 'Latitude', 'number', true], ['longitude', 'Longitude', 'number', true], ['city', 'Cidade', 'text', false], ['state', 'UF', 'text', false], ['stationType', 'Tipo de estação', 'text', false], ['status', 'Status na origem', 'text', false], ['baseUrl', 'URL base', 'url', true], ['hoursToFetch', 'Horas para buscar', 'number', true], ['active', 'Ativa', 'checkbox', false],
        ],
        'hydro' => [
            ['cemadenTransactionId', 'ID da transação Cemaden', 'number', true], ['stationCode', 'Código', 'text', false], ['stationName', 'Nome da estação', 'text', false], ['city', 'Cidade', 'text', false], ['state', 'UF', 'text', false], ['baseUrl', 'URL de chuva', 'url', true], ['levelBaseUrl', 'URL de nível do rio', 'url', true], ['recordsToFetch', 'Registros para buscar', 'number', true], ['active', 'Ativa', 'checkbox', false],
        ],
    ];

    public function __construct(private readonly EntityManagerInterface $em, private readonly ValidatorInterface $validator) {}

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, Partner $partner): Response
    {
        $requestedPartnerId = $partner->getId();
        $partner = $this->resolvePartnerAccess($partner);
        if ($requestedPartnerId !== $partner->getId()) {
            if ($request->isMethod('POST')) {
                throw $this->createAccessDeniedException('O formulário foi enviado para outro parceiro. Atualize a página e tente novamente.');
            }
            return $this->redirectToRoute('admin_partner_data_index', ['id' => $partner->getId()]);
        }
        $errors = [];
        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token');
            if (!$this->isCsrfTokenValid('partner_data_' . $partner->getId(), $token)) {
                $errors[] = 'A sessão do formulário expirou. Atualize a página e tente novamente.';
            } else {
                $errors = $this->save($partner, $request->request->all());
            }
            if ($errors === []) {
                $this->addFlash('success', 'Dados do parceiro salvos.');
                return $this->redirectToRoute('admin_partner_data_index', ['id' => $partner->getId()]);
            }
        }

        $data = [];
        foreach (self::TYPES as $key => $class) {
            $data[$key] = [
                'items' => $this->em->getRepository($class)->findBy(['partner' => $partner], ['id' => 'DESC']),
                'fields' => self::FIELDS[$key],
            ];
        }

        return $this->render('admin/partner/data.html.twig', [
            'partner' => $partner,
            'data' => $data,
            'errors' => $errors,
            'submitted' => $request->isMethod('POST') ? $request->request->all() : [],
        ]);
    }

    private function save(Partner $partner, array $payload): array
    {
        $kind = (string) ($payload['kind'] ?? '');
        if (!isset(self::TYPES[$kind])) {
            return ['Tipo de cadastro inválido.'];
        }
        $class = self::TYPES[$kind];
        $id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $item = $id ? $this->em->getRepository($class)->find($id) : null;
        if (($payload['action'] ?? '') === 'delete') {
            if (!$item || $item->getPartner()?->getId() !== $partner->getId()) return ['Este registro não pertence ao parceiro selecionado.'];
            $this->em->remove($item);
            $this->em->flush();
            return [];
        }
        if ($id && (!$item || $item->getPartner()?->getId() !== $partner->getId())) {
            return ['Este registro não pertence ao parceiro selecionado.'];
        }
        $item ??= new $class();
        $values = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        try {
            if ($item instanceof PartnerApiLink) {
                $item->setType((string) ($values['type'] ?? ''));
                $item->setName(trim((string) ($values['name'] ?? '')));
                $item->setUrl(trim((string) ($values['url'] ?? '')));
                $item->setActive(isset($values['active']));
                $item->setCreatedAt($item->getCreatedAt() ?? new \DateTimeImmutable());
                $item->setUpdatedAt(new \DateTimeImmutable());
            } elseif ($item instanceof PartnerCameraLink) {
                $item->setName(trim((string) ($values['name'] ?? '')));
                $item->setUrl(trim((string) ($values['url'] ?? '')));
                $item->setLatitude((string) ($values['latitude'] ?? ''));
                $item->setLongitude((string) ($values['longitude'] ?? ''));
                $item->setCity($this->nullable($values, 'city'));
                $item->setState($this->nullable($values, 'state'));
                $item->setCountryCode($this->nullable($values, 'countryCode'));
                $item->setProvider($this->nullable($values, 'provider'));
                $item->setNotes($this->nullable($values, 'notes'));
                $item->setIsActive(isset($values['isActive']));
                $item->setUpdatedAt(new \DateTimeImmutable());
            } elseif ($item instanceof WeatherLocation) {
                $item->setName(trim((string) ($values['name'] ?? '')));
                $item->setLatitude((string) ($values['latitude'] ?? ''));
                $item->setLongitude((string) ($values['longitude'] ?? ''));
                $item->setCity($this->nullable($values, 'city'));
                $item->setState($this->nullable($values, 'state'));
                $item->setCountryCode($this->nullable($values, 'countryCode'));
                $item->setTimezone($this->nullable($values, 'timezone'));
                $item->setProvider(trim((string) ($values['provider'] ?? 'open-meteo')));
                if (trim((string) ($values['apiToken'] ?? '')) !== '') $item->setApiToken(trim((string) $values['apiToken']));
                $item->setActive(isset($values['active']));
                $item->setUpdatedAt(new \DateTimeImmutable());
            } elseif ($item instanceof CemadenStationLink) {
                $item->setCemadenStationId((int) ($values['cemadenStationId'] ?? 0));
                $item->setStationCode($this->nullable($values, 'stationCode'));
                $item->setStationName(trim((string) ($values['stationName'] ?? '')));
                $item->setLatitude((string) ($values['latitude'] ?? ''));
                $item->setLongitude((string) ($values['longitude'] ?? ''));
                $item->setCity($this->nullable($values, 'city'));
                $item->setState($this->nullable($values, 'state'));
                $item->setStationType($this->nullable($values, 'stationType'));
                $item->setStatus($this->nullable($values, 'status'));
                $item->setBaseUrl(trim((string) ($values['baseUrl'] ?? '')));
                $item->setHoursToFetch((int) ($values['hoursToFetch'] ?? 24));
                $item->setActive(isset($values['active']));
                $item->setUpdatedAt(new \DateTimeImmutable());
            } elseif ($item instanceof CemadenHidroStationLink) {
                $item->setCemadenTransactionId((int) ($values['cemadenTransactionId'] ?? 0));
                $item->setStationCode($this->nullable($values, 'stationCode'));
                $item->setStationName($this->nullable($values, 'stationName'));
                $item->setCity($this->nullable($values, 'city'));
                $item->setState($this->nullable($values, 'state'));
                $item->setBaseUrl(trim((string) ($values['baseUrl'] ?? '')));
                $item->setLevelBaseUrl(trim((string) ($values['levelBaseUrl'] ?? '')));
                $item->setRecordsToFetch((int) ($values['recordsToFetch'] ?? 24));
                $item->setActive(isset($values['active']));
                $item->setUpdatedAt(new \DateTimeImmutable());
            }
            $item->setPartner($partner);
        } catch (\Throwable $exception) {
            return [$exception->getMessage()];
        }

        $violations = $this->validator->validate($item);
        if (count($violations) > 0) {
            foreach ($violations as $violation) $errors[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
            return $errors;
        }
        if ($item instanceof PartnerApiLink && (!filter_var($item->getUrl(), FILTER_VALIDATE_URL) || trim((string) $item->getName()) === '')) {
            return ['Informe nome e uma URL válida para o link.'];
        }

        try {
            $this->em->persist($item);
            $this->em->flush();
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            return ['Já existe um registro com esse identificador ou coordenadas para este parceiro.'];
        }
        return [];
    }

    private function nullable(array $values, string $key): ?string
    {
        $value = trim((string) ($values[$key] ?? ''));
        return $value !== '' ? $value : null;
    }

    private function resolvePartnerAccess(Partner $requestedPartner): Partner
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User || $user->getId() === null) {
            throw $this->createAccessDeniedException();
        }

        $partnerId = $this->em->createQueryBuilder()
            ->select('IDENTITY(account.partner)')
            ->from(\App\Entity\User::class, 'account')
            ->where('account.id = :userId')
            ->setParameter('userId', $user->getId())
            ->getQuery()
            ->getSingleScalarResult();
        $hasGlobalRole = array_intersect($user->getRoles(), [\App\Entity\User::ROLE_ADMIN, \App\Entity\User::ROLE_SUPER_ADMIN]) !== [];

        if ($hasGlobalRole && $partnerId === null) {
            return $requestedPartner;
        }
        if (!$this->isGranted('ROLE_PARTNER_ADMIN') || $partnerId === null) {
            throw $this->createAccessDeniedException('Seu usuário não possui parceiro vinculado para administrar.');
        }

        return $this->em->getReference(Partner::class, (int) $partnerId);
    }
}
