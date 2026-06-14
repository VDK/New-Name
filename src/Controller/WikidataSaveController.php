<?php

namespace App\Controller;

use App\Data\NameTypes;
use App\Service\NameAnalyzer;
use App\Service\ScriptDetector;
use App\Service\WikidataEditService;
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
    public function save(Request $request, WikimediaOAuthClient $oauthClient, NameAnalyzer $analyzer, WikidataEditService $editService, UrlGeneratorInterface $urlGenerator): RedirectResponse|Response
    {
        $name = trim((string) $request->request->get('name', ''));
        $labelTransliteration = trim((string) $request->request->get('label_transliteration', ''));
        $displayLabel = $labelTransliteration !== '' ? $labelTransliteration : $name;
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
            $return = '/?' . http_build_query(array_filter([
                'name' => $name,
                'type' => $type,
                'language' => $languageCode,
            ], static fn (?string $value): bool => $value !== null && $value !== ''));

            return new RedirectResponse($urlGenerator->generate('oauth_login', ['return' => $return]));
        }

        $analysis = $analyzer->analyze($name, $type);
        $languageQid = $this->languageQid($languageCode);
        if ($languageQid === null) {
            $detectedLanguageCode = $this->detectedLanguageCode($analysis);
            if ($detectedLanguageCode !== null) {
                $languageQid = $this->languageQid($detectedLanguageCode);
            }
        }
        if ($languageQid === null) {
            $scriptLanguageCode = $this->languageCodeForScript($analysis['script'] ?? null);
            if ($scriptLanguageCode !== null) {
                $languageQid = $this->languageQid($scriptLanguageCode);
            }
        }
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
                $analysis['relationshipSuggestions']
            );
        } catch (\Throwable $e) {
            return new Response(
                '<!doctype html><meta charset="utf-8"><title>Wikidata edit failed</title><body style="font-family:sans-serif;max-width:720px;margin:40px auto;line-height:1.5"><h1>Wikidata edit failed</h1><p>' .
                htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
                '</p><p><a href="javascript:history.back()">Go back</a></p></body>',
                502
            );
        }

        return new Response($this->completionPage($request, $name, $displayLabel, $type, $scriptQid, $languageQid, $nativeLabelLanguage, $result, $uiLanguage));
    }

    private function interfaceLanguage(string $language): string
    {
        return in_array($language, ['en', 'nl', 'de', 'fr', 'es'], true) ? $language : 'en';
    }

    private function normalizeType(string $type): string
    {
        return match ($type) {
            NameTypes::CHINESE_FAMILY_NAME => NameTypes::FAMILY_NAME,
            NameTypes::CHINESE_GIVEN_NAME => NameTypes::GIVEN_NAME,
            default => in_array($type, NameTypes::ACTIVE_TYPES, true) ? $type : NameTypes::GIVEN_NAME,
        };
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

    /**
     * @param array<string, mixed> $analysis
     */
    private function detectedLanguageCode(array $analysis): ?string
    {
        foreach ($analysis['affixes'] ?? [] as $affix) {
            if (!is_array($affix)) {
                continue;
            }
            $code = $this->languageCodeForAffix($affix);
            if ($code !== null) {
                return $code;
            }
        }

        $textLanguage = $this->languageCodeForTextAndScript((string) ($analysis['name'] ?? ''), $analysis['script'] ?? null, (string) ($analysis['selectedType'] ?? ''));
        if ($textLanguage !== null) {
            return $textLanguage;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $affix
     */
    private function languageCodeForAffix(array $affix): ?string
    {
        $group = (string) ($affix['group'] ?? '');
        $value = mb_strtolower((string) ($affix['value'] ?? ''));

        if ($group === 'nl' && $value === 'de') {
            return null;
        }

        if (in_array($group, ['nl', 'de', 'ga', 'gd', 'fy'], true)) {
            return $group;
        }

        if ($group === 'pt' && in_array($value, ['da', 'das', 'do', 'dos', 'e'], true)) {
            return 'pt';
        }

        if ($group === 'fr' && in_array($value, ["l'", 'le', 'la', "d'", 'du', 'des'], true)) {
            return 'fr';
        }

        if ($group === 'es' && in_array($value, ['del', 'y'], true)) {
            return 'es';
        }

        return null;
    }

    /**
     * @param mixed $script
     */
    private function languageCodeForScript(mixed $script): ?string
    {
        if (!is_array($script)) {
            return null;
        }

        return [
            'Arabic' => 'ar',
            'Hebrew' => 'he',
            'Hangul' => 'ko',
            'Hiragana' => 'ja',
            'Katakana' => 'ja',
            'Greek' => 'el',
            'Georgian' => 'ka',
            'Armenian' => 'hy',
            'Devanagari' => 'hi',
        ][(string) ($script['script'] ?? '')] ?? null;
    }

    private function languageCodeForTextAndScript(string $name, mixed $script, string $selectedType): ?string
    {
        if (!is_array($script)) {
            return null;
        }

        if (($script['script'] ?? '') === 'Cyrillic') {
            if (str_contains($name, 'ї') || str_contains($name, 'Ї')) {
                return 'uk';
            }
            if (str_contains($name, 'ў') || str_contains($name, 'Ў')) {
                return 'be';
            }
        }

        if (($script['script'] ?? '') === 'Latin') {
            $folded = mb_strtolower($name);
            if (str_contains($folded, 'å')) {
                return 'sv';
            }
            if (str_contains($folded, 'ij')) {
                return 'nl';
            }
            if ($selectedType === NameTypes::FAMILY_NAME && str_contains($folded, 'uy')) {
                return 'nl';
            }
        }

        return null;
    }

    private function t(string $language, string $key): string
    {
        $language = $this->interfaceLanguage($language);
        $translations = [
            'en' => [
                'analyze' => 'Analyze',
                'copy' => 'Copy',
                'created' => 'Created',
                'create_another_name' => 'Create another name',
                'credit' => 'by',
                'instance_of' => 'instance of',
                'label' => 'Label',
                'language_of_name' => 'language of name',
                'made_changes_to_item' => 'Changes were made to item',
                'new_item_can_be_found_at' => 'New item can be found at',
                'on_wikidata' => 'on Wikidata',
                'mit' => 'MIT licensed',
                'native_label' => 'native label',
                'not_set' => 'not set',
                'related_updates' => 'related updates',
                'related_updates_skipped' => 'Related updates skipped',
                'saved_on_wikidata' => 'Saved on Wikidata',
                'saved_title' => 'Wikidata item saved',
                'source_code' => 'Source code',
                'updated' => 'Updated',
                'writing_system' => 'writing system',
            ],
            'nl' => [
                'analyze' => 'Analyseren',
                'copy' => 'Kopieren',
                'created' => 'Aangemaakt',
                'create_another_name' => 'Nog een naam invoeren',
                'credit' => 'door',
                'instance_of' => 'is een',
                'label' => 'Label',
                'language_of_name' => 'taal van de naam',
                'made_changes_to_item' => 'Wijzigingen zijn gedaan op item',
                'new_item_can_be_found_at' => 'Nieuw item staat op',
                'on_wikidata' => 'op Wikidata',
                'mit' => 'MIT-licentie',
                'native_label' => 'native label',
                'not_set' => 'niet ingesteld',
                'related_updates' => 'gerelateerde updates',
                'related_updates_skipped' => 'Gerelateerde updates overgeslagen',
                'saved_on_wikidata' => 'Opgeslagen op Wikidata',
                'saved_title' => 'Wikidata-item opgeslagen',
                'source_code' => 'Broncode',
                'updated' => 'Bijgewerkt',
                'writing_system' => 'schrift',
            ],
            'de' => [
                'analyze' => 'Analysieren',
                'copy' => 'Kopieren',
                'created' => 'Erstellt',
                'create_another_name' => 'Weiteren Namen erfassen',
                'credit' => 'von',
                'instance_of' => 'ist ein',
                'label' => 'Label',
                'language_of_name' => 'Sprache des Namens',
                'made_changes_to_item' => 'Änderungen wurden am Item vorgenommen',
                'new_item_can_be_found_at' => 'Das neue Item ist zu finden unter',
                'on_wikidata' => 'auf Wikidata',
                'mit' => 'MIT-lizenziert',
                'native_label' => 'native label',
                'not_set' => 'nicht gesetzt',
                'related_updates' => 'verknüpfte Updates',
                'related_updates_skipped' => 'Verknüpfte Updates übersprungen',
                'saved_on_wikidata' => 'Auf Wikidata gespeichert',
                'saved_title' => 'Wikidata-Item gespeichert',
                'source_code' => 'Quellcode',
                'updated' => 'Aktualisiert',
                'writing_system' => 'Schriftsystem',
            ],
            'fr' => [
                'analyze' => 'Analyser',
                'copy' => 'Copier',
                'created' => 'Créé',
                'create_another_name' => 'Créer un autre nom',
                'credit' => 'par',
                'instance_of' => 'nature de l’élément',
                'label' => 'Libellé',
                'language_of_name' => 'langue du nom',
                'made_changes_to_item' => 'Les modifications ont été faites sur l’élément',
                'new_item_can_be_found_at' => 'Le nouvel élément se trouve à',
                'on_wikidata' => 'sur Wikidata',
                'mit' => 'Licence MIT',
                'native_label' => 'native label',
                'not_set' => 'non défini',
                'related_updates' => 'mises à jour liées',
                'related_updates_skipped' => 'Mises à jour liées ignorées',
                'saved_on_wikidata' => 'Enregistré sur Wikidata',
                'saved_title' => 'Élément Wikidata enregistré',
                'source_code' => 'Code source',
                'updated' => 'Mis à jour',
                'writing_system' => 'système d’écriture',
            ],
            'es' => [
                'analyze' => 'Analizar',
                'copy' => 'Copiar',
                'created' => 'Creado',
                'create_another_name' => 'Crear otro nombre',
                'credit' => 'por',
                'instance_of' => 'instancia de',
                'label' => 'Etiqueta',
                'language_of_name' => 'idioma del nombre',
                'made_changes_to_item' => 'Se hicieron cambios en el elemento',
                'new_item_can_be_found_at' => 'El elemento nuevo se encuentra en',
                'on_wikidata' => 'en Wikidata',
                'mit' => 'Licencia MIT',
                'native_label' => 'native label',
                'not_set' => 'sin definir',
                'related_updates' => 'actualizaciones relacionadas',
                'related_updates_skipped' => 'Actualizaciones relacionadas omitidas',
                'saved_on_wikidata' => 'Guardado en Wikidata',
                'saved_title' => 'Elemento de Wikidata guardado',
                'source_code' => 'Código fuente',
                'updated' => 'Actualizado',
                'writing_system' => 'sistema de escritura',
            ],
        ];

        return $translations[$language][$key] ?? $translations['en'][$key] ?? $key;
    }

    /**
     * @param array{entityId: string, mode: string, relatedUpdates: int, warnings: list<string>} $result
     */
    private function completionPage(Request $request, string $name, string $displayLabel, string $type, string $scriptQid, ?string $languageQid, string $nativeLabelLanguage, array $result, string $uiLanguage): string
    {
        $entityId = htmlspecialchars($result['entityId'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $entityIdJs = htmlspecialchars(json_encode($result['entityId'], JSON_THROW_ON_ERROR), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeDisplayLabel = htmlspecialchars($displayLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $homeAction = htmlspecialchars($request->getBasePath() . '/', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $mode = $result['mode'] === 'updated' ? $this->t($uiLanguage, 'updated') : $this->t($uiLanguage, 'created');
        $titleType = $this->completionTypeLabel($uiLanguage, $type);
        $typeLabel = htmlspecialchars($this->itemDisplayLabel(NameTypes::LABELS[$type] ?? $type, NameTypes::TYPE_ITEMS[$type] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $nativeLanguage = htmlspecialchars($nativeLabelLanguage !== '' ? $nativeLabelLanguage : 'mul', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $script = htmlspecialchars($this->scriptDisplayLabel($scriptQid), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $language = htmlspecialchars($languageQid ? $this->languageDisplayLabel($languageQid) : $this->t($uiLanguage, 'not_set'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $relatedUpdates = (int) $result['relatedUpdates'];
        $warnings = '';
        $safeUiLanguage = htmlspecialchars($uiLanguage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $titleText = $mode . ' ' . $titleType . " '" . $displayLabel . "' " . $this->t($uiLanguage, 'on_wikidata');
        $title = htmlspecialchars($titleText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $entityMessage = $result['mode'] === 'created' ? $this->t($uiLanguage, 'new_item_can_be_found_at') : $this->t($uiLanguage, 'made_changes_to_item');
        $safeEntityMessage = htmlspecialchars($entityMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $copy = htmlspecialchars($this->t($uiLanguage, 'copy'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $label = htmlspecialchars($this->t($uiLanguage, 'label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $nativeLabel = htmlspecialchars($this->t($uiLanguage, 'native_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $instanceOf = htmlspecialchars($this->t($uiLanguage, 'instance_of'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $writingSystem = htmlspecialchars($this->t($uiLanguage, 'writing_system'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $languageOfName = htmlspecialchars($this->t($uiLanguage, 'language_of_name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $relatedUpdatesLabel = htmlspecialchars($this->t($uiLanguage, 'related_updates'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $relatedSkipped = htmlspecialchars($this->t($uiLanguage, 'related_updates_skipped'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $createAnother = htmlspecialchars($this->t($uiLanguage, 'create_another_name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $analyze = htmlspecialchars($this->t($uiLanguage, 'analyze'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $credit = htmlspecialchars($this->t($uiLanguage, 'credit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $sourceCode = htmlspecialchars($this->t($uiLanguage, 'source_code'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $mit = htmlspecialchars($this->t($uiLanguage, 'mit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

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
        section { background: #fff; border: 1px solid #c8ccd1; border-radius: 2px; padding: 18px; }
        h1 { margin: 0 0 12px; font-size: 24px; font-weight: 500; }
        h2 { margin: 0 0 10px; font-size: 18px; }
        p { margin: 8px 0; }
        dl { display: grid; grid-template-columns: 160px 1fr; gap: 8px 12px; margin: 0; }
        dt { color: #54595d; }
        dd { margin: 0; }
        a { color: #36c; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .actions { display: flex; gap: 12px; flex-wrap: wrap; }
        .button { display: inline-flex; align-items: center; min-height: 36px; padding: 6px 12px; border: 1px solid #36c; background: #36c; color: #fff; border-radius: 2px; font-weight: 700; }
        .button.secondary { background: #fff; color: #36c; }
        .search { display: grid; grid-template-columns: 1fr auto; gap: 8px; }
        .search input { min-width: 0; min-height: 36px; border: 1px solid #a2a9b1; border-radius: 2px; padding: 6px 10px; font-size: 16px; }
        .search button { display: inline-flex; align-items: center; justify-content: center; min-height: 36px; border: 1px solid #36c; border-radius: 2px; background: #36c; color: #fff; padding: 6px 12px; font-weight: 700; cursor: pointer; }
        .search button:disabled { border-color: #c8ccd1; background: #c8ccd1; cursor: not-allowed; }
        .spinner { display: none; width: 16px; height: 16px; margin-right: 8px; border: 2px solid rgba(255,255,255,.55); border-top-color: #fff; border-radius: 50%; animation: spin .75s linear infinite; }
        .is-loading .spinner { display: inline-block; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .copy { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; margin-left: 6px; border: 1px solid #a2a9b1; border-radius: 2px; background: #fff; color: #202122; cursor: pointer; vertical-align: middle; }
        .copy:hover { background: #eaecf0; }
        .copy.is-copied { border-color: #14866d; color: #14866d; }
        .copy svg { width: 16px; height: 16px; }
        footer { color: #54595d; font-size: 13px; text-align: center; }
    </style>
</head>
<body>
<main>
    <section>
        <h1>$title</h1>
        <p>$safeEntityMessage <a href="https://www.wikidata.org/wiki/$entityId" target="_blank" rel="noopener noreferrer">$entityId</a><button class="copy" type="button" aria-label="$copy $entityId" title="$copy $entityId" onclick="copyEntityId(this, $entityIdJs)"><svg viewBox="0 0 20 20" aria-hidden="true"><path fill="currentColor" d="M6 2h9a1 1 0 0 1 1 1v11h-2V4H6V2Zm-2 4h9a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Zm1 2v8h7V8H5Z"/></svg></button></p>
        <dl>
            <dt>$label</dt><dd>$safeDisplayLabel (mul)</dd>
            <dt>$nativeLabel</dt><dd>$safeName ($nativeLanguage)</dd>
            <dt>$instanceOf</dt><dd>$typeLabel</dd>
            <dt>$writingSystem</dt><dd>$script</dd>
            <dt>$languageOfName</dt><dd>$language</dd>
            <dt>$relatedUpdatesLabel</dt><dd>$relatedUpdates</dd>
        </dl>
    </section>
    $warningBlock
    <section>
        <h2>$createAnother</h2>
        <form class="search" method="get" action="$homeAction" data-loading>
            <input type="hidden" name="ui" value="$safeUiLanguage">
            <input name="name" type="text" autocomplete="off">
            <button type="submit"><span class="spinner" aria-hidden="true"></span><span>$analyze</span></button>
        </form>
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
</script>
</body>
</html>
HTML;
    }

    private function completionTypeLabel(string $uiLanguage, string $type): string
    {
        $labels = [
            'en' => [
                NameTypes::FAMILY_NAME => 'last name',
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

    private function itemDisplayLabel(string $label, string $qid): string
    {
        return $qid !== '' ? $label . ' (' . $qid . ')' : $label;
    }

    private function scriptDisplayLabel(string $scriptQid): string
    {
        return $this->itemDisplayLabel($this->scriptLabel($scriptQid), $scriptQid);
    }

    private function languageDisplayLabel(string $languageQid): string
    {
        return $this->itemDisplayLabel($this->languageLabel($languageQid), $languageQid);
    }

    private function scriptLabel(string $scriptQid): string
    {
        foreach (ScriptDetector::SCRIPTS as $meta) {
            if ($meta['qid'] === $scriptQid) {
                return $meta['label'];
            }
        }

        return $scriptQid !== '' ? $scriptQid : 'not set';
    }
}
