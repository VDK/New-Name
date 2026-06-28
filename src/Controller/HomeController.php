<?php

namespace App\Controller;

use App\Data\LanguageHeuristics;
use App\Data\NameTypes;
use App\Service\NameAnalyzer;
use App\Service\NameTransliterator;
use App\Service\OAuthAuthorizationRequired;
use App\Service\ScriptDetector;
use App\Service\ScriptLanguageLookup;
use App\Service\NameFlowState;
use App\Service\UniversalNameSearch;
use App\Service\WikidataClient;
use App\Service\WikimediaOAuthClient;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(Request $request, NameAnalyzer $analyzer, WikimediaOAuthClient $oauthClient, NameTransliterator $transliterator, ScriptLanguageLookup $scriptLanguages, WikidataClient $wikidataClient): Response|RedirectResponse
    {
        $code = (string) $request->query->get('code', '');
        $state = (string) $request->query->get('state', '');
        if ($code !== '' && $state !== '') {
            $oauthClient->completeAuthorization($code, $state);
            $returnTo = (string) $request->getSession()->get('oauth_return_to', '/');
            $request->getSession()->remove('oauth_return_to');

            return new RedirectResponse($returnTo !== '' ? $returnTo : '/');
        }

        $name = trim((string) $request->query->get('name', ''));
        $type = $request->query->get('type');
        $language = $request->query->get('language');

        $languages = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $request->query->get('languages', ''))
        )));

        $preferredLanguages = $this->preferredLanguages(
            (string) $request->query->get('preferred_languages', '')
        );

        if ($preferredLanguages === [] && $languages !== []) {
            $preferredLanguages = array_fill_keys(
                array_filter($languages),
                2
            );
        }

        $uiLanguage = $this->interfaceLanguage((string) $request->query->get('ui', 'en'));
        $selectedItemId = strtoupper(trim((string) $request->query->get('existing_item', '')));
        if (preg_match('/^Q\d+$/', $selectedItemId) !== 1) {
            $selectedItemId = '';
        }
        $analysisRequested = $request->query->getBoolean('_analysis');
        $analysisPending = $name !== '' && !$analysisRequested;
        $analysis = $name !== '' && $analysisRequested
            ? $analyzer->analyze($name, is_string($type) ? $type : null, null, $selectedItemId)
            : null;
        if ($analysis) {
            $defaultTransliteration = $this->defaultTransliteration($name, $analysis['script'] ?? null, $transliterator);
            if ($selectedItemId !== '') {
                $selectedItem = $wikidataClient->nameItem($selectedItemId);
                $existingMulLabel = trim((string) ($selectedItem['mulLabel'] ?? ''));
                if ($existingMulLabel !== '') {
                    $defaultTransliteration = $existingMulLabel;
                }
            }
            if ($defaultTransliteration !== '') {
                $analysis = $analyzer->analyze($name, is_string($type) ? $type : null, $defaultTransliteration, $selectedItemId);
            }
        }
        $authorized = $oauthClient->isAuthorized();
        $username = '';
        if ($authorized) {
            try {
                $username = $oauthClient->getUsername();
            } catch (OAuthAuthorizationRequired) {
                $authorized = false;
                $username = '';
            } catch (\Throwable) {
                $username = '';
            }
        }

        $equivalentItemId = strtoupper(trim((string) $request->query->get('_equivalent', '')));
        if (preg_match('/^Q\d+$/', $equivalentItemId) !== 1) {
            $equivalentItemId = '';
        }

        $response = new Response($this->page($request, $name, $analysis, $analysisPending, is_string($language) ? $language : '', $authorized, $username, $uiLanguage, $preferredLanguages, $languages, $transliterator, $scriptLanguages, $wikidataClient, $equivalentItemId));
        $response->headers->set('Cache-Control', 'no-store, max-age=0');

        return $response;
    }

    #[Route('/api/name-relationships', name: 'api_name_relationships', methods: ['GET'])]
    public function nameRelationships(Request $request, NameAnalyzer $analyzer): JsonResponse
    {
        $name = trim((string) $request->query->get('name', ''));
        $transliteration = trim((string) $request->query->get('transliteration', ''));
        $type = (string) $request->query->get('type', '');
        $selectedItemId = strtoupper(trim((string) $request->query->get('existing_item', '')));
        $uiLanguage = $this->interfaceLanguage((string) $request->query->get('ui', 'en'));
        if ($name === '' || $transliteration === '') {
            return new JsonResponse(['html' => '']);
        }

        $analysis = $analyzer->analyze($name, $type, $transliteration, $selectedItemId);
        $html = '';
        foreach ($analysis['relationshipSuggestions'] as $suggestion) {
            $html .= $this->relationshipCheck($suggestion, $uiLanguage);
        }

        return new JsonResponse(['html' => $html], 200, ['Cache-Control' => 'no-store, max-age=0']);
    }

    #[Route('/api/script-preview', name: 'api_script_preview', methods: ['GET'])]
    public function scriptPreview(Request $request, NameTransliterator $transliterator): JsonResponse
    {
        $name = trim((string) $request->query->get('name', ''));
        $scriptQid = (string) $request->query->get('script', '');
        $script = null;
        foreach (ScriptDetector::SCRIPTS as $scriptName => $meta) {
            if ($meta['qid'] === $scriptQid) {
                $script = ['script' => $scriptName, ...$meta];
                break;
            }
        }

        $value = $name !== '' && is_array($script)
            ? $this->defaultTransliteration($name, $script, $transliterator)
            : '';

        return new JsonResponse([
            'transliteration' => $value,
            'showTransliteration' => !in_array($scriptQid, ['', 'Q8229'], true),
            'labelGroup' => $scriptQid === 'Q8209'
                ? $this->t($this->interfaceLanguage((string) $request->query->get('ui', 'en')), 'cyrillic_languages')
                : '',
        ], 200, ['Cache-Control' => 'no-store, max-age=0']);
    }

    /**
     * @param array<string, mixed>|null $analysis
     */
    private function page(Request $request, string $name, ?array $analysis, bool $analysisPending, string $language, bool $authorized, string $username, string $uiLanguage, array $preferredLanguages, array $languages,  NameTransliterator $transliterator, ScriptLanguageLookup $scriptLanguages, WikidataClient $wikidataClient, string $equivalentItemId = ''): string
    {
        $safeUiLanguage = htmlspecialchars($uiLanguage, ENT_QUOTES, 'UTF-8');
        $review = $analysis
            ? $this->review($request, $analysis, $language, $authorized, $uiLanguage, $preferredLanguages, $languages, $transliterator, $scriptLanguages, $wikidataClient, $equivalentItemId)
            : ($analysisPending ? $this->analysisLoading($name, $uiLanguage) : '');
        $bodyClass = $analysis || $analysisPending ? 'has-review' : 'start';
        $auth = $this->authStatus($request, $authorized, $username, $uiLanguage);
        $languageSwitch = $authorized ? '' : $this->languageSwitch($request, $uiLanguage);
        $tagline = match ($uiLanguage) {
            'nl' => 'Maak Wikidata-items<span class="tagline-break"><br></span> voor voor-&nbsp;en&nbsp;achternamen.',
            'de' => 'Neue Wikidata-Items<span class="tagline-break"><br></span> für Vor-&nbsp;und&nbsp;Nachnamen erstellen.',
            'fr' => 'Créer des éléments Wikidata<span class="tagline-break"><br></span> pour les prénoms et les noms de famille.',
            'es' => 'Crear elementos de Wikidata<span class="tagline-break"><br></span> para nombres de pila y apellidos.',
            default => 'Create Wikidata items<span class="tagline-break"><br></span> for given and family names.',
        };
        $possibleFullName = htmlspecialchars($this->t($uiLanguage, 'possible_full_name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $confirmNamePart = htmlspecialchars($this->t($uiLanguage, 'confirm_name_part'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $credit = htmlspecialchars($this->t($uiLanguage, 'credit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $sourceCode = htmlspecialchars($this->t($uiLanguage, 'source_code'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $mit = htmlspecialchars($this->t($uiLanguage, 'mit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $languageSearchUrl = json_encode($request->getBasePath() . '/index.php/api/languages', JSON_THROW_ON_ERROR);
        $nameItemSearchUrl = json_encode($request->getBasePath() . '/index.php/api/name-items', JSON_THROW_ON_ERROR);
        $relationshipSearchUrl = json_encode($request->getBasePath() . '/index.php/api/name-relationships', JSON_THROW_ON_ERROR);
        $scriptPreviewUrl = json_encode($request->getBasePath() . '/index.php/api/script-preview', JSON_THROW_ON_ERROR);
        $peopleBaseUrl = json_encode($request->getBasePath() . '/index.php/people', JSON_THROW_ON_ERROR);
        $nameAffixes = json_encode($this->nameAffixes(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $matchAction = htmlspecialchars($this->t($uiLanguage, 'match'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $jsCreateNewItem = json_encode($this->t($uiLanguage, 'create_new_item'), JSON_THROW_ON_ERROR);
        $jsCreateNote = json_encode($this->t($uiLanguage, 'create_note'), JSON_THROW_ON_ERROR);
        $jsReviewRequired = json_encode($this->t($uiLanguage, 'review_required_fields'), JSON_THROW_ON_ERROR);
        $jsReviewUpdates = json_encode($this->t($uiLanguage, 'review_updates'), JSON_THROW_ON_ERROR);
        $jsUpdateItem = json_encode($this->t($uiLanguage, 'update_item'), JSON_THROW_ON_ERROR);
        $jsUpdatePrefix = json_encode($this->t($uiLanguage, 'update_prefix'), JSON_THROW_ON_ERROR);
        $jsUpdateHeading = json_encode($this->t($uiLanguage, 'update_heading'), JSON_THROW_ON_ERROR);
        $jsLocatedAt = json_encode($this->t($uiLanguage, 'located_at'), JSON_THROW_ON_ERROR);
        $jsSelectedItem = json_encode($this->t($uiLanguage, 'selected_item'), JSON_THROW_ON_ERROR);
        $jsAlreadyExists = json_encode($this->t($uiLanguage, 'already_exists'), JSON_THROW_ON_ERROR);
        $jsNotSet = json_encode($this->t($uiLanguage, 'not_set'), JSON_THROW_ON_ERROR);
        $analysisUrl = 'null';
        if ($analysisPending) {
            $analysisQuery = $request->query->all();
            $analysisQuery['_analysis'] = '1';
            $analysisUrl = json_encode(
                $request->getBasePath() . '/index.php?' . http_build_query($analysisQuery),
                JSON_THROW_ON_ERROR
            );
        }
        $requestedItemId = strtoupper(trim((string) $request->query->get('existing_item', '')));
        $requestedType = trim((string) $request->query->get('type', ''));
        $hasRequestedItem = preg_match('/^Q\d+$/', $requestedItemId) === 1;
        $flowState = $hasRequestedItem
            ? NameFlowState::UPDATE
            : ($analysis || $analysisPending ? NameFlowState::CREATE : NameFlowState::SEARCH);
        $universalSearch = UniversalNameSearch::render([
            'action' => '',
            'ui' => $uiLanguage,
            'label' => $this->t($uiLanguage, 'name'),
            'inputId' => 'name-input',
            'suggestionsId' => 'name-item-suggestions',
            'itemId' => 'selected-name-item',
            'typeId' => 'selected-name-type',
            'analyzeId' => 'analyze-button',
            'actionsId' => 'matched-name-actions',
            'matchId' => 'match-name-button',
            'createLabel' => $this->t($uiLanguage, 'analyze'),
            'progressLabel' => $this->t($uiLanguage, 'analyzing'),
            'updateLabel' => $this->t($uiLanguage, 'update_item'),
            'matchLabel' => $this->t($uiLanguage, 'match'),
            'value' => $analysis ? '' : $name,
            'autofocus' => !$analysisPending,
            'selectionActive' => $analysisPending && $hasRequestedItem,
            'itemValue' => $analysisPending ? $requestedItemId : '',
            'typeValue' => $analysisPending ? $requestedType : '',
            'disabled' => $analysisPending,
            'readonly' => $analysisPending,
        ], $flowState);

        return <<<HTML
<!doctype html>
<html lang="$safeUiLanguage">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="new-name-build" content="2026-06-12-2">
    <title>New Name</title>
    <style>
        :root {
            --bg: #f8f9fa;
            --panel: #ffffff;
            --text: #202122;
            --muted: #54595d;
            --line: #a2a9b1;
            --soft: #eaecf0;
            --accent: #36c;
            --accent-dark: #2a4b8d;
            --danger: #d33;
            --focus: #36c;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Lato, Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .shell {
            width: min(720px, calc(100% - 32px));
            margin: 0 auto;
            padding: 32px 0 48px;
        }

        body.start .shell {
            min-height: 100vh;
            display: grid;
            align-content: center;
            padding-top: 0;
        }

        .brand {
            text-align: center;
            margin-bottom: 22px;
        }

        .auth-status {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 6px;
            min-height: 28px;
            margin-bottom: 12px;
            color: var(--muted);
            font-size: 14px;
        }

        .auth-line {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .auth-status a {
            color: var(--accent);
            text-decoration: none;
        }

        .auth-status a:hover {
            text-decoration: underline;
        }

        .topbar {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 16px;
            align-items: start;
            margin-bottom: 12px;
        }

        .language-switch {
            display: flex;
            align-items: center;
            font-size: 13px;
        }

        .language-switch label {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
        }

        .language-switch select {
            width: auto;
            max-width: max-content;
            min-height: 32px;
            padding: 4px 28px 4px 8px;
            border: 1px solid #a2a9b1;
            border-radius: 2px;
            background: #fff;
            color: var(--text);
        }

        h1 {
            margin: 0 0 8px;
            font-size: 44px;
            line-height: 1.1;
            font-weight: 400;
        }

        .tagline {
            margin: 0;
            color: var(--muted);
            font-size: 16px;
        }

        .tagline-break {
            display: none;
        }

        .search-card {
            width: min(720px, 100%);
            margin: 0 auto;
        }

        .search-label {
            display: block;
            margin: 0 0 6px;
            color: var(--text);
            font-size: 14px;
            font-weight: 700;
        }

        .search {
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
        }

        .name-search-wrap {
            position: relative;
        }

        .search-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            width: 100%;
        }

        .search-actions.is-split > button { flex: 1 1 50%; }
        .search-actions > button,
        .search-actions > .search-actions.is-split { width: 100%; }
        .search-actions.is-split > button { min-width: 0; }

        .full-name-confirmation {
            margin: 0;
            padding: 10px 12px;
            border-left: 4px solid #fc3;
            background: #fef6e7;
            font-size: 14px;
            line-height: 1.4;
        }

        .full-name-confirmation label {
            display: flex;
            gap: 8px;
            align-items: flex-start;
            font-weight: 400;
        }

        .full-name-confirmation input {
            flex: 0 0 auto;
            width: 18px;
            height: 18px;
            margin-top: 1px;
        }

        .search-button-spinner {
            display: none;
            width: 16px;
            height: 16px;
            margin-right: 7px;
            border: 2px solid rgba(255,255,255,.55);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .75s linear infinite;
        }

        #analyze-button.is-searching .search-button-spinner { display: inline-block; }
        .search-progress-label { display: none; }
        #analyze-button.is-searching .search-default-label { display: none; }
        #analyze-button.is-searching .search-progress-label { display: inline; }

        .name-suggestions {
            display: none;
            position: absolute;
            z-index: 20;
            top: calc(100% + 2px);
            left: 0;
            right: 0;
            max-height: 280px;
            overflow: auto;
            margin: 0;
            padding: 4px 0;
            list-style: none;
            border: 1px solid var(--line);
            background: #fff;
            box-shadow: 0 2px 6px rgba(0, 0, 0, .15);
        }

        .name-suggestions li {
            display: flex;
            flex-direction: column;
            gap: 2px;
            align-items: stretch;
            padding: 8px 10px;
            cursor: pointer;
            border-top: 1px solid var(--line);
        }

        .name-suggestions li:first-child { border-top: 0; }

        .name-suggestions li:hover,
        .name-suggestions li.is-active {
            background: var(--soft);
        }

        .name-suggestions strong,
        .name-suggestions span {
            display: block;
        }

        .search input {
            width: 100%;
            min-width: 0;
            height: 44px;
            border: 1px solid var(--line);
            border-radius: 2px;
            outline: 0;
            padding: 0 12px;
            font-size: 18px;
            background: var(--panel);
        }

        .full-name-confirmation input {
            flex: 0 0 auto;
            width: 18px;
            min-width: 18px;
            height: 18px;
            margin: 1px 0 0;
            padding: 0;
            box-shadow: none;
        }

        .search input:focus, select:focus {
            border-color: var(--focus);
            box-shadow: inset 0 0 0 1px var(--focus);
        }

        button, .pill-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            border: 1px solid var(--accent);
            border-radius: 2px;
            padding: 8px 14px;
            background: var(--accent);
            color: #fff;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            white-space: nowrap;
        }

        button:hover, .pill-link:hover {
            border-color: var(--accent-dark);
            background: var(--accent-dark);
        }

        button:disabled {
            border-color: #c8ccd1;
            background: #c8ccd1;
            color: #fff;
            cursor: not-allowed;
        }

        .spinner {
            display: none;
            width: 16px;
            height: 16px;
            margin-right: 8px;
            border: 2px solid rgba(255, 255, 255, 0.55);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.75s linear infinite;
        }

        .is-loading .spinner {
            display: inline-block;
        }

        .analysis-loading {
            overflow: hidden;
        }

        .analysis-loading h2 {
            margin-bottom: 12px;
        }

        .analysis-progress {
            position: relative;
            height: 4px;
            overflow: hidden;
            border-radius: 2px;
            background: var(--soft);
        }

        .analysis-progress::after {
            content: "";
            position: absolute;
            inset: 0 auto 0 -35%;
            width: 35%;
            background: var(--accent);
            animation: analysis-progress 1.15s ease-in-out infinite;
        }

        @keyframes analysis-progress {
            from { left: -35%; }
            to { left: 135%; }
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .review {
            margin-top: 28px;
            display: grid;
            gap: 14px;
        }

        section {
            background: var(--panel);
            border: 1px solid #c8ccd1;
            border-radius: 2px;
            padding: 18px;
        }

        h2 {
            margin: 0 0 12px;
            font-size: 18px;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 14px;
        }

        label {
            font-weight: 700;
        }

        select {
            width: 100%;
            height: 42px;
            margin-top: 7px;
            border: 1px solid #b8c4cf;
            border-radius: 2px;
            padding: 7px 8px;
            background: #fff;
            font-size: 15px;
        }

        select:disabled,
        .typeahead:read-only {
            border-color: #c8ccd1;
            background: #eaecf0;
            color: #54595d;
            cursor: not-allowed;
            opacity: 1;
        }

        .typeahead {
            width: 100%;
            height: 42px;
            margin-top: 7px;
            border: 1px solid #b8c4cf;
            border-radius: 2px;
            padding: 7px 8px;
            background: #fff;
            font-size: 15px;
        }

        .typeahead:focus {
            border-color: var(--focus);
            box-shadow: inset 0 0 0 1px var(--focus);
            outline: 0;
        }

        .required {
            color: var(--danger);
            font-size: 12px;
            font-weight: 700;
        }

        .optional {
            color: var(--muted);
            font-weight: 400;
        }

        .warning {
            display: none;
            margin-top: 8px;
            padding: 8px 10px;
            border: 1px solid #fc3;
            background: #fef6e7;
            color: #202122;
            font-size: 14px;
        }

        .typeahead-wrap {
            position: relative;
        }

        .suggest-list {
            display: none;
            position: absolute;
            z-index: 5;
            left: 0;
            right: 0;
            top: calc(100% + 2px);
            max-height: 260px;
            overflow: auto;
            margin: 0;
            padding: 4px 0;
            list-style: none;
            border: 1px solid #a2a9b1;
            background: #fff;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
        }

        .suggest-option {
            padding: 8px 10px;
            cursor: pointer;
            display: grid;
            grid-template-rows: auto auto;
            column-gap: 8px;
            row-gap: 2px;
        }

        .suggest-option:hover,
        .suggest-option.is-active {
            background: #eaecf0;
        }

        .suggest-label {
            font-weight: 700;
        }

        .suggest-alt {
            color: var(--muted);
            font-style: italic;
            font-weight: 400;
        }

        .suggest-desc {
            color: var(--muted);
            font-size: 12px;
        }

        .hint, .reason {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.4;
        }

        .checks {
            display: grid;
            gap: 10px;
        }

        .subhead {
            margin: 18px 0 8px;
            color: var(--muted);
            font-size: 14px;
            font-weight: 700;
        }

        .check {
            display: grid;
            grid-template-columns: 24px 1fr;
            gap: 10px;
            align-items: start;
            padding: 10px;
            border: 1px solid var(--line);
            border-radius: 2px;
            background: #fff;
        }

        .check.duplicate-check {
            grid-template-columns: 24px 1fr auto;
            align-items: center;
        }

        .match-option {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 5px 12px;
            border: 1px solid var(--accent);
            border-radius: 2px;
            background: var(--accent);
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }

        .match-option:hover {
            background: var(--accent-dark);
        }

        .check input {
            width: 18px;
            height: 18px;
            margin-top: 2px;
        }

        .check.featured {
            border-color: var(--accent);
            background: #f8fbff;
            padding-top: 16px;
            padding-bottom: 16px;
        }

        .check.featured span {
            align-self: center;
        }

        .meta {
            color: var(--muted);
            font-size: 13px;
        }

        .qid-link {
            color: var(--muted);
            font-size: 13px;
        }

        .badges {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }

        .badge {
            display: inline-flex;
            border-radius: 999px;
            padding: 4px 9px;
            background: var(--soft);
            color: var(--text);
            font-size: 12px;
            font-weight: 700;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        th, td {
            padding: 9px 8px;
            border-top: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
        }

        th {
            width: 140px;
            color: var(--muted);
        }

        a { color: var(--accent-dark); }

        .actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
        }

        .actions .full-name-confirmation {
            flex: 1 1 100%;
            width: 100%;
        }

        .secondary-action {
            border-color: var(--line);
            background: #fff;
            color: var(--text);
        }

        .secondary-action:hover {
            border-color: #72777d;
            background: var(--soft);
        }

        .update-type-display {
            min-height: 42px;
            margin-top: 7px;
            padding: 10px 0;
            font-weight: 700;
        }

        .selected-item-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px;
            border: 1px solid var(--line);
            background: #fff;
        }

        .selected-item-details {
            min-width: 0;
        }

        .selected-item-card .meta {
            display: block;
            margin-top: 3px;
        }

        .selected-item-card .qid-link {
            display: block;
            width: fit-content;
            margin-top: 5px;
        }

        .selected-item-change {
            display: inline-block;
            margin-top: 8px;
            font-size: 13px;
        }

        .footer {
            margin-top: 18px;
            color: var(--muted);
            font-size: 13px;
            text-align: center;
        }

        .footer a {
            color: var(--accent-dark);
        }

        @media (max-width: 760px) {
            h1 { font-size: 38px; }
            .grid { grid-template-columns: 1fr; }
            th { width: auto; }
            .name-suggestions {
                position: static;
                margin-top: 2px;
            }
        }

        @media (max-width: 480px) {
            .tagline-break { display: inline; }
        }
    </style>
    <script>
        function clearLanguageSelection() {
            var value = document.getElementById('language-value');
            var code = document.getElementById('language-code');
            if (value) value.value = '';
            if (code) code.value = '';
            var nativeLabel = document.getElementById('native-label-language');
            if (nativeLabel) nativeLabel.textContent = 'mul';
            updateLanguageClaim('', '');
        }

        function languagePreferences() {
            try {
                var stored = JSON.parse(localStorage.getItem('newNameLanguagePreferences') || '{}');
                return stored && typeof stored === 'object' ? stored : {};
            } catch (e) {
                return {};
            }
        }

        function preferenceString() {
            var prefs = languagePreferences();
            return Object.keys(prefs)
                .filter(function(code) { return /^[a-z-]{2,12}$/.test(code); })
                .sort(function(a, b) { return (prefs[b] || 0) - (prefs[a] || 0); })
                .slice(0, 8)
                .map(function(code) { return code + ':' + Math.max(1, Math.min(99, parseInt(prefs[code], 10) || 1)); })
                .join(',');
        }

        function rememberLanguageCode(code) {
            if (!code) return;
            try {
                var prefs = languagePreferences();
                prefs[code] = Math.min(99, (parseInt(prefs[code], 10) || 0) + 1);
                localStorage.setItem('newNameLanguagePreferences', JSON.stringify(prefs));
            } catch (e) {}
        }

        function attachLanguagePreferences(form) {
            if (!form) return;
            form.addEventListener('submit', function() {
                var existing = form.querySelector('input[name="preferred_languages"]');
                if (!existing) {
                    existing = document.createElement('input');
                    existing.type = 'hidden';
                    existing.name = 'preferred_languages';
                    form.appendChild(existing);
                }
                existing.value = preferenceString();
            });
        }

        var languageTimer = null;
        var languageItems = [];
        var languageActive = -1;

        function closeLanguageList() {
            var list = document.getElementById('language-suggestions');
            var input = document.getElementById('language-input');
            if (list) {
                list.style.display = 'none';
                list.innerHTML = '';
            }
            if (input) input.setAttribute('aria-expanded', 'false');
            languageItems = [];
            languageActive = -1;
        }

        function setLanguageActive(index) {
            var list = document.getElementById('language-suggestions');
            if (!list || !languageItems.length) return;
            if (index < 0) index = languageItems.length - 1;
            if (index >= languageItems.length) index = 0;
            languageActive = index;
            Array.prototype.forEach.call(list.children, function(child, i) {
                child.classList.toggle('is-active', i === languageActive);
            });
            list.children[languageActive].scrollIntoView({ block: 'nearest' });
        }

        function pickLanguage(item) {
            var input = document.getElementById('language-input');
            var value = document.getElementById('language-value');
            var code = document.getElementById('language-code');
            if (!input || !value || !code) return;
            input.value = item.label || item.id;
            value.value = item.id || '';
            code.value = item.code || '';
            rememberLanguageCode(item.code || '');
            var nativeLabel = document.getElementById('native-label-language');
            if (nativeLabel) nativeLabel.textContent = item.code || 'mul';
            updateLanguageClaim(item.label || item.id || '', item.id || '');
            closeLanguageList();
        }

        function updateLanguageClaim(label, qid) {
            var checkbox = document.getElementById('claim_P407');
            var detail = document.getElementById('claim-P407-detail');
            if (detail) detail.textContent = label || $jsNotSet;
            if (!checkbox) return;
            checkbox.disabled = !qid;
            checkbox.checked = !!qid;
        }

        function renderLanguages(items) {
            var list = document.getElementById('language-suggestions');
            var input = document.getElementById('language-input');
            if (!list || !input) return;
            languageItems = items || [];
            list.innerHTML = '';
            if (!languageItems.length) {
                closeLanguageList();
                return;
            }
            languageItems.forEach(function(item) {
                var option = document.createElement('li');
                option.className = 'suggest-option';
                option.setAttribute('role', 'option');
                option.innerHTML = '<span class="suggest-label"></span><span class="suggest-desc"></span>';
                var label = option.querySelector('.suggest-label');
                label.textContent = item.label || '';
                if (item.altLabel) {
                    var alt = document.createElement('span');
                    alt.className = 'suggest-alt';
                    alt.textContent = ' (' + item.altLabel + ')';
                    label.appendChild(alt);
                }
                option.querySelector('.suggest-desc').textContent = item.description || '';
                option.addEventListener('click', function(event) {
                    event.preventDefault();
                    pickLanguage(item);
                });
                list.appendChild(option);
            });
            languageActive = 0;
            list.children[0].classList.add('is-active');
            list.style.display = 'block';
            input.setAttribute('aria-expanded', 'true');
        }

        function searchLanguages(input, changed) {
            if (changed) clearLanguageSelection();
            clearTimeout(languageTimer);
            var hints = input.dataset.hints || '';
            if (input.value.length < 2 && hints === '') {
                closeLanguageList();
                return;
            }
            languageTimer = setTimeout(function() {
                fetch($languageSearchUrl + '?q=' + encodeURIComponent(input.value) + '&hints=' + encodeURIComponent(hints) + '&ui=$safeUiLanguage&v=20260620-language-picker')
                    .then(function(response) {
                        if (!response.ok) throw new Error('Language search failed');
                        return response.json();
                    })
                    .then(function(items) { renderLanguages(Array.isArray(items) ? items : []); })
                    .catch(closeLanguageList);
            }, 180);
        }

        function languageKeydown(event) {
            var list = document.getElementById('language-suggestions');
            if (!list || list.style.display !== 'block') return;
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                setLanguageActive(languageActive + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                setLanguageActive(languageActive - 1);
            } else if (event.key === 'Enter') {
                event.preventDefault();
                if (languageItems[languageActive]) pickLanguage(languageItems[languageActive]);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                closeLanguageList();
            }
        }

        function updateMode(resetTransliteration) {
            resetTransliteration = resetTransliteration === true;
            var serverExistingItem = document.getElementById('server-existing-item');
            var selected = document.querySelector('input[name="existing_item"]:checked') || serverExistingItem;
            var serverUpdate = !!serverExistingItem;
            var heading = document.getElementById('apply-heading');
            var note = document.getElementById('apply-note');
            var button = document.getElementById('save-button');
            var script = document.getElementById('script');
            var frozenScript = document.getElementById('frozen-script');
            var transliteration = document.getElementById('label-transliteration');
            var typeEditor = document.getElementById('type-editor');
            var typeDisplay = document.getElementById('update-type-display');
            var reviewHeading = document.getElementById('review-heading');
            var matchWithoutUpdate = document.getElementById('match-without-update');
            var overwriteDescriptions = document.getElementById('overwrite-descriptions');
            var overwriteDescriptionsRow = document.getElementById('overwrite-descriptions-row');
            var nativeLabelField = document.getElementById('native-label-field');
            var nativeLabelInput = document.getElementById('native-label-input');
            var nativeLabelLocked = document.getElementById('native-label-locked');
            var nativeLabelRow = document.getElementById('native-label-row');
            var scriptField = document.getElementById('script-field');
            var existingItemHeading = document.getElementById('existing-item-heading');
            var existingItemHint = document.getElementById('existing-item-hint');
            var existingItemOptions = document.getElementById('existing-item-options');
            var selectedItemCard = document.getElementById('selected-item-card');
            var selectedItemLabel = document.getElementById('selected-item-label');
            var selectedItemDescription = document.getElementById('selected-item-description');
            var selectedItemQid = document.getElementById('selected-item-qid');
            var selectedItemMatch = document.getElementById('selected-item-match');
            var isCreate = !selected || selected.value === '';
            document.querySelectorAll('[data-suggestion-target]').forEach(function(row) {
                if (isCreate) {
                    row.style.display = '';
                    var createInput = row.querySelector('input');
                    if (createInput) createInput.disabled = false;
                    return;
                }
                var hide = row.dataset.suggestionTarget === selected.value;
                row.style.display = hide ? 'none' : '';
                var input = row.querySelector('input');
                if (input) input.disabled = hide;
            });
            if (isCreate) {
                if (nativeLabelField) nativeLabelField.style.display = '';
                if (scriptField) scriptField.style.display = '';
                document.querySelectorAll('[data-update-label]').forEach(function(row) {
                    row.style.display = '';
                });
                if (script) {
                    script.disabled = false;
                    script.value = script.dataset.detected || '';
                    updateScriptFields(script);
                }
                if (frozenScript) {
                    frozenScript.disabled = true;
                    frozenScript.value = '';
                }
                if (transliteration) {
                    var keepCreateTransliteration = !resetTransliteration
                        && transliteration.dataset.userEdited === '1'
                        && transliteration.value.trim() !== '';
                    if (!keepCreateTransliteration) {
                        transliteration.value = transliteration.dataset.defaultValue || '';
                        transliteration.dataset.userEdited = '0';
                    }
                    transliteration.disabled = false;
                    updateLabelPreview(transliteration);
                }
                if (typeEditor) typeEditor.style.display = '';
                var typeSelect = document.getElementById('type');
                if (typeSelect) typeSelect.disabled = false;
                if (typeDisplay) typeDisplay.style.display = 'none';
                if (reviewHeading) reviewHeading.textContent = $jsReviewRequired;
                if (matchWithoutUpdate) matchWithoutUpdate.style.display = 'none';
                if (overwriteDescriptions) {
                    overwriteDescriptions.checked = false;
                    overwriteDescriptions.disabled = true;
                }
                if (overwriteDescriptionsRow) overwriteDescriptionsRow.style.display = 'none';
                if (nativeLabelInput) nativeLabelInput.readOnly = false;
                if (nativeLabelLocked) nativeLabelLocked.style.display = 'none';
                if (nativeLabelRow) nativeLabelRow.style.display = '';
                if (existingItemHeading) existingItemHeading.textContent = $jsAlreadyExists;
                if (existingItemHint) existingItemHint.style.display = '';
                if (existingItemOptions) existingItemOptions.style.display = '';
                if (selectedItemCard) selectedItemCard.style.display = 'none';
                if (heading) heading.textContent = $jsCreateNewItem;
                if (note) {
                    note.textContent = $jsCreateNote;
                    note.style.display = '';
                }
                if (button) {
                    var text = button.querySelector('span:last-child');
                    if (text) text.textContent = $jsCreateNewItem;
                }
                return;
            }
            var label = selected.dataset.label || 'item';
            var qid = selected.value;
            var typeLabel = selected.dataset.typeLabel || '';
            var description = selected.dataset.description || '';
            var existingScriptInput = document.querySelector('[data-existing-script-for="' + qid + '"]');
            var existingScript = selected.dataset.script || (existingScriptInput ? existingScriptInput.value : '');
            var existingMulInput = document.querySelector('[data-existing-mul-for="' + qid + '"]');
            var existingMul = selected.dataset.mul || (existingMulInput ? existingMulInput.value : '');
            if (existingMul) label = existingMul;
            if (script) {
                script.value = existingScript || script.dataset.detected || '';
                script.disabled = !!existingScript;
            }
            if (frozenScript) {
                frozenScript.value = existingScript;
                frozenScript.disabled = !existingScript;
            }
            var transliterationField = document.getElementById('transliteration-field');
            var usesTransliteration = !!script && script.value !== '' && script.value !== 'Q8229';
            var proposedTransliteration = transliteration ? (transliteration.dataset.defaultValue || '') : '';
            if (transliteration) {
                var nextTransliteration = existingMul || proposedTransliteration;
                var keepUserTransliteration = !resetTransliteration
                    && transliteration.dataset.userEdited === '1'
                    && transliteration.value.trim() !== '';
                transliteration.dataset.existingValue = existingMul;
                if (!keepUserTransliteration) {
                    transliteration.value = nextTransliteration;
                    transliteration.dataset.userEdited = '0';
                }
                transliteration.disabled = !usesTransliteration;
                syncTransliterationUpdate(transliteration);
            }
            if (transliterationField) {
                transliterationField.style.display = usesTransliteration ? '' : 'none';
            }
            if (nativeLabelField) nativeLabelField.style.display = '';
            if (scriptField) scriptField.style.display = '';
            if (nativeLabelRow) nativeLabelRow.style.display = 'none';
            if (script) warnScriptChange(script);
            if (typeEditor) typeEditor.style.display = '';
            var typeSelect = document.getElementById('type');
            if (typeSelect) typeSelect.disabled = true;
            if (reviewHeading) reviewHeading.textContent = $jsUpdateItem;
            if (matchWithoutUpdate) {
                matchWithoutUpdate.style.display = 'inline-flex';
                matchWithoutUpdate.dataset.nameItem = qid;
            }
            if (overwriteDescriptions) overwriteDescriptions.disabled = false;
            if (overwriteDescriptionsRow) overwriteDescriptionsRow.style.display = '';
            if (nativeLabelInput) nativeLabelInput.readOnly = true;
            if (nativeLabelLocked) nativeLabelLocked.style.display = '';
            if (serverUpdate) {
                if (existingItemHeading) existingItemHeading.textContent = $jsSelectedItem;
                if (existingItemHint) existingItemHint.style.display = 'none';
                if (existingItemOptions) existingItemOptions.style.display = 'none';
                if (selectedItemCard) selectedItemCard.style.display = '';
                if (selectedItemLabel) selectedItemLabel.textContent = label;
                if (selectedItemDescription) {
                    selectedItemDescription.textContent = description;
                    selectedItemDescription.style.display = description ? 'block' : 'none';
                }
                if (selectedItemQid) {
                    selectedItemQid.textContent = qid;
                    selectedItemQid.href = 'https://www.wikidata.org/wiki/' + qid;
                }
                if (selectedItemMatch) {
                    var selectedName = document.querySelector('.review input[name="name"]');
                    selectedItemMatch.href = $peopleBaseUrl + '?name_item=' + encodeURIComponent(qid)
                        + '&person_query=' + encodeURIComponent(selectedName ? selectedName.value : label)
                        + '&ui=' + encodeURIComponent('$safeUiLanguage');
                }
            } else {
                if (existingItemHeading) existingItemHeading.textContent = $jsAlreadyExists;
                if (existingItemHint) existingItemHint.style.display = '';
                if (existingItemOptions) existingItemOptions.style.display = '';
                if (selectedItemCard) selectedItemCard.style.display = 'none';
            }
            if (heading) heading.textContent = $jsReviewUpdates;
            if (note) note.style.display = 'none';
            if (button) {
                var text = button.querySelector('span:last-child');
                if (text) text.textContent = $jsUpdateItem;
            }
        }

        function warnScriptChange(select) {
            var warning = document.getElementById('script-warning');
            if (!warning) return;
            if (select.disabled) {
                warning.style.display = 'none';
                return;
            }
            warning.style.display = select.value === select.dataset.detected ? 'none' : 'block';
        }

        function updateScriptFields(select) {
            warnScriptChange(select);
            var scriptClaimDetail = document.getElementById('claim-P282-detail');
            if (scriptClaimDetail) {
                scriptClaimDetail.textContent = select.options[select.selectedIndex]
                    ? select.options[select.selectedIndex].textContent
                    : '';
            }
            var nativeLabel = document.getElementById('native-label-input');
            var transliterationField = document.getElementById('transliteration-field');
            var transliteration = document.getElementById('label-transliteration');
            var scriptSummary = document.getElementById('script-label-summary');
            var scriptSummaryValue = document.getElementById('script-label-summary-value');
            if (!nativeLabel) return;
            fetch($scriptPreviewUrl
                + '?name=' + encodeURIComponent(nativeLabel.value.trim())
                + '&script=' + encodeURIComponent(select.value)
                + '&ui=' + encodeURIComponent('$safeUiLanguage'))
                .then(function(response) {
                    if (!response.ok) throw new Error('Script preview failed');
                    return response.json();
                })
                .then(function(data) {
                    var value = data.transliteration || '';
                    if (transliterationField) transliterationField.style.display = data.showTransliteration ? '' : 'none';
                    if (transliteration) {
                        transliteration.dataset.defaultValue = value;
                        if (transliteration.dataset.userEdited !== '1' || transliteration.value.trim() === '') {
                            transliteration.value = value;
                            transliteration.dataset.userEdited = '0';
                        }
                        updateLabelPreview(transliteration);
                    }
                    if (scriptSummary) scriptSummary.style.display = data.labelGroup ? '' : 'none';
                    if (scriptSummaryValue) {
                        scriptSummaryValue.textContent = nativeLabel.value.trim() + ' (' + (data.labelGroup || '') + ')';
                    }
                    document.querySelectorAll('[data-script-language-row]').forEach(function(row) {
                        row.style.display = data.labelGroup ? 'none' : '';
                    });
                })
                .catch(function() {});
        }

        function updateLabelPreview(input) {
            var value = input.value.trim() || input.dataset.originalName || '';
            document.querySelectorAll('[data-label-preview="display"]').forEach(function(preview) {
                preview.textContent = value + ' (' + preview.dataset.labelPreviewLanguage + ')';
            });
        }

        function syncTransliterationUpdate(input) {
            updateLabelPreview(input);
            var changed = input.value.trim() !== ''
                && input.value.trim() !== (input.dataset.existingValue || '');
            document.querySelectorAll('[data-update-label]').forEach(function(row) {
                row.style.display = changed && row.dataset.updateLabel === 'mul' ? '' : 'none';
            });
        }

        function renderExistingNameItems(items) {
            var table = document.getElementById('existing-item-options');
            var nativeLabel = document.getElementById('native-label-input');
            var type = document.querySelector('.review select[name="type"]');
            if (!table || !nativeLabel || !type) return;
            table.innerHTML = '';
            table.style.opacity = '';

            var createRow = document.createElement('tr');
            var createCell = document.createElement('td');
            createCell.colSpan = 2;
            var createLabel = document.createElement('label');
            createLabel.className = 'check featured';
            var createRadio = document.createElement('input');
            createRadio.type = 'radio';
            createRadio.name = 'existing_item';
            createRadio.value = '';
            createRadio.checked = true;
            createRadio.dataset.label = $jsCreateNewItem;
            var createText = document.createElement('span');
            var createStrong = document.createElement('strong');
            createStrong.textContent = $jsCreateNewItem;
            createText.appendChild(createStrong);
            createLabel.appendChild(createRadio);
            createLabel.appendChild(createText);
            createCell.appendChild(createLabel);
            createRow.appendChild(createCell);
            table.appendChild(createRow);

            var wantedType = type.value === 'family' ? 'family' : 'given';
            items.filter(function(item) {
                return item.type === wantedType;
            }).slice(0, 8).forEach(function(item) {
                var row = document.createElement('tr');
                var cell = document.createElement('td');
                cell.colSpan = 2;
                var label = document.createElement('label');
                label.className = 'check duplicate-check';
                var radio = document.createElement('input');
                radio.type = 'radio';
                radio.name = 'existing_item';
                radio.value = item.id;
                radio.dataset.label = item.label;
                radio.dataset.description = item.description || '';
                radio.dataset.typeLabel = wantedType === 'family' ? 'family name' : 'given name';
                radio.addEventListener('change', function() { updateMode(true); });
                var text = document.createElement('span');
                var strong = document.createElement('strong');
                strong.textContent = item.label;
                var qid = document.createElement('a');
                qid.className = 'qid-link';
                qid.href = 'https://www.wikidata.org/wiki/' + item.id;
                qid.target = '_blank';
                qid.rel = 'noopener noreferrer';
                qid.textContent = ' (' + item.id + ')';
                text.appendChild(strong);
                text.appendChild(qid);
                text.appendChild(document.createElement('br'));
                var description = document.createElement('span');
                description.className = 'meta';
                description.textContent = item.description || (wantedType === 'family' ? 'family name' : 'given name');
                text.appendChild(description);
                var match = document.createElement('a');
                match.className = 'match-option';
                match.href = $peopleBaseUrl + '?name_item=' + encodeURIComponent(item.id)
                    + '&person_query=' + encodeURIComponent(nativeLabel.value.trim())
                    + '&ui=' + encodeURIComponent('$safeUiLanguage');
                match.textContent = '$matchAction';
                label.appendChild(radio);
                label.appendChild(text);
                label.appendChild(match);
                cell.appendChild(label);
                row.appendChild(cell);
                table.appendChild(row);
            });
        }

        function confirmNameType(value) {
            var type = document.querySelector('.review select[name="type"]');
            var hiddenType = document.querySelector('.review input[name="type"]');
            var current = type ? type.value : (hiddenType ? hiddenType.value : '');
            var next = value === 'family'
                ? 'family'
                : (current === 'family' ? 'given' : current);
            if (type) type.value = next || 'given';
            if (hiddenType) hiddenType.value = next || 'given';
        }

        function initNameItemSearch() {
            var input = document.getElementById('name-input');
            var list = document.getElementById('name-item-suggestions');
            var itemValue = document.getElementById('selected-name-item');
            var typeValue = document.getElementById('selected-name-type');
            var analyzeButton = document.getElementById('analyze-button');
            var matchedActions = document.getElementById('matched-name-actions');
            var matchButton = document.getElementById('match-name-button');
            if (!input || !list || !itemValue || !typeValue || !analyzeButton || !matchedActions || !matchButton) return;
            var timer = null;
            var items = [];
            var active = -1;
            var searchPending = false;

            function close() {
                list.style.display = 'none';
                list.innerHTML = '';
                input.setAttribute('aria-expanded', 'false');
                items = [];
                active = -1;
            }

            function clearSelection() {
                itemValue.value = '';
                typeValue.value = '';
                analyzeButton.style.display = '';
                analyzeButton.classList.remove('is-searching');
                matchedActions.style.display = 'none';
                analyzeButton.disabled = searchPending;
            }

            function select(item) {
                input.value = item.label;
                itemValue.value = item.id;
                typeValue.value = item.type;
                analyzeButton.style.display = 'none';
                matchedActions.style.display = 'flex';
                close();
            }

            function render(found) {
                items = found || [];
                list.innerHTML = '';
                if (!items.length) {
                    close();
                    return;
                }
                items.forEach(function(item, index) {
                    var option = document.createElement('li');
                    var title = document.createElement('strong');
                    title.textContent = item.label;
                    var detail = document.createElement('span');
                    detail.className = 'meta';
                    var typeLabel = item.type === 'given' ? 'given name' : 'family name';
                    detail.textContent = item.description
                        || (item.label.toLowerCase().indexOf(typeLabel) === -1 ? typeLabel : '');
                    option.appendChild(title);
                    option.appendChild(detail);
                    option.addEventListener('click', function(event) {
                        event.preventDefault();
                        select(item);
                    });
                    option.addEventListener('mouseenter', function() { active = index; });
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
                    analyzeButton.disabled = false;
                    close();
                    return;
                }
                searchPending = true;
                analyzeButton.disabled = true;
                analyzeButton.classList.add('is-searching');
                timer = setTimeout(function() {
                    fetch($nameItemSearchUrl + '?q=' + encodeURIComponent(input.value.trim()) + '&ui=' + encodeURIComponent('$safeUiLanguage'))
                        .then(function(response) {
                            if (!response.ok) throw new Error('Name search failed');
                            return response.json();
                        })
                        .then(function(found) {
                            searchPending = false;
                            analyzeButton.disabled = false;
                            analyzeButton.classList.remove('is-searching');
                            render(found);
                        })
                        .catch(function() {
                            searchPending = false;
                            analyzeButton.disabled = false;
                            analyzeButton.classList.remove('is-searching');
                            close();
                        });
                }, 200);
            });
            input.addEventListener('keydown', function(event) {
                if (!items.length) return;
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    active = event.key === 'ArrowDown'
                        ? (active + 1) % items.length
                        : (active <= 0 ? items.length - 1 : active - 1);
                    Array.prototype.forEach.call(list.children, function(child, index) {
                        child.classList.toggle('is-active', index === active);
                    });
                } else if (event.key === 'Enter' && active >= 0) {
                    event.preventDefault();
                    select(items[active]);
                } else if (event.key === 'Escape') {
                    close();
                }
            });
            matchButton.addEventListener('click', function() {
                if (!itemValue.value) return;
                window.location = $peopleBaseUrl + '?name_item=' + encodeURIComponent(itemValue.value)
                    + '&person_query=' + encodeURIComponent(input.value.trim())
                    + '&ui=' + encodeURIComponent('$safeUiLanguage');
            });
            input.form.addEventListener('submit', function() {
                if (itemValue.value) return;
                close();
                analyzeButton.classList.add('is-searching');
                analyzeButton.disabled = true;
            });
            document.addEventListener('click', function(event) {
                if (!input.parentElement.contains(event.target)) close();
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            if ($analysisUrl) {
                fetch($analysisUrl, {
                    cache: 'no-store',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function(response) {
                        if (!response.ok) throw new Error('Analysis failed');
                        return response.text();
                    })
                    .then(function(html) {
                        document.open();
                        document.write(html);
                        document.close();
                    })
                    .catch(function() {
                        var loading = document.getElementById('analysis-loading');
                        if (!loading) return;
                        loading.classList.add('warning');
                        loading.innerHTML = '<h2>Analysis failed</h2><p>Please reload the page to try again.</p>';
                    });
                return;
            }
            document.querySelectorAll('form[data-loading]').forEach(function(form) {
                attachLanguagePreferences(form);
                form.addEventListener('submit', function() {
                    var button = form.querySelector('button[type="submit"]');
                    if (!button) return;
                    button.classList.add('is-loading');
                    button.disabled = true;
                });
            });
            var languageInput = document.getElementById('language-input');
            if (languageInput) languageInput.addEventListener('keydown', languageKeydown);
            initNameItemSearch();
            var transliterationInput = document.getElementById('label-transliteration');
            if (transliterationInput) {
                var relationshipTimer = null;
                transliterationInput.addEventListener('input', function() {
                    transliterationInput.dataset.userEdited = '1';
                    syncTransliterationUpdate(transliterationInput);
                    clearTimeout(relationshipTimer);
                    relationshipTimer = setTimeout(function() {
                        var nativeLabel = document.getElementById('native-label-input');
                        var type = document.querySelector('.review input[name="type"]')
                            || document.querySelector('.review select[name="type"]');
                        var target = document.getElementById('relationship-suggestions');
                        var heading = document.getElementById('relationship-suggestions-heading');
                        if (!nativeLabel || !type || !target || !heading || transliterationInput.value.trim().length < 2) return;
                        fetch($relationshipSearchUrl
                            + '?name=' + encodeURIComponent(nativeLabel.value.trim())
                            + '&transliteration=' + encodeURIComponent(transliterationInput.value.trim())
                            + '&type=' + encodeURIComponent(type.value)
                            + '&existing_item=' + encodeURIComponent((document.getElementById('server-existing-item') || {}).value || '')
                            + '&ui=' + encodeURIComponent('$safeUiLanguage'))
                            .then(function(response) {
                                if (!response.ok) throw new Error('Relationship search failed');
                                return response.json();
                            })
                            .then(function(data) {
                                target.innerHTML = data.html || '';
                                heading.style.display = data.html ? '' : 'none';
                                updateMode();
                            })
                            .catch(function() {});
                    }, 300);
                });
            }
            var nativeLabelInput = document.getElementById('native-label-input');
            var existingOptions = document.getElementById('existing-item-options');
            if (nativeLabelInput && !nativeLabelInput.readOnly && existingOptions) {
                var duplicateTimer = null;
                nativeLabelInput.addEventListener('input', function() {
                    clearTimeout(duplicateTimer);
                    var query = nativeLabelInput.value.trim();
                    if (query.length < 2) {
                        renderExistingNameItems([]);
                        return;
                    }
                    duplicateTimer = setTimeout(function() {
                        existingOptions.style.opacity = '.55';
                        fetch($nameItemSearchUrl + '?q=' + encodeURIComponent(query) + '&ui=' + encodeURIComponent('$safeUiLanguage'))
                            .then(function(response) {
                                if (!response.ok) throw new Error('Name search failed');
                                return response.json();
                            })
                            .then(function(items) {
                                renderExistingNameItems(Array.isArray(items) ? items : []);
                            })
                            .catch(function() {
                                existingOptions.style.opacity = '';
                            });
                    }, 220);
                });
            }
            document.querySelectorAll('input[name="existing_item"]').forEach(function(input) {
                input.addEventListener('change', function() { updateMode(true); });
            });
            var matchWithoutUpdate = document.getElementById('match-without-update');
            if (matchWithoutUpdate) {
                matchWithoutUpdate.addEventListener('click', function(event) {
                    if (matchWithoutUpdate.tagName === 'A' && matchWithoutUpdate.getAttribute('href')) return;
                    event.preventDefault();
                    var selected = document.querySelector('input[name="existing_item"]:checked');
                    var name = document.querySelector('.review input[name="name"]');
                    if (!selected || !selected.value || !name) return;
                    window.location = $peopleBaseUrl + '?name_item=' + encodeURIComponent(selected.value)
                        + '&person_query=' + encodeURIComponent(name.value)
                        + '&ui=' + encodeURIComponent('$safeUiLanguage');
                });
            }
            var chooseAnotherItem = document.getElementById('choose-another-item');
            if (chooseAnotherItem) {
                chooseAnotherItem.addEventListener('click', function() {
                    var createOption = document.querySelector('input[name="existing_item"][value=""]');
                    if (createOption) {
                        createOption.checked = true;
                        updateMode(true);
                        return;
                    }
                    var nativeLabel = document.getElementById('native-label-input');
                    var type = document.querySelector('.review input[name="type"]')
                        || document.querySelector('.review select[name="type"]');
                    var params = new URLSearchParams({
                        ui: '$safeUiLanguage',
                        name: nativeLabel ? nativeLabel.value : '',
                        type: type ? type.value : ''
                    });
                    window.location = '?' + params.toString();
                });
            }
            document.addEventListener('click', function(event) {
                var picker = document.querySelector('.typeahead-wrap');
                var list = document.getElementById('language-suggestions');
                if (picker && list && !picker.contains(event.target)) list.style.display = 'none';
            });
            updateMode(true);
        });
    </script>
</head>
<body class="$bodyClass">
    <main class="shell">
        <div class="topbar">
            $languageSwitch
            $auth
        </div>
        <div class="brand">
            <h1>New Name</h1>
            <p class="tagline">$tagline</p>
        </div>

        $universalSearch

        $review
        <footer class="footer">
            New Name $credit <a href="https://www.veradekok.nl/" target="_blank" rel="noopener noreferrer">Vera de Kok</a>.
            <a href="https://github.com/VDK/New-Name" target="_blank" rel="noopener noreferrer">$sourceCode</a>.
            $mit.
        </footer>
    </main>
</body>
</html>
HTML;
    }

    private function interfaceLanguage(string $language): string
    {
        return in_array($language, ['en', 'nl', 'de', 'fr', 'es'], true) ? $language : 'en';
    }

    private function languageSwitch(Request $request, string $current): string
    {
        $query = $request->query->all();
        unset($query['ui']);

        $hidden = '';
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $hidden .= '<input type="hidden" name="' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        }

        $options = '';
        foreach (['en' => 'English', 'nl' => 'Nederlands', 'de' => 'Deutsch', 'fr' => 'Français', 'es' => 'Español'] as $code => $label) {
            $selected = $code === $current ? ' selected' : '';
            $options .= '<option value="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</option>';
        }

        $label = htmlspecialchars($this->t($current, 'interface_language'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<form class="language-switch" method="get" action="" aria-label="' . $label . '">' . $hidden . '<label for="ui-language">' . $label . '</label><select id="ui-language" name="ui" onchange="this.form.submit()">' . $options . '</select></form>';
    }

    private function authStatus(Request $request, bool $authorized, string $username, string $uiLanguage): string
    {
        $return = htmlspecialchars(rawurlencode($request->getRequestUri()), ENT_QUOTES, 'UTF-8');
        $login = htmlspecialchars($this->t($uiLanguage, 'log_in'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $oauthBase = htmlspecialchars($request->getBasePath() . '/index.php/oauth', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (!$authorized) {
            return '<div class="auth-status"><a href="' . $oauthBase . '/login?return=' . $return . '">' . $login . '</a></div>';
        }

        $safeUsername = htmlspecialchars($username !== '' ? $username : 'Wikimedia', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $logout = htmlspecialchars($this->t($uiLanguage, 'log_out'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $languageSwitch = $this->languageSwitch($request, $uiLanguage);

        return '<div class="auth-status"><div class="auth-line"><strong>' . $safeUsername . '</strong><a href="' . $oauthBase . '/logout">' . $logout . '</a></div>' . $languageSwitch . '</div>';
    }

    private function t(string $language, string $key): string
    {
        $language = $this->interfaceLanguage($language);
        $translations = [
            'en' => [
                'already_exists' => 'Already exists?',
                'analyze' => 'Create',
                'analyzing' => 'Analyze',
                'confidence' => 'Confidence',
                'create_new_item' => 'Create new item',
                'create_note' => 'A new item with the following properties will be created.',
                'credit' => 'by',
                'cyrillic_languages' => 'Cyrillic languages',
                'interface_language' => 'Interface language',
                'infix' => 'infix',
                'label' => 'Label',
                'language_of_name' => 'Language of name',
                'log_in' => 'Log in',
                'log_in_with_wikimedia' => 'Log in with Wikimedia',
                'log_in_before_saving' => 'Log in with Wikimedia before saving changes.',
                'log_out' => 'Log out',
                'located_at' => 'Located at',
                'locked' => 'locked',
                'match' => 'Match',
                'match_without_updating' => 'Go to matching',
                'mit' => 'MIT licensed',
                'name' => 'Name',
                'native_label' => 'Native label',
                'not_set' => 'not set',
                'optional' => 'optional',
                'overwrite_descriptions' => 'Description',
                'possible_full_name' => 'This looks like a person’s full name.',
                'confirm_name_part' => 'I confirm that this is a given or family name.',
                'confirm_given_name' => 'I confirm that this is a given name.',
                'confirm_family_name' => 'I confirm that this is a family name.',
                'given_name_identical_family' => 'Given name equivalent',
                'choose_another_item' => 'Choose another item',
                'ready_to_create' => 'Ready to create',
                'review_required_fields' => 'Review required fields',
                'review_updates' => 'Review updates',
                'selected_item' => 'Selected item',
                'script_warning' => 'This changes a high-confidence script detection. Check the name carefully before creating or updating Wikidata.',
                'source_code' => 'Source code',
                'suggestions' => 'Suggestions',
                'tagline' => 'Create Wikidata items for given and family names.',
                'try_not_duplicate' => 'Try not to make duplicate items. Is it one of these?',
                'transliteration' => 'Transliteration',
                'transliteration_hint' => 'Used as the mul label; the original spelling remains the native label.',
                'type' => 'Type',
                'update_item' => 'Update item',
                'update_heading' => 'Update',
                'update_prefix' => 'Update',
                'writing_system' => 'Writing system',
            ],
            'nl' => [
                'already_exists' => 'Bestaat al?',
                'analyze' => 'Aanmaken',
                'analyzing' => 'Analyseren',
                'confidence' => 'Zekerheid',
                'create_new_item' => 'Nieuw item aanmaken',
                'create_note' => 'Er wordt een nieuw item met deze eigenschappen aangemaakt.',
                'credit' => 'door',
                'cyrillic_languages' => 'Cyrillische talen',
                'interface_language' => 'Interfacetaal',
                'infix' => 'tussenvoegsel',
                'label' => 'Label',
                'language_of_name' => 'Taal van de naam',
                'log_in' => 'Inloggen',
                'log_in_with_wikimedia' => 'Inloggen met Wikimedia',
                'log_in_before_saving' => 'Log in met Wikimedia voordat je wijzigingen opslaat.',
                'log_out' => 'Uitloggen',
                'located_at' => 'Te vinden op',
                'locked' => 'vergrendeld',
                'match' => 'Koppelen',
                'match_without_updating' => 'Naar koppelen',
                'mit' => 'MIT-licentie',
                'name' => 'Naam',
                'native_label' => 'Native label',
                'not_set' => 'niet ingesteld',
                'optional' => 'optioneel',
                'overwrite_descriptions' => 'Beschrijving',
                'possible_full_name' => 'Dit lijkt op de volledige naam van een persoon.',
                'confirm_name_part' => 'Ik bevestig dat dit een voor- of achternaam is.',
                'confirm_given_name' => 'Ik bevestig dat dit een voornaam is.',
                'confirm_family_name' => 'Ik bevestig dat dit een achternaam is.',
                'given_name_identical_family' => 'Equivalent als voornaam',
                'choose_another_item' => 'Ander item kiezen',
                'ready_to_create' => 'Klaar om op te slaan',
                'review_required_fields' => 'Velden controleren',
                'review_updates' => 'Wijzigingen controleren',
                'selected_item' => 'Geselecteerd item',
                'script_warning' => 'Dit wijzigt een schrijfwijze-detectie met hoge zekerheid. Controleer de naam goed voordat je Wikidata aanmaakt of bijwerkt.',
                'source_code' => 'Broncode',
                'suggestions' => 'Suggesties',
                'tagline' => 'Maak Wikidata-items voor voor- en achternamen.',
                'try_not_duplicate' => 'Probeer dubbele items te voorkomen. Is het een van deze?',
                'transliteration' => 'Transcriptie',
                'transliteration_hint' => 'Wordt gebruikt als mul-label; de oorspronkelijke schrijfwijze blijft de native label.',
                'type' => 'Type',
                'update_item' => 'Item bijwerken',
                'update_heading' => 'Bijwerken',
                'update_prefix' => 'Bijwerken',
                'writing_system' => 'Schrift',
            ],
            'de' => [
                'already_exists' => 'Existiert bereits?',
                'analyze' => 'Erstellen',
                'analyzing' => 'Analysieren',
                'confidence' => 'Sicherheit',
                'create_new_item' => 'Neues Item erstellen',
                'create_note' => 'Ein neues Item mit den folgenden Eigenschaften wird erstellt.',
                'credit' => 'von',
                'cyrillic_languages' => 'kyrillische Sprachen',
                'interface_language' => 'Oberflächensprache',
                'infix' => 'Namenszusatz',
                'label' => 'Label',
                'language_of_name' => 'Sprache des Namens',
                'log_in' => 'Anmelden',
                'log_in_with_wikimedia' => 'Mit Wikimedia anmelden',
                'log_in_before_saving' => 'Melde dich mit Wikimedia an, bevor du Änderungen speicherst.',
                'log_out' => 'Abmelden',
                'located_at' => 'Zu finden unter',
                'locked' => 'gesperrt',
                'match' => 'Zuordnen',
                'match_without_updating' => 'Zur Zuordnung',
                'mit' => 'MIT-lizenziert',
                'name' => 'Name',
                'native_label' => 'Native label',
                'not_set' => 'nicht gesetzt',
                'optional' => 'optional',
                'overwrite_descriptions' => 'Beschreibung',
                'possible_full_name' => 'Dies sieht wie der vollständige Name einer Person aus.',
                'confirm_name_part' => 'Ich bestätige, dass dies ein Vor- oder Nachname ist.',
                'confirm_given_name' => 'Ich bestätige, dass dies ein Vorname ist.',
                'confirm_family_name' => 'Ich bestätige, dass dies ein Nachname ist.',
                'given_name_identical_family' => 'Entsprechender Vorname',
                'choose_another_item' => 'Anderes Item wählen',
                'ready_to_create' => 'Bereit zum Speichern',
                'review_required_fields' => 'Felder prüfen',
                'review_updates' => 'Änderungen prüfen',
                'selected_item' => 'Ausgewähltes Item',
                'script_warning' => 'Dies ändert eine Schrifterkennung mit hoher Sicherheit. Prüfe den Namen sorgfältig, bevor du Wikidata erstellst oder aktualisierst.',
                'source_code' => 'Quellcode',
                'suggestions' => 'Vorschläge',
                'tagline' => 'Neue Wikidata-Items für Vor- und Nachnamen erstellen.',
                'try_not_duplicate' => 'Versuche doppelte Items zu vermeiden. Ist es eines davon?',
                'transliteration' => 'Transliteration',
                'transliteration_hint' => 'Wird als mul-Label verwendet; die ursprüngliche Schreibweise bleibt das native label.',
                'type' => 'Typ',
                'update_item' => 'Item aktualisieren',
                'update_heading' => 'Aktualisieren',
                'update_prefix' => 'Aktualisieren',
                'writing_system' => 'Schriftsystem',
            ],
            'fr' => [
                'already_exists' => 'Existe déjà ?',
                'analyze' => 'Créer',
                'analyzing' => 'Analyser',
                'confidence' => 'Confiance',
                'create_new_item' => 'Créer un nouvel élément',
                'create_note' => 'Un nouvel élément avec les propriétés suivantes sera créé.',
                'credit' => 'par',
                'cyrillic_languages' => 'langues cyrilliques',
                'interface_language' => 'Langue de l’interface',
                'infix' => 'particule',
                'label' => 'Libellé',
                'language_of_name' => 'Langue du nom',
                'log_in' => 'Connexion',
                'log_in_with_wikimedia' => 'Connexion avec Wikimedia',
                'log_in_before_saving' => 'Connectez-vous avec Wikimedia avant d’enregistrer.',
                'log_out' => 'Déconnexion',
                'located_at' => 'Situé à',
                'locked' => 'verrouillé',
                'match' => 'Associer',
                'match_without_updating' => 'Aller à l’association',
                'mit' => 'Licence MIT',
                'name' => 'Nom',
                'native_label' => 'Native label',
                'not_set' => 'non défini',
                'optional' => 'facultatif',
                'overwrite_descriptions' => 'Description',
                'possible_full_name' => 'Cela ressemble au nom complet d’une personne.',
                'confirm_name_part' => 'Je confirme qu’il s’agit d’un prénom ou d’un nom de famille.',
                'confirm_given_name' => 'Je confirme qu’il s’agit d’un prénom.',
                'confirm_family_name' => 'Je confirme qu’il s’agit d’un nom de famille.',
                'given_name_identical_family' => 'Prénom équivalent',
                'choose_another_item' => 'Choisir un autre élément',
                'ready_to_create' => 'Prêt à enregistrer',
                'review_required_fields' => 'Vérifier les champs',
                'review_updates' => 'Vérifier les modifications',
                'selected_item' => 'Élément sélectionné',
                'script_warning' => 'Cela modifie une détection d’écriture à haute confiance. Vérifiez le nom avant de créer ou mettre à jour Wikidata.',
                'source_code' => 'Code source',
                'suggestions' => 'Suggestions',
                'tagline' => 'Créer des éléments Wikidata pour les prénoms et les noms de famille.',
                'try_not_duplicate' => 'Évitez de créer des doublons. Est-ce l’un de ces éléments ?',
                'transliteration' => 'Translittération',
                'transliteration_hint' => 'Utilisée comme libellé mul ; l’écriture originale reste le native label.',
                'type' => 'Type',
                'update_item' => 'Mettre à jour',
                'update_heading' => 'Mettre à jour',
                'update_prefix' => 'Mettre à jour',
                'writing_system' => 'Système d’écriture',
            ],
            'es' => [
                'already_exists' => '¿Ya existe?',
                'analyze' => 'Crear',
                'analyzing' => 'Analizar',
                'confidence' => 'Confianza',
                'create_new_item' => 'Crear elemento nuevo',
                'create_note' => 'Se creará un elemento nuevo con las siguientes propiedades.',
                'credit' => 'por',
                'cyrillic_languages' => 'idiomas cirílicos',
                'interface_language' => 'Idioma de la interfaz',
                'infix' => 'partícula',
                'label' => 'Etiqueta',
                'language_of_name' => 'Idioma del nombre',
                'log_in' => 'Iniciar sesión',
                'log_in_with_wikimedia' => 'Iniciar sesión con Wikimedia',
                'log_in_before_saving' => 'Inicia sesión con Wikimedia antes de guardar cambios.',
                'log_out' => 'Cerrar sesión',
                'located_at' => 'Ubicado en',
                'locked' => 'bloqueado',
                'match' => 'Vincular',
                'match_without_updating' => 'Ir a vincular',
                'mit' => 'Licencia MIT',
                'name' => 'Nombre',
                'native_label' => 'Native label',
                'not_set' => 'sin definir',
                'optional' => 'opcional',
                'overwrite_descriptions' => 'Descripción',
                'possible_full_name' => 'Esto parece el nombre completo de una persona.',
                'confirm_name_part' => 'Confirmo que es un nombre de pila o un apellido.',
                'confirm_given_name' => 'Confirmo que es un nombre de pila.',
                'confirm_family_name' => 'Confirmo que es un apellido.',
                'given_name_identical_family' => 'Nombre de pila equivalente',
                'choose_another_item' => 'Elegir otro elemento',
                'ready_to_create' => 'Listo para guardar',
                'review_required_fields' => 'Revisar campos',
                'review_updates' => 'Revisar cambios',
                'selected_item' => 'Elemento seleccionado',
                'script_warning' => 'Esto cambia una detección de escritura de alta confianza. Revisa el nombre antes de crear o actualizar Wikidata.',
                'source_code' => 'Código fuente',
                'suggestions' => 'Sugerencias',
                'tagline' => 'Crea elementos de Wikidata para nombres de pila y apellidos.',
                'try_not_duplicate' => 'Intenta no crear elementos duplicados. ¿Es uno de estos?',
                'transliteration' => 'Transliteración',
                'transliteration_hint' => 'Se usa como etiqueta mul; la escritura original sigue siendo el native label.',
                'type' => 'Tipo',
                'update_item' => 'Actualizar elemento',
                'update_heading' => 'Actualizar',
                'update_prefix' => 'Actualizar',
                'writing_system' => 'Sistema de escritura',
            ],
        ];

        return $translations[$language][$key] ?? $translations['en'][$key] ?? $key;
    }

    private function analysisLoading(string $name, string $uiLanguage): string
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $heading = match ($uiLanguage) {
            'nl' => "Analyseren van '$safeName'",
            'de' => "'$safeName' wird analysiert",
            'fr' => "Analyse de '$safeName'",
            'es' => "Analizando '$safeName'",
            default => "Analyzing '$safeName'",
        };

        return <<<HTML
<section id="analysis-loading" class="analysis-loading" aria-live="polite" aria-busy="true">
    <h2>$heading</h2>
    <div class="analysis-progress" role="progressbar" aria-label="$heading"></div>
</section>
HTML;
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function review(Request $request, array $analysis, string $language, bool $authorized, string $uiLanguage, array $preferredLanguages, array $languages, NameTransliterator $transliterator, ScriptLanguageLookup $scriptLanguages, WikidataClient $wikidataClient, string $equivalentItemId = ''): string
    {
        $selectedType = (string) $analysis['selectedType'];
        $selectedItemId = strtoupper(trim((string) $request->query->get('existing_item', '')));
        $selectedMatch = null;
        foreach ([...($analysis['sameTypeMatches'] ?? []), ...($analysis['matches'] ?? [])] as $match) {
            if (($match['id'] ?? '') === $selectedItemId) {
                $selectedMatch = $match;
                break;
            }
        }
        $isUpdate = preg_match('/^Q\d+$/', $selectedItemId) === 1;
        if ($isUpdate) {
            $currentItem = $wikidataClient->nameItem($selectedItemId);
            if (is_array($currentItem)) {
                $currentItem['scripts'] = ($currentItem['script'] ?? '') !== '' ? [$currentItem['script']] : [];
                $selectedMatch = $currentItem;
            }
        }
        if ($isUpdate && is_array($selectedMatch)) {
            $existingType = $this->nameTypeFromInstances($selectedMatch['instanceOf'] ?? []);
            if ($existingType !== null) {
                $selectedType = $existingType;
                $analysis['selectedType'] = $existingType;
                $analysis['selectedTypeLabel'] = NameTypes::LABELS[$existingType] ?? $existingType;
                $analysis['descriptions'] = (new \App\Service\DescriptionSet())->forType($existingType, (string) $analysis['name']);
            }
        }
        $existingScriptQid = $isUpdate ? (string) ($selectedMatch['scripts'][0] ?? $selectedMatch['script'] ?? '') : '';
        $existingMulLabel = $isUpdate ? trim((string) ($selectedMatch['mulLabel'] ?? '')) : '';
        if ($existingScriptQid !== '') {
            foreach (ScriptDetector::SCRIPTS as $scriptName => $scriptMeta) {
                if (($scriptMeta['qid'] ?? '') === $existingScriptQid) {
                    $analysis['script'] = ['script' => $scriptName, 'confidence' => 'high', ...$scriptMeta];
                    break;
                }
            }
        }
        $rootType = in_array($selectedType, [NameTypes::FAMILY_NAME, NameTypes::CHINESE_FAMILY_NAME], true)
            ? NameTypes::FAMILY_NAME
            : NameTypes::GIVEN_NAME;
        $hasEquivalent = $equivalentItemId !== '';
        if ($hasEquivalent) {
            $analysis['_equivalent'] = $equivalentItemId;
            $hasP1533 = false;
            foreach ($analysis['relationshipSuggestions'] as $suggestion) {
                if (($suggestion['property'] ?? '') === 'P1533' && ($suggestion['target'] ?? '') === $equivalentItemId) {
                    $hasP1533 = true;
                    break;
                }
            }
            if (!$hasP1533) {
                $equivalentNameItem = $wikidataClient->nameItem($equivalentItemId);
                if (is_array($equivalentNameItem)) {
                    $oppositeIsGiven = $this->isGivenNameType((string) $analysis['selectedType']);
                    $equivalentInstanceLabels = [];
                    foreach ($equivalentNameItem['instanceOf'] ?? [] as $instanceQid) {
                        $label = NameTypes::ITEM_LABELS[$instanceQid] ?? $instanceQid;
                        if ($label !== '') {
                            $equivalentInstanceLabels[] = $label;
                        }
                    }
                    array_unshift($analysis['relationshipSuggestions'], [
                        'target' => $equivalentItemId,
                        'targetLabel' => $equivalentNameItem['label'],
                        'targetTypes' => $equivalentInstanceLabels,
                        'property' => 'P1533',
                        'propertyLabel' => $oppositeIsGiven ? 'family name identical to this given name' : 'given name equivalent',
                        'value' => 'related_' . $equivalentItemId . '_P1533',
                        'reason' => $oppositeIsGiven ? 'family name equivalent of the created name item' : 'given name equivalent of the created name item',
                        'direction' => $oppositeIsGiven ? '' : 'target',
                    ]);
                }
            }
        }
        $rootTypeLabel = $this->nameTypeLabel($uiLanguage, $rootType);
        $selectedLabel = trim((string) ($selectedMatch['mulLabel'] ?? ''))
            ?: (string) ($selectedMatch['label'] ?? $analysis['name']);
        $resolvedLanguage = $this->resolveLanguage(
            $language,
            $languages,
            $selectedType,
            $analysis,
            $preferredLanguages
        );
        if ($isUpdate && ($selectedMatch['languageCode'] ?? '') !== '') {
            $resolvedLanguage = (string) $selectedMatch['languageCode'];
        }
        $type = $this->typeSelect((string) $analysis['selectedType'], (string) $analysis['name'], $uiLanguage, $isUpdate, $rootTypeLabel);
        $script = $this->scriptSelect($analysis, $uiLanguage, $isUpdate);
        $hintCodes = $this->uniqueLanguageHints(
            $languages,
            $this->languageHintCodes($analysis)
        );

        $languageSelect = $this->languageSelect(
            $resolvedLanguage,
            $hintCodes,
            $this->languageConfidence($language, $selectedType, $analysis, $preferredLanguages, $resolvedLanguage),
            $uiLanguage
        );
        $nativeValue = $isUpdate && !empty($selectedMatch['nativeLabels'])
            ? (string) $selectedMatch['nativeLabels'][0]
            : (string) $analysis['name'];
        $nativeLabelField = $this->nativeLabelField($nativeValue, $resolvedLanguage, $uiLanguage, $isUpdate);
        $transliteration = $this->transliterationField(
            (string) $analysis['name'],
            $analysis['script'] ?? null,
            $transliterator,
            $uiLanguage,
            $isUpdate,
            $existingMulLabel
        );
        $duplicates = $isUpdate
            ? $this->selectedItemBox(
                $request,
                $selectedItemId,
                $selectedLabel,
                (string) ($selectedMatch['description'] ?? ''),
                $rootTypeLabel,
                (string) $analysis['name'],
                $uiLanguage
            )
            : $this->duplicates($request, $analysis, $uiLanguage, '', true);
        $generatedTransliteration = $this->defaultTransliteration((string) $analysis['name'], $analysis['script'] ?? null, $transliterator);
        $displayLabel = $isUpdate && $existingMulLabel !== ''
            ? $existingMulLabel
            : $generatedTransliteration;
        $updateTransliteration = $isUpdate
            && $displayLabel !== ''
            && $displayLabel !== $existingMulLabel;
        $showNativeLabelCheck = !$isUpdate || empty($selectedMatch['nativeLabels']);
        $checks = $this->checks(
            $analysis,
            $resolvedLanguage,
            $uiLanguage,
            $displayLabel !== '' ? $displayLabel : (string) $analysis['name'],
            $scriptLanguages,
            $showNativeLabelCheck,
            $isUpdate,
            $updateTransliteration
        );
        $safeUiLanguage = htmlspecialchars($uiLanguage, ENT_QUOTES, 'UTF-8');
        $requiresConfirmation = !$isUpdate && $this->requiresFullNameConfirmation((string) $analysis['name']);
        $action = $this->submitAction(
            $request,
            $authorized,
            $uiLanguage,
            $isUpdate,
            $requiresConfirmation,
            $selectedItemId,
            (string) $analysis['name']
        );
        $reviewRequiredText = $isUpdate
            ? $this->t($uiLanguage, 'update_item')
            : $this->t($uiLanguage, 'review_required_fields');
        $reviewRequired = htmlspecialchars($reviewRequiredText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSelectedItemId = htmlspecialchars($selectedItemId, ENT_QUOTES, 'UTF-8');
        $createNewItem = htmlspecialchars($this->t($uiLanguage, 'create_new_item'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $reviewUpdates = htmlspecialchars($this->t($uiLanguage, 'review_updates'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $applyHeading = $isUpdate ? $reviewUpdates : $createNewItem;
        $createNote = htmlspecialchars($this->t($uiLanguage, 'create_note'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $readyToCreate = htmlspecialchars($isUpdate ? $this->t($uiLanguage, 'update_item') : $this->t($uiLanguage, 'ready_to_create'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $saveAction = htmlspecialchars($request->getBasePath() . '/index.php/wikidata/save', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $updateStateInput = '';
        if ($isUpdate) {
            $existingScript = htmlspecialchars((string) ($selectedMatch['scripts'][0] ?? ''), ENT_QUOTES, 'UTF-8');
            $existingMul = htmlspecialchars((string) ($selectedMatch['mulLabel'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $hasNative = empty($selectedMatch['nativeLabels']) ? '0' : '1';
            $safeSelectedLabel = htmlspecialchars($selectedLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeSelectedDescription = htmlspecialchars((string) ($selectedMatch['description'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeRootTypeLabel = htmlspecialchars($rootTypeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $updateStateInput = '<input id="server-existing-item" name="existing_item" type="hidden" value="' . $safeSelectedItemId . '" data-label="' . $safeSelectedLabel . '" data-description="' . $safeSelectedDescription . '" data-type-label="' . $safeRootTypeLabel . '" data-script="' . $existingScript . '" data-mul="' . $existingMul . '" data-has-native="' . $hasNative . '">';
        }

        return <<<HTML
<form class="review" method="post" action="$saveAction" data-loading>
    <input type="hidden" name="ui" value="$safeUiLanguage">
    $updateStateInput
    $duplicates
    <section>
        <h2 id="review-heading">$reviewRequired</h2>
        <div class="grid">
            $nativeLabelField
            $type
            $script
            $languageSelect
            $transliteration
        </div>
    </section>
    <section>
        <h2 id="apply-heading">$applyHeading</h2>
        <p id="apply-note" class="hint">$createNote</p>
        <div class="checks">$checks</div>
    </section>
    <section>
        <h2>$readyToCreate</h2>
        $action
    </section>
</form>
HTML;
    }

    private function submitAction(
        Request $request,
        bool $authorized,
        string $uiLanguage = 'en',
        bool $isUpdate = false,
        bool $requiresConfirmation = false,
        string $selectedItemId = '',
        string $name = '',
    ): string
    {
        $matchWithoutUpdating = htmlspecialchars($this->t($uiLanguage, 'match_without_updating'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($isUpdate) {
            $matchUrl = htmlspecialchars(
                $request->getBasePath() . '/index.php/people?' . http_build_query([
                    'name_item' => $selectedItemId,
                    'person_query' => $name,
                    'ui' => $uiLanguage,
                ]),
                ENT_QUOTES,
                'UTF-8'
            );
            $matchButton = '<a id="match-without-update" class="pill-link secondary-action" href="' . $matchUrl . '">' . $matchWithoutUpdating . '</a>';
        } else {
            $matchButton = '<button id="match-without-update" class="secondary-action" type="button" style="display:none">' . $matchWithoutUpdating . '</button>';
        }
        $confirmation = '';
        if ($requiresConfirmation) {
            $possibleFullName = htmlspecialchars($this->t($uiLanguage, 'possible_full_name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $confirmGivenName = htmlspecialchars($this->t($uiLanguage, 'confirm_given_name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $confirmFamilyName = htmlspecialchars($this->t($uiLanguage, 'confirm_family_name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $confirmation = '<div class="full-name-confirmation"><strong>' . $possibleFullName . '</strong><label><input name="confirm_name_type" type="radio" value="given" required onchange="confirmNameType(this.value)"> <span>' . $confirmGivenName . '</span></label><label><input name="confirm_name_type" type="radio" value="family" required onchange="confirmNameType(this.value)"> <span>' . $confirmFamilyName . '</span></label></div>';
        }
        $submitLabel = htmlspecialchars($this->t($uiLanguage, $isUpdate ? 'update_item' : 'create_new_item'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($authorized) {
            return '<div class="actions">' . $confirmation . $matchButton . '<button id="save-button" type="submit"><span class="spinner" aria-hidden="true"></span><span>' . $submitLabel . '</span></button></div>';
        }

        $return = $request->getRequestUri();
        $loginUrl = htmlspecialchars($request->getBasePath() . '/index.php/oauth/login?return=' . rawurlencode($return), ENT_QUOTES, 'UTF-8');

        return '<p class="hint">' . htmlspecialchars($this->t($uiLanguage, 'log_in_before_saving'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p><div class="actions">' . $confirmation . $matchButton . '<a class="pill-link" href="' . $loginUrl . '">' . htmlspecialchars($this->t($uiLanguage, 'log_in_with_wikimedia'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></div>';
    }

    private function nativeLabelField(string $value, string $language, string $uiLanguage, bool $locked): string
    {
        $safeValue = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLanguage = htmlspecialchars($language !== '' ? $language : 'mul', ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars($this->t($uiLanguage, 'native_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $readonly = $locked ? ' readonly' : '';
        $fieldStyle = '';

        return <<<HTML
<div id="native-label-field"$fieldStyle>
    <label for="native-label-input">$label</label>
    <input id="native-label-input" class="typeahead" name="name" type="text" value="$safeValue"$readonly required>
    <p class="hint">$safeLanguage</p>
</div>
HTML;
    }

    private function typeSelect(string $selected, string $name, string $uiLanguage, bool $locked = false, string $lockedLabel = ''): string
    {
        $options = '';
        foreach (NameTypes::ACTIVE_TYPES as $type) {
            $label = $this->nameTypeLabel($uiLanguage, $type);
            $isSelected = $type === $selected ? ' selected' : '';
            $urlType = NameTypes::toUrl($type);
            $options .= '<option value="' . htmlspecialchars($urlType, ENT_QUOTES, 'UTF-8') . '"' . $isSelected . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }

        $safeName = rawurlencode($name);
        $safeUiLanguage = rawurlencode($uiLanguage);
        $confidence = $this->confidenceLabel($uiLanguage, $this->typeConfidence($selected));
        $typeLabel = htmlspecialchars($this->t($uiLanguage, 'type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $confidenceLabel = htmlspecialchars($this->t($uiLanguage, 'confidence'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $selectDisabled = $locked ? ' disabled' : '';
        $hiddenType = $locked ? '<input name="type" type="hidden" value="' . htmlspecialchars(NameTypes::toUrl($selected), ENT_QUOTES, 'UTF-8') . '">' : '';

        return <<<HTML
<div id="type-field">
    <label for="type">$typeLabel</label>
    <div id="type-editor">
        <select id="type" name="type"$selectDisabled onchange="window.location='?ui=$safeUiLanguage&name=$safeName&type=' + encodeURIComponent(this.value)">
            $options
        </select>
        <p class="hint">$confidenceLabel: $confidence.</p>
    </div>
    $hiddenType
</div>
HTML;
    }

    private function transliterationField(string $name, mixed $script, NameTransliterator $transliterator, string $uiLanguage, bool $isUpdate = false, string $existingMulLabel = ''): string
    {
        $generatedValue = $this->defaultTransliteration($name, $script, $transliterator);
        $value = $isUpdate && $existingMulLabel !== '' ? $existingMulLabel : $generatedValue;
        $safeValue = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeGeneratedValue = htmlspecialchars($generatedValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeExistingValue = htmlspecialchars($existingMulLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeOriginal = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $label = htmlspecialchars($this->t($uiLanguage, 'transliteration'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $hint = htmlspecialchars($this->t($uiLanguage, 'transliteration_hint'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $show = is_array($script)
            && ($script['script'] ?? '') !== 'Latin';
        $style = $show ? '' : ' style="display:none"';
        $disabled = $show ? '' : ' disabled';

        return <<<HTML
<div id="transliteration-field"$style>
    <label for="label-transliteration">$label</label>
    <input id="label-transliteration" class="typeahead" name="label_transliteration" type="text" value="$safeValue" data-default-value="$safeGeneratedValue" data-existing-value="$safeExistingValue" data-original-name="$safeOriginal" autocomplete="off"$disabled>
    <p class="hint">$hint</p>
</div>
HTML;
    }

    private function defaultTransliteration(string $name, mixed $script, NameTransliterator $transliterator): string
    {
        if (!is_array($script) || ($script['script'] ?? '') === 'Latin') {
            return '';
        }

        return $transliterator->transliterate($name, $script);
    }

    private function typeConfidence(string $selected): string
    {
        return str_contains($selected, 'chinese') ? 'medium' : 'high';
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function scriptSelect(array $analysis, string $uiLanguage, bool $locked = false): string
    {
        $script = $analysis['script'];
        $detected = is_array($script) ? $script['qid'] : '';
        $confidence = $this->confidenceLabel($uiLanguage, is_array($script) ? $script['confidence'] : 'required');
        $options = '';

        foreach (ScriptDetector::SCRIPTS as $meta) {
            $selected = $meta['qid'] === $detected ? ' selected' : '';
            $options .= '<option value="' . htmlspecialchars($meta['qid'], ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars($this->scriptLabel($uiLanguage, $meta['qid'], $meta['label']), ENT_QUOTES, 'UTF-8') . '</option>';
        }

        $writingSystem = htmlspecialchars($this->t($uiLanguage, 'writing_system'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $confidenceLabel = htmlspecialchars($this->t($uiLanguage, 'confidence'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $scriptWarning = htmlspecialchars($this->t($uiLanguage, 'script_warning'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fieldStyle = '';
        $disabled = $locked ? ' disabled' : '';

        return <<<HTML
<div id="script-field"$fieldStyle>
    <label for="script">$writingSystem</label>
        <select id="script" name="script" required data-detected="$detected" onchange="updateScriptFields(this)"$disabled>
        $options
    </select>
    <input id="frozen-script" name="script" type="hidden" value="" disabled>
    <p class="hint">$confidenceLabel: $confidence.</p>
    <div id="script-warning" class="warning">$scriptWarning</div>
</div>
HTML;
    }

    /**
     * @param list<string> $hintCodes
     */
    private function languageSelect(string $selected, array $hintCodes, string $confidence, string $uiLanguage): string
    {
        $languages = $this->languages();
        $selectedLabel = '';
        foreach ($languages as $code => $meta) {
            if ($code === $selected) {
                $selectedLabel = $meta['label'];
            }
        }
        $safeSelected = htmlspecialchars($selectedLabel, ENT_QUOTES, 'UTF-8');
        $safeSelectedQid = htmlspecialchars($selected !== '' && isset($languages[$selected]) ? $languages[$selected]['item'] : '', ENT_QUOTES, 'UTF-8');
        $safeSelectedCode = htmlspecialchars($selected !== '' && isset($languages[$selected]) ? $selected : '', ENT_QUOTES, 'UTF-8');
        $safeHints = htmlspecialchars(implode(',', $hintCodes), ENT_QUOTES, 'UTF-8');
        $safeConfidence = htmlspecialchars($this->confidenceLabel($uiLanguage, $confidence), ENT_QUOTES, 'UTF-8');
        $languageOfName = htmlspecialchars($this->t($uiLanguage, 'language_of_name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $optional = htmlspecialchars($this->t($uiLanguage, 'optional'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $confidenceLabel = htmlspecialchars($this->t($uiLanguage, 'confidence'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<div>
    <label for="language-input">$languageOfName <span class="optional">($optional)</span></label>
    <div class="typeahead-wrap">
        <input id="language-input" class="typeahead" name="language_label" type="text" autocomplete="off" value="$safeSelected" data-hints="$safeHints" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="language-suggestions" oninput="searchLanguages(this, true)" onfocus="searchLanguages(this, false)">
        <input id="language-value" name="language" type="hidden" value="$safeSelectedQid">
        <input id="language-code" name="language_code" type="hidden" value="$safeSelectedCode">
        <ul id="language-suggestions" class="suggest-list" role="listbox"></ul>
    </div>
    <p class="hint">$confidenceLabel: $safeConfidence.</p>
</div>
HTML;
    }

    /**
     * @return array{code: string, label: string, item: string}|null
     */
    private function defaultLanguageForType(string $selectedType): ?array
    {
        if (in_array($selectedType, [NameTypes::CHINESE_FAMILY_NAME, NameTypes::CHINESE_GIVEN_NAME], true)) {
            return ['code' => 'cmn', 'label' => 'Mandarin', 'item' => 'Q9192'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $analysis
     */
    /**
     * @param array<string, int> $preferredLanguages
     */
  private function resolveLanguage(
        string $language,
        array $languages,
        string $selectedType,
        array $analysis,
        array $preferredLanguages
    ): string {
        if ($language !== '') {
            return $language;
        }

        // expliciet meegegeven talen
        if ($languages !== []) {
            return $languages[0];
        }

        $textLanguage = $this->languageCodeForTextAndScript(
            (string) ($analysis['name'] ?? ''),
            $analysis['script'] ?? null,
            $selectedType
        );
        if ($textLanguage !== null) {
            return $textLanguage;
        }

        // naam-analyse
        $affixCodes = $this->affixLanguageCodes($analysis);
        if ($affixCodes !== []) {
            return $this->preferredLanguageAmong($preferredLanguages, $affixCodes)
                ?? $affixCodes[0];
        }

        // gebruikersvoorkeuren pas daarna
        if ($preferredLanguages !== []) {
            return array_key_first($preferredLanguages);
        }

        $scriptLanguage = $this->languageCodeForScript(
            $analysis['script'] ?? null
        );
        if ($scriptLanguage !== null) {
            return $scriptLanguage;
        }

        return $this->defaultLanguageForType($selectedType)['code'] ?? '';
    }

    /**
     * @param array<string, mixed> $analysis
     * @param array<string, int> $preferredLanguages
     */
    private function languageConfidence(string $language, string $selectedType, array $analysis, array $preferredLanguages, string $resolvedLanguage): string
    {
        if ($language !== '') {
            return 'selected';
        }

        if ($resolvedLanguage === '') {
            return 'none';
        }

        if (($this->defaultLanguageForType($selectedType)['code'] ?? null) === $resolvedLanguage) {
            return 'high';
        }

        foreach ($analysis['affixes'] ?? [] as $affix) {
            if (!is_array($affix)) {
                continue;
            }
            if ($this->languageCodeForAffix($affix, []) === $resolvedLanguage) {
                return count($this->affixLanguageCodes($analysis)) > 1 ? 'medium' : 'high';
            }
        }

        if ($this->languageCodeForTextAndScript((string) ($analysis['name'] ?? ''), $analysis['script'] ?? null, $selectedType) === $resolvedLanguage) {
            return 'high';
        }

        if (($preferredLanguages[$resolvedLanguage] ?? 0) >= 2) {
            return 'medium';
        }

        if ($this->languageCodeForScript($analysis['script'] ?? null) === $resolvedLanguage) {
            return 'high';
        }

        return 'low';
    }

    /**
     * @param array<string, mixed> $analysis
     * @return list<string>
     */
    private function languageHintCodes(array $analysis): array
    {
        $hints = $this->uniqueLanguageHints(
            $this->affixLanguageCodes($analysis),
            $this->slavicSuffixLanguageHints($analysis)
        );

        $script = is_array($analysis['script'] ?? null) ? (string) $analysis['script']['script'] : '';
        $textLanguage = $this->languageCodeForTextAndScript((string) ($analysis['name'] ?? ''), $analysis['script'] ?? null, (string) ($analysis['selectedType'] ?? ''));
        if ($textLanguage === 'uk') {
            return $this->uniqueLanguageHints($hints, ['uk', 'ru', 'bg', 'sr', 'mk', 'be']);
        }
        if ($textLanguage === 'be') {
            return $this->uniqueLanguageHints($hints, ['be', 'ru', 'uk', 'bg', 'sr', 'mk']);
        }
        if ($textLanguage === 'nl') {
            return $this->uniqueLanguageHints($hints, ['nl', 'en', 'de', 'fr', 'fy']);
        }
        if ($textLanguage === 'de') {
            return $this->uniqueLanguageHints($hints, ['de', 'nl', 'en', 'fr']);
        }
        if ($textLanguage === 'sv') {
            return $this->uniqueLanguageHints($hints, ['sv', 'da', 'no', 'fi', 'de']);
        }

        return $this->uniqueLanguageHints($hints, match ($script) {
            'Cyrillic' => ['ru', 'uk', 'bg', 'sr', 'mk', 'be'],
            'Latin' => ['en', 'fr', 'de', 'es', 'it', 'nl', 'pt', 'pl', 'cs', 'sv', 'da', 'no', 'fi', 'hu', 'ro'],
            default => [],
        });
    }

    /**
     * @param array<string, mixed> $analysis
     * @return list<string>
     */
    private function slavicSuffixLanguageHints(array $analysis): array
    {
        foreach ($analysis['affixes'] ?? [] as $affix) {
            if (!is_array($affix) || ($affix['group'] ?? '') !== 'slavic') {
                continue;
            }

            return match (mb_strtolower((string) ($affix['value'] ?? ''))) {
                'sky', 'skaya' => ['ru', 'uk', 'be'],
                'ski', 'ska' => ['pl', 'ru', 'uk', 'be'],
                'owicz', 'ewicz', 'wicz' => ['pl', 'be', 'uk'],
                'ovich', 'evich', 'vich' => ['ru', 'uk', 'be', 'sr'],
                'vic', 'vić', 'ic', 'ić' => ['sr', 'hr', 'bs', 'sl'],
                'ov', 'ova', 'ev', 'eva', 'in', 'ina' => ['ru', 'bg', 'uk', 'be'],
                default => ['ru', 'uk', 'pl', 'sr', 'bg', 'be'],
            };
        }

        return [];
    }

    /**
     * @param list<string> ...$groups
     * @return list<string>
     */
    private function uniqueLanguageHints(array ...$groups): array
    {
        $hints = [];
        foreach ($groups as $group) {
            foreach ($group as $code) {
                if (preg_match('/^[a-z-]{2,12}$/', $code) === 1) {
                    $hints[$code] = $code;
                }
            }
        }

        return array_values($hints);
    }

    /**
     * @param array<string, mixed> $analysis
     * @return list<string>
     */
    private function affixLanguageCodes(array $analysis): array
    {
        $codes = [];
        foreach ($analysis['affixes'] ?? [] as $affix) {
            if (!is_array($affix)) {
                continue;
            }
            $code = $this->languageCodeForAffix($affix, []);
            if ($code !== null) {
                $codes[$code] = $code;
            }
        }

        return array_values($codes);
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function selectedItemBox(Request $request, string $id, string $label, string $description, string $typeLabel, string $name, string $uiLanguage): string
    {
        $heading = htmlspecialchars($this->t($uiLanguage, 'selected_item'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeId = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeDescription = htmlspecialchars($description !== '' ? $description : $typeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $matchLabel = htmlspecialchars($this->t($uiLanguage, 'match'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $matchUrl = htmlspecialchars(
            $request->getBasePath() . '/index.php/people?' . http_build_query([
                'name_item' => $id,
                'person_query' => $name,
                'ui' => $uiLanguage,
            ]),
            ENT_QUOTES,
            'UTF-8'
        );

        return <<<HTML
<section id="existing-item-section">
    <h2 id="existing-item-heading">$heading</h2>
    <div id="selected-item-card" class="selected-item-card">
        <div class="selected-item-details">
            <strong id="selected-item-label">$safeLabel</strong>
            <span id="selected-item-description" class="meta">$safeDescription</span>
            <a id="selected-item-qid" class="qid-link" href="https://www.wikidata.org/wiki/$safeId" target="_blank" rel="noopener noreferrer">$safeId</a>
        </div>
        <a id="selected-item-match" class="match-option" href="$matchUrl">$matchLabel</a>
    </div>
</section>
HTML;
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function duplicates(Request $request, array $analysis, string $uiLanguage, string $selectedItem = '', bool $dynamic = false): string
    {
        $createNewItem = htmlspecialchars($this->t($uiLanguage, 'create_new_item'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $alreadyExists = htmlspecialchars($this->t($uiLanguage, 'already_exists'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tryNotDuplicate = htmlspecialchars($this->t($uiLanguage, 'try_not_duplicate'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $chooseAnother = htmlspecialchars($this->t($uiLanguage, 'choose_another_item'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $matchLabel = htmlspecialchars($this->t($uiLanguage, 'match'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rootType = in_array((string) ($analysis['selectedType'] ?? ''), [NameTypes::FAMILY_NAME, NameTypes::CHINESE_FAMILY_NAME], true)
            ? NameTypes::FAMILY_NAME
            : NameTypes::GIVEN_NAME;
        $rootTypeLabel = htmlspecialchars($this->nameTypeLabel($uiLanguage, $rootType), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $createChecked = $selectedItem === '' ? ' checked' : '';
        $rows = '<tr><td colspan="2"><label class="check featured"><input type="radio" name="existing_item" value=""' . $createChecked . ' data-label="' . $createNewItem . '"><span><strong>' . $createNewItem . '</strong></span></label></td></tr>';
        foreach (array_slice($analysis['sameTypeMatches'], 0, 8) as $match) {
            $id = htmlspecialchars((string) $match['id'], ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars((string) $match['label'], ENT_QUOTES, 'UTF-8');
            $descriptionText = $this->translatedInstanceList($uiLanguage, $match['instanceLabels'] ?? []) ?: (string) $match['description'];
            $description = htmlspecialchars($descriptionText, ENT_QUOTES, 'UTF-8');
            $checked = $selectedItem === (string) $match['id'] ? ' checked' : '';
            $matchUrl = htmlspecialchars(
                $request->getBasePath() . '/index.php/people?' . http_build_query([
                    'name_item' => (string) $match['id'],
                    'person_query' => (string) $analysis['name'],
                    'ui' => $uiLanguage,
                ]),
                ENT_QUOTES,
                'UTF-8'
            );
            $existingScript = htmlspecialchars((string) ($match['scripts'][0] ?? ''), ENT_QUOTES, 'UTF-8');
            $rows .= "<input type=\"hidden\" data-existing-script-for=\"$id\" value=\"$existingScript\">";
            $existingMul = htmlspecialchars((string) ($match['mulLabel'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $rows .= "<input type=\"hidden\" data-existing-mul-for=\"$id\" value=\"$existingMul\">";
            $hasExistingNative = empty($match['nativeLabels']) ? '0' : '1';
            $rows .= "<input type=\"hidden\" data-existing-native-for=\"$id\" value=\"$hasExistingNative\">";
            $rows .= "<tr><td colspan=\"2\"><label class=\"check duplicate-check\"><input type=\"radio\" name=\"existing_item\" value=\"$id\"$checked data-label=\"$label\" data-description=\"$description\" data-type-label=\"$rootTypeLabel\"><span><strong>$label</strong> <a class=\"qid-link\" href=\"https://www.wikidata.org/wiki/$id\" target=\"_blank\" rel=\"noopener noreferrer\">($id)</a><br><span class=\"meta\">$description</span></span><a class=\"match-option\" href=\"$matchUrl\">$matchLabel</a></label></td></tr>";
        }

        return <<<HTML
<section id="existing-item-section" data-dynamic="{$dynamic}">
    <h2 id="existing-item-heading">$alreadyExists</h2>
    <p id="existing-item-hint" class="hint">$tryNotDuplicate</p>
    <table id="existing-item-options">$rows</table>
    <div id="selected-item-card" class="selected-item-card" style="display:none">
        <div class="selected-item-details">
            <strong id="selected-item-label"></strong>
            <span id="selected-item-description" class="meta"></span>
            <a id="selected-item-qid" class="qid-link" target="_blank" rel="noopener noreferrer"></a>
            <button id="choose-another-item" class="selected-item-change secondary-action" type="button">$chooseAnother</button>
        </div>
        <a id="selected-item-match" class="match-option" href="#">$matchLabel</a>
    </div>
</section>
HTML;
    }

    /**
     * @param array<string, mixed> $analysis
     */
    /**
     * @return array<string, array{label: string, item: string}>
     */
    private function languages(): array
    {
        return [
            'en' => ['label' => 'English', 'item' => 'Q1860'],
            'nl' => ['label' => 'Dutch', 'item' => 'Q7411'],
            'de' => ['label' => 'German', 'item' => 'Q188'],
            'uk' => ['label' => 'Ukrainian', 'item' => 'Q8798'],
            'bg' => ['label' => 'Bulgarian', 'item' => 'Q7918'],
            'sr' => ['label' => 'Serbian', 'item' => 'Q9299'],
            'mk' => ['label' => 'Macedonian', 'item' => 'Q9296'],
            'be' => ['label' => 'Belarusian', 'item' => 'Q9091'],
            'fy' => ['label' => 'West Frisian', 'item' => 'Q27175'],
            'ga' => ['label' => 'Irish', 'item' => 'Q9142'],
            'gd' => ['label' => 'Scottish Gaelic', 'item' => 'Q9314'],
            'fr' => ['label' => 'French', 'item' => 'Q150'],
            'es' => ['label' => 'Spanish', 'item' => 'Q1321'],
            'it' => ['label' => 'Italian', 'item' => 'Q652'],
            'pt' => ['label' => 'Portuguese', 'item' => 'Q5146'],
            'pl' => ['label' => 'Polish', 'item' => 'Q809'],
            'cs' => ['label' => 'Czech', 'item' => 'Q9056'],
            'sv' => ['label' => 'Swedish', 'item' => 'Q9027'],
            'da' => ['label' => 'Danish', 'item' => 'Q9035'],
            'no' => ['label' => 'Norwegian', 'item' => 'Q9043'],
            'is' => ['label' => 'Icelandic', 'item' => 'Q294'],
            'fi' => ['label' => 'Finnish', 'item' => 'Q1412'],
            'hu' => ['label' => 'Hungarian', 'item' => 'Q9067'],
            'ro' => ['label' => 'Romanian', 'item' => 'Q7913'],
            'zh' => ['label' => 'Chinese', 'item' => 'Q7850'],
            'cmn' => ['label' => 'Mandarin', 'item' => 'Q9192'],
            'ja' => ['label' => 'Japanese', 'item' => 'Q5287'],
            'ko' => ['label' => 'Korean', 'item' => 'Q9176'],
            'ar' => ['label' => 'Arabic', 'item' => 'Q13955'],
            'hy' => ['label' => 'Armenian', 'item' => 'Q8785'],
            'ka' => ['label' => 'Georgian', 'item' => 'Q8108'],
            'el' => ['label' => 'Greek', 'item' => 'Q9129'],
            'sa' => ['label' => 'Sanskrit', 'item' => 'Q11059'],
            'ru' => ['label' => 'Russian', 'item' => 'Q7737'],
            'he' => ['label' => 'Hebrew', 'item' => 'Q9288'],
            'hi' => ['label' => 'Hindi', 'item' => 'Q1568'],
        ];
    }

    /**
     * @param array<string, string> $affix
     */
    /**
     * @param array<string, int> $preferredLanguages
     */
    private function languageCodeForAffix(array $affix, array $preferredLanguages = []): ?string
    {
        $group = $affix['group'] ?? '';
        $value = mb_strtolower($affix['value'] ?? '');

        if ($group === 'nl' && $value === 'de') {
            return $this->preferredLanguageAmong($preferredLanguages, ['nl', 'fr', 'es']);
        }

        if (in_array($group, ['en', 'nl', 'de', 'ga', 'gd', 'fy', 'sv', 'da', 'no', 'is'], true)) {
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

        if ($value === 'de' || $value === 'de la') {
            return $this->preferredLanguageAmong($preferredLanguages, ['nl', 'fr', 'es']);
        }

        return null;
    }

    /**
     * @param array<string, int> $preferredLanguages
     * @param list<string> $codes
     */
    private function preferredLanguageAmong(array $preferredLanguages, array $codes): ?string
    {
        $bestCode = null;
        $bestScore = 0;
        foreach ($codes as $code) {
            $score = $preferredLanguages[$code] ?? 0;
            if ($score > $bestScore) {
                $bestCode = $code;
                $bestScore = $score;
            }
        }

        return $bestScore >= 2 ? $bestCode : null;
    }

    /**
     * @return array<string, int>
     */
    private function preferredLanguages(string $preferences): array
    {
        $out = [];
        foreach (explode(',', $preferences) as $part) {
            if (!preg_match('/^([a-z-]{2,12}):([0-9]{1,2})$/', trim($part), $match)) {
                continue;
            }
            $out[$match[1]] = max(1, min(99, (int) $match[2]));
        }

        return $out;
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
            if (preg_match('/[äöüß]/u', $folded) === 1) {
                return 'de';
            }
            if (str_contains($folded, 'å')) {
                return 'sv';
            }
            if (str_contains($folded, 'uij') || str_contains($folded, 'ij')) {
                return 'nl';
            }
            if ($selectedType === NameTypes::FAMILY_NAME && str_contains($folded, 'uy')) {
                return 'nl';
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function checks(array $analysis, string $language, string $uiLanguage, string $displayLabel, ScriptLanguageLookup $scriptLanguages, bool $showNativeLabel = true, bool $isUpdate = false, bool $updateTransliteration = false): string
    {
        $checks = '';
        $languages = $this->languages();
        if (!$isUpdate) {
            foreach ($this->labelRows($analysis, $language, $uiLanguage, $displayLabel, $scriptLanguages) as $labelRow) {
                $checks .= $labelRow;
            }
        } elseif (
            is_array($analysis['script'] ?? null)
            && ($analysis['script']['qid'] ?? '') !== 'Q8229'
        ) {
            $checks .= $this->labelRow($uiLanguage, $displayLabel, 'mul', !$updateTransliteration);
        }
        $checks .= $this->overwriteDescriptionsCheck($analysis, $uiLanguage, $isUpdate);
        if (!$isUpdate && $showNativeLabel) {
            $checks .= $this->nativeLabelRow((string) $analysis['name'], $this->nativeLabelLanguage($language), $uiLanguage);
        }

        foreach ($analysis['claims'] as $claim) {
            if ($isUpdate && in_array($claim['property'], ['P31', 'P282'], true)) {
                continue;
            }
            $title = $this->sentenceCase($this->propertyLabel($uiLanguage, $claim['property'], $claim['propertyLabel']));
            $detail = match ($claim['property']) {
                'P31' => $this->itemLabel($uiLanguage, (string) $claim['value'], (string) $claim['valueLabel']),
                'P282' => $this->scriptLabel($uiLanguage, (string) $claim['value'], (string) $claim['valueLabel']),
                default => $claim['valueLabel'],
            };
            $checks .= $this->staticRow(
                $title,
                $detail,
                $claim['property'] === 'P282' ? 'claim-P282-detail' : ''
            );
        }

        $languageLabel = $language !== '' && isset($languages[$language])
            ? $languages[$language]['label']
            : $this->t($uiLanguage, 'not_set');
        $checks .= $this->languageClaimCheck($languageLabel, $language !== '', $uiLanguage);

        foreach ($this->affixChecks($analysis, $uiLanguage) as $affixCheck) {
            $checks .= $affixCheck;
        }

        $suggestions = '';
        foreach ($analysis['relationshipSuggestions'] as $suggestion) {
            $suggestions .= $this->relationshipCheck($suggestion, $uiLanguage);
        }

        $suggestionDisplay = $suggestions !== '' ? '' : ' style="display:none"';
        $checks .= '<div id="relationship-suggestions-heading" class="subhead"' . $suggestionDisplay . '>'
            . htmlspecialchars($this->t($uiLanguage, 'suggestions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</div><div id="relationship-suggestions">' . $suggestions . '</div>';

        return $checks;
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function overwriteDescriptionsCheck(array $analysis, string $uiLanguage, bool $visible): string
    {
        $label = htmlspecialchars($this->t($uiLanguage, 'overwrite_descriptions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $description = (string) (($analysis['descriptions']['mul'] ?? null)
            ?: ($analysis['descriptions']['en'] ?? '')
            ?: (NameTypes::DESCRIPTIONS[$analysis['selectedType'] ?? NameTypes::GIVEN_NAME]['en'] ?? ''));
        $safeDescription = htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $style = $visible ? '' : ' style="display:none"';
        $disabled = $visible ? '' : ' disabled';

        return <<<HTML
<label id="overwrite-descriptions-row" class="check" for="overwrite-descriptions"$style>
    <input id="overwrite-descriptions" name="apply[]" value="overwrite_descriptions" type="checkbox"$disabled>
    <span><strong>$label</strong><br><span class="meta">$safeDescription (mul)</span></span>
</label>
HTML;
    }

    /**
     * @param array<string, mixed> $analysis
     * @return list<string>
     */
    private function affixChecks(array $analysis, string $uiLanguage): array
    {
        $selectedType = (string) ($analysis['selectedType'] ?? '');
        if (!in_array($selectedType, [NameTypes::FAMILY_NAME, NameTypes::CHINESE_FAMILY_NAME], true)) {
            return [];
        }

        $checks = [];
        $seen = [];
        foreach ($analysis['affixes'] ?? [] as $affix) {
            if (!is_array($affix) || ($affix['kind'] ?? '') !== 'prefix') {
                continue;
            }
            $item = (string) ($affix['item'] ?? '');
            if (!preg_match('/^Q\d+$/', $item) || isset($seen[$item])) {
                continue;
            }
            $seen[$item] = true;
            $label = (string) ($affix['itemLabel'] ?? $affix['value'] ?? $item);
            $checks[] = $this->check('claim_P7377_' . $item, $this->t($uiLanguage, 'infix'), $label . ' (' . $item . ')', true);
        }

        return $checks;
    }

    private function nativeLabelLanguage(string $language): string
    {
        return $language !== '' ? $language : 'mul';
    }

    private function languageClaimCheck(string $detail, bool $available, string $uiLanguage): string
    {
        $title = htmlspecialchars($this->t($uiLanguage, 'language_of_name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeDetail = htmlspecialchars($detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $checked = $available ? ' checked' : '';
        $disabled = $available ? '' : ' disabled';

        return <<<HTML
<label class="check" for="claim_P407">
    <input id="claim_P407" name="apply[]" value="claim_P407" type="checkbox"$checked$disabled>
    <span>
        <strong>$title</strong><br>
        <span id="claim-P407-detail" class="meta">$safeDetail</span>
    </span>
</label>
HTML;
    }

    /**
     * @param array<string, mixed> $analysis
     * @return list<string>
     */
    private function labelRows(array $analysis, string $language, string $uiLanguage, string $displayLabel, ScriptLanguageLookup $scriptLanguages): array
    {
        $name = (string) ($analysis['name'] ?? '');
        $scriptQid = is_array($analysis['script'] ?? null) ? (string) ($analysis['script']['qid'] ?? '') : '';
        $labelLanguages = ['mul' => $displayLabel];

        if ($scriptQid !== 'Q8209') {
            foreach ($scriptLanguages->languageCodesForScript($scriptQid) as $scriptLanguage) {
                $labelLanguages[$scriptLanguage] = $name;
            }
        }

        $rows = [];
        foreach ($labelLanguages as $labelLanguage => $labelValue) {
            $rows[] = $this->labelRow($uiLanguage, $labelValue, $labelLanguage);
        }
        $rows[] = $this->scriptLabelSummaryRow(
            $name,
            $this->t($uiLanguage, 'cyrillic_languages'),
            $uiLanguage,
            $scriptQid === 'Q8209'
        );

        return $rows;
    }

    private function scriptLabelSummaryRow(string $name, string $group, string $uiLanguage, bool $visible): string
    {
        $title = htmlspecialchars($this->t($uiLanguage, 'label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeGroup = htmlspecialchars($group, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $style = $visible ? '' : ' style="display:none"';

        return <<<HTML
<div id="script-label-summary" class="check" data-update-label="script"$style>
    <span></span>
    <span>
        <strong>$title</strong><br>
        <span id="script-label-summary-value" class="meta">$safeName ($safeGroup)</span>
    </span>
</div>
HTML;
    }

    private function labelRow(string $uiLanguage, string $displayLabel, string $language, bool $hidden = false): string
    {
        $safeTitle = htmlspecialchars($this->t($uiLanguage, 'label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeDisplayLabel = htmlspecialchars($displayLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLanguage = htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $previewAttribute = $language === 'mul' ? ' data-label-preview="display"' : '';
        $scriptLanguageAttribute = $language !== 'mul' ? ' data-script-language-row' : '';
        $updateLabelAttribute = ' data-update-label="' . ($language === 'mul' ? 'mul' : 'script') . '"';
        $style = $hidden ? ' style="display:none"' : '';

        return <<<HTML
<div class="check"$scriptLanguageAttribute$updateLabelAttribute$style>
    <span></span>
    <span>
        <strong>$safeTitle</strong><br>
        <span class="meta" data-label-preview-language="$safeLanguage"$previewAttribute>$safeDisplayLabel ($safeLanguage)</span>
    </span>
</div>
HTML;
    }

    private function nativeLabelRow(string $name, string $language, string $uiLanguage): string
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLanguage = htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $nativeLabel = htmlspecialchars($this->t($uiLanguage, 'native_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<div id="native-label-row" class="check">
    <span></span>
    <span>
        <strong>$nativeLabel</strong><br>
        <span class="meta">$safeName (<span id="native-label-language">$safeLanguage</span>)</span>
    </span>
</div>
HTML;
    }

    private function staticRow(string $title, string $detail, string $detailId = ''): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeDetail = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');
        $id = $detailId !== '' ? ' id="' . htmlspecialchars($detailId, ENT_QUOTES, 'UTF-8') . '"' : '';

        return <<<HTML
<div class="check">
    <span></span>
    <span>
        <strong>$safeTitle</strong><br>
        <span class="meta"$id>$safeDetail</span>
    </span>
</div>
HTML;
    }

    private function check(string $id, string $title, string $detail, bool $checked, bool $required = false, string $reason = ''): string
    {
        $safeId = htmlspecialchars(preg_replace('/[^a-z0-9_]+/i', '_', $id) ?? $id, ENT_QUOTES, 'UTF-8');
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeDetail = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');
        $safeReason = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
        $checkedAttr = $checked ? ' checked' : '';
        $requiredAttr = $required ? ' disabled checked' : $checkedAttr;
        $requiredText = $required ? '<span class="required">required</span>' : '';
        $value = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        $reasonHtml = $safeReason !== '' ? '<p class="reason">' . $safeReason . '</p>' : '';

        return <<<HTML
<label class="check" for="$safeId">
    <input id="$safeId" name="apply[]" value="$value" type="checkbox"$requiredAttr>
    <span>
        <strong>$safeTitle</strong> $requiredText<br>
        <span class="meta">$safeDetail</span>
        $reasonHtml
    </span>
</label>
HTML;
    }

    /**
     * @param array{target: string, targetLabel: string, targetTypes?: list<string>, property: string, propertyLabel: string, value: string, reason: string, checked?: bool} $suggestion
     */
    private function relationshipCheck(array $suggestion, string $uiLanguage): string
    {
        $id = 'related_' . $suggestion['target'] . '_' . $suggestion['property'];
        $safeId = htmlspecialchars(preg_replace('/[^a-z0-9_]+/i', '_', $id) ?? $id, ENT_QUOTES, 'UTF-8');
        $value = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        $propertyId = htmlspecialchars($suggestion['property'], ENT_QUOTES, 'UTF-8');
        $propertyText = ($suggestion['property'] ?? '') === 'P1533' && ($suggestion['direction'] ?? '') === 'target'
            ? $this->t($uiLanguage, 'given_name_identical_family')
            : $this->propertyLabel($uiLanguage, $suggestion['property'], $suggestion['propertyLabel']);
        $property = htmlspecialchars($this->sentenceCase($propertyText), ENT_QUOTES, 'UTF-8');
        $target = htmlspecialchars($suggestion['target'], ENT_QUOTES, 'UTF-8');
        $targetLabel = htmlspecialchars($suggestion['targetLabel'], ENT_QUOTES, 'UTF-8');
        $targetTypes = htmlspecialchars($this->translatedInstanceList($uiLanguage, $suggestion['targetTypes'] ?? []), ENT_QUOTES, 'UTF-8');
        $typeText = $targetTypes !== '' ? '<br><span class="meta">' . $targetTypes . '</span>' : '';
        $checked = $suggestion['property'] === 'P5278' && empty($suggestion['checked']) ? '' : ' checked';
        return <<<HTML
<label class="check" for="$safeId" data-suggestion-target="$target" data-property="$propertyId">
    <input id="$safeId" name="apply[]" value="$value" type="checkbox"$checked>
    <span>
        <strong>$property</strong><br>
        <span class="meta">$targetLabel <a class="qid-link" href="https://www.wikidata.org/wiki/$target" target="_blank" rel="noopener noreferrer">($target)</a></span>$typeText
    </span>
</label>
HTML;
    }

    /**
     * @param list<string> $labels
     */
    private function translatedInstanceList(string $uiLanguage, array $labels): string
    {
        $translated = [];
        foreach ($labels as $label) {
            $translated[] = $this->itemLabelByEnglish($uiLanguage, (string) $label);
        }

        return implode(', ', array_values(array_filter(array_unique($translated))));
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

    private function nameTypeLabel(string $uiLanguage, string $type): string
    {
        return $this->itemLabel($uiLanguage, NameTypes::TYPE_ITEMS[$type] ?? '', NameTypes::LABELS[$type] ?? $type);
    }

    /**
     * @param list<string> $instances
     */
    private function nameTypeFromInstances(array $instances): ?string
    {
        foreach ([
            NameTypes::FEMALE_GIVEN_NAME,
            NameTypes::MALE_GIVEN_NAME,
            NameTypes::UNISEX_GIVEN_NAME,
            NameTypes::FAMILY_NAME,
            NameTypes::GIVEN_NAME,
        ] as $type) {
            if (in_array(NameTypes::TYPE_ITEMS[$type], $instances, true)) {
                return $type;
            }
        }

        return null;
    }

    private function itemLabel(string $uiLanguage, string $qid, string $fallback): string
    {
        $labels = [
            'en' => [
                'Q101352' => 'family name',
                'Q66480858' => 'affixed family name',
                'Q60558422' => 'compound surname',
                'Q4167410' => 'Wikimedia disambiguation page',
                'Q202444' => 'given name',
                'Q12308941' => 'male given name',
                'Q11879590' => 'female given name',
                'Q3409032' => 'unisex given name',
            ],
            'nl' => [
                'Q101352' => 'achternaam',
                'Q66480858' => 'achternaam met tussenvoegsel',
                'Q60558422' => 'samengestelde achternaam',
                'Q4167410' => 'Wikimedia-doorverwijspagina',
                'Q202444' => 'voornaam',
                'Q12308941' => 'mannelijke voornaam',
                'Q11879590' => 'vrouwelijke voornaam',
                'Q3409032' => 'unisex voornaam',
            ],
            'de' => [
                'Q101352' => 'Familienname',
                'Q66480858' => 'Familienname mit Namenszusatz',
                'Q60558422' => 'zusammengesetzter Familienname',
                'Q4167410' => 'Wikimedia-Begriffsklärungsseite',
                'Q202444' => 'Vorname',
                'Q12308941' => 'männlicher Vorname',
                'Q11879590' => 'weiblicher Vorname',
                'Q3409032' => 'Unisex-Vorname',
            ],
            'fr' => [
                'Q101352' => 'nom de famille',
                'Q66480858' => 'nom de famille avec particule',
                'Q60558422' => 'nom de famille composé',
                'Q4167410' => 'page d’homonymie Wikimedia',
                'Q202444' => 'prénom',
                'Q12308941' => 'prénom masculin',
                'Q11879590' => 'prénom féminin',
                'Q3409032' => 'prénom épicène',
            ],
            'es' => [
                'Q101352' => 'apellido',
                'Q66480858' => 'apellido con partícula',
                'Q60558422' => 'apellido compuesto',
                'Q4167410' => 'página de desambiguación de Wikimedia',
                'Q202444' => 'nombre de pila',
                'Q12308941' => 'nombre masculino',
                'Q11879590' => 'nombre femenino',
                'Q3409032' => 'nombre unisex',
            ],
        ];

        $uiLanguage = $this->interfaceLanguage($uiLanguage);

        return $labels[$uiLanguage][$qid] ?? $labels['en'][$qid] ?? $fallback;
    }

    private function itemLabelByEnglish(string $uiLanguage, string $label): string
    {
        $qid = array_search($label, NameTypes::ITEM_LABELS, true);

        return is_string($qid) ? $this->itemLabel($uiLanguage, $qid, $label) : $label;
    }

    private function scriptLabel(string $uiLanguage, string $qid, string $fallback): string
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

        return $labels[$uiLanguage][$qid] ?? $labels['en'][$qid] ?? $fallback;
    }

    private function confidenceLabel(string $uiLanguage, string $confidence): string
    {
        $labels = [
            'en' => ['high' => 'high', 'medium' => 'medium', 'low' => 'low', 'required' => 'required'],
            'nl' => ['high' => 'hoog', 'medium' => 'gemiddeld', 'low' => 'laag', 'required' => 'verplicht'],
            'de' => ['high' => 'hoch', 'medium' => 'mittel', 'low' => 'niedrig', 'required' => 'erforderlich'],
            'fr' => ['high' => 'élevée', 'medium' => 'moyenne', 'low' => 'faible', 'required' => 'obligatoire'],
            'es' => ['high' => 'alta', 'medium' => 'media', 'low' => 'baja', 'required' => 'obligatorio'],
        ];

        $uiLanguage = $this->interfaceLanguage($uiLanguage);

        return $labels[$uiLanguage][$confidence] ?? $labels['en'][$confidence] ?? $confidence;
    }

    private function propertyLabel(string $uiLanguage, string $propertyId, string $fallback): string
    {
        $labels = [
            'en' => [
                'P31' => 'instance of',
                'P282' => 'writing system',
                'P407' => 'language of name',
                'P1705' => 'native label',
                'P7377' => 'infix',
                'P460' => 'said to be the same as',
                'P1889' => 'different from',
                'P1533' => 'family name identical to this given name',
                'P1560' => 'other gender form',
                'P5278' => 'other gender form',
            ],
            'nl' => [
                'P31' => 'is een',
                'P282' => 'schrift',
                'P407' => 'taal van de naam',
                'P1705' => 'native label',
                'P7377' => 'tussenvoegsel',
                'P460' => 'mogelijk dezelfde naam',
                'P1889' => 'niet hetzelfde als',
                'P1533' => 'identieke achternaam',
                'P1560' => 'vorm voor ander gender',
                'P5278' => 'vorm voor ander gender',
            ],
            'de' => [
                'P31' => 'ist ein',
                'P282' => 'Schriftsystem',
                'P407' => 'Sprache des Namens',
                'P1705' => 'native label',
                'P7377' => 'Namenszusatz',
                'P460' => 'möglicherweise derselbe Name',
                'P1889' => 'verschieden von',
                'P1533' => 'identischer Nachname',
                'P1560' => 'Form für anderes Geschlecht',
                'P5278' => 'Form für anderes Geschlecht',
            ],
            'fr' => [
                'P31' => 'nature de l’élément',
                'P282' => 'système d’écriture',
                'P407' => 'langue du nom',
                'P1705' => 'native label',
                'P7377' => 'particule',
                'P460' => 'nom possiblement identique',
                'P1889' => 'à ne pas confondre avec',
                'P1533' => 'nom de famille identique',
                'P1560' => 'forme pour un autre genre',
                'P5278' => 'forme pour un autre genre',
            ],
            'es' => [
                'P31' => 'instancia de',
                'P282' => 'sistema de escritura',
                'P407' => 'idioma del nombre',
                'P1705' => 'native label',
                'P7377' => 'partícula',
                'P460' => 'posiblemente el mismo nombre',
                'P1889' => 'diferente de',
                'P1533' => 'apellido idéntico',
                'P1560' => 'forma de otro género',
                'P5278' => 'forma de otro género',
            ],
        ];

        $uiLanguage = $this->interfaceLanguage($uiLanguage);

        return $labels[$uiLanguage][$propertyId] ?? $labels['en'][$propertyId] ?? $fallback;
    }

    private function sentenceCase(string $text): string
    {
        return $text === '' ? '' : mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
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

    private function requiresFullNameConfirmation(string $name): bool
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
        foreach ($this->nameAffixes() as $affix) {
            if (str_starts_with($normalized, $affix . ' ') || str_starts_with($normalized, $affix . '-')) {
                return false;
            }
        }

        preg_match_all('/[\p{L}\p{M}]+(?:[’\'-][\p{L}\p{M}]+)*/u', $normalized, $parts);

        return count($parts[0] ?? []) > 1;
    }
}
