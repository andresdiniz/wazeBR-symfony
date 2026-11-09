<?php

declare(strict_types=1);
namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use App\Service\JamDurationModel;
use App\Service\JamForecastInputPolicy;
use App\Service\JamSpeedStatistics;

#[AsCommand(name: 'app:jams:forecast', description: 'Acompanha jams não interditados e estima duração observada no feed.') ]
final class JamDurationForecastCommand extends Command
{
    private const RECENT_CLOSED_SECONDS = 3600;
    private const MAX_ROWS_PER_PARTNER = 2000;

    public function __construct(private readonly Connection $db, private readonly LockFactory $locks, private readonly JamDurationModel $model, private readonly JamForecastInputPolicy $policy, #[Autowire('%kernel.project_dir%')] private readonly string $projectDir) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('partner-id',null,InputOption::VALUE_REQUIRED,'Processa somente um parceiro')->addOption('dry-run',null,InputOption::VALUE_NONE,'Calcula sem publicar histórico');
    }
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        $id=$input->getOption('partner-id');
        if($id!==null&&(!ctype_digit((string)$id)||(int)$id<1))return Command::INVALID;
        $partners=$this->db->fetchAllAssociative('SELECT id, fetch_frequency, fetch_frequency_unit, last_fetch_at FROM partner WHERE is_active=1'.($id!==null?' AND id=?':''),$id!==null?[(int)$id]:[]);
        if(!$partners){$output->writeln('Nenhum parceiro ativo encontrado.');return Command::FAILURE;}
        $directory=$this->projectDir.'/var/jam-duration';
        if(!is_dir($directory)&&!mkdir($directory,0770,true)&&!is_dir($directory))throw new \RuntimeException('Não foi possível criar diretório privado.');
        $failed=false;
        foreach($partners as $partner){
            $pid=(int)$partner['id'];$lock=$this->locks->createLock('jam-duration-'.$pid,300);
            if(!$lock->acquire()){$output->writeln('Parceiro '.$pid.': já em execução.');continue;}
            try{
                $path=$directory.'/'.$pid.'.json';
                $state=is_file($path)?json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR):['open'=>[],'history'=>[]];
                $recentCutoff=gmdate('Y-m-d H:i:s',time()-self::RECENT_CLOSED_SECONDS);
                $rows=$this->db->fetchAllAssociative('SELECT id, uuid, partner_id, street, city, road_type, speed_kmh, length, delay, level, line, blocking_alert_uuid, is_active, last_seen_at, deactivated_at FROM waze_jams WHERE partner_id=? AND (is_active=1 OR (is_active=0 AND deactivated_at>=?)) ORDER BY is_active DESC, last_seen_at DESC, id DESC LIMIT '.self::MAX_ROWS_PER_PARTNER,[$pid,$recentCutoff]);
                if(count($rows)>=self::MAX_ROWS_PER_PARTNER){
                    $output->writeln(sprintf('ATENÇÃO: parceiro %d atingiu o limite de %d registros por execução; alguns jams recentes podem ficar pendentes.', $pid, self::MAX_ROWS_PER_PARTNER));
                }
                [$state,$rows,$excluded]=$this->policy->prepare($state,$rows);
                $unit=strtolower((string)$partner['fetch_frequency_unit']);
                $seconds=max(1,(int)$partner['fetch_frequency'])*(in_array($unit,['h','hr','hrs','hour','hours','hora','horas'],true)?3600:(in_array($unit,['s','sec','secs','second','seconds','seg','segs','segundo','segundos'],true)?1:60));
                $state=$this->model->update($state,$rows,time(),max(900,3*$seconds));
                $state=JamSpeedStatistics::enrichState($state);
                $state['excluded_interdictions']=$excluded;
                if(!$input->getOption('dry-run')){
                    $temp=tempnam($directory,'jam-');if($temp===false)throw new \RuntimeException('Falha no temporário.');
                    try{
                        if(file_put_contents($temp,json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),LOCK_EX)===false)throw new \RuntimeException('Falha ao gravar.');
                        if(!rename($temp,$path))throw new \RuntimeException('Falha ao publicar.');
                    }finally{if(is_file($temp))unlink($temp);}
                }
                $old=count(array_filter($state['items'],static fn(array $item):bool=>$item['status']==='Dados desatualizados'));
                $output->writeln(sprintf('Parceiro %d: %d interdições excluídas; %d jams; %d desatualizados; %d episódios; %s.',$pid,$excluded,count($state['items']),$old,count($state['history']),$input->getOption('dry-run')?'simulado':'publicado'));
                if($old>0)$output->writeln('ATENÇÃO: a previsão não coleta o Waze. Verifique app:fetch-waze-feed, links ativos, frequência, erros e worker. last_fetch_at do parceiro (UTC): '.($partner['last_fetch_at']??'ausente'));
            }catch(\Throwable $e){$failed=true;$output->writeln('Erro no parceiro '.$pid.': '.$e->getMessage());}finally{$lock->release();}
        }
        return $failed?Command::FAILURE:Command::SUCCESS;
    }
}
