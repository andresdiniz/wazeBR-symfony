<?php

declare(strict_types=1);
namespace App\Service;

final class JamForecastInputPolicy
{
    /** Remove interdições antes de construir episódios; não as trata como jams encerrados. */
    public function prepare(array $state, array $rows): array
    {
        $excluded=[];
        foreach($rows as $row){
            if((int)$row['level']>=5 || !empty($row['blocking_alert_uuid']))$excluded[(string)$row['id']]=true;
        }
        $rows=array_values(array_filter($rows,static fn(array $row):bool=>!isset($excluded[(string)$row['id']])));
        $state['open']=array_filter($state['open']??[],static fn(array $episode):bool=>empty($episode['blocked'])&&!isset($excluded[(string)$episode['jam_id']]));
        $state['history']=array_values(array_filter($state['history']??[],static fn(array $episode):bool=>empty($episode['blocked'])&&!isset($excluded[(string)$episode['jam_id']])));
        $state['items']=[];
        return [$state,$rows,count($excluded)];
    }
}
