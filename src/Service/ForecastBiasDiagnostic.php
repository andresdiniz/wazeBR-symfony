<?php
declare(strict_types=1);
namespace App\Service;

final class ForecastBiasDiagnostic
{
    /** Diagnostics only: legacy audit does not identify model versions. */
    public function analyze(array $rows, \DateTimeImmutable $split, \DateTimeImmutable $asOf, int $minWindows = 20, int $minDays = 3, string $timezone = 'America/Sao_Paulo'): array
    {
        $tz = new \DateTimeZone($timezone); $utc = new \DateTimeZone('UTC');
        $selected = [];
        foreach ($rows as $r) {
            foreach (['predicted_time','actual_time','horizon_minutes'] as $field) {
                if (!isset($r[$field]) || !is_numeric($r[$field]) || !is_finite((float)$r[$field])) continue 2;
            }
            $p = (float)$r['predicted_time']; $a = (float)$r['actual_time']; $h = (float)$r['horizon_minutes'];
            if ($p < 0 || $a <= 0 || $h <= 0 || $h > 360 || empty($r['evaluated_at']) || empty($r['captured_at'])) continue;
            $issued = new \DateTimeImmutable($r['issued_at'], $utc);
            $captured = new \DateTimeImmutable($r['captured_at'], $utc);
            $start = new \DateTimeImmutable($r['target_start_at'], $utc);
            $end = new \DateTimeImmutable($r['target_end_at'], $utc);
            $evaluated = new \DateTimeImmutable($r['evaluated_at'], $utc);
            if ($issued >= $start || $captured < $issued || $captured >= $start || $end <= $start || $end > $asOf || $evaluated < $end || $evaluated > $asOf) continue;
            $set = $end <= $split && $evaluated <= $split ? 'train' : ($issued >= $split ? 'test' : null);
            if ($set === null) continue;
            $band = $h <= 30 ? 30 : ($h <= 60 ? 60 : ($h <= 180 ? 180 : 360));
            $key = $set . '|' . $band . '|' . $r['route_id'] . '|' . $r['target_start_at'] . '|' . $r['target_end_at'];
            $candidate = ['p'=>$p,'a'=>$a,'h'=>$h,'distance'=>abs($h-$band),'id'=>(int)$r['id'],'day'=>$start->setTimezone($tz)->format('Y-m-d'),'band'=>$band,'set'=>$set];
            $old = $selected[$key] ?? null;
            if ($old === null || [$candidate['distance'],$h,-$candidate['id']] < [$old['distance'],$old['h'],-$old['id']]) $selected[$key] = $candidate;
        }
        $out = [];
        foreach ([30,60,180,360] as $band) {
            $train = array_values(array_filter($selected, static fn(array $r): bool => $r['band']===$band && $r['set']==='train'));
            $test = array_values(array_filter($selected, static fn(array $r): bool => $r['band']===$band && $r['set']==='test'));
            $n = count($train); $days = count(array_unique(array_column($train,'day')));
            $bias = $n ? array_sum(array_map(static fn(array $r): float => $r['p']-$r['a'],$train))/$n : null;
            $supported = $n >= $minWindows && $days >= $minDays;
            $correction = $supported ? $bias * $n/($n+30) : 0.0;
            $base = []; $adjusted = []; $changed = 0;
            foreach ($test as $r) {
                $delta = max(-min(30.0,0.2*$r['p']), min(min(30.0,0.2*$r['p']),$correction));
                $prediction = max(0.0,$r['p']-$delta);
                $base[]=$r['p']-$r['a']; $adjusted[]=$prediction-$r['a'];
                if (abs($delta)>0.000001) $changed++;
            }
            $out[]=['horizon_max_minutes'=>$band,'training_windows'=>$n,'training_days'=>$days,'training_bias_seconds'=>$bias,'supported'=>$supported,'reason'=>$supported ? 'candidate_only' : 'insufficient_training','candidate_correction_seconds'=>$supported ? $correction : null,'test_windows'=>count($test),'changed_test_windows'=>$changed,'base'=>$this->metrics($base),'candidate'=>$this->metrics($adjusted)];
        }
        return $out;
    }

    private function metrics(array $errors): ?array
    {
        $n=count($errors); if (!$n) return null;
        return ['mae'=>array_sum(array_map('abs',$errors))/$n,'rmse'=>sqrt(array_sum(array_map(static fn(float $e): float=>$e*$e,$errors))/$n),'bias'=>array_sum($errors)/$n];
    }
}
