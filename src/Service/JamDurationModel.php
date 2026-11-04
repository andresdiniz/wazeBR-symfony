<?php

declare(strict_types=1);
namespace App\Service;

/** Baseline experimental: sobrevivência do episódio observado, não liberação física da via. */
final class JamDurationModel
{
    public function update(array $state,array $rows,int $now,int $gap): array
    {
        $open=$state['open']??[];$history=$state['history']??[];$byId=[];
        foreach($rows as $row)$byId[(string)$row['id']]=$row;
        foreach($open as $id=>$episode){
            $row=$byId[$id]??null;
            $seen=$row?$this->stamp($row['last_seen_at']):0;
            $inactive=$row && !(bool)$row['is_active'];
            $broken=$seen>$episode['last']+$gap || $now-$episode['last']>$gap && $seen<=$episode['last'];
            if($inactive || $broken || !$row){
                $end=$inactive?$this->stamp($row['deactivated_at']):0;
                $episode['duration']=max(0,$episode['last']-$episode['start']);
                // Fechamento observado somente se a lacuna é pequena e houve 3 leituras.
                $episode['event']=$inactive && !$broken && $end>=$episode['last'] && $end-$episode['last']<=$gap && count($episode['samples'])>=3;
                if($episode['event'])$episode['duration']=max(0,$end-$episode['start']);
                $episode['end_reason']=$episode['event']?'desativado_no_feed':'censurado';
                if($episode['duration']>0)$history[]=$episode;
                unset($open[$id]);
            }
        }
        $items=[];
        foreach($rows as $row){
            if(!(bool)$row['is_active'])continue;
            $id=(string)$row['id'];$seen=$this->stamp($row['last_seen_at']);
            $line=is_string($row['line'])?json_decode($row['line'],true):$row['line'];$line=is_array($line)?$line:[];
            $first=$line[0]??[];$last=$line?end($line):[];
            $lat=isset($first['y'])?(float)$first['y']:null;$lng=isset($first['x'])?(float)$first['x']:null;
            if($lat!==null&&($lat < -90||$lat>90||$lng < -180||$lng>180)){$lat=null;$lng=null;}
            $heading=$lat!==null&&isset($last['y'],$last['x'])?rad2deg(atan2(((float)$last['x']-$lng)*cos(deg2rad($lat)),(float)$last['y']-$lat)):null;
            $base=['jam_id'=>(int)$row['id'],'street'=>(string)$row['street'],'city'=>(string)$row['city'],'road'=>(int)$row['road_type'],'lat'=>$lat,'lng'=>$lng,'heading'=>$heading,'blocked'=>!empty($row['blocking_alert_uuid'])||(int)$row['level']>=5,'speed'=>(float)$row['speed_kmh']];
            $fresh=$seen>0&&$seen<=$now+60&&$now-$seen<=$gap;
            if($fresh){
                if(!isset($open[$id]))$open[$id]=$base+['start'=>$seen,'last'=>$seen,'samples'=>[]];
                if(!$open[$id]['samples']||$seen>$open[$id]['last']){
                    $open[$id]['samples'][]=['at'=>$seen,'speed'=>(float)$row['speed_kmh'],'length'=>(int)$row['length'],'delay'=>(int)$row['delay']];
                    $open[$id]['samples']=array_slice($open[$id]['samples'],-30);$open[$id]['last']=$seen;
                }
            }
            $episode=$open[$id]??null;
            $age=$episode?max(0,$seen-$episode['start']):0;
            $samples=$episode['samples']??[];$trend='Sem sequência suficiente';
            if(count($samples)>=3){$a=$samples[max(0,count($samples)-3)];$b=end($samples);$trend=$b['speed']>$a['speed']+3&&$b['length']<$a['length']?'Melhorando':($b['speed']<$a['speed']-3&&$b['length']>$a['length']?'Piorando':'Sem mudança clara');}
            $items[]=$base+['level'=>(int)$row['level'],'length'=>(int)$row['length'],'delay'=>(int)$row['delay'],'last'=>$seen,'age'=>$age,'trend'=>$trend,'status'=>!$fresh?'Dados desatualizados':($base['blocked']?'Bloqueio: sem estimativa': 'Coletando histórico'),'prediction'=>null];
        }
        $history=array_values(array_filter($history,static fn(array $e):bool=>$e['last']>=$now-90*86400));
        $history=array_slice($history,-2000);
        foreach($items as &$item){
            if($item['status']!=='Coletando histórico')continue;
            $episode=$open[(string)$item['jam_id']];$pool=[];
            foreach($history as $past){
                if($past['jam_id']===$item['jam_id']||!$this->similar($episode,$past))continue;
                $pool[]=['duration'=>$past['duration'],'event'=>$past['event']];
            }
            foreach($open as $otherId=>$other){
                if((string)$otherId===(string)$item['jam_id']||!$this->similar($episode,$other))continue;
                $pool[]=['duration'=>max(0,$other['last']-$other['start']),'event'=>false];
            }
            $prediction=$this->estimate($pool,$item['age']);$item['prediction']=$prediction;
            $item['status']=$prediction['median']!==null?'Estimativa experimental':sprintf('Histórico insuficiente (%d comparáveis)',$prediction['at_risk']);
        }unset($item);
        return ['version'=>1,'generated_at'=>$now,'gap_seconds'=>$gap,'model'=>'Kaplan-Meier condicional v1','target'=>'Tempo restante até desativação observada no feed','open'=>$open,'history'=>$history,'items'=>$items];
    }
    private function stamp(mixed $value): int
    {return $value?((new \DateTimeImmutable((string)$value,new \DateTimeZone('UTC')))->getTimestamp()):0;}
    private function similar(array $a,array $b): bool
    {
        if($a['blocked']||$b['blocked']||$a['city']!==$b['city']||$a['road']!==$b['road']||abs($a['speed']-$b['speed'])>15)return false;
        if($a['lat']===null||$b['lat']===null)return false;
        if($a['heading']===null||$b['heading']===null)return false;
        $angle=abs($a['heading']-$b['heading']);if(min($angle,360-$angle)>45)return false;
        $dx=deg2rad($a['lng']-$b['lng'])*cos(deg2rad(($a['lat']+$b['lat'])/2));$dy=deg2rad($a['lat']-$b['lat']);
        $distance=6371000*sqrt($dx*$dx+$dy*$dy);
        if($distance>1500)return false;
        $ha=(int)gmdate('G',$a['start']-10800);$hb=(int)gmdate('G',$b['start']-10800);$hours=abs($ha-$hb);
        return min($hours,24-$hours)<=2 && ((int)gmdate('N',$a['start']-10800)>=6)===((int)gmdate('N',$b['start']-10800)>=6);
    }
    /** Conditional risk set T > age. Quartiles are empirical, not calibrated confidence bounds. */
    public function estimate(array $pool,int $age): array
    {
        $pool=array_values(array_filter($pool,static fn(array $r):bool=>$r['duration']>$age));
        $n=count($pool);$events=count(array_filter($pool,static fn(array $r):bool=>(bool)$r['event']));
        $result=['at_risk'=>$n,'events'=>$events,'lower'=>null,'median'=>null,'upper'=>null];
        if($n<20||$events<10)return $result;
        $times=[];foreach($pool as $r){$t=(int)$r['duration'];$times[$t]??=['event'=>0,'censor'=>0];$times[$t][$r['event']?'event':'censor']++;}ksort($times);
        $survival=1.0;$risk=$n;
        foreach($times as $t=>$counts){
            if($risk<=0)break;
            $survival*=1-$counts['event']/$risk;
            foreach(['lower'=>0.75,'median'=>0.5,'upper'=>0.25] as $key=>$threshold)if($result[$key]===null&&$survival<=$threshold)$result[$key]=max(0,(int)$t-$age);
            $risk-=$counts['event']+$counts['censor'];
        }
        return $result;
    }
}
