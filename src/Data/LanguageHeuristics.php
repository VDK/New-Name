<?php

namespace App\Data;

final class LanguageHeuristics
{
    public const PREFIX_ITEMS = [
        'nl' => [
            'van der' => ['qid' => 'Q69869239', 'label' => 'van der'],
            'van de' => ['qid' => 'Q69869744', 'label' => 'van de'],
            'van' => ['qid' => 'Q1258618', 'label' => 'van'],
            'de' => ['qid' => 'Q11071485', 'label' => 'de'],
            'De' => ['qid' => 'Q69874623', 'label' => 'De'],
            'ten' => ['qid' => 'Q106043664', 'label' => 'ten'],
            'ter' => ['qid' => 'Q109323372', 'label' => 'ter'],
            "'t" => ['qid' => 'Q4540585', 'label' => "'t"],
        ],
        'de' => [
            'von der' => ['qid' => 'Q75135664', 'label' => 'von der'],
            'von dem' => ['qid' => 'Q83441628', 'label' => 'von dem'],
            'von' => ['qid' => 'Q70084230', 'label' => 'Von'],
        ],
    ];

    public const PREFIXES = [
        'nl' => ['van der', 'van den', 'van de', 'van het', 'van', 'de', 'den', 'ten', 'ter', "'t", "'s"],
        'de' => ['von und zu', 'von der', 'von dem', 'von', 'zu'],
        'fr' => ["l'", 'le', 'la', "d'", 'de la', 'du', 'des', 'de'],
        'it' => ['di', 'da', 'della', 'dello', 'degli', 'del'],
        'ar' => ['al', 'el', 'abu', 'ibn', 'bin', 'bint'],
        'pt' => ['da', 'das', 'do', 'dos', 'e'],
        'es' => ['de la', 'del', 'de', 'y'],
    ];

    public const SUFFIXES = [
        'slavic' => ['ovich', 'evich', 'owicz', 'ewicz', 'vich', 'wicz', 'vic', 'vić', 'ic', 'ić', 'ski', 'ska', 'sky', 'ov', 'ova', 'ev', 'eva', 'in', 'ina'],
        'armenian' => ['ian', 'yan'],
        'scandinavian' => ['dottir', 'dóttir', 'sen', 'sson', 'son'],
        'georgian' => ['shvili', 'dze'],
        'lithuanian' => ['aitis', 'evičius', 'avičius', 'ienė', 'ytė'],
        'persian' => ['zadeh', 'pour'],
    ];

    public const LANGUAGE_PATTERNS = [
        ['group' => 'ga', 'value' => "O'", 'confidence' => 'medium', 'subject' => 'name', 'regex' => "/^O['\x{2019}][\p{L}]/u"],
        ['group' => 'gd', 'value' => 'Mac/Mc', 'confidence' => 'low', 'subject' => 'name', 'regex' => '/^Ma?c[\p{Lu}]/u'],
        ['group' => 'gd', 'value' => 'Mac/Mc', 'confidence' => 'low', 'subject' => 'normalized', 'regex' => '/^ma?c[\p{Ll}]/u'],
    ];

    public const LANGUAGE_SUFFIXES = [
        ['group' => 'fy', 'value' => '-stra', 'confidence' => 'medium', 'suffix' => 'stra'],
        ['group' => 'de', 'value' => '-dorf', 'confidence' => 'medium', 'suffix' => 'dorf'],
        ['group' => 'de', 'value' => '-dorff', 'confidence' => 'medium', 'suffix' => 'dorff'],
        ['group' => 'de', 'value' => '-stein', 'confidence' => 'medium', 'suffix' => 'stein'],
        ['group' => 'nl', 'value' => '-stein', 'confidence' => 'low', 'suffix' => 'stein'],
        ['group' => 'de', 'value' => '-berg', 'confidence' => 'medium', 'suffix' => 'berg'],
        ['group' => 'nl', 'value' => '-berg', 'confidence' => 'medium', 'suffix' => 'berg'],
        ['group' => 'sv', 'value' => '-berg', 'confidence' => 'medium', 'suffix' => 'berg'],
        ['group' => 'da', 'value' => '-berg', 'confidence' => 'low', 'suffix' => 'berg'],
        ['group' => 'no', 'value' => '-berg', 'confidence' => 'low', 'suffix' => 'berg'],
        ['group' => 'de', 'value' => '-burg', 'confidence' => 'medium', 'suffix' => 'burg'],
        ['group' => 'nl', 'value' => '-burg', 'confidence' => 'low', 'suffix' => 'burg'],
        ['group' => 'de', 'value' => '-feld', 'confidence' => 'medium', 'suffix' => 'feld'],
        ['group' => 'nl', 'value' => '-veld', 'confidence' => 'medium', 'suffix' => 'veld'],
        ['group' => 'de', 'value' => '-bach', 'confidence' => 'medium', 'suffix' => 'bach'],
        ['group' => 'de', 'value' => '-wald', 'confidence' => 'medium', 'suffix' => 'wald'],
        ['group' => 'de', 'value' => '-heim', 'confidence' => 'medium', 'suffix' => 'heim'],
        ['group' => 'nl', 'value' => '-heim', 'confidence' => 'low', 'suffix' => 'heim'],
        ['group' => 'de', 'value' => '-thal', 'confidence' => 'medium', 'suffix' => 'thal'],
        ['group' => 'nl', 'value' => '-daal', 'confidence' => 'medium', 'suffix' => 'daal'],
        ['group' => 'de', 'value' => '-stadt', 'confidence' => 'medium', 'suffix' => 'stadt'],
        ['group' => 'de', 'value' => '-weiler', 'confidence' => 'medium', 'suffix' => 'weiler'],
        ['group' => 'de', 'value' => '-kirch', 'confidence' => 'medium', 'suffix' => 'kirch'],
        ['group' => 'nl', 'value' => '-dijk', 'confidence' => 'medium', 'suffix' => 'dijk'],
        ['group' => 'fy', 'value' => '-dyk', 'confidence' => 'medium', 'suffix' => 'dyk'],
        ['group' => 'nl', 'value' => '-veen', 'confidence' => 'medium', 'suffix' => 'veen'],
        ['group' => 'nl', 'value' => '-broek', 'confidence' => 'medium', 'suffix' => 'broek'],
        ['group' => 'nl', 'value' => '-kamp', 'confidence' => 'medium', 'suffix' => 'kamp'],
        ['group' => 'nl', 'value' => '-huis', 'confidence' => 'medium', 'suffix' => 'huis'],
        ['group' => 'nl', 'value' => '-horst', 'confidence' => 'medium', 'suffix' => 'horst'],
        ['group' => 'sv', 'value' => '-ström', 'confidence' => 'medium', 'suffix' => 'ström'],
        ['group' => 'sv', 'value' => '-gren', 'confidence' => 'medium', 'suffix' => 'gren'],
        ['group' => 'sv', 'value' => '-lund', 'confidence' => 'medium', 'suffix' => 'lund'],
        ['group' => 'sv', 'value' => '-qvist', 'confidence' => 'medium', 'suffix' => 'qvist'],
        ['group' => 'sv', 'value' => '-kvist', 'confidence' => 'medium', 'suffix' => 'kvist'],
        ['group' => 'da', 'value' => '-gaard', 'confidence' => 'medium', 'suffix' => 'gaard'],
        ['group' => 'no', 'value' => '-gård', 'confidence' => 'medium', 'suffix' => 'gård'],
    ];
}
