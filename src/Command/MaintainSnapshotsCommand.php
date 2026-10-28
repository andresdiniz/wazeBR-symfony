<?php
declare(strict_types=1);
namespace App\Command;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:db:maintain-snapshots', description: 'Global snapshot maintenance. Dry run unless --execute is supplied.')]
final class MaintainSnapshotsCommand extends Command
{
    public function __construct(private readonly Connection $db) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('execute',null,InputOption::VALUE_NONE,'Apply irreversible cleanup to configured database, all partners')
            ->addOption('keep-payload-hours',null,InputOption::VALUE_REQUIRED,'Keep recent route JSON payloads (minimum 24 hours)','48')
            ->addOption('keep-traffic-days',null,InputOption::VALUE_REQUIRED,'Keep traffic light history (minimum 1 day)','30')
            ->addOption('batch-size',null,InputOption::VALUE_REQUIRED,'Rows per batch, maximum 10000','1000')
            ->addOption('max-batches',null,InputOption::VALUE_REQUIRED,'Maximum batches per table','50')
            ->addOption('max-seconds',null,InputOption::VALUE_REQUIRED,'Stop starting new batches after this many seconds','120')
            ->addOption('pause-ms',null,InputOption::VALUE_REQUIRED,'Pause between batches, 0 to 2000 milliseconds','100')
            ->addOption('optimize',null,InputOption::VALUE_NONE,'Manual only: rebuild route snapshot table after complete cleanup; can exceed time budget');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io=new SymfonyStyle($input,$output); $locked=false; $lockName='';
        try {
            $limits=['keep-payload-hours'=>[24,87600],'keep-traffic-days'=>[1,3650],'batch-size'=>[1,10000],'max-batches'=>[1,10000],'max-seconds'=>[1,3600],'pause-ms'=>[0,2000]]; $v=[];
            foreach($limits as $name=>[$min,$max]) {
                $n=filter_var($input->getOption($name),FILTER_VALIDATE_INT);
                if($n===false || $n<$min || $n>$max) throw new \InvalidArgumentException('Invalid option '.$name.'; range '.$min.'..'.$max);
                $v[$name]=$n;
            }
            $execute=(bool)$input->getOption('execute');
            if($input->getOption('optimize') && !$execute) throw new \InvalidArgumentException('--optimize requires --execute.');
            $database=(string)$this->db->fetchOne('SELECT DATABASE()');
            if($database==='') throw new \RuntimeException('No database selected.');
            $lockName='snapshot_maintenance_'.substr(hash('sha256',$database),0,24);
            $locked=(int)$this->db->fetchOne('SELECT GET_LOCK(:name, 0)',['name'=>$lockName])===1;
            if(!$locked) { $io->warning('Maintenance is already running or lock unavailable.'); return Command::FAILURE; }
            if($this->db->isTransactionActive()) throw new \RuntimeException('Run outside an existing transaction.');
            $now=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));
            $payloadCutoff=$now->modify('-'.$v['keep-payload-hours'].' hours')->format('Y-m-d H:i:s');
            $trafficCutoff=$now->modify('-'.$v['keep-traffic-days'].' days')->format('Y-m-d H:i:s');
            $io->title(($execute?'APPLY':'DRY RUN').' snapshot maintenance');
            $io->definitionList(['Database'=>$database],['Scope'=>'ALL PARTNERS'],['Route payload cutoff UTC'=>$payloadCutoff],['Traffic snapshot cutoff UTC'=>$trafficCutoff]);
            if(!$execute) {
                $io->table(['Operation','Eligible rows'],[
                    ['Set route payload NULL',$this->db->fetchOne('SELECT COUNT(*) FROM waze_tvt_route_snapshot WHERE payload IS NOT NULL AND recorded_at < :cutoff',['cutoff'=>$payloadCutoff])],
                    ['Delete old traffic snapshots',$this->db->fetchOne('SELECT COUNT(*) FROM traffic_light_snapshot WHERE read_at < :cutoff',['cutoff'=>$trafficCutoff])],
                ]);
                $io->note('No data changed. Back up before using --execute. Counts may be expensive on large tables.');
                return Command::SUCCESS;
            }
            $deadline=microtime(true)+$v['max-seconds'];
            $route=$this->clean('waze_tvt_route_snapshot','payload IS NOT NULL AND recorded_at < :cutoff',$payloadCutoff,false,$v,$deadline);
            $traffic=$this->clean('traffic_light_snapshot','read_at < :cutoff',$trafficCutoff,true,$v,$deadline);
            $io->table(['Operation','Changed','Batches','Bounded scan complete'],[
                ['Route payloads',$route['rows'],$route['batches'],$route['complete']?'yes':'no'],
                ['Traffic snapshots',$traffic['rows'],$traffic['batches'],$traffic['complete']?'yes':'no'],
            ]);
            if(!$route['complete'] || !$traffic['complete']) $io->warning('Budget reached: partial cleanup; safe to rerun. The next scheduled run will continue remaining eligible data.');
            if($input->getOption('optimize')) {
                if(!$route['complete'] || !$traffic['complete']) throw new \RuntimeException('Skipping OPTIMIZE after incomplete cleanup.');
                $io->warning('Manual OPTIMIZE may rebuild the table, need extra disk space, and wait for metadata locks.');
                $messages=$this->db->fetchAllAssociative('OPTIMIZE TABLE waze_tvt_route_snapshot');
                foreach($messages as $message) {
                    $io->writeln((string)($message['Msg_text']??json_encode($message)));
                    if(strtolower((string)($message['Msg_type']??''))==='error') throw new \RuntimeException('OPTIMIZE returned an error.');
                }
            }
            return Command::SUCCESS;
        } catch(\Throwable $e) { $io->error($e->getMessage()); return Command::FAILURE; }
        finally {
            if($locked) { try { $this->db->fetchOne('SELECT RELEASE_LOCK(:name)',['name'=>$lockName]); } catch(\Throwable) {} }
        }
    }
    private function clean(string $table,string $predicate,string $cutoff,bool $delete,array $v,float $deadline): array
    {
        // Table names and predicates are private hard-coded call sites, never user input.
        $cursor=0; $total=0; $batches=0;
        $maxId=(int)$this->db->fetchOne('SELECT COALESCE(MAX(id),0) FROM '.$table);
        if($maxId===0) return ['rows'=>0,'batches'=>0,'complete'=>true];
        while($batches<$v['max-batches'] && microtime(true)<$deadline) {
            $ids=$this->db->executeQuery('SELECT id FROM '.$table.' WHERE id > :cursor AND id <= :max_id AND '.$predicate.' ORDER BY id ASC LIMIT '.$v['batch-size'],['cursor'=>$cursor,'max_id'=>$maxId,'cutoff'=>$cutoff])->fetchFirstColumn();
            if($ids===[]) return ['rows'=>$total,'batches'=>$batches,'complete'=>true];
            $cursor=(int)end($ids);
            $sql=($delete?'DELETE FROM '.$table:'UPDATE '.$table.' SET payload = NULL').' WHERE id IN (:ids) AND '.$predicate;
            $total+=$this->db->executeStatement($sql,['ids'=>array_map('intval',$ids),'cutoff'=>$cutoff],['ids'=>ArrayParameterType::INTEGER]);
            $batches++;
            if($v['pause-ms']>0) usleep($v['pause-ms']*1000);
        }
        return ['rows'=>$total,'batches'=>$batches,'complete'=>false];
    }
}
