<?php

declare(strict_types=1);
namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class JamForecastController extends AbstractController
{
    #[Route('/jams/previsao',name:'jam_forecast_index',methods:['GET'])]
    public function index(#[Autowire('%kernel.project_dir%')] string $projectDir): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');$user=$this->getUser();
        if(!$user instanceof User)throw $this->createAccessDeniedException();
        $partner=$user->getPartner();
        if(!$user->isGlobalAdmin()&&$partner===null)throw $this->createAccessDeniedException('Usuário sem parceiro.');
        $directory=$projectDir.'/var/jam-duration';
        $paths=$user->isGlobalAdmin()?(glob($directory.'/*.json')?:[]):[$directory.'/'.$partner->getId().'.json'];
        $items=[];$errors=[];
        foreach($paths as $path){
            if(!is_file($path))continue;
            try{
                $payload=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
                if(($payload['version']??0)!==1)continue;
                foreach($payload['items']??[] as $item){
                    $item['partner_id']=(int)basename($path,'.json');$item['generated_at']=$payload['generated_at'];
                    if(time()-$payload['generated_at']>max(600,$payload['gap_seconds'])){$item['status']='Previsão desatualizada';$item['prediction']=null;}
                    $items[]=$item;
                }
            }catch(\Throwable){$errors[]='Não foi possível ler uma atualização; execute o comando novamente.';}
        }
        usort($items,static fn(array $a,array $b):int=>$b['level']<=>$a['level']);
        $response=$this->render('jam/forecast.html.twig',['items'=>$items,'errors'=>$errors,'partner'=>$partner]);
        $response->headers->set('Cache-Control','private, no-store');return $response;
    }
}
