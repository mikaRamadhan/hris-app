<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Statistik absensi pribadi (total jam kerja, aktivitas harian, ketepatan masuk/pulang).
 * Murni (tanpa database) agar mudah diuji.
 *
 * $rows: [['date' => 'Y-m-d', 'in' => timestamp, 'out' => timestamp|null, 'in_status' => string, 'out_status' => string|null], ...]
 *
 * Jam per hari dibagi tiga berdasarkan jam kantor:
 *   before = sebelum jam masuk (mis. datang pukul 08:30 -> 0,5 jam)
 *   work   = di dalam jam kantor
 *   after  = setelah jam pulang (lembur)
 */
class AttendanceStats
{
    public static function build(array $rows, string $from, string $to, string $tz, string $onTime, string $outFrom, array $workDays, string $today): array
    {
        $zone = new DateTimeZone($tz);
        $hm = fn (string $s) => array_map('intval', explode(':', $s));
        $at = function (int $ts, string $time) use ($zone, $hm): int {
            [$h, $m] = $hm($time);

            return (new DateTimeImmutable('@' . $ts))->setTimezone($zone)->setTime($h, $m, 0)->getTimestamp();
        };

        $byDate = [];
        $totalMin = 0;
        $present = 0;
        $withHours = 0;
        $inC = ['on_time' => 0, 'tolerance' => 0, 'late' => 0];
        $outC = ['normal' => 0, 'early_leave' => 0];

        foreach ($rows as $r) {
            if (empty($r['in'])) {
                continue;
            }
            $present++;
            if (isset($inC[$r['in_status'] ?? ''])) {
                $inC[$r['in_status']]++;
            }
            if (empty($r['out'])) {
                continue; // belum check-out: belum ada jam kerja
            }
            if (isset($outC[$r['out_status'] ?? ''])) {
                $outC[$r['out_status']]++;
            }
            $i = (int) $r['in'];
            $o = (int) $r['out'];
            $ws = $at($i, $onTime);
            $we = $at($i, $outFrom);
            $total = max(0, intdiv($o - $i, 60));
            $before = $i < $ws ? max(0, intdiv(min($o, $ws) - $i, 60)) : 0;
            $after = $o > $we ? max(0, intdiv($o - max($i, $we), 60)) : 0;
            $work = max(0, $total - $before - $after);
            $byDate[$r['date']] = [$before, $work, $after];
            $totalMin += $total;
            $withHours++;
        }

        [$h1, $m1] = $hm($onTime);
        [$h2, $m2] = $hm($outFrom);
        $perDay = max(0, (($h2 * 60 + $m2) - ($h1 * 60 + $m1)) / 60);

        $daily = [];
        $workdays = 0;
        $d = new DateTimeImmutable($from, $zone);
        $end = new DateTimeImmutable($to, $zone);
        while ($d <= $end) {
            $k = $d->format('Y-m-d');
            [$b, $w, $a] = $byDate[$k] ?? [0, 0, 0];
            $daily[] = ['date' => $k, 'before' => round($b / 60, 2), 'work' => round($w / 60, 2), 'after' => round($a / 60, 2)];
            if (in_array((int) $d->format('N'), $workDays, true) && $k <= $today) {
                $workdays++;
            }
            $d = $d->modify('+1 day');
        }

        return [
            'range' => ['from' => $from, 'to' => $to],
            'total_hours' => round($totalMin / 60, 2),
            'target_hours' => round($workdays * $perDay, 2),
            'workdays' => $workdays,
            'days_present' => $present,
            'avg_hours' => $withHours ? round($totalMin / 60 / $withHours, 2) : 0,
            'late_count' => $inC['tolerance'] + $inC['late'],
            'check_in' => $inC,
            'check_out' => $outC,
            'daily' => $daily,
        ];
    }
}
