<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\WazeAlertRepository;
use App\Repository\WazeAlertTypeRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/alertas', name: 'alert_')]
#[IsGranted('ROLE_USER')]
class AlertController extends AbstractController
{
    private const PERIOD_PRESETS = ['today' => ['label' => 'Hoje', 'days' => 0], 'yesterday' => ['label' => 'Ontem', 'days' => 1], 'week' => ['label' => '7 dias', 'days' => 7], 'month' => ['label' => '30 dias', 'days' => 30], 'six_months' => ['label' => '6 meses', 'days' => 182], 'year' => ['label' => '1 ano', 'days' => 365]];
    public function __construct(private readonly TenantContext $tenantContext, private readonly WazeAlertRepository $alertRepo, private readonly WazeAlertTypeRepository $alertTypeRepo) {}
    private function filtersFromRequest(Request $request): array { return ['type' => $request->query->get('type') ?: null, 'subtype' => $request->query->get('subtype') ?: null, 'city' => $request->query->get('city') ?: null, 'street' => $request->query->get('street') ?: null, 'excludeStreet' => $request->query->get('excludeStreet') ?: null, 'dateFrom' => $request->query->get('dateFrom') ?: null, 'dateTo' => $request->query->get('dateTo') ?: null]; }
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response { $partner=$this->tenantContext->requirePartner(); $locale=$request->getLocale()?:'pt'; $filters=$this->filtersFromRequest($request); $result=$this->alertRepo->findFilteredByPartner($partner,$filters,max(1,(int)$request->query->get('page',1)),30); $bySubtype=array_map(static fn(array $row): array => ['label'=>($row['subtype'] ?: 'Sem subtipo'),'total'=>(int)$row['total']],$this->alertRepo->countBySubtypeFiltered($partner,$filters,10)); $byDay=$this->alertRepo->countByDayFiltered($partner,$filters); $byHour=$this->alertRepo->countByHourOfDayFiltered($partner,$filters); return $this->render('alert/index.html.twig',['partner'=>$partner,'alerts'=>$result['items'],'total'=>$result['total'],'page'=>1,'pages'=>$result['pages'],'types'=>$this->alertRepo->findDistinctTypes($partner),'subtypes'=>$this->alertRepo->findDistinctSubtypes($partner,$filters['type']),'cities'=>$this->alertRepo->findDistinctCities($partner),'streets'=>$this->alertRepo->findDistinctStreets($partner),'bySubtype'=>$bySubtype,'byConfidence'=>$this->alertRepo->countByConfidenceFiltered($partner,$filters),'byDay'=>$byDay,'byHour'=>$byHour,'byHourTrend'=>$byDay,'trendType'=>'day','byWeekday'=>$this->alertRepo->countByWeekdayFiltered($partner,$filters),'topStreets'=>$this->alertRepo->topStreetsFiltered($partner,$filters,10),'hotspots'=>$this->alertRepo->findHotspotsFiltered($partner,$filters,15),'mapAlerts'=>$this->alertRepo->findForMapFiltered($partner,$filters,500),'type'=>$filters['type'],'subtype'=>$filters['subtype'],'city'=>$filters['city'],'street'=>$filters['street'],'excludeStreet'=>$filters['excludeStreet'],'period'=>$request->query->get('period'),'periods'=>self::PERIOD_PRESETS,'dateFrom'=>$filters['dateFrom'],'dateTo'=>$filters['dateTo'],'typesMap'=>$this->alertTypeRepo->getTypesMap($locale),'subtypesMap'=>$this->alertTypeRepo->getSubtypesMap($locale)]); }
    #[Route('/export.csv', name: 'export', methods: ['GET'])]
    public function export(Request $request): Response { $partner=$this->tenantContext->requirePartner(); $alerts=$this->alertRepo->findAllFilteredByPartnerForExport($partner,$this->filtersFromRequest($request)); $output=fopen('php://temp','w+'); fputcsv($output,['ID','Tipo','Subtipo','Via','Cidade','Publicado','Confiança','Curtidas','Latitude','Longitude'],';'); foreach($alerts as $a) fputcsv($output,[$a->getId(),$a->getType(),$a->getSubtype(),$a->getStreet(),$a->getCity(),$a->getPubMillis(),$a->getConfidence(),$a->getNThumbsUp(),$a->getLatitude(),$a->getLongitude()],';'); rewind($output); $content=stream_get_contents($output); fclose($output); $response=new Response("\xEF\xBB\xBF".$content); $response->headers->set('Content-Type','text/csv; charset=UTF-8'); $response->headers->set('Content-Disposition','attachment; filename=alertas.csv'); return $response; }
    #[Route('/ao-vivo', name: 'live', methods: ['GET'])]
    public function live(Request $request): Response { $partner=$this->tenantContext->requirePartner(); $locale=$request->getLocale()?:'pt'; $alerts=$this->alertRepo->findActiveByPartner($partner,10); $regions=[]; foreach($alerts as $a){$city=$a->getCity()?:'Sem cidade';$regions[$city]=($regions[$city]??0)+1;} arsort($regions); $regionRows=array_map(static fn($city,$count)=>['city'=>$city,'count'=>$count],array_keys($regions),array_values($regions)); dump(['rota'=>'alert_live','total_alertas_ativos'=>count($alerts),'regioes'=>$regionRows,'ids'=>array_map(static fn($a)=>$a->getId(),$alerts),'pub_millis'=>array_map(static fn($a)=>$a->getPubMillis(),$alerts),'agora_millis'=>time()*1000,'limite_millis'=>(time()-600)*1000]); return $this->render('alert/live.html.twig',['partner'=>$partner,'regions'=>$regionRows,'alerts'=>$alerts,'hours'=>0,'total'=>count($alerts),'typesMap'=>$this->alertTypeRepo->getTypesMap($locale),'subtypesMap'=>$this->alertTypeRepo->getSubtypesMap($locale)]); }
    #[Route('/{id}', name: 'show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(int $id): Response { $partner=$this->tenantContext->requirePartner(); $alert=$this->alertRepo->findOneByPartner($id,$partner); if(!$alert) throw $this->createNotFoundException('Alerta não encontrado.'); return $this->render('alert/show.html.twig',['partner'=>$partner,'alert'=>$alert,'typesMap'=>$this->alertTypeRepo->getTypesMap('pt'),'subtypesMap'=>$this->alertTypeRepo->getSubtypesMap('pt')]); }
}
