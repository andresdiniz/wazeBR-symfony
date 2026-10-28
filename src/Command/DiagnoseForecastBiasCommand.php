<?php
declare(strict_types=1);
namespace App\Command;

use App\Service\ForecastBiasDiagnostic;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:tvt:diagnose-forecast-bias', description: 'Read-only temporal holdout diagnostic; never changes published forecasts.') ]
final class DiagnoseForecastBiasCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly ForecastBiasDiagnostic $diagnostic) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('route',null,InputOption::VALUE_REQUIRED,'Internal route ID (required)')
            ->addOption('train-days',null,InputOption::VALUE_REQUIRED,'Training period before holdout','30')
            ->addOption('test-days',null,InputOption::VALUE_REQUIRED,'Later holdout period','7')
            ->addOption('min-windows',null,InputOption::VALUE_REQUIRED,'Minimum independent training windows per band','20')
            ->addOption('min-days',null,InputOption::VALUE_REQUIRED,'Minimum distinct training days','3')
            ->addOption('timezone',null,InputOption::VALUE_REQUIRED,'Timezone for counting distinct days','America/Sao_Paulo')
            ->addOption('json',null,InputOption::VALUE_NONE,'Output JSON');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io=new SymfonyStyle($input,$output);
        try {
            $values=[];
            foreach (['route','train-days','test-days','min-windows','min-days'] as $name) {
                $v=filter_var($input->getOption($name),FILTER_VALIDATE_INT);
                if ($v===false || $v<1) throw new \InvalidArgumentException('Invalid positive integer: '.$name);
                $values[$name]=$v;
            }
            if ($values['train-days']>90 || $values['test-days']>30) throw new \InvalidArgumentException('Maximum 90 training days and 30 test days.');
            $timezone=(string)$input->getOption('timezone'); new \DateTimeZone($timezone);
            $route=$this->db->fetchAssociative('SELECT id, partner_id FROM waze_tvt_route WHERE id = :id',['id'=>$values['route']]);
            if ($route===false) throw new \InvalidArgumentException('Route not found.');
            $utc=new \DateTimeZone('UTC'); $now=new \DateTimeImmutable('now',$utc);
            $split=$now->modify('-'.$values['test-days'].' days');
            $from=$split->modify('-'.$values['train-days'].' days');
            $rows=$this->db->fetchAllAssociative('SELECT id, route_id, issued_at, captured_at, target_start_at, target_end_at, evaluated_at, horizon_minutes, predicted_time, actual_time FROM waze_tvt_forecast_audit WHERE route_id = :id AND status = \'evaluated\' AND target_start_at >= :from AND target_end_at <= :now AND evaluated_at IS NOT NULL AND evaluated_at <= :evaluated_before AND predicted_time IS NOT NULL AND actual_time IS NOT NULL AND horizon_minutes > 0 AND horizon_minutes <= 360 ORDER BY target_start_at, id LIMIT 20001',['id'=>$values['route'],'from'=>$from->format('Y-m-d H:i:s'),'now'=>$now->format('Y-m-d H:i:s'),'evaluated_before'=>$now->format('Y-m-d H:i:s')]);
            if(count($rows)>20000) throw new \RuntimeException('More than 20000 emissions: reduce the period; refusing a truncated diagnostic.');
            $bands=$this->diagnostic->analyze($rows,$split,$now,$values['min-windows'],$values['min-days'],$timezone);
            $report=['mode'=>'read_only_diagnostic','route_id'=>(int)$route['id'],'partner_id'=>(int)$route['partner_id'],'training_from'=>$from->format(DATE_ATOM),'split_at'=>$split->format(DATE_ATOM),'as_of'=>$now->format(DATE_ATOM),'raw_emissions'=>count($rows),'bands'=>$bands,'limitations'=>['Legacy audits have no model version or immutable original-observation version.','Later reevaluations are excluded from training when evaluated_at is after split; original values cannot be reconstructed.','Shrinkage 30 and caps 30 seconds/20 percent are experimental, not optimized.','This is a single temporal holdout, not full walk-forward validation.','No forecasts or calibration parameters were saved.']];
            if($input->getOption('json')) { $output->writeln(json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),OutputInterface::OUTPUT_RAW); return Command::SUCCESS; }
            $io->title('Diagnostico de vies: rota '.$values['route']);
            $table=[];
            foreach($bands as $b) $table[]=[ $b['horizon_max_minutes'],$b['training_windows'],$b['training_days'],$b['supported']?'sim':'nao',$b['test_windows'],$b['base']===null?'—':round($b['base']['mae'],2),$b['candidate']===null?'—':round($b['candidate']['mae'],2),$b['candidate']===null?'—':round($b['candidate']['bias'],2) ];
            $io->table(['Horizonte max (min)','Treino','Dias','Suporte','Teste','MAE base','MAE candidato','Vies candidato'],$table);
            $io->note($report['limitations']);
            if($rows===[]) $io->warning('Sem auditoria avaliada no periodo; nao e possivel calibrar.');
            return Command::SUCCESS;
        } catch(\Throwable $e) { $io->error($e->getMessage()); return Command::FAILURE; }
    }
}
