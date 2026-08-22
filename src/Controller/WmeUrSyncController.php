<?php
// src/Controller/WmeUrSyncController.php
namespace App\Controller;

use App\Entity\WmeUpdateRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class WmeUrSyncController
{
    public function __construct(private EntityManagerInterface $em) {}

    #[Route('/api/wme/urs', methods: ['POST'])]
    public function receive(Request $request): JsonResponse
    {
        $expected = $_ENV['WME_UR_SYNC_TOKEN'] ?? '';
        $provided = preg_replace('/^Bearer\\s+/i', '', $request->headers->get('Authorization', ''));
        if ($expected === '' || !hash_equals($expected, $provided)) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try { $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return new JsonResponse(['error' => 'invalid_json'], 400); }

        $items = $body['items'] ?? null;
        if (!is_array($items) || count($items) > 100) {
            return new JsonResponse(['error' => 'invalid_batch'], 422);
        }

        $repo = $this->em->getRepository(WmeUpdateRequest::class);
        $accepted = 0;

        foreach ($items as $item) {
            $id = isset($item['id']) ? (string) $item['id'] : '';
            $lat = filter_var($item['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $lon = filter_var($item['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($id === '' || $lat === false || $lon === false || abs($lat) > 90 || abs($lon) > 180) continue;

            $entity = $repo->findOneBy(['externalId' => $id]) ?? (new WmeUpdateRequest())->setExternalId($id);
            $entity->setType(isset($item['type']) ? (string) $item['type'] : null)
                ->setStatus('open')
                ->setDescription(isset($item['description']) ? (string) $item['description'] : null)
                ->setSeverity(isset($item['severity']) ? (string) $item['severity'] : null)
                ->setSource(isset($item['source']) ? (string) $item['source'] : null)
                ->setLatitude((float) $lat)
                ->setLongitude((float) $lon)
                ->setReportedAt($this->date($item['reportedOn'] ?? null) ?? new \DateTimeImmutable())
                ->setCollectedAt($this->date($item['collectedAt'] ?? null) ?? new \DateTimeImmutable())
                ->setUpdatedAt(new \DateTimeImmutable());
            $this->em->persist($entity);
            $accepted++;
        }

        $this->em->flush();
        return new JsonResponse(['accepted' => $accepted]);
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) return (new \DateTimeImmutable())->setTimestamp((int) $value / 1000);
        try { return new \DateTimeImmutable((string) $value); } catch (\Exception) { return null; }
    }
}
