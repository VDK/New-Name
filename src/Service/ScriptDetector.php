<?php

namespace App\Service;

final class ScriptDetector
{
    public const SCRIPTS = [
        'Han' => ['qid' => 'Q8201', 'label' => 'Chinese characters'],
        'Latin' => ['qid' => 'Q8229', 'label' => 'Latin script'],
        'Cyrillic' => ['qid' => 'Q8209', 'label' => 'Cyrillic script'],
        'Arabic' => ['qid' => 'Q8196', 'label' => 'Arabic script'],
        'Hebrew' => ['qid' => 'Q33513', 'label' => 'Hebrew alphabet'],
        'Hangul' => ['qid' => 'Q8222', 'label' => 'Hangul'],
        'Hiragana' => ['qid' => 'Q48332', 'label' => 'hiragana'],
        'Katakana' => ['qid' => 'Q82946', 'label' => 'katakana'],
        'Devanagari' => ['qid' => 'Q38592', 'label' => 'Devanagari'],
        'Greek' => ['qid' => 'Q8216', 'label' => 'Greek alphabet'],
        'Georgian' => ['qid' => 'Q8301', 'label' => 'Georgian scripts'],
        'Armenian' => ['qid' => 'Q8221', 'label' => 'Armenian alphabet'],
    ];

    /**
     * @return array<string, array{qid: string, label: string}>
     */
    public function options(): array
    {
        return self::SCRIPTS;
    }

    /**
     * @return array{script: string, qid: string, label: string, confidence: string}|null
     */
    public function detect(string $name): ?array
    {
        $counts = [];

        foreach (self::SCRIPTS as $script => $meta) {
            preg_match_all('/\p{' . $script . '}/u', $name, $matches);
            $count = count($matches[0] ?? []);
            if ($count > 0) {
                $counts[$script] = $count;
            }
        }

        if (!$counts) {
            return null;
        }

        arsort($counts);
        $script = array_key_first($counts);
        $total = array_sum($counts);
        $ratio = $counts[$script] / max(1, $total);

        return [
            'script' => $script,
            'qid' => self::SCRIPTS[$script]['qid'],
            'label' => self::SCRIPTS[$script]['label'],
            'confidence' => $ratio >= 0.8 ? 'high' : 'medium',
        ];
    }
}
