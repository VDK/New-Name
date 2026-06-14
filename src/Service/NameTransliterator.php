<?php

namespace App\Service;

final class NameTransliterator
{
    /**
     * Uses the bundled Wikidata gadget dictionary first, with a small fallback map.
     */
    public function transliterate(string $name, ?array $script): string
    {
        $scriptName = is_array($script) ? (string) ($script['script'] ?? '') : '';
        if ($scriptName === '' || $scriptName === 'Han') {
            return '';
        }

        $gadget = $this->transliterateWithGadget($name);
        if ($gadget !== '') {
            return $gadget;
        }

        return match ($scriptName) {
            'Cyrillic' => $this->map($name, self::CYRILLIC),
            'Greek' => $this->map($name, self::GREEK),
            'Hebrew' => $this->map($name, self::HEBREW),
            'Arabic' => $this->map($name, self::ARABIC),
            default => '',
        };
    }

    private function transliterateWithGadget(string $name): string
    {
        $dictionary = $this->gadgetDictionary();
        if ($dictionary === []) {
            return '';
        }

        $chars = preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars)) {
            return '';
        }

        $out = [];
        $nuktas = $this->gadgetNuktas();
        foreach ($chars as $i => $char) {
            $code = mb_ord($char, 'UTF-8');
            if ($code >= 0xAC00 && $code <= 0xD7A3) {
                $hangul = $code - 44032;
                $out[] = self::HANGUL[(int) floor($hangul / 588)]
                    . self::HANGUL[19 + (int) floor(($hangul % 588) / 28)]
                    . self::HANGUL[40 + (($hangul % 588) % 28)];
                continue;
            }

            $next = $chars[$i + 1] ?? '';
            if (isset($dictionary[$char], $dictionary[$next]) && $dictionary[$next] === "\u{0323}" && isset($nuktas[$char])) {
                $out[] = '';
                $chars[$i + 1] = $nuktas[$char];
                continue;
            }

            $mapped = $dictionary[$char] ?? $char;
            if (str_starts_with($mapped, "\u{25CB}") && $out !== []) {
                $lastIndex = array_key_last($out);
                if (is_int($lastIndex) && str_ends_with($out[$lastIndex], 'a')) {
                    $out[$lastIndex] = substr($out[$lastIndex], 0, -1);
                    $mapped = mb_substr($mapped, 1);
                }
            }
            $out[] = $mapped;
        }

        $transliterated = implode('', $out);
        $transliterated = preg_replace('/[^\p{Latin}\p{N}\s\'"-]/u', '', $transliterated) ?? $transliterated;
        $transliterated = trim(preg_replace('/\s+/u', ' ', $transliterated) ?? $transliterated);

        return $transliterated !== $name ? $transliterated : '';
    }

    /**
     * @return array<string, string>
     */
    private function gadgetDictionary(): array
    {
        static $dictionary = null;
        if (is_array($dictionary)) {
            return $dictionary;
        }

        $dictionary = $this->parseGadgetObject('dictionary');

        return $dictionary;
    }

    /**
     * @return array<string, string>
     */
    private function gadgetNuktas(): array
    {
        static $nuktas = null;
        if (is_array($nuktas)) {
            return $nuktas;
        }

        $nuktas = $this->parseGadgetObject('nuktas');

        return $nuktas;
    }

    /**
     * @return array<string, string>
     */
    private function parseGadgetObject(string $name): array
    {
        $file = dirname(__DIR__, 2) . '/data/transliteration-gadget.js';
        if (!is_file($file)) {
            return [];
        }

        $source = file_get_contents($file);
        if (!is_string($source)) {
            return [];
        }

        if (!preg_match('/var\s+' . preg_quote($name, '/') . '\s*=\s*\{(.*?)\}\s*(?:;|\/\/)/su', $source, $match)) {
            return [];
        }

        preg_match_all('/"((?:\\\\.|[^"\\\\])*)"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/u', $match[1], $pairs, PREG_SET_ORDER);
        $out = [];
        foreach ($pairs as $pair) {
            $out[$this->decodeJsString($pair[1])] = $this->decodeJsString($pair[2]);
        }

        return $out;
    }

    private function decodeJsString(string $value): string
    {
        return str_replace(['\\"', '\\\\'], ['"', '\\'], $value);
    }

    /**
     * @param array<string, string> $map
     */
    private function map(string $name, array $map): string
    {
        $out = strtr($name, $map);
        $out = preg_replace('/[^\p{Latin}\p{N}\s\'-]/u', '', $out) ?? $out;
        $out = trim(preg_replace('/\s+/u', ' ', $out) ?? $out);

        return $out !== $name ? $out : '';
    }

    private const HANGUL = [
        'g', 'gg', 'n', 'd', 'dd', 'l', 'm', 'b', 'bb', 's', 'ss', '', 'j', 'jj', 'ch', 'k', 't', 'p', 'h',
        'a', 'ae', 'ya', 'yae', 'eo', 'e', 'yeo', 'ye', 'o', 'wa', 'wae', 'oe', 'yo', 'u', 'weo', 'we', 'wi', 'yu', 'eu', 'ui', 'i',
        '', 'g', 'gg', 'gs', 'n', 'nj', 'nh', 'd', 'l', 'lg', 'lm', 'lb', 'ls', 'lt', 'lp', 'lh', 'm', 'b', 'bs', 's', 'ss', 'ng', 'j', 'ch', 'k', 't', 'p', 'h',
    ];

    private const CYRILLIC = [
        'А' => 'A', 'а' => 'a', 'Ә' => 'A', 'ә' => 'a', 'Б' => 'B', 'б' => 'b',
        'В' => 'V', 'в' => 'v', 'Г' => 'G', 'г' => 'g', 'Ғ' => 'G', 'ғ' => 'g',
        'Д' => 'D', 'д' => 'd', 'Е' => 'E', 'е' => 'e', 'Ё' => 'Yo', 'ё' => 'yo',
        'Ж' => 'Zh', 'ж' => 'zh', 'З' => 'Z', 'з' => 'z', 'И' => 'I', 'и' => 'i',
        'Й' => 'Y', 'й' => 'y', 'К' => 'K', 'к' => 'k', 'Қ' => 'Q', 'қ' => 'q',
        'Л' => 'L', 'л' => 'l', 'М' => 'M', 'м' => 'm', 'Н' => 'N', 'н' => 'n',
        'Ң' => 'Ng', 'ң' => 'ng', 'О' => 'O', 'о' => 'o', 'Ө' => 'O', 'ө' => 'o',
        'П' => 'P', 'п' => 'p', 'Р' => 'R', 'р' => 'r', 'С' => 'S', 'с' => 's',
        'Т' => 'T', 'т' => 't', 'У' => 'U', 'у' => 'u', 'Ұ' => 'U', 'ұ' => 'u',
        'Ү' => 'U', 'ү' => 'u', 'Ф' => 'F', 'ф' => 'f', 'Х' => 'Kh', 'х' => 'kh',
        'Һ' => 'H', 'һ' => 'h', 'Ц' => 'Ts', 'ц' => 'ts', 'Ч' => 'Ch', 'ч' => 'ch',
        'Ш' => 'Sh', 'ш' => 'sh', 'Щ' => 'Shch', 'щ' => 'shch', 'Ъ' => '', 'ъ' => '',
        'Ы' => 'Y', 'ы' => 'y', 'І' => 'I', 'і' => 'i', 'Ь' => '', 'ь' => '',
        'Э' => 'E', 'э' => 'e', 'Ю' => 'Yu', 'ю' => 'yu', 'Я' => 'Ya', 'я' => 'ya',
        'Є' => 'Ye', 'є' => 'ye', 'Ї' => 'Yi', 'ї' => 'yi', 'Ґ' => 'G', 'ґ' => 'g',
        'Ў' => 'U', 'ў' => 'u', 'Љ' => 'Lj', 'љ' => 'lj', 'Њ' => 'Nj', 'њ' => 'nj',
        'Ћ' => 'C', 'ћ' => 'c', 'Ђ' => 'Dj', 'ђ' => 'dj', 'Џ' => 'Dz', 'џ' => 'dz',
    ];

    private const GREEK = [
        'Α' => 'A', 'α' => 'a', 'Β' => 'V', 'β' => 'v', 'Γ' => 'G', 'γ' => 'g',
        'Δ' => 'D', 'δ' => 'd', 'Ε' => 'E', 'ε' => 'e', 'Ζ' => 'Z', 'ζ' => 'z',
        'Η' => 'I', 'η' => 'i', 'Θ' => 'Th', 'θ' => 'th', 'Ι' => 'I', 'ι' => 'i',
        'Κ' => 'K', 'κ' => 'k', 'Λ' => 'L', 'λ' => 'l', 'Μ' => 'M', 'μ' => 'm',
        'Ν' => 'N', 'ν' => 'n', 'Ξ' => 'X', 'ξ' => 'x', 'Ο' => 'O', 'ο' => 'o',
        'Π' => 'P', 'π' => 'p', 'Ρ' => 'R', 'ρ' => 'r', 'Σ' => 'S', 'σ' => 's',
        'ς' => 's', 'Τ' => 'T', 'τ' => 't', 'Υ' => 'Y', 'υ' => 'y', 'Φ' => 'F',
        'φ' => 'f', 'Χ' => 'Ch', 'χ' => 'ch', 'Ψ' => 'Ps', 'ψ' => 'ps', 'Ω' => 'O',
        'ω' => 'o', 'ά' => 'a', 'έ' => 'e', 'ή' => 'i', 'ί' => 'i', 'ό' => 'o',
        'ύ' => 'y', 'ώ' => 'o',
    ];

    private const HEBREW = [
        'א' => '', 'ב' => 'b', 'ג' => 'g', 'ד' => 'd', 'ה' => 'h', 'ו' => 'v',
        'ז' => 'z', 'ח' => 'kh', 'ט' => 't', 'י' => 'y', 'כ' => 'kh', 'ך' => 'kh',
        'ל' => 'l', 'מ' => 'm', 'ם' => 'm', 'נ' => 'n', 'ן' => 'n', 'ס' => 's',
        'ע' => '', 'פ' => 'p', 'ף' => 'p', 'צ' => 'ts', 'ץ' => 'ts', 'ק' => 'q',
        'ר' => 'r', 'ש' => 'sh', 'ת' => 't',
    ];

    private const ARABIC = [
        'ا' => 'a', 'أ' => 'a', 'إ' => 'i', 'آ' => 'a', 'ب' => 'b', 'ت' => 't',
        'ث' => 'th', 'ج' => 'j', 'ح' => 'h', 'خ' => 'kh', 'د' => 'd', 'ذ' => 'dh',
        'ر' => 'r', 'ز' => 'z', 'س' => 's', 'ش' => 'sh', 'ص' => 's', 'ض' => 'd',
        'ط' => 't', 'ظ' => 'z', 'ع' => '', 'غ' => 'gh', 'ف' => 'f', 'ق' => 'q',
        'ك' => 'k', 'ل' => 'l', 'م' => 'm', 'ن' => 'n', 'ه' => 'h', 'و' => 'w',
        'ي' => 'y', 'ى' => 'a', 'ة' => 'a', 'ء' => '',
    ];
}
