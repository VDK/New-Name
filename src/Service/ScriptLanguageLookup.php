<?php

namespace App\Service;

use Symfony\Component\HttpKernel\KernelInterface;

final class ScriptLanguageLookup
{
    /**
     * @var array<string, list<string>>|null
     */
    private ?array $codesByScript = null;

    public function __construct(private readonly KernelInterface $kernel)
    {
    }

    /**
     * @return list<string>
     */
    public function languageCodesForScript(?string $scriptQid): array
    {
        if ($scriptQid === null || $scriptQid === '') {
            return [];
        }

        $codes = $this->load()[$scriptQid] ?? [];
        if ($codes !== []) {
            return $codes;
        }

        return $this->fallbackLanguageCodesForScript($scriptQid);
    }

    /**
     * @return array<string, list<string>>
     */
    private function load(): array
    {
        if ($this->codesByScript !== null) {
            return $this->codesByScript;
        }

        $this->codesByScript = [];
        $path = $this->kernel->getProjectDir() . '/data/script-languages.tsv';
        if (!is_file($path)) {
            return $this->codesByScript;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return $this->codesByScript;
        }

        $header = fgetcsv($handle, 0, "\t");
        if (!is_array($header)) {
            fclose($handle);

            return $this->codesByScript;
        }

        $columns = array_flip($header);
        while (($row = fgetcsv($handle, 0, "\t")) !== false) {
            $script = (string) ($row[$columns['script'] ?? -1] ?? '');
            $code = (string) ($row[$columns['code'] ?? -1] ?? '');
            if (!preg_match('/^Q\d+$/', $script) || !preg_match('/^[a-z][a-z0-9-]{1,15}$/', $code)) {
                continue;
            }
            $this->codesByScript[$script][$code] = $code;
        }
        fclose($handle);

        foreach ($this->codesByScript as $script => $codes) {
            $this->codesByScript[$script] = array_values($codes);
        }

        return $this->codesByScript;
    }

    /**
     * @return list<string>
     */
    private function fallbackLanguageCodesForScript(string $scriptQid): array
    {
        return match ($scriptQid) {
            'Q8209' => ['ba', 'be', 'bg', 'ce', 'cv', 'kk', 'kk-cyrl', 'ky', 'mk', 'mn', 'mhr', 'myv', 'os', 'ru', 'rue', 'sah', 'sr', 'sr-ec', 'tg', 'tg-cyrl', 'tt-cyrl', 'udm', 'uk'],
            'Q8196' => ['ar', 'ary', 'arz', 'ckb', 'fa', 'kk-arab', 'ks-arab', 'ku-arab', 'pnb', 'ps', 'sd', 'ug-arab', 'ur'],
            'Q33513' => ['he', 'yi'],
            'Q8222' => ['ko'],
            'Q38592' => ['hi', 'ks-deva', 'mai', 'mr', 'ne', 'new', 'sa'],
            'Q8216' => ['el'],
            'Q8301' => ['ka'],
            'Q8221' => ['hy'],
            'Q8201' => ['cdo', 'gan', 'gan-hans', 'gan-hant', 'lzh', 'nan', 'wuu', 'yue', 'zh', 'zh-classical', 'zh-cn', 'zh-hans', 'zh-hant', 'zh-hk', 'zh-min-nan', 'zh-mo', 'zh-my', 'zh-sg', 'zh-tw', 'zh-yue'],
            'Q48332', 'Q82946' => ['ja'],
            default => [],
        };
    }
}
