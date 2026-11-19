<?php

namespace App\Services;

class FaceMatcher
{
    /** Jarak Euclidean dua embedding wajah (makin kecil makin mirip). */
    public static function distance(array $a, array $b): float
    {
        if (count($a) !== count($b) || count($a) === 0) {
            return INF;
        }
        $sum = 0.0;
        foreach ($a as $i => $v) {
            $d = (float) $v - (float) $b[$i];
            $sum += $d * $d;
        }

        return sqrt($sum);
    }

    public static function matches(array $stored, array $candidate): array
    {
        $dist = self::distance($stored, $candidate);

        return [$dist <= config('hris.face_threshold'), $dist];
    }
}
