<?php

namespace App\Data;

final class NameTypes
{
    public const FAMILY_NAME = 'family_name';
    public const GIVEN_NAME = 'given_name';
    public const MALE_GIVEN_NAME = 'male_given_name';
    public const FEMALE_GIVEN_NAME = 'female_given_name';
    public const UNISEX_GIVEN_NAME = 'unisex_given_name';
    public const CHINESE_FAMILY_NAME = 'chinese_family_name';
    public const CHINESE_GIVEN_NAME = 'chinese_given_name';

    public const ACTIVE_TYPES = [
        self::FAMILY_NAME,
        self::GIVEN_NAME,
        self::MALE_GIVEN_NAME,
        self::FEMALE_GIVEN_NAME,
        self::UNISEX_GIVEN_NAME,
    ];

    public const TYPE_ITEMS = [
        self::FAMILY_NAME => 'Q101352',
        self::GIVEN_NAME => 'Q202444',
        self::MALE_GIVEN_NAME => 'Q12308941',
        self::FEMALE_GIVEN_NAME => 'Q11879590',
        self::UNISEX_GIVEN_NAME => 'Q3409032',
        self::CHINESE_FAMILY_NAME => 'Q101352',
        self::CHINESE_GIVEN_NAME => 'Q202444',
    ];

    public const LABELS = [
        self::FAMILY_NAME => 'family name',
        self::GIVEN_NAME => 'given name',
        self::MALE_GIVEN_NAME => 'male given name',
        self::FEMALE_GIVEN_NAME => 'female given name',
        self::UNISEX_GIVEN_NAME => 'unisex given name',
        self::CHINESE_FAMILY_NAME => 'Chinese family name',
        self::CHINESE_GIVEN_NAME => 'Chinese given name',
    ];

    public const ITEM_LABELS = [
        'Q101352' => 'family name',
        'Q66480858' => 'affixed family name',
        'Q60558422' => 'compound surname',
        'Q4167410' => 'Wikimedia disambiguation page',
        'Q23765057' => 'given name has to use a different item than disambiguation pages',
        'Q27924673' => 'family name has to use a different item than disambiguation page',
        'Q202444' => 'given name',
        'Q12308941' => 'male given name',
        'Q11879590' => 'female given name',
        'Q3409032' => 'unisex given name',
    ];

    public const COMPATIBLE_TYPE_ITEMS = [
        self::FAMILY_NAME => ['Q101352', 'Q66480858', 'Q60558422'],
        self::CHINESE_FAMILY_NAME => ['Q101352', 'Q66480858', 'Q60558422'],
        self::GIVEN_NAME => ['Q202444', 'Q12308941', 'Q11879590', 'Q3409032'],
        self::CHINESE_GIVEN_NAME => ['Q202444', 'Q12308941', 'Q11879590', 'Q3409032'],
        self::MALE_GIVEN_NAME => ['Q12308941', 'Q202444'],
        self::FEMALE_GIVEN_NAME => ['Q11879590', 'Q202444'],
        self::UNISEX_GIVEN_NAME => ['Q3409032'],
    ];

    public const DESCRIPTIONS = [
        self::FAMILY_NAME => [
            'en' => 'family name',
            'nl' => 'familienaam',
            'de' => 'Familienname',
            'fr' => 'nom de famille',
            'es' => 'apellido',
            'it' => 'cognome',
            'hy' => 'ազգանուն',
        ],
        self::GIVEN_NAME => [
            'en' => 'given name',
            'nl' => 'voornaam',
            'de' => 'Vorname',
            'fr' => 'prenom',
            'es' => 'nombre de pila',
            'it' => 'nome proprio',
            'hy' => 'անուն',
        ],
        self::MALE_GIVEN_NAME => [
            'en' => 'male given name',
            'nl' => 'mannelijke voornaam',
            'de' => 'mannlicher Vorname',
            'fr' => 'prenom masculin',
            'es' => 'nombre masculino',
            'it' => 'nome proprio maschile',
            'hy' => 'արական անուն',
        ],
        self::FEMALE_GIVEN_NAME => [
            'en' => 'female given name',
            'nl' => 'vrouwelijke voornaam',
            'de' => 'weiblicher Vorname',
            'fr' => 'prenom feminin',
            'es' => 'nombre femenino',
            'it' => 'nome proprio femminile',
            'hy' => 'իգական անուն',
        ],
        self::UNISEX_GIVEN_NAME => [
            'en' => 'unisex given name',
            'nl' => 'uniseks voornaam',
            'de' => 'geschlechtsneutraler Vorname',
            'fr' => 'prenom epicene',
            'es' => 'nombre unisex',
            'it' => 'nome proprio unisex',
            'hy' => 'ունիսեքս անուն',
        ],
        self::CHINESE_FAMILY_NAME => [
            'en' => 'Chinese family name (%name%)',
            'en-gb' => 'Chinese surname (%name%)',
            'nl' => 'Chinese familienaam (%name%)',
            'de' => 'chinesischer Familienname (%name%)',
            'fr' => 'nom de famille chinois (%name%)',
            'zh' => '姓氏',
            'zh-hans' => '姓氏',
            'zh-hant' => '姓氏',
        ],
        self::CHINESE_GIVEN_NAME => [
            'en' => 'Chinese given name (%name%)',
            'nl' => 'Chinese voornaam (%name%)',
            'de' => 'chinesischer Vorname (%name%)',
            'fr' => 'prenom chinois (%name%)',
            'zh' => '中文人名',
            'zh-hans' => '中文人名',
            'zh-hant' => '中文人名',
        ],
    ];
}
