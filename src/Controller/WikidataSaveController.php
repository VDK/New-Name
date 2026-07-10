<?php

namespace App\Controller;

use App\Data\NameTypes;
use App\Service\NameAnalyzer;
use App\Service\OAuthAuthorizationRequired;
use App\Service\ScriptDetector;
use App\Service\WikidataEditService;
use App\Service\WikidataClient;
use App\Service\WikimediaOAuthClient;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class WikidataSaveController
{
    use NameControllerTrait;

    #[Route('/wikidata/save', name: 'wikidata_save_home', methods: ['GET'])]
    public function home(Request $request): RedirectResponse
    {
        return new RedirectResponse($request->getBasePath() . '/');
    }

    #[Route('/wikidata/save', name: 'wikidata_save', methods: ['POST'])]
    public function save(Request $request, WikimediaOAuthClient $oauthClient, NameAnalyzer $analyzer, WikidataEditService $editService, WikidataClient $wikidataClient, UrlGeneratorInterface $urlGenerator): RedirectResponse|Response
    {
        $name = trim((string) $request->request->get('name', ''));
        $labelTransliteration = trim((string) $request->request->get('label_transliteration', ''));
        $type = $this->normalizeType((string) $request->request->get('type', NameTypes::GIVEN_NAME));
        $scriptQid = (string) $request->request->get('script', '');
        $languageCode = (string) $request->request->get('language', '');
        $nativeLabelLanguage = trim((string) $request->request->get('language_code', ''));
        $existingItem = (string) $request->request->get('existing_item', '');
        $uiLanguage = $this->interfaceLanguage((string) $request->request->get('ui', 'en'));
        $apply = $request->request->all('apply');

        if ($name === '') {
            return new Response('Missing name.', 400);
        }

        if (!$oauthClient->isAuthorized()) {
            $return = $urlGenerator->generate('app_home', array_filter([
                'name' => $name,
                'type' => NameTypes::toUrl($type),
                'language' => $nativeLabelLanguage,
                'ui' => $uiLanguage,
            ], static fn (?string $value): bool => $value !== null && $value !== ''));

            return new RedirectResponse($urlGenerator->generate('oauth_login', ['return' => $return]));
        }

        $existingNameItem = null;
        if ($existingItem !== '') {
            $existingNameItem = $wikidataClient->nameItem($existingItem);
            if ($existingNameItem && $existingNameItem['script'] !== '') {
                $scriptQid = $existingNameItem['script'];
            }
        }
        if ($scriptQid === '' || $scriptQid === 'Q8229') {
            $labelTransliteration = '';
        }
        $existingMulLabel = trim((string) ($existingNameItem['mulLabel'] ?? ''));
        $updateTransliteration = $existingItem !== ''
            && $scriptQid !== ''
            && $scriptQid !== 'Q8229'
            && $labelTransliteration !== ''
            && $labelTransliteration !== $existingMulLabel;
        $displayLabel = $existingItem !== '' && !$updateTransliteration
            ? ($existingMulLabel !== '' ? $existingMulLabel : $name)
            : ($labelTransliteration !== '' ? $labelTransliteration : $name);
        $analysis = $analyzer->analyze($name, $type, $labelTransliteration, $existingItem);
        $languageQid = $this->languageQid($languageCode);
        if ($nativeLabelLanguage === '') {
            $nativeLabelLanguage = $this->languageCode($languageQid) ?? 'mul';
        }
        try {
            $result = $editService->save(
                $name,
                $displayLabel,
                $type,
                $scriptQid !== '' ? $scriptQid : null,
                $languageQid,
                $nativeLabelLanguage,
                $existingItem !== '' ? $existingItem : null,
                array_values(array_filter($apply, 'is_string')),
                $analysis['relationshipSuggestions'],
                $updateTransliteration
            );
        } catch (OAuthAuthorizationRequired) {
            $return = $urlGenerator->generate('app_home', array_filter([
                'name' => $name,
                'type' => NameTypes::toUrl($type),
                'language' => $nativeLabelLanguage,
                'ui' => $uiLanguage,
                'auth' => 'expired',
            ], static fn (?string $value): bool => $value !== null && $value !== ''));

            return new RedirectResponse($urlGenerator->generate('oauth_login', ['return' => $return]));
        } catch (\Throwable $e) {
            return new Response(
                '<!doctype html><meta charset="utf-8"><title>Wikidata edit failed</title><body style="font-family:sans-serif;max-width:720px;margin:40px auto;line-height:1.5"><h1>Wikidata edit failed</h1><p>' .
                htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
                '</p><p><a href="javascript:history.back()">Go back</a></p></body>',
                502
            );
        }

        $request->getSession()->getFlashBag()->add(
            'save_status',
            $result['mode'] === 'updated' ? 'updated' : 'created'
        );

        return new RedirectResponse($urlGenerator->generate('person_name', [
            'name_item' => $result['entityId'],
            'person_query' => $name,
            'ui' => $uiLanguage,
        ]));
    }

    private function normalizeType(string $type): string
    {
        $normalized = NameTypes::fromUrl($type);

        return $normalized === NameTypes::GIVEN_SCOPE || $normalized === null ? NameTypes::GIVEN_NAME : $normalized;
    }

    private function languageQid(string $code): ?string
    {
        if (preg_match('/^Q\d+$/', $code)) {
            return $code;
        }

        return [
            'en' => 'Q1860',
            'nl' => 'Q7411',
            'de' => 'Q188',
            'uk' => 'Q8798',
            'bg' => 'Q7918',
            'sr' => 'Q9299',
            'mk' => 'Q9296',
            'be' => 'Q9091',
            'fy' => 'Q27175',
            'ga' => 'Q9142',
            'gd' => 'Q9314',
            'fr' => 'Q150',
            'es' => 'Q1321',
            'it' => 'Q652',
            'pt' => 'Q5146',
            'pl' => 'Q809',
            'cs' => 'Q9056',
            'sv' => 'Q9027',
            'da' => 'Q9035',
            'no' => 'Q9043',
            'is' => 'Q294',
            'fi' => 'Q1412',
            'hu' => 'Q9067',
            'ro' => 'Q7913',
            'zh' => 'Q7850',
            'cmn' => 'Q9192',
            'ja' => 'Q5287',
            'ko' => 'Q9176',
            'ar' => 'Q13955',
            'hy' => 'Q8785',
            'ka' => 'Q8108',
            'el' => 'Q9129',
            'ru' => 'Q7737',
            'he' => 'Q9288',
            'hi' => 'Q1568',
        ][$code] ?? null;
    }

    private function languageCode(?string $qid): ?string
    {
        return [
            'Q1860' => 'en',
            'Q7411' => 'nl',
            'Q188' => 'de',
            'Q8798' => 'uk',
            'Q7918' => 'bg',
            'Q9299' => 'sr',
            'Q9296' => 'mk',
            'Q9091' => 'be',
            'Q27175' => 'fy',
            'Q9142' => 'ga',
            'Q9314' => 'gd',
            'Q150' => 'fr',
            'Q1321' => 'es',
            'Q652' => 'it',
            'Q5146' => 'pt',
            'Q809' => 'pl',
            'Q9056' => 'cs',
            'Q9027' => 'sv',
            'Q9035' => 'da',
            'Q9043' => 'no',
            'Q294' => 'is',
            'Q1412' => 'fi',
            'Q9067' => 'hu',
            'Q7913' => 'ro',
            'Q7850' => 'zh',
            'Q9192' => 'cmn',
            'Q5287' => 'ja',
            'Q9176' => 'ko',
            'Q13955' => 'ar',
            'Q8785' => 'hy',
            'Q8108' => 'ka',
            'Q9129' => 'el',
            'Q7737' => 'ru',
            'Q9288' => 'he',
            'Q1568' => 'hi',
        ][$qid ?? ''] ?? null;
    }

    private function languageLabel(?string $qid): string
    {
        $code = $this->languageCode($qid);

        return [
            'en' => 'English',
            'nl' => 'Dutch',
            'de' => 'German',
            'uk' => 'Ukrainian',
            'bg' => 'Bulgarian',
            'sr' => 'Serbian',
            'mk' => 'Macedonian',
            'be' => 'Belarusian',
            'fy' => 'West Frisian',
            'ga' => 'Irish',
            'gd' => 'Scottish Gaelic',
            'fr' => 'French',
            'es' => 'Spanish',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'pl' => 'Polish',
            'cs' => 'Czech',
            'sv' => 'Swedish',
            'da' => 'Danish',
            'no' => 'Norwegian',
            'is' => 'Icelandic',
            'fi' => 'Finnish',
            'hu' => 'Hungarian',
            'ro' => 'Romanian',
            'zh' => 'Chinese',
            'cmn' => 'Mandarin',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'ar' => 'Arabic',
            'hy' => 'Armenian',
            'ka' => 'Georgian',
            'el' => 'Greek',
            'ru' => 'Russian',
            'he' => 'Hebrew',
            'hi' => 'Hindi',
        ][$code ?? ''] ?? ($qid ?? '');
    }

    private function t(string $language, string $key): string
    {
        $language = $this->interfaceLanguage($language);
        $translations = [
            'en' => [
                'add_name_to_people' => 'Add this name to people',
                'analyze' => 'Create',
                'analyzing' => 'Analyze',
                'copy' => 'Copy',
                'created' => 'Created',
                'created_new_item' => 'Created item',
                'updated_item' => 'Updated item',
                'create_another_name' => 'Create another name',
                'create_given_name_equivalent' => 'Create given name equivalent',
                'create_family_name_equivalent' => 'Create family name equivalent',
                'credit' => 'by',
                'instance_of' => 'Instance of',
                'inverse_name_link_saved' => 'The given name equivalent was saved on the given-name item, where Wikidata defines this property.',
                'label' => 'Label',
                'language_of_name' => 'Language of name',
                'made_changes_to_item' => 'Changed at',
                'match' => 'Match',
                'name' => 'Name',
                'new_item_can_be_found_at' => 'New item at',
                'on_wikidata' => 'on Wikidata',
                'person_full_name' => 'Name',
                'mit' => 'MIT licensed',
                'native_label' => 'Native label',
                'not_set' => 'not set',
                'possible_full_name' => 'This looks like a person’s full name.',
                'confirm_name_part' => 'I confirm that this is a given or family name.',
                'related_updates' => 'Related updates',
                'related_updates_skipped' => 'Related updates skipped',
                'saved_on_wikidata' => 'Saved on Wikidata',
                'saved_title' => 'Wikidata item saved',
                'search_people' => 'Search people',
                'source_code' => 'Source code',
                'tagline' => 'Create Wikidata items for given and family names.',
                'update' => 'Update',
                'updated' => 'Updated',
                'writing_system' => 'Writing system',
            ],
            'nl' => [
                'add_name_to_people' => 'Deze naam aan personen toevoegen',
                'analyze' => 'Aanmaken',
                'analyzing' => 'Analyseren',
                'copy' => 'Kopieren',
                'created' => 'Aangemaakt',
                'created_new_item' => 'Item aangemaakt',
                'updated_item' => 'Item bijgewerkt',
                'create_another_name' => 'Nog een naam invoeren',
                'create_given_name_equivalent' => 'Voornaamequivalent aanmaken',
                'create_family_name_equivalent' => 'Achternaamequivalent aanmaken',
                'credit' => 'door',
                'instance_of' => 'Is een',
                'inverse_name_link_saved' => 'Het equivalent als voornaam is opgeslagen op het voornaamitem, waar Wikidata deze eigenschap definieert.',
                'label' => 'Label',
                'language_of_name' => 'Taal van de naam',
                'made_changes_to_item' => 'Gewijzigd op',
                'match' => 'Koppelen',
                'name' => 'Naam',
                'new_item_can_be_found_at' => 'Nieuw item staat op',
                'on_wikidata' => 'op Wikidata',
                'person_full_name' => 'Naam',
                'mit' => 'MIT-licentie',
                'native_label' => 'Native label',
                'not_set' => 'niet ingesteld',
                'possible_full_name' => 'Dit lijkt op de volledige naam van een persoon.',
                'confirm_name_part' => 'Ik bevestig dat dit een voor- of achternaam is.',
                'related_updates' => 'Gerelateerde updates',
                'related_updates_skipped' => 'Gerelateerde updates overgeslagen',
                'saved_on_wikidata' => 'Opgeslagen op Wikidata',
                'saved_title' => 'Wikidata-item opgeslagen',
                'search_people' => 'Personen zoeken',
                'source_code' => 'Broncode',
                'tagline' => 'Maak Wikidata-items voor voor- en achternamen.',
                'update' => 'Bijwerken',
                'updated' => 'Bijgewerkt',
                'writing_system' => 'Schrift',
            ],
            'de' => [
                'add_name_to_people' => 'Diesen Namen zu Personen hinzufügen',
                'analyze' => 'Erstellen',
                'analyzing' => 'Analysieren',
                'copy' => 'Kopieren',
                'created' => 'Erstellt',
                'created_new_item' => 'Item erstellt',
                'updated_item' => 'Item aktualisiert',
                'create_another_name' => 'Weiteren Namen erfassen',
                'create_given_name_equivalent' => 'Vornamen-Entsprechung erstellen',
                'create_family_name_equivalent' => 'Familiennamen-Entsprechung erstellen',
                'credit' => 'von',
                'instance_of' => 'ist ein',
                'inverse_name_link_saved' => 'Der entsprechende Vorname wurde beim Vornamen-Item gespeichert, auf dem Wikidata diese Eigenschaft definiert.',
                'label' => 'Label',
                'language_of_name' => 'Sprache des Namens',
                'made_changes_to_item' => 'Geändert auf',
                'match' => 'Zuordnen',
                'name' => 'Name',
                'new_item_can_be_found_at' => 'Das neue Item ist zu finden unter',
                'on_wikidata' => 'auf Wikidata',
                'person_full_name' => 'Name',
                'mit' => 'MIT-lizenziert',
                'native_label' => 'Native label',
                'not_set' => 'nicht gesetzt',
                'possible_full_name' => 'Dies sieht wie der vollständige Name einer Person aus.',
                'confirm_name_part' => 'Ich bestätige, dass dies ein Vor- oder Nachname ist.',
                'related_updates' => 'verknüpfte Updates',
                'related_updates_skipped' => 'Verknüpfte Updates übersprungen',
                'saved_on_wikidata' => 'Auf Wikidata gespeichert',
                'saved_title' => 'Wikidata-Item gespeichert',
                'search_people' => 'Personen suchen',
                'source_code' => 'Quellcode',
                'tagline' => 'Neue Wikidata-Items für Vor- und Nachnamen erstellen.',
                'update' => 'Aktualisieren',
                'updated' => 'Aktualisiert',
                'writing_system' => 'Schriftsystem',
            ],
            'fr' => [
                'add_name_to_people' => 'Ajouter ce nom à des personnes',
                'analyze' => 'Créer',
                'analyzing' => 'Analyser',
                'copy' => 'Copier',
                'created' => 'Créé',
                'created_new_item' => 'Élément créé',
                'updated_item' => 'Élément mis à jour',
                'create_another_name' => 'Créer un autre nom',
                'create_given_name_equivalent' => 'Créer un prénom équivalent',
                'create_family_name_equivalent' => 'Créer un nom de famille équivalent',
                'credit' => 'par',
                'instance_of' => 'Nature de l’élément',
                'inverse_name_link_saved' => 'Le prénom équivalent a été enregistré sur l’élément du prénom, où Wikidata définit cette propriété.',
                'label' => 'Libellé',
                'language_of_name' => 'Langue du nom',
                'made_changes_to_item' => 'Modifié sur',
                'match' => 'Associer',
                'name' => 'Nom',
                'new_item_can_be_found_at' => 'Le nouvel élément se trouve à',
                'on_wikidata' => 'sur Wikidata',
                'person_full_name' => 'Nom',
                'mit' => 'Licence MIT',
                'native_label' => 'Native label',
                'not_set' => 'non défini',
                'possible_full_name' => 'Cela ressemble au nom complet d’une personne.',
                'confirm_name_part' => 'Je confirme qu’il s’agit d’un prénom ou d’un nom de famille.',
                'related_updates' => 'Mises à jour liées',
                'related_updates_skipped' => 'Mises à jour liées ignorées',
                'saved_on_wikidata' => 'Enregistré sur Wikidata',
                'saved_title' => 'Élément Wikidata enregistré',
                'search_people' => 'Rechercher des personnes',
                'source_code' => 'Code source',
                'tagline' => 'Créer des éléments Wikidata pour les prénoms et les noms de famille.',
                'update' => 'Mettre à jour',
                'updated' => 'Mis à jour',
                'writing_system' => 'Système d’écriture',
            ],
            'es' => [
                'add_name_to_people' => 'Añadir este nombre a personas',
                'analyze' => 'Crear',
                'analyzing' => 'Analizar',
                'copy' => 'Copiar',
                'created' => 'Creado',
                'created_new_item' => 'Elemento creado',
                'updated_item' => 'Elemento actualizado',
                'create_another_name' => 'Crear otro nombre',
                'create_given_name_equivalent' => 'Crear nombre de pila equivalente',
                'create_family_name_equivalent' => 'Crear apellido equivalente',
                'credit' => 'por',
                'instance_of' => 'Instancia de',
                'inverse_name_link_saved' => 'El nombre de pila equivalente se guardó en el elemento del nombre de pila, donde Wikidata define esta propiedad.',
                'label' => 'Etiqueta',
                'language_of_name' => 'Idioma del nombre',
                'made_changes_to_item' => 'Modificado en',
                'match' => 'Vincular',
                'name' => 'Nombre',
                'new_item_can_be_found_at' => 'El elemento nuevo se encuentra en',
                'on_wikidata' => 'en Wikidata',
                'person_full_name' => 'Nombre',
                'mit' => 'Licencia MIT',
                'native_label' => 'Native label',
                'not_set' => 'sin definir',
                'possible_full_name' => 'Esto parece el nombre completo de una persona.',
                'confirm_name_part' => 'Confirmo que es un nombre de pila o un apellido.',
                'related_updates' => 'Actualizaciones relacionadas',
                'related_updates_skipped' => 'Actualizaciones relacionadas omitidas',
                'saved_on_wikidata' => 'Guardado en Wikidata',
                'saved_title' => 'Elemento de Wikidata guardado',
                'search_people' => 'Buscar personas',
                'source_code' => 'Código fuente',
                'tagline' => 'Crear elementos de Wikidata para nombres y apellidos.',
                'update' => 'Actualizar',
                'updated' => 'Actualizado',
                'writing_system' => 'Sistema de escritura',
            ],
        ];

        return $translations[$language][$key] ?? $translations['en'][$key] ?? $key;
    }

    private function isGivenNameType(string $type): bool
    {
        return in_array($type, [
            NameTypes::GIVEN_NAME,
            NameTypes::MALE_GIVEN_NAME,
            NameTypes::FEMALE_GIVEN_NAME,
            NameTypes::UNISEX_GIVEN_NAME,
            NameTypes::CHINESE_GIVEN_NAME,
        ], true);
    }

    

    private function itemDisplayLabel(string $label, string $qid): string
    {
        return $qid !== '' ? $label . ' (' . $qid . ')' : $label;
    }

    private function scriptDisplayLabel(string $scriptQid, string $uiLanguage): string
    {
        return $this->itemDisplayLabel($this->scriptLabel($scriptQid, $uiLanguage), $scriptQid);
    }

    private function languageDisplayLabel(string $languageQid): string
    {
        return $this->itemDisplayLabel($this->languageLabel($languageQid), $languageQid);
    }

    private function scriptLabel(string $scriptQid, string $uiLanguage): string
    {
        $labels = [
            'en' => [
                'Q8201' => 'Chinese characters',
                'Q8229' => 'Latin script',
                'Q8209' => 'Cyrillic script',
                'Q8196' => 'Arabic script',
                'Q33513' => 'Hebrew alphabet',
                'Q8222' => 'Hangul',
                'Q48332' => 'hiragana',
                'Q82946' => 'katakana',
                'Q38592' => 'Devanagari',
                'Q8216' => 'Greek alphabet',
                'Q8301' => 'Georgian scripts',
                'Q8221' => 'Armenian alphabet',
            ],
            'nl' => [
                'Q8201' => 'Chinese karakters',
                'Q8229' => 'Latijns schrift',
                'Q8209' => 'cyrillisch schrift',
                'Q8196' => 'Arabisch schrift',
                'Q33513' => 'Hebreeuws alfabet',
                'Q8222' => 'Hangul',
                'Q48332' => 'hiragana',
                'Q82946' => 'katakana',
                'Q38592' => 'Devanagari',
                'Q8216' => 'Grieks alfabet',
                'Q8301' => 'Georgische schriften',
                'Q8221' => 'Armeens alfabet',
            ],
            'de' => [
                'Q8201' => 'chinesische Schriftzeichen',
                'Q8229' => 'lateinische Schrift',
                'Q8209' => 'kyrillische Schrift',
                'Q8196' => 'arabische Schrift',
                'Q33513' => 'hebräisches Alphabet',
                'Q8222' => 'Hangul',
                'Q48332' => 'Hiragana',
                'Q82946' => 'Katakana',
                'Q38592' => 'Devanagari',
                'Q8216' => 'griechisches Alphabet',
                'Q8301' => 'georgische Schriften',
                'Q8221' => 'armenisches Alphabet',
            ],
            'fr' => [
                'Q8201' => 'caractères chinois',
                'Q8229' => 'alphabet latin',
                'Q8209' => 'alphabet cyrillique',
                'Q8196' => 'alphabet arabe',
                'Q33513' => 'alphabet hébreu',
                'Q8222' => 'hangul',
                'Q48332' => 'hiragana',
                'Q82946' => 'katakana',
                'Q38592' => 'devanagari',
                'Q8216' => 'alphabet grec',
                'Q8301' => 'écritures géorgiennes',
                'Q8221' => 'alphabet arménien',
            ],
            'es' => [
                'Q8201' => 'caracteres chinos',
                'Q8229' => 'alfabeto latino',
                'Q8209' => 'alfabeto cirílico',
                'Q8196' => 'alfabeto árabe',
                'Q33513' => 'alfabeto hebreo',
                'Q8222' => 'hangul',
                'Q48332' => 'hiragana',
                'Q82946' => 'katakana',
                'Q38592' => 'devanagari',
                'Q8216' => 'alfabeto griego',
                'Q8301' => 'escrituras georgianas',
                'Q8221' => 'alfabeto armenio',
            ],
        ];

        $uiLanguage = $this->interfaceLanguage($uiLanguage);
        if (isset($labels[$uiLanguage][$scriptQid])) {
            return $labels[$uiLanguage][$scriptQid];
        }

        foreach (ScriptDetector::SCRIPTS as $meta) {
            if ($meta['qid'] === $scriptQid) {
                return $meta['label'];
            }
        }

        return $scriptQid !== '' ? $scriptQid : 'not set';
    }

    private function itemLabel(string $uiLanguage, string $qid, string $fallback): string
    {
        $labels = [
            'en' => [
                'Q101352' => 'family name',
                'Q66480858' => 'affixed family name',
                'Q60558422' => 'compound surname',
                'Q202444' => 'given name',
                'Q12308941' => 'male given name',
                'Q11879590' => 'female given name',
                'Q3409032' => 'unisex given name',
            ],
            'nl' => [
                'Q101352' => 'achternaam',
                'Q66480858' => 'achternaam met tussenvoegsel',
                'Q60558422' => 'samengestelde achternaam',
                'Q202444' => 'voornaam',
                'Q12308941' => 'mannelijke voornaam',
                'Q11879590' => 'vrouwelijke voornaam',
                'Q3409032' => 'unisex voornaam',
            ],
            'de' => [
                'Q101352' => 'Familienname',
                'Q66480858' => 'Familienname mit Namenszusatz',
                'Q60558422' => 'zusammengesetzter Familienname',
                'Q202444' => 'Vorname',
                'Q12308941' => 'männlicher Vorname',
                'Q11879590' => 'weiblicher Vorname',
                'Q3409032' => 'Unisex-Vorname',
            ],
            'fr' => [
                'Q101352' => 'nom de famille',
                'Q66480858' => 'nom de famille avec particule',
                'Q60558422' => 'nom de famille composé',
                'Q202444' => 'prénom',
                'Q12308941' => 'prénom masculin',
                'Q11879590' => 'prénom féminin',
                'Q3409032' => 'prénom épicène',
            ],
            'es' => [
                'Q101352' => 'apellido',
                'Q66480858' => 'apellido con partícula',
                'Q60558422' => 'apellido compuesto',
                'Q202444' => 'nombre de pila',
                'Q12308941' => 'nombre masculino',
                'Q11879590' => 'nombre femenino',
                'Q3409032' => 'nombre unisex',
            ],
        ];

        $uiLanguage = $this->interfaceLanguage($uiLanguage);

        return $labels[$uiLanguage][$qid] ?? $labels['en'][$qid] ?? $fallback;
    }

}
