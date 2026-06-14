<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

final class LanguageSearchController
{
    #[Route('/api/languages', name: 'api_languages', methods: ['GET'])]
    public function search(Request $request, KernelInterface $kernel): JsonResponse
    {
        $query = mb_strtolower(trim((string) $request->query->get('q', '')));
        $uiLanguage = $this->interfaceLanguage((string) $request->query->get('ui', 'en'));
        $hints = $this->hintCodes((string) $request->query->get('hints', ''));
        if (mb_strlen($query) < 2 && $hints === []) {
            return new JsonResponse([], 200, ['Cache-Control' => 'no-store, max-age=0']);
        }

        $rows = $this->loadLanguages($kernel->getProjectDir() . '/data/languages.tsv');
        $matches = [];
        $seen = [];

        foreach ($rows as $row) {
            if (isset($seen[$row['id']]) || $row['label_en'] === '' || $this->isSignLanguage($row)) {
                continue;
            }

            $haystack = mb_strtolower(implode(' ', array_filter([
                $row['id'],
                $row['code'],
                $row['label_en'],
                $row['label_fr'],
                $row['label_de'],
                $row['label_nl'],
                $row['label_es'],
            ])));

            $isHint = in_array($row['code'], $hints, true);
            if (!$isHint && (mb_strlen($query) < 2 || !str_contains($haystack, $query))) {
                continue;
            }

            $seen[$row['id']] = true;
            $label = $this->labelForLanguage($row, $uiLanguage);

            $matches[] = [
                'id' => $row['id'],
                'code' => $row['code'],
                'label' => $label,
                'altLabel' => $this->bestAltLabel($query, $row, $uiLanguage, $label),
                'description' => $this->descriptionForLanguage($row, $uiLanguage),
                'score' => $this->score($query, $row, $row['id'], $hints),
            ];
        }

        usort($matches, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['label'], $b['label']));
        $matches = array_slice($matches, 0, 8);

        $matches = array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'code' => $row['code'],
            'label' => $row['label'],
            'altLabel' => $row['altLabel'],
            'description' => $row['description'],
        ], $matches);

        return new JsonResponse($matches, 200, ['Cache-Control' => 'no-store, max-age=0']);
    }

    /**
     * @param array<string, string> $row
     */
    /**
     * @param list<string> $hints
     * @param array<string, string> $row
     */
    private function score(string $query, array $row, string $id, array $hints = []): int
    {
        $score = $this->labelScore($query, $row['label_en'], 120, 90, 50, 35);
        $code = mb_strtolower($row['code']);
        $hintIndex = array_search($code, $hints, true);
        if (is_int($hintIndex)) {
            $score += 300 - $hintIndex;
        }
        if ($code === $query) {
            $score += 240;
        } elseif ($code !== '' && str_starts_with($code, $query)) {
            $score += 80;
        }

        foreach (['label_nl', 'label_de', 'label_fr', 'label_es'] as $column) {
            $score = max($score, $this->labelScore($query, $row[$column], 80, 55, 35, 20));
        }

        if (preg_match('/^Q(\d+)$/', $id, $match)) {
            $score += max(0, 12 - strlen($match[1]));
        }

        return $score;
    }

    /**
     * @return list<string>
     */
    private function hintCodes(string $hints): array
    {
        return array_values(array_filter(array_unique(array_map(
            static fn (string $code): string => mb_strtolower(trim($code)),
            explode(',', $hints)
        )), static fn (string $code): bool => preg_match('/^[a-z-]{2,12}$/', $code) === 1));
    }

    /**
     * @param array<string, string> $row
     */
    private function isSignLanguage(array $row): bool
    {
        foreach (['label_en', 'label_fr', 'label_de', 'label_nl', 'label_es'] as $column) {
            if (str_contains(mb_strtolower($row[$column]), 'sign language')) {
                return true;
            }
        }

        return str_starts_with(mb_strtolower($row['code']), 'sgn');
    }

    private function interfaceLanguage(string $language): string
    {
        return in_array($language, ['en', 'nl', 'de', 'fr', 'es'], true) ? $language : 'en';
    }

    /**
     * @param array<string, string> $row
     */
    private function labelForLanguage(array $row, string $language): string
    {
        return $row['label_' . $language] ?: ($row['label_en'] ?: ($row['label_nl'] ?: ($row['label_de'] ?: ($row['label_fr'] ?: ($row['label_es'] ?: $row['id'])))));
    }

    /**
     * @param array<string, string> $row
     */
    private function bestAltLabel(string $query, array $row, string $language, string $label): string
    {
        $best = '';
        $bestScore = 0;
        foreach (['en', 'nl', 'de', 'fr', 'es'] as $candidateLanguage) {
            if ($candidateLanguage === $language) {
                continue;
            }
            $candidate = $row['label_' . $candidateLanguage] ?? '';
            if ($candidate === '' || $candidate === $label) {
                continue;
            }
            $score = $this->labelScore($query, $candidate, 120, 90, 50, 35);
            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * @param array<string, string> $row
     */
    private function descriptionForLanguage(array $row, string $language): string
    {
        return $row['description_' . $language] ?? '';
    }

    private function labelScore(string $query, string $label, int $exact, int $prefix, int $word, int $contains): int
    {
        $candidate = mb_strtolower($label);
        if ($candidate === $query) {
            return $exact;
        }
        if (str_starts_with($candidate, $query)) {
            return $prefix;
        }
        if (str_contains($candidate, ' ' . $query)) {
            return $word;
        }
        if (str_contains($candidate, $query)) {
            return $contains;
        }

        return 0;
    }

    /**
     * @return list<array<string, string>>
     */
    private function loadLanguages(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $header = fgetcsv($handle, 0, "\t");
        if (!is_array($header)) {
            fclose($handle);
            return [];
        }

        $rows = [];
        while (($data = fgetcsv($handle, 0, "\t")) !== false) {
            $row = array_combine($header, $data);
            if (!is_array($row)) {
                continue;
            }

            $id = basename((string) ($row['item'] ?? ''));
            if (!preg_match('/^Q\d+$/', $id)) {
                continue;
            }

            $rows[] = [
                'id' => $id,
                'code' => (string) ($row['code'] ?? ''),
                'label_en' => (string) ($row['label_en'] ?? ''),
                'label_fr' => (string) ($row['label_fr'] ?? ''),
                'label_de' => (string) ($row['label_de'] ?? ''),
                'label_nl' => (string) ($row['label_nl'] ?? ''),
                'label_es' => (string) ($row['label_es'] ?? ''),
                'description_en' => (string) ($row['description_en'] ?? ''),
                'description_fr' => (string) ($row['description_fr'] ?? ''),
                'description_de' => (string) ($row['description_de'] ?? ''),
                'description_nl' => (string) ($row['description_nl'] ?? ''),
                'description_es' => (string) ($row['description_es'] ?? ''),
            ];
        }
        fclose($handle);

        return $rows;
    }
}
