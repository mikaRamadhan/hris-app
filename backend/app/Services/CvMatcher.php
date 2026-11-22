<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationDocument;
use Illuminate\Support\Facades\Storage;

/**
 * Menilai kecocokan CV pelamar dengan keyword yang ditentukan HRD pada lowongan.
 * Skor = total bobot keyword yang ditemukan / total bobot semua keyword x 100.
 */
class CvMatcher
{
    private const MAX_CHARS = 200000;

    /** Ekstrak teks dari file CV (pdf, docx). Mengembalikan '' bila tidak terbaca (mis. PDF hasil scan, .doc lama). */
    public static function extractText(string $absPath, string $ext): string
    {
        $ext = strtolower($ext);
        $text = '';
        try {
            if ($ext === 'pdf' && class_exists(\Smalot\PdfParser\Parser::class)) {
                $text = (new \Smalot\PdfParser\Parser())->parseFile($absPath)->getText();
            } elseif ($ext === 'docx' && class_exists(\ZipArchive::class)) {
                $zip = new \ZipArchive();
                if ($zip->open($absPath) === true) {
                    $xml = (string) $zip->getFromName('word/document.xml');
                    $zip->close();
                    $xml = preg_replace('/<\/w:p>|<w:br\s*\/?>|<w:tab\s*\/?>/i', "\n", $xml);
                    $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }
        } catch (\Throwable $e) {
            $text = '';
        }
        $text = preg_replace('/[ \t]+/u', ' ', (string) $text);

        return mb_substr(trim((string) $text), 0, self::MAX_CHARS);
    }

    /** Murni (tanpa database) agar mudah diuji. $keywords: [['keyword' => 'laravel', 'weight' => 3], ...] */
    public static function score(array $keywords, string $text): array
    {
        $matched = [];
        $missing = [];
        $got = 0;
        $total = 0;
        foreach ($keywords as $k) {
            $word = trim((string) ($k['keyword'] ?? ''));
            if ($word === '') {
                continue;
            }
            $w = max(1, min(5, (int) ($k['weight'] ?? 1)));
            $total += $w;
            $parts = array_map(fn ($p) => preg_quote($p, '/'), preg_split('/\s+/u', $word));
            $pattern = '/(?<![\p{L}\p{N}])' . implode('\s+', $parts) . '(?![\p{L}\p{N}])/iu';
            if ($text !== '' && preg_match($pattern, $text) === 1) {
                $got += $w;
                $matched[] = ['keyword' => $word, 'weight' => $w];
            } else {
                $missing[] = ['keyword' => $word, 'weight' => $w];
            }
        }

        return [
            'score' => $total > 0 ? round($got / $total * 100, 2) : null,
            'matched' => $matched, 'missing' => $missing, 'matched_weight' => $got, 'total_weight' => $total,
        ];
    }

    /** Hitung ulang skor sebuah lamaran dan simpan (skor + rincian). */
    public static function evaluate(Application $app): void
    {
        $app->loadMissing(['vacancy', 'applicant', 'documents']);
        $keywords = $app->vacancy?->cv_keywords ?? [];
        if (!is_array($keywords) || !count($keywords)) {
            $app->forceFill(['cv_score' => null, 'cv_match' => null])->save();

            return;
        }

        $cvText = '';
        foreach ($app->documents->where('type', 'cv') as $doc) {
            $cvText .= "\n" . self::documentText($doc);
        }
        $cvText = trim($cvText);

        // Cadangan bila CV tidak terbaca: pakai keahlian & ringkasan di profil pelamar (ditandai di hasil).
        $source = 'cv';
        $text = $cvText;
        if ($cvText === '') {
            $p = $app->applicant;
            $text = trim(implode("\n", array_filter([$p?->skills, $p?->summary, $p?->education, $p?->institution])));
            $source = $text === '' ? 'none' : 'profile';
        }

        $res = self::score($keywords, $text);
        $res['source'] = $source;
        $res['chars'] = mb_strlen($text);
        $app->forceFill(['cv_score' => $res['score'], 'cv_match' => $res])->save();
    }

    /** Ambil teks dokumen; ekstrak sekali lalu disimpan agar hitung ulang cepat. */
    private static function documentText(ApplicationDocument $doc): string
    {
        if ($doc->extracted_text !== null) {
            return $doc->extracted_text;
        }
        $text = '';
        if (Storage::disk('local')->exists($doc->path)) {
            $text = self::extractText(Storage::disk('local')->path($doc->path), pathinfo($doc->path, PATHINFO_EXTENSION));
        }
        $doc->forceFill(['extracted_text' => $text])->save();

        return $text;
    }
}
