<?php

namespace App\Controller;

use App\Data\LanguageHeuristics;
use App\Data\NameTypes;
use App\Service\NameAnalyzer;
use App\Service\NameFlowState;
use App\Service\OAuthAuthorizationRequired;
use App\Service\ScriptDetector;
use App\Service\UniversalNameSearch;
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

        $savedLanguageQid = in_array('claim_P407', $apply, true) ? $languageQid : null;
        $savedNameItem = $wikidataClient->nameItem((string) $result['entityId'], true);
        $personPage = $wikidataClient->personMatchesPage(
            $name,
            $name,
            $scriptQid,
            $nativeLabelLanguage !== '' ? $nativeLabelLanguage : 'en',
            (string) $result['entityId'],
            $this->isGivenNameType($type) ? 'given' : 'family',
            12,
            0,
            [],
            $savedNameItem['equivalents'] ?? [],
            $savedNameItem['sameAs'] ?? []
        );

        return new Response($this->completionPage($request, $name, $displayLabel, $type, $scriptQid, $savedLanguageQid, $nativeLabelLanguage, $result, $uiLanguage, $personPage['matches'], $personPage['nextOffset'], $personPage['hasMore'], $savedNameItem));
    }

    private function interfaceLanguage(string $language): string
    {
        return in_array($language, ['en', 'nl', 'de', 'fr', 'es'], true) ? $language : 'en';
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

    /**
     * @param array{entityId: string, mode: string, relatedUpdates: int, warnings: list<string>} $result
     */
    private function completionPage(Request $request, string $name, string $displayLabel, string $type, string $scriptQid, ?string $languageQid, string $nativeLabelLanguage, array $result, string $uiLanguage, array $personMatches, int $peopleNextOffset, bool $peopleHasMore, ?array $savedNameItem): string
    {
        $entityId = htmlspecialchars($result['entityId'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $entityIdJs = htmlspecialchars(json_encode($result['entityId'], JSON_THROW_ON_ERROR), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeDisplayLabel = htmlspecialchars($displayLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $isUpdate = $result['mode'] === 'updated';
        $typeQid = NameTypes::TYPE_ITEMS[$type] ?? '';
        $typeText = $this->itemLabel($uiLanguage, $typeQid, NameTypes::LABELS[$type] ?? $type);
        $savedDescriptionText = trim((string) ($savedNameItem['description'] ?? '')) ?: $typeText;
        $savedDescription = htmlspecialchars($savedDescriptionText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $inverseRelationshipNote = ((int) ($result['inverseRelationshipUpdates'] ?? 0)) > 0
            ? '<p class="notice">' . htmlspecialchars($this->t($uiLanguage, 'inverse_name_link_saved'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            : '';
        $warnings = '';
        $safeUiLanguage = htmlspecialchars($uiLanguage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $titleText = $this->t($uiLanguage, $isUpdate ? 'updated_item' : 'created_new_item');
        $title = htmlspecialchars($titleText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $copy = htmlspecialchars($this->t($uiLanguage, 'copy'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $relatedSkipped = htmlspecialchars($this->t($uiLanguage, 'related_updates_skipped'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $possibleFullName = htmlspecialchars($this->t($uiLanguage, 'possible_full_name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $confirmNamePart = htmlspecialchars($this->t($uiLanguage, 'confirm_name_part'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tagline = match ($uiLanguage) {
            'nl' => 'Maak Wikidata-items<span class="tagline-break"><br></span> voor voor-&nbsp;en&nbsp;achternamen.',
            'de' => 'Neue Wikidata-Items<span class="tagline-break"><br></span> für Vor-&nbsp;und&nbsp;Nachnamen erstellen.',
            'fr' => 'Créer des éléments Wikidata<span class="tagline-break"><br></span> pour les prénoms et les noms de famille.',
            'es' => 'Crear elementos de Wikidata<span class="tagline-break"><br></span> para nombres de pila y apellidos.',
            default => 'Create Wikidata items<span class="tagline-break"><br></span> for given and family names.',
        };
        $credit = htmlspecialchars($this->t($uiLanguage, 'credit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $sourceCode = htmlspecialchars($this->t($uiLanguage, 'source_code'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $mit = htmlspecialchars($this->t($uiLanguage, 'mit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $peopleSaveAction = htmlspecialchars($request->getBasePath() . '/index.php/people/save', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $peopleSearchUrl = json_encode($request->getBasePath() . '/index.php/api/people', JSON_THROW_ON_ERROR);
        $peopleSaveUrl = json_encode($request->getBasePath() . '/index.php/people/save', JSON_THROW_ON_ERROR);
        $personQueryJs = json_encode($name, JSON_THROW_ON_ERROR);
        $uiLanguageJs = json_encode($uiLanguage, JSON_THROW_ON_ERROR);
        $nameItemSearchUrl = json_encode($request->getBasePath() . '/index.php/api/name-items', JSON_THROW_ON_ERROR);
        $peopleBaseUrl = json_encode($request->getBasePath() . '/index.php/people', JSON_THROW_ON_ERROR);
        $nameAffixes = json_encode($this->nameAffixes(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $universalSearch = UniversalNameSearch::render([
            'action' => $request->getBasePath() . '/index.php/',
            'ui' => $uiLanguage,
            'label' => $this->t($uiLanguage, 'name'),
            'inputId' => 'completion-name-input',
            'suggestionsId' => 'completion-name-suggestions',
            'itemId' => 'completion-selected-item',
            'typeId' => 'completion-selected-type',
            'analyzeId' => 'completion-analyze',
            'actionsId' => 'completion-match-actions',
            'matchId' => 'completion-match',
            'createLabel' => $this->t($uiLanguage, 'analyze'),
            'progressLabel' => $this->t($uiLanguage, 'analyzing'),
            'updateLabel' => $this->t($uiLanguage, 'update'),
            'matchLabel' => $this->t($uiLanguage, 'match'),
            'formId' => 'completion-name-search',
            'selectionActive' => false,
        ], NameFlowState::MATCH);
        $personRows = '';
        $personOptions = $this->personOptions($savedNameItem);
        $personActions = $this->personActionButtons($savedNameItem, (string) $result['entityId'], $this->isGivenNameType($type) ? 'given' : 'family');
        $personOptionsJs = json_encode($personOptions, JSON_THROW_ON_ERROR);
        $personActionsJs = json_encode($personActions, JSON_THROW_ON_ERROR);
        $personActionClass = substr_count($personActions, '<button') > 1 ? 'person-actions has-equivalent' : 'person-actions';
        $equivalentBlock = '';
        $hasEquivalents = false;
        foreach ($savedNameItem['equivalents'] ?? [] as $equivalent) {
            $equivalentId = htmlspecialchars((string) ($equivalent['id'] ?? ''), ENT_QUOTES, 'UTF-8');
            if (!preg_match('/^Q\d+$/', $equivalentId)) {
                continue;
            }
            $hasEquivalents = true;
            $equivalentLabel = htmlspecialchars((string) ($equivalent['label'] ?? $equivalentId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $equivalentIsGiven = ($equivalent['type'] ?? '') === 'given';
            $equivalentHeading = $equivalentIsGiven ? 'Given name equivalent' : 'Family name equivalent';
            $equivalentDescription = htmlspecialchars(
                (string) ($equivalent['description'] ?? ($equivalentIsGiven ? 'given name' : 'family name')),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
            $equivalentDetails = '';
            if (($equivalent['script'] ?? '') !== '' && ($equivalent['script'] ?? '') !== 'Q8229') {
                $nativeLabel = htmlspecialchars((string) ($equivalent['nativeLabel'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $scriptLabel = htmlspecialchars((string) ($equivalent['scriptLabel'] ?? $equivalent['script']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                if ($nativeLabel !== '') {
                    $equivalentDetails .= '<span class="item-detail"><b>Native label</b> ' . $nativeLabel . '</span>';
                }
                $equivalentDetails .= '<span class="item-detail"><b>Writing system</b> ' . $scriptLabel . '</span>';
            }
            $equivalentBlock .= <<<HTML
        <div class="equivalent-item">
            <h2>$equivalentHeading</h2>
            <div class="saved-item-card">
                <div class="saved-item-details">
                    <strong>$equivalentLabel</strong>
                    <span>$equivalentDescription</span>
                    $equivalentDetails
                    <a href="https://www.wikidata.org/wiki/$equivalentId" target="_blank" rel="noopener noreferrer">$equivalentId</a>
                </div>
            </div>
        </div>
HTML;
        }
        $createEquivalentButton = '';
        if (!$hasEquivalents) {
            $oppositeType = $this->isGivenNameType($type) ? 'family' : 'given';
            $labelKey = $oppositeType === 'given' ? 'create_given_name_equivalent' : 'create_family_name_equivalent';
            $safeCreateEquivalentLabel = htmlspecialchars($this->t($uiLanguage, $labelKey), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $createEquivalentUrl = htmlspecialchars(
                $request->getBasePath() . '/index.php/?name=' . urlencode($name) . '&type=' . urlencode($oppositeType) . '&_analysis=1&_equivalent=' . urlencode($result['entityId']) . '&ui=' . urlencode($uiLanguage),
                ENT_QUOTES,
                'UTF-8'
            );
            $createEquivalentButton = <<<HTML
        <p><a href="$createEquivalentUrl" class="equivalent-create">$safeCreateEquivalentLabel</a></p>
HTML;
        }
        $matchingHeading = htmlspecialchars(
            $this->hasBothNameTypes($savedNameItem)
                ? 'Match people to these names'
                : 'Match people to this ' . ($this->isGivenNameType($type) ? 'given name' : 'family name'),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        foreach ($personMatches as $person) {
            $personId = htmlspecialchars((string) $person['id'], ENT_QUOTES, 'UTF-8');
            $personLabel = htmlspecialchars((string) $person['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $personDescription = htmlspecialchars((string) $person['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $description = $personDescription !== '' ? '<span class="person-description">' . $personDescription . '</span>' : '';
            $personRows .= <<<HTML
<form class="person person-match" method="post" action="$peopleSaveAction" data-person-id="$personId">
    <input type="hidden" name="person" value="$personId">
    <input type="hidden" name="person_query" value="$safeName">
    <input type="hidden" name="ui" value="$safeUiLanguage">
    <input type="hidden" name="ajax" value="1">
    <div><strong>$personLabel</strong> <a href="https://www.wikidata.org/wiki/$personId" target="_blank" rel="noopener noreferrer">($personId)</a>$description</div>
    $personOptions
    <div class="$personActionClass">$personActions</div>
</form>
HTML;
        }
        if ($personRows === '') {
            $personRows = '<p class="hint">No potential matches found.</p>';
        }
        $loadMorePeople = $peopleHasMore
            ? '<p><button id="completion-load-more" class="load-more" type="button" data-next-offset="' . $peopleNextOffset . '">Load more people</button></p>'
            : '';
        foreach ($result['warnings'] as $warning) {
            $warnings .= '<li>' . htmlspecialchars($warning, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
        }
        $warningBlock = $warnings !== '' ? '<section><h2>' . $relatedSkipped . '</h2><ul>' . $warnings . '</ul></section>' : '';

        return <<<HTML
<!doctype html>
<html lang="$safeUiLanguage">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>$title</title>
    <style>
        body { margin: 0; background: #f8f9fa; color: #202122; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Lato, Helvetica, Arial, sans-serif; }
        main { width: min(720px, calc(100% - 32px)); margin: 40px auto; display: grid; gap: 16px; }
        .brand { text-align: center; margin-bottom: 6px; }
        .brand h1 { margin-bottom: 8px; font-size: 44px; font-weight: 400; }
        .brand p { color: #54595d; }
        .tagline-break { display: none; }
        .search-card { position: relative; }
        .search-label { display: block; margin-bottom: 6px; font-weight: 700; }
        section { background: #fff; border: 1px solid #c8ccd1; border-radius: 2px; padding: 18px; }
        h1 { margin: 0 0 12px; font-size: 24px; font-weight: 500; }
        h2 { margin: 0 0 10px; font-size: 18px; }
        p { margin: 8px 0; }
        a { color: #36c; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .actions { display: flex; gap: 12px; flex-wrap: wrap; }
        .button { display: inline-flex; align-items: center; min-height: 36px; padding: 6px 12px; border: 1px solid #36c; background: #36c; color: #fff; border-radius: 2px; font-weight: 700; }
        .button.secondary { background: #fff; color: #36c; }
        .search { display: grid; grid-template-columns: 1fr; gap: 8px; }
        .search label { display: block; margin-bottom: 6px; font-weight: 700; }
        .search input { width: 100%; box-sizing: border-box; min-width: 0; height: 44px; border: 1px solid #a2a9b1; border-radius: 2px; outline: 0; padding: 0 12px; background: #fff; font-size: 18px; }
        .search input:focus { border-color: #36c; box-shadow: inset 0 0 0 1px #36c; }
        .search button { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; border: 1px solid #36c; border-radius: 2px; background: #36c; color: #fff; padding: 8px 14px; font-weight: 700; cursor: pointer; white-space: nowrap; }
        .search button:hover { border-color: #2a4b8d; background: #2a4b8d; }
        .search button:disabled { border-color: #c8ccd1; background: #c8ccd1; cursor: not-allowed; }
        .search-actions { display: flex; justify-content: flex-end; gap: 8px; width: 100%; }
        .search-actions > #completion-analyze,
        .search-actions > .search-actions.is-split { width: 100%; }
        .search-actions.is-split > button { flex:1 1 50%; min-width: 0; }
        .full-name-confirmation { display:none; margin:0; padding:10px 12px; border-left:4px solid #fc3; background:#fef6e7; font-size:14px; line-height:1.4; }
        .full-name-confirmation label { display:flex; gap:8px; align-items:flex-start; font-weight:400; }
        .search .full-name-confirmation input { flex:0 0 auto; width:18px; min-width:18px; height:18px; margin:1px 0 0; padding:0; box-shadow:none; }
        .search-button-spinner { display:none; width:16px; height:16px; margin-right:7px; border:2px solid rgba(255,255,255,.55); border-top-color:#fff; border-radius:50%; animation:spin .75s linear infinite; }
        #completion-analyze.is-searching .search-button-spinner { display:inline-block; }
        .search-progress-label { display:none; }
        #completion-analyze.is-searching .search-default-label { display:none; }
        #completion-analyze.is-searching .search-progress-label { display:inline; }
        .name-search-wrap { position: relative; }
        .name-suggestions { display:none; position:absolute; z-index:20; top:calc(100% + 2px); left:0; right:0; max-height:280px; overflow:auto; margin:0; padding:4px 0; list-style:none; border:1px solid #a2a9b1; background:#fff; box-shadow:0 2px 6px rgba(0,0,0,.15); }
        .name-suggestions li { display:flex; flex-direction:column; gap:2px; align-items:stretch; padding:8px 10px; cursor:pointer; border-top:1px solid #a2a9b1; }
        .name-suggestions li:first-child { border-top:0; }
        .name-suggestions li:hover,.name-suggestions li.is-active { background:#eaecf0; }
        .name-suggestions strong,.name-suggestions span { display:block; }
        .spinner { display: none; width: 16px; height: 16px; margin-right: 8px; border: 2px solid rgba(255,255,255,.55); border-top-color: #fff; border-radius: 50%; animation: spin .75s linear infinite; }
        .is-loading .spinner { display: inline-block; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .copy { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; margin-left: 6px; border: 1px solid #a2a9b1; border-radius: 2px; background: #fff; color: #202122; cursor: pointer; vertical-align: middle; }
        .copy:hover { background: #eaecf0; }
        .copy.is-copied { border-color: #14866d; color: #14866d; }
        .copy svg { width: 16px; height: 16px; }
        .entity-location { white-space: nowrap; }
        .saved-item-card { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px; border:1px solid #c8ccd1; background:#fff; }
        .saved-item-details { min-width:0; }
        .saved-item-details strong,.saved-item-details span,.saved-item-details a { display:block; }
        .saved-item-details span { margin-top:3px; color:#54595d; font-size:13px; }
        .saved-item-details .item-detail { margin-top:5px; color:#202122; }
        .saved-item-details .item-detail b { color:#54595d; margin-right:5px; }
        .saved-item-details a { width:fit-content; margin-top:5px; }
        .equivalent-create { display:inline-flex; align-items:center; min-height:36px; margin-top:12px; padding:6px 12px; border:1px solid #36c; background:#36c; color:#fff; border-radius:2px; font-weight:700; text-decoration:none; }
        .equivalent-create:hover { background:#2a4b8d; border-color:#2a4b8d; text-decoration:none; }
        .equivalent-item { margin-top:16px; }
        .equivalent-item h2 { margin-bottom:8px; font-size:16px; }
        .person { display: grid; gap: 10px; padding: 14px 0; border-top: 1px solid #c8ccd1; }
        .person:first-child { border-top: 0; }
        .person-description { display: block; margin-top: 3px; color: #54595d; font-size: 13px; }
        .options { display: flex; flex-wrap: wrap; gap: 10px; }
        .ordinal { width: 100%; box-sizing: border-box; min-height: 44px; padding: 6px 9px; border: 1px solid #a2a9b1; font-size: 16px; }
        .person-actions { display: flex; gap: 8px; }
        .person-actions button { display: inline-flex; flex:1 1 100%; min-width:0; align-items: center; justify-content: center; min-height: 40px; padding: 6px 10px; border: 1px solid #36c; border-radius: 2px; background: #36c; color: #fff; font-weight: 700; cursor: pointer; }
        .person-actions.has-equivalent button { flex-basis:50%; }
        .person-actions button:hover { border-color: #2a4b8d; background: #2a4b8d; }
        .load-more { border:1px solid #72777d; border-radius:2px; background:#fff; color:#202122; min-height:32px; padding:5px 12px; font-weight:700; cursor:pointer; }
        .load-more:hover { background:#eaecf0; }
        .button-spinner { display: none; width: 14px; height: 14px; margin-right: 6px; border: 2px solid rgba(255,255,255,.55); border-top-color: #fff; border-radius: 50%; animation: spin .75s linear infinite; }
        .add-name.is-loading .button-spinner { display: inline-block; }
        .add-name.is-success { border-color: #14866d; background: #14866d; }
        .add-name.is-present,
        .add-name.is-present:hover { border-color: #a2a9b1; background: #eaecf0; color: #54595d; cursor: not-allowed; }
        .add-name.is-failure { border-color: #b32424; background: #b32424; }
        footer { color: #54595d; font-size: 13px; text-align: center; }
        @media (max-width: 620px) {
            .name-suggestions { position: static; margin-top: 2px; }
            .person-actions button { width: 100%; }
        }
        @media (max-width: 480px) {
            .tagline-break { display: inline; }
        }
    </style>
</head>
<body>
<main>
    <div class="brand">
        <h1>New Name</h1>
        <p>$tagline</p>
    </div>
    $universalSearch
    <section>
        <h1>$title</h1>
        <div class="saved-item-card">
            <div class="saved-item-details">
                <strong>$safeDisplayLabel</strong>
                <span>$savedDescription</span>
                <a href="https://www.wikidata.org/wiki/$entityId" target="_blank" rel="noopener noreferrer">$entityId</a>
            </div>
            <button class="copy" type="button" aria-label="$copy $entityId" title="$copy $entityId" onclick="copyEntityId(this, $entityIdJs)"><svg viewBox="0 0 20 20" aria-hidden="true"><path fill="currentColor" d="M6 2h9a1 1 0 0 1 1 1v11h-2V4H6V2Zm-2 4h9a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Zm1 2v8h7V8H5Z"/></svg></button>
        </div>
        $createEquivalentButton
        $equivalentBlock
    </section>
    $inverseRelationshipNote
    $warningBlock
    <section>
        <h2>$matchingHeading</h2>
        <div id="completion-people-list">$personRows</div>
        $loadMorePeople
    </section>
    <footer>
        New Name $credit <a href="https://www.veradekok.nl/" target="_blank" rel="noopener noreferrer">Vera de Kok</a>.
        <a href="https://github.com/VDK/New-Name" target="_blank" rel="noopener noreferrer">$sourceCode</a>.
        $mit.
    </footer>
</main>
<script>
document.querySelectorAll('form[data-loading]').forEach(function(form) {
    form.addEventListener('submit', function() {
        var button = form.querySelector('button[type="submit"]');
        if (!button) return;
        button.classList.add('is-loading');
        button.disabled = true;
    });
});

function copyEntityId(button, id) {
    var text = String(id);
    var fallback = function (value) {
        var textarea = document.createElement('textarea');
        textarea.value = value;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.top = '-9999px';
        textarea.style.left = '-9999px';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        textarea.setSelectionRange(0, value.length);
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(textarea);
        return ok;
    };
    var copied = function (ok) {
        var old = button.title;
        var oldLabel = button.getAttribute('aria-label') || old;
        button.title = ok === false ? 'Copy failed' : 'Copied';
        button.setAttribute('aria-label', ok === false ? 'Copy failed' : 'Copied');
        button.classList.toggle('is-copied', ok !== false);
        setTimeout(function () {
            button.title = old;
            button.setAttribute('aria-label', oldLabel);
            button.classList.remove('is-copied');
        }, 1200);
    };
    if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(function () {
            copied(true);
        }).catch(function () {
            copied(fallback(text));
        });
        return;
    }
    copied(fallback(text));
}

(function() {
    var input = document.getElementById('completion-name-input');
    var list = document.getElementById('completion-name-suggestions');
    var itemValue = document.getElementById('completion-selected-item');
    var typeValue = document.getElementById('completion-selected-type');
    var analyze = document.getElementById('completion-analyze');
    var actions = document.getElementById('completion-match-actions');
    var match = document.getElementById('completion-match');
    if (!input || !list || !itemValue || !typeValue || !analyze || !actions || !match) return;
    var timer = null;
    var items = [];
    var searchPending = false;

    function close() {
        list.style.display = 'none';
        list.innerHTML = '';
        input.setAttribute('aria-expanded', 'false');
        items = [];
    }
    function clearSelection() {
        itemValue.value = '';
        typeValue.value = '';
        analyze.style.display = '';
        analyze.classList.remove('is-searching');
        actions.style.display = 'none';
        analyze.disabled = searchPending;
    }
    function select(item) {
        input.value = item.label;
        itemValue.value = item.id;
        typeValue.value = item.type;
        analyze.style.display = 'none';
        actions.style.display = 'flex';
        close();
    }
    function render(found) {
        items = found || [];
        list.innerHTML = '';
        if (!items.length) {
            close();
            return;
        }
        items.forEach(function(item) {
            var option = document.createElement('li');
            var title = document.createElement('strong');
            title.textContent = item.label;
            var detail = document.createElement('span');
            detail.textContent = item.description || (item.type === 'given' ? 'given name' : 'family name');
            option.appendChild(title);
            option.appendChild(detail);
            option.addEventListener('click', function(event) {
                event.preventDefault();
                select(item);
            });
            list.appendChild(option);
        });
        list.style.display = 'block';
        input.setAttribute('aria-expanded', 'true');
    }
    input.addEventListener('input', function() {
        clearSelection();
        clearTimeout(timer);
        if (input.value.trim().length < 2) {
            searchPending = false;
            analyze.disabled = false;
            close();
            return;
        }
        searchPending = true;
        analyze.disabled = true;
        analyze.classList.add('is-searching');
        timer = setTimeout(function() {
            fetch($nameItemSearchUrl + '?q=' + encodeURIComponent(input.value.trim()) + '&ui=' + encodeURIComponent('$safeUiLanguage'))
                .then(function(response) {
                    if (!response.ok) throw new Error('Name search failed');
                    return response.json();
                })
                .then(function(found) {
                    searchPending = false;
                    analyze.disabled = false;
                    analyze.classList.remove('is-searching');
                    render(found);
                })
                .catch(function() {
                    searchPending = false;
                    analyze.disabled = false;
                    analyze.classList.remove('is-searching');
                    close();
                });
        }, 200);
    });
    match.addEventListener('click', function() {
        if (!itemValue.value) return;
        window.location = $peopleBaseUrl + '?name_item=' + encodeURIComponent(itemValue.value)
            + '&person_query=' + encodeURIComponent(input.value.trim())
            + '&ui=' + encodeURIComponent('$safeUiLanguage');
    });
    input.form.addEventListener('submit', function() {
        if (itemValue.value) return;
        close();
        analyze.classList.add('is-searching');
        analyze.disabled = true;
    });
    document.addEventListener('click', function(event) {
        if (!input.parentElement.contains(event.target)) close();
    });
}());

function wireCompletionPerson(form) {
    form.addEventListener('submit', function(event) {
        event.preventDefault();
        var add = event.submitter && event.submitter.classList.contains('add-name') ? event.submitter : null;
        if (!add) return;
        var label = add ? add.querySelector('.button-label') : null;
        var removing = add.dataset.action === 'remove';
        if (!add.dataset.defaultLabel && label) add.dataset.defaultLabel = label.textContent;
        if (add) {
            add.disabled = true;
            add.classList.remove('is-success', 'is-present', 'is-failure');
            add.classList.add('is-loading');
        }
        if (label) label.textContent = removing ? 'Undoing' : 'Adding';
        var data = new FormData(form);
        data.set('name_item', add.dataset.nameItem || '');
        data.set('property', add.dataset.property || '');
        data.set('edit_action', removing ? 'remove' : 'add');
        fetch(form.action, {
            method: 'POST',
            body: data,
            headers: { 'Accept': 'application/json' }
        }).then(function(response) {
            return response.json().then(function(data) {
                if (response.status === 401 && data.login) {
                    window.location = data.login;
                    return;
                }
                if (!response.ok) throw new Error(data.error || 'Could not add name');
                add.classList.remove('is-loading');
                if (removing) {
                    add.classList.remove('is-success');
                    add.dataset.action = 'add';
                    add.disabled = false;
                    label.textContent = data.changed ? add.dataset.defaultLabel : 'Could not undo';
                } else if (data.changed) {
                    add.classList.add('is-success');
                    add.dataset.action = 'remove';
                    add.disabled = false;
                    label.textContent = 'Undo';
                } else {
                    add.classList.add('is-present');
                    add.disabled = true;
                    label.textContent = 'Already present';
                }
            });
        }).catch(function(error) {
            if (add) {
                add.disabled = false;
                add.classList.remove('is-loading');
                add.classList.add('is-failure');
            }
            if (label) label.textContent = 'Failed';
            if (add) add.title = error.message;
        });
    });
}
document.querySelectorAll('.person-match').forEach(function(form) {
    wireCompletionPerson(form);
});

(function () {
    var button = document.getElementById('completion-load-more');
    var list = document.getElementById('completion-people-list');
    if (!button || !list) return;
    var loading = false;

    var personOptions = $personOptionsJs;
    var personActions = $personActionsJs;
    function appendPerson(person) {
        if (document.querySelector('[data-person-id="' + person.id + '"]')) return;
        var form = document.createElement('form');
        var empty = list.querySelector('.hint');
        if (empty) empty.remove();
        form.className = 'person person-match';
        form.method = 'post';
        form.action = $peopleSaveUrl;
        form.dataset.personId = person.id;
        form.innerHTML =
            '<input type="hidden" name="person" value="' + person.id + '">' +
            '<input type="hidden" name="person_query" value="">' +
            '<input type="hidden" name="ui" value="">' +
            '<input type="hidden" name="ajax" value="1">';
        form.querySelector('[name="person_query"]').value = $personQueryJs;
        form.querySelector('[name="ui"]').value = $uiLanguageJs;
        var identity = document.createElement('div');
        var strong = document.createElement('strong');
        strong.textContent = person.label;
        var link = document.createElement('a');
        link.href = 'https://www.wikidata.org/wiki/' + person.id;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = ' (' + person.id + ')';
        identity.appendChild(strong);
        identity.appendChild(link);
        if (person.description) {
            var description = document.createElement('span');
            description.className = 'person-description';
            description.textContent = person.description;
            identity.appendChild(description);
        }
        var options = document.createElement('div');
        options.innerHTML = personOptions;
        var actions = document.createElement('div');
        actions.className = 'person-actions';
        actions.innerHTML = personActions;
        if (actions.querySelectorAll('button').length > 1) actions.classList.add('has-equivalent');
        form.appendChild(identity);
        if (options.firstElementChild) form.appendChild(options.firstElementChild);
        form.appendChild(actions);
        list.appendChild(form);
        wireCompletionPerson(form);
    }

    button.addEventListener('click', function () {
        if (loading) return;
        loading = true;
        button.disabled = true;
        button.textContent = 'Loading...';
        var offset = parseInt(button.dataset.nextOffset || '0', 10);
        fetch($peopleSearchUrl + '?name_item=$entityId&q=' + encodeURIComponent($personQueryJs)
            + '&ui=' + encodeURIComponent($uiLanguageJs)
            + '&offset=' + offset, { cache: 'no-store' })
            .then(function(response) {
                if (!response.ok) throw new Error('People search failed');
                return response.json();
            })
            .then(function(data) {
                personOptions = data.personOptions || personOptions;
                personActions = data.personActions || personActions;
                (data.matches || []).forEach(function(person) {
                    appendPerson(person);
                });
                button.dataset.nextOffset = String(data.nextOffset || offset + 36);
                if (data.hasMore) {
                    button.disabled = false;
                    button.textContent = 'Load more people';
                } else {
                    button.remove();
                }
            })
            .catch(function() {
                button.disabled = false;
                button.textContent = 'Load more people';
            })
            .finally(function() { loading = false; });
    });
}());
</script>
</body>
</html>
HTML;
    }

    private function completionTypeLabel(string $uiLanguage, string $type): string
    {
        $labels = [
            'en' => [
                NameTypes::FAMILY_NAME => 'family name',
                NameTypes::GIVEN_NAME => 'first name',
                NameTypes::MALE_GIVEN_NAME => 'male first name',
                NameTypes::FEMALE_GIVEN_NAME => 'female first name',
                NameTypes::UNISEX_GIVEN_NAME => 'unisex first name',
            ],
            'nl' => [
                NameTypes::FAMILY_NAME => 'achternaam',
                NameTypes::GIVEN_NAME => 'voornaam',
                NameTypes::MALE_GIVEN_NAME => 'mannelijke voornaam',
                NameTypes::FEMALE_GIVEN_NAME => 'vrouwelijke voornaam',
                NameTypes::UNISEX_GIVEN_NAME => 'unisex voornaam',
            ],
            'de' => [
                NameTypes::FAMILY_NAME => 'Nachname',
                NameTypes::GIVEN_NAME => 'Vorname',
                NameTypes::MALE_GIVEN_NAME => 'männlicher Vorname',
                NameTypes::FEMALE_GIVEN_NAME => 'weiblicher Vorname',
                NameTypes::UNISEX_GIVEN_NAME => 'Unisex-Vorname',
            ],
            'fr' => [
                NameTypes::FAMILY_NAME => 'nom de famille',
                NameTypes::GIVEN_NAME => 'prénom',
                NameTypes::MALE_GIVEN_NAME => 'prénom masculin',
                NameTypes::FEMALE_GIVEN_NAME => 'prénom féminin',
                NameTypes::UNISEX_GIVEN_NAME => 'prénom épicène',
            ],
            'es' => [
                NameTypes::FAMILY_NAME => 'apellido',
                NameTypes::GIVEN_NAME => 'nombre de pila',
                NameTypes::MALE_GIVEN_NAME => 'nombre masculino',
                NameTypes::FEMALE_GIVEN_NAME => 'nombre femenino',
                NameTypes::UNISEX_GIVEN_NAME => 'nombre unisex',
            ],
        ];

        $uiLanguage = $this->interfaceLanguage($uiLanguage);

        return $labels[$uiLanguage][$type] ?? $labels['en'][$type] ?? (NameTypes::LABELS[$type] ?? $type);
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

    private function personOptions(?array $nameItem): string
    {
        $hasGivenName = ($nameItem['type'] ?? '') === 'given';
        foreach ($nameItem['equivalents'] ?? [] as $equivalent) {
            $hasGivenName = $hasGivenName || ($equivalent['type'] ?? '') === 'given';
        }

        return $hasGivenName
            ? '<div class="options"><label>Series ordinal <span class="muted">(optional)</span><input class="ordinal" name="ordinal" inputmode="numeric" pattern="[1-9][0-9]*"></label></div>'
            : '';
    }

    private function personActionButtons(?array $nameItem, string $fallbackId, string $fallbackType): string
    {
        $actions = [];
        $primaryType = (string) ($nameItem['type'] ?? $fallbackType);
        $primaryId = (string) ($nameItem['id'] ?? $fallbackId);
        if (in_array($primaryType, ['given', 'family'], true) && preg_match('/^Q\d+$/', $primaryId)) {
            $actions[$primaryType] = ['id' => $primaryId, 'type' => $primaryType];
        }
        foreach ($nameItem['equivalents'] ?? [] as $equivalent) {
            $type = (string) ($equivalent['type'] ?? '');
            $id = (string) ($equivalent['id'] ?? '');
            if (in_array($type, ['given', 'family'], true) && preg_match('/^Q\d+$/', $id)) {
                $actions[$type] ??= ['id' => $id, 'type' => $type];
            }
        }

        $html = '';
        foreach (['given', 'family'] as $type) {
            if (!isset($actions[$type])) {
                continue;
            }
            $id = htmlspecialchars($actions[$type]['id'], ENT_QUOTES, 'UTF-8');
            $property = $type === 'given' ? 'P735' : 'P734';
            $label = $type === 'given' ? 'Given name' : 'Family name';
            $html .= '<button type="submit" class="add-name" data-name-item="' . $id . '" data-property="' . $property . '"><span class="button-spinner" aria-hidden="true"></span><span class="button-label">' . $label . '</span></button>';
        }

        return $html;
    }

    private function hasBothNameTypes(?array $nameItem): bool
    {
        if (!$nameItem) {
            return false;
        }
        $types = [(string) ($nameItem['type'] ?? '') => true];
        foreach ($nameItem['equivalents'] ?? [] as $equivalent) {
            $types[(string) ($equivalent['type'] ?? '')] = true;
        }

        return isset($types['given'], $types['family']);
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

    /**
     * @return list<string>
     */
    private function nameAffixes(): array
    {
        $affixes = [];
        foreach (LanguageHeuristics::PREFIXES as $prefixes) {
            foreach ($prefixes as $prefix) {
                $affixes[mb_strtolower($prefix)] = mb_strtolower($prefix);
            }
        }
        usort($affixes, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return array_values($affixes);
    }
}
