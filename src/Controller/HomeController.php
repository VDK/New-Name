<?php

namespace App\Controller;

use App\Data\NameTypes;
use App\Service\NameAnalyzer;
use App\Service\NameTransliterator;
use App\Service\ScriptDetector;
use App\Service\WikimediaOAuthClient;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(Request $request, NameAnalyzer $analyzer, WikimediaOAuthClient $oauthClient, NameTransliterator $transliterator): Response|RedirectResponse
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
        $preferredLanguages = $this->preferredLanguages((string) $request->query->get('preferred_languages', ''));
        $uiLanguage = $this->interfaceLanguage((string) $request->query->get('ui', 'en'));
        $analysis = $name !== '' ? $analyzer->analyze($name, is_string($type) ? $type : null) : null;
        $authorized = $oauthClient->isAuthorized();
        $username = '';
        if ($authorized) {
            try {
                $username = $oauthClient->getUsername();
            } catch (\Throwable) {
                $username = '';
            }
        }

        $response = new Response($this->page($request, $name, $analysis, is_string($language) ? $language : '', $authorized, $username, $uiLanguage, $preferredLanguages, $transliterator));
        $response->headers->set('Cache-Control', 'no-store, max-age=0');

        return $response;
    }

    /**
     * @param array<string, mixed>|null $analysis
     */
    private function page(Request $request, string $name, ?array $analysis, string $language, bool $authorized, string $username, string $uiLanguage, array $preferredLanguages, NameTransliterator $transliterator): string
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUiLanguage = htmlspecialchars($uiLanguage, ENT_QUOTES, 'UTF-8');
        $review = $analysis ? $this->review($request, $analysis, $language, $authorized, $uiLanguage, $preferredLanguages, $transliterator) : '';
        $bodyClass = $analysis ? 'has-review' : 'start';
        $auth = $this->authStatus($request, $authorized, $username, $uiLanguage);
        $languageSwitch = $this->languageSwitch($request, $uiLanguage);
        $tagline = htmlspecialchars($this->t($uiLanguage, 'tagline'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $nameLabel = htmlspecialchars($this->t($uiLanguage, 'name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $analyze = htmlspecialchars($this->t($uiLanguage, 'analyze'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $credit = htmlspecialchars($this->t($uiLanguage, 'credit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $sourceCode = htmlspecialchars($this->t($uiLanguage, 'source_code'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $mit = htmlspecialchars($this->t($uiLanguage, 'mit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $jsCreateNewItem = json_encode($this->t($uiLanguage, 'create_new_item'), JSON_THROW_ON_ERROR);
        $jsCreateNote = json_encode($this->t($uiLanguage, 'create_note'), JSON_THROW_ON_ERROR);
        $jsUpdateItem = json_encode($this->t($uiLanguage, 'update_item'), JSON_THROW_ON_ERROR);
        $jsUpdatePrefix = json_encode($this->t($uiLanguage, 'update_prefix'), JSON_THROW_ON_ERROR);

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
            justify-content: flex-end;
            align-items: center;
            gap: 8px;
            min-height: 28px;
            margin-bottom: 12px;
            color: var(--muted);
            font-size: 14px;
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
            gap: 6px;
            font-size: 13px;
        }

        .language-switch label {
            color: var(--muted);
            font-weight: 600;
        }

        .language-switch select {
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
            grid-template-columns: 1fr auto;
            gap: 8px;
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
            justify-content: flex-end;
            gap: 10px;
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
            .search { grid-template-columns: 1fr; }
            .search button { width: 100%; }
            .grid { grid-template-columns: 1fr; }
            th { width: auto; }
        }
    </style>
    <script>
        function clearLanguageSelection() {
            var value = document.getElementById('language-value');
            var code = document.getElementById('language-code');
            if (value) value.value = '';
            if (code) code.value = '';
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
            closeLanguageList();
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
                option.addEventListener('mousedown', function(event) {
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
                fetch('index.php/api/languages?q=' + encodeURIComponent(input.value) + '&hints=' + encodeURIComponent(hints) + '&ui=$safeUiLanguage&v=20260614-hints')
                    .then(function(response) { return response.json(); })
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

        function updateMode() {
            var selected = document.querySelector('input[name="existing_item"]:checked');
            var heading = document.getElementById('apply-heading');
            var note = document.getElementById('apply-note');
            var button = document.getElementById('save-button');
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
            if (heading) heading.textContent = $jsUpdatePrefix + ' ' + label + ' (' + qid + ')';
            if (note) note.style.display = 'none';
            if (button) {
                var text = button.querySelector('span:last-child');
                if (text) text.textContent = $jsUpdateItem;
            }
        }

        function warnScriptChange(select) {
            var warning = document.getElementById('script-warning');
            if (!warning) return;
            warning.style.display = select.value === select.dataset.detected ? 'none' : 'block';
        }

        function updateLabelPreview(input) {
            var value = input.value.trim() || input.dataset.originalName || '';
            document.querySelectorAll('[data-label-preview="display"]').forEach(function(preview) {
                preview.textContent = value + ' (' + preview.dataset.labelPreviewLanguage + ')';
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
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
            var transliterationInput = document.getElementById('label-transliteration');
            if (transliterationInput) transliterationInput.addEventListener('input', function() { updateLabelPreview(transliterationInput); });
            document.querySelectorAll('input[name="existing_item"]').forEach(function(input) {
                input.addEventListener('change', updateMode);
            });
            document.addEventListener('click', function(event) {
                var picker = document.querySelector('.typeahead-wrap');
                var list = document.getElementById('language-suggestions');
                if (picker && list && !picker.contains(event.target)) list.style.display = 'none';
            });
            updateMode();
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

        <div class="search-card">
            <label class="search-label" for="name-input">$nameLabel</label>
            <form class="search" method="get" action="" data-loading>
                <input type="hidden" name="ui" value="$safeUiLanguage">
                <input id="name-input" name="name" type="text" value="$safeName" autocomplete="off" autofocus required>
                <button type="submit"><span class="spinner" aria-hidden="true"></span><span>$analyze</span></button>
            </form>
        </div>

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
        if (!$authorized) {
            return '<div class="auth-status"><a href="index.php/oauth/login?return=' . $return . '">' . $login . '</a></div>';
        }

        $safeUsername = htmlspecialchars($username !== '' ? $username : 'Wikimedia', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $loggedInAs = htmlspecialchars($this->t($uiLanguage, 'logged_in_as'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $logout = htmlspecialchars($this->t($uiLanguage, 'log_out'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div class="auth-status"><span>' . $loggedInAs . ' <strong>' . $safeUsername . '</strong></span><a href="index.php/oauth/logout">' . $logout . '</a></div>';
    }

    private function t(string $language, string $key): string
    {
        $language = $this->interfaceLanguage($language);
        $translations = [
            'en' => [
                'already_exists' => 'Already Exists?',
                'analyze' => 'Analyze',
                'confidence' => 'Confidence',
                'create_new_item' => 'Create new item',
                'create_note' => 'A new item with the following properties will be created.',
                'credit' => 'by',
                'interface_language' => 'Interface language',
                'infix' => 'infix',
                'label' => 'Label',
                'language_of_name' => 'Language of name',
                'log_in' => 'Log in',
                'log_in_with_wikimedia' => 'Log in with Wikimedia',
                'log_in_before_saving' => 'Log in with Wikimedia before saving changes.',
                'log_out' => 'Log out',
                'logged_in_as' => 'Logged in as',
                'mit' => 'MIT licensed',
                'name' => 'Name',
                'native_label' => 'native label',
                'optional' => 'optional',
                'ready_to_create' => 'Ready To Create',
                'review_required_fields' => 'Review Required Fields',
                'script_warning' => 'This changes a high-confidence script detection. Check the name carefully before creating or updating Wikidata.',
                'source_code' => 'Source code',
                'suggestions' => 'Suggestions',
                'tagline' => 'Create Wikidata items for given names and family names.',
                'try_not_duplicate' => 'Try not to make duplicate items. Is it one of these?',
                'transliteration' => 'Transliteration',
                'transliteration_hint' => 'Used as the mul label; the original spelling remains the native label.',
                'type' => 'Type',
                'update_item' => 'Update item',
                'update_prefix' => 'Update',
                'writing_system' => 'Writing system',
            ],
            'nl' => [
                'already_exists' => 'Bestaat al?',
                'analyze' => 'Analyseren',
                'confidence' => 'Zekerheid',
                'create_new_item' => 'Nieuw item aanmaken',
                'create_note' => 'Er wordt een nieuw item met deze eigenschappen aangemaakt.',
                'credit' => 'door',
                'interface_language' => 'Interfacetaal',
                'infix' => 'tussenvoegsel',
                'label' => 'Label',
                'language_of_name' => 'Taal van de naam',
                'log_in' => 'Inloggen',
                'log_in_with_wikimedia' => 'Inloggen met Wikimedia',
                'log_in_before_saving' => 'Log in met Wikimedia voordat je wijzigingen opslaat.',
                'log_out' => 'Uitloggen',
                'logged_in_as' => 'Ingelogd als',
                'mit' => 'MIT-licentie',
                'name' => 'Naam',
                'native_label' => 'native label',
                'optional' => 'optioneel',
                'ready_to_create' => 'Klaar om op te slaan',
                'review_required_fields' => 'Velden controleren',
                'script_warning' => 'Dit wijzigt een schrijfwijze-detectie met hoge zekerheid. Controleer de naam goed voordat je Wikidata aanmaakt of bijwerkt.',
                'source_code' => 'Broncode',
                'suggestions' => 'Suggesties',
                'tagline' => 'Maak Wikidata-items voor voor- en achternamen.',
                'try_not_duplicate' => 'Probeer dubbele items te voorkomen. Is het een van deze?',
                'transliteration' => 'Transcriptie',
                'transliteration_hint' => 'Wordt gebruikt als mul-label; de oorspronkelijke schrijfwijze blijft de native label.',
                'type' => 'Type',
                'update_item' => 'Item bijwerken',
                'update_prefix' => 'Bijwerken',
                'writing_system' => 'Schrift',
            ],
            'de' => [
                'already_exists' => 'Existiert bereits?',
                'analyze' => 'Analysieren',
                'confidence' => 'Sicherheit',
                'create_new_item' => 'Neues Item erstellen',
                'create_note' => 'Ein neues Item mit den folgenden Eigenschaften wird erstellt.',
                'credit' => 'von',
                'interface_language' => 'Oberflächensprache',
                'infix' => 'Namenszusatz',
                'label' => 'Label',
                'language_of_name' => 'Sprache des Namens',
                'log_in' => 'Anmelden',
                'log_in_with_wikimedia' => 'Mit Wikimedia anmelden',
                'log_in_before_saving' => 'Melde dich mit Wikimedia an, bevor du Änderungen speicherst.',
                'log_out' => 'Abmelden',
                'logged_in_as' => 'Angemeldet als',
                'mit' => 'MIT-lizenziert',
                'name' => 'Name',
                'native_label' => 'native label',
                'optional' => 'optional',
                'ready_to_create' => 'Bereit zum Speichern',
                'review_required_fields' => 'Felder prüfen',
                'script_warning' => 'Dies ändert eine Schrifterkennung mit hoher Sicherheit. Prüfe den Namen sorgfältig, bevor du Wikidata erstellst oder aktualisierst.',
                'source_code' => 'Quellcode',
                'suggestions' => 'Vorschläge',
                'tagline' => 'Wikidata-Items für Vor- und Nachnamen erstellen.',
                'try_not_duplicate' => 'Versuche doppelte Items zu vermeiden. Ist es eines davon?',
                'transliteration' => 'Transliteration',
                'transliteration_hint' => 'Wird als mul-Label verwendet; die ursprüngliche Schreibweise bleibt das native label.',
                'type' => 'Typ',
                'update_item' => 'Item aktualisieren',
                'update_prefix' => 'Aktualisieren',
                'writing_system' => 'Schriftsystem',
            ],
            'fr' => [
                'already_exists' => 'Existe déjà ?',
                'analyze' => 'Analyser',
                'confidence' => 'Confiance',
                'create_new_item' => 'Créer un nouvel élément',
                'create_note' => 'Un nouvel élément avec les propriétés suivantes sera créé.',
                'credit' => 'par',
                'interface_language' => 'Langue de l’interface',
                'infix' => 'particule',
                'label' => 'Libellé',
                'language_of_name' => 'Langue du nom',
                'log_in' => 'Connexion',
                'log_in_with_wikimedia' => 'Connexion avec Wikimedia',
                'log_in_before_saving' => 'Connectez-vous avec Wikimedia avant d’enregistrer.',
                'log_out' => 'Déconnexion',
                'logged_in_as' => 'Connecté comme',
                'mit' => 'Licence MIT',
                'name' => 'Nom',
                'native_label' => 'native label',
                'optional' => 'facultatif',
                'ready_to_create' => 'Prêt à enregistrer',
                'review_required_fields' => 'Vérifier les champs',
                'script_warning' => 'Cela modifie une détection d’écriture à haute confiance. Vérifiez le nom avant de créer ou mettre à jour Wikidata.',
                'source_code' => 'Code source',
                'suggestions' => 'Suggestions',
                'tagline' => 'Créer des éléments Wikidata pour les prénoms et les noms de famille.',
                'try_not_duplicate' => 'Évitez de créer des doublons. Est-ce l’un de ces éléments ?',
                'transliteration' => 'Translittération',
                'transliteration_hint' => 'Utilisée comme libellé mul ; l’écriture originale reste le native label.',
                'type' => 'Type',
                'update_item' => 'Mettre à jour',
                'update_prefix' => 'Mettre à jour',
                'writing_system' => 'Système d’écriture',
            ],
            'es' => [
                'already_exists' => '¿Ya existe?',
                'analyze' => 'Analizar',
                'confidence' => 'Confianza',
                'create_new_item' => 'Crear elemento nuevo',
                'create_note' => 'Se creará un elemento nuevo con las siguientes propiedades.',
                'credit' => 'por',
                'interface_language' => 'Idioma de la interfaz',
                'infix' => 'partícula',
                'label' => 'Etiqueta',
                'language_of_name' => 'Idioma del nombre',
                'log_in' => 'Iniciar sesión',
                'log_in_with_wikimedia' => 'Iniciar sesión con Wikimedia',
                'log_in_before_saving' => 'Inicia sesión con Wikimedia antes de guardar cambios.',
                'log_out' => 'Cerrar sesión',
                'logged_in_as' => 'Sesión iniciada como',
                'mit' => 'Licencia MIT',
                'name' => 'Nombre',
                'native_label' => 'native label',
                'optional' => 'opcional',
                'ready_to_create' => 'Listo para guardar',
                'review_required_fields' => 'Revisar campos',
                'script_warning' => 'Esto cambia una detección de escritura de alta confianza. Revisa el nombre antes de crear o actualizar Wikidata.',
                'source_code' => 'Código fuente',
                'suggestions' => 'Sugerencias',
                'tagline' => 'Crea elementos de Wikidata para nombres de pila y apellidos.',
                'try_not_duplicate' => 'Intenta no crear elementos duplicados. ¿Es uno de estos?',
                'transliteration' => 'Transliteración',
                'transliteration_hint' => 'Se usa como etiqueta mul; la escritura original sigue siendo el native label.',
                'type' => 'Tipo',
                'update_item' => 'Actualizar elemento',
                'update_prefix' => 'Actualizar',
                'writing_system' => 'Sistema de escritura',
            ],
        ];

        return $translations[$language][$key] ?? $translations['en'][$key] ?? $key;
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function review(Request $request, array $analysis, string $language, bool $authorized, string $uiLanguage, array $preferredLanguages, NameTransliterator $transliterator): string
    {
        $selectedType = (string) $analysis['selectedType'];
        $resolvedLanguage = $this->resolveLanguage($language, $selectedType, $analysis, $preferredLanguages);
        $type = $this->typeSelect((string) $analysis['selectedType'], (string) $analysis['name'], $uiLanguage);
        $script = $this->scriptSelect($analysis, $uiLanguage);
        $languageSelect = $this->languageSelect($resolvedLanguage, $this->languageHintCodes($analysis), $this->languageConfidence($language, $selectedType, $analysis, $preferredLanguages, $resolvedLanguage), $uiLanguage);
        $transliteration = $this->transliterationField((string) $analysis['name'], $analysis['script'] ?? null, $transliterator, $uiLanguage);
        $duplicates = $this->duplicates($analysis, $uiLanguage);
        $displayLabel = $this->defaultTransliteration((string) $analysis['name'], $analysis['script'] ?? null, $transliterator);
        $checks = $this->checks($analysis, $resolvedLanguage, $uiLanguage, $displayLabel !== '' ? $displayLabel : (string) $analysis['name']);
        $safeName = htmlspecialchars((string) $analysis['name'], ENT_QUOTES, 'UTF-8');
        $safeUiLanguage = htmlspecialchars($uiLanguage, ENT_QUOTES, 'UTF-8');
        $action = $this->submitAction($request, $authorized, $uiLanguage);
        $reviewRequired = htmlspecialchars($this->t($uiLanguage, 'review_required_fields'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $createNewItem = htmlspecialchars($this->t($uiLanguage, 'create_new_item'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $createNote = htmlspecialchars($this->t($uiLanguage, 'create_note'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $readyToCreate = htmlspecialchars($this->t($uiLanguage, 'ready_to_create'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<form class="review" method="post" action="index.php/wikidata/save" data-loading>
    <input type="hidden" name="name" value="$safeName">
    <input type="hidden" name="ui" value="$safeUiLanguage">
    <section>
        <h2>$reviewRequired</h2>
        <div class="grid">
            $type
            $script
            $languageSelect
            $transliteration
        </div>
    </section>
    $duplicates
    <section>
        <h2 id="apply-heading">$createNewItem</h2>
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

    private function submitAction(Request $request, bool $authorized, string $uiLanguage = 'en'): string
    {
        if ($authorized) {
            return '<div class="actions"><button id="save-button" type="submit"><span class="spinner" aria-hidden="true"></span><span>' . htmlspecialchars($this->t($uiLanguage, 'create_new_item'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span></button></div>';
        }

        $return = $request->getRequestUri();

        return '<p class="hint">' . htmlspecialchars($this->t($uiLanguage, 'log_in_before_saving'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p><div class="actions"><a class="pill-link" href="index.php/oauth/login?return=' . htmlspecialchars(rawurlencode($return), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($this->t($uiLanguage, 'log_in_with_wikimedia'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></div>';
    }

    private function typeSelect(string $selected, string $name, string $uiLanguage): string
    {
        $options = '';
        foreach (NameTypes::ACTIVE_TYPES as $type) {
            $label = NameTypes::LABELS[$type] ?? $type;
            $isSelected = $type === $selected ? ' selected' : '';
            $options .= '<option value="' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '"' . $isSelected . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }

        $safeName = urlencode($name);
        $safeUiLanguage = rawurlencode($uiLanguage);
        $confidence = $this->typeConfidence($selected);
        $typeLabel = htmlspecialchars($this->t($uiLanguage, 'type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $confidenceLabel = htmlspecialchars($this->t($uiLanguage, 'confidence'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<div>
    <label for="type">$typeLabel</label>
    <select id="type" name="type" onchange="window.location='?ui=$safeUiLanguage&name=$safeName&type=' + encodeURIComponent(this.value)">
        $options
    </select>
    <p class="hint">$confidenceLabel: $confidence.</p>
</div>
HTML;
    }

    private function transliterationField(string $name, mixed $script, NameTransliterator $transliterator, string $uiLanguage): string
    {
        $value = $this->defaultTransliteration($name, $script, $transliterator);
        if ($value === '') {
            return '';
        }

        $safeValue = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeOriginal = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $label = htmlspecialchars($this->t($uiLanguage, 'transliteration'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $hint = htmlspecialchars($this->t($uiLanguage, 'transliteration_hint'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<div>
    <label for="label-transliteration">$label</label>
    <input id="label-transliteration" class="typeahead" name="label_transliteration" type="text" value="$safeValue" data-original-name="$safeOriginal" autocomplete="off">
    <p class="hint">$hint</p>
</div>
HTML;
    }

    private function defaultTransliteration(string $name, mixed $script, NameTransliterator $transliterator): string
    {
        if (!is_array($script) || ($script['script'] ?? '') === 'Latin' || ($script['script'] ?? '') === 'Han') {
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
    private function scriptSelect(array $analysis, string $uiLanguage): string
    {
        $script = $analysis['script'];
        $detected = is_array($script) ? $script['qid'] : '';
        $confidence = is_array($script) ? $script['confidence'] : 'required';
        $options = '';

        foreach (ScriptDetector::SCRIPTS as $meta) {
            $selected = $meta['qid'] === $detected ? ' selected' : '';
            $options .= '<option value="' . htmlspecialchars($meta['qid'], ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') . '</option>';
        }

        $writingSystem = htmlspecialchars($this->t($uiLanguage, 'writing_system'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $confidenceLabel = htmlspecialchars($this->t($uiLanguage, 'confidence'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $scriptWarning = htmlspecialchars($this->t($uiLanguage, 'script_warning'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<div>
    <label for="script">$writingSystem</label>
    <select id="script" name="script" required data-detected="$detected" onchange="warnScriptChange(this)">
        $options
    </select>
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
        $safeConfidence = htmlspecialchars($confidence, ENT_QUOTES, 'UTF-8');
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
    private function resolveLanguage(string $language, string $selectedType, array $analysis, array $preferredLanguages): string
    {
        if ($language !== '') {
            return $language;
        }

        foreach ($analysis['affixes'] ?? [] as $affix) {
            $code = is_array($affix) ? $this->languageCodeForAffix($affix, $preferredLanguages) : null;
            if ($code !== null) {
                return $code;
            }
        }

        $textLanguage = $this->languageCodeForTextAndScript((string) ($analysis['name'] ?? ''), $analysis['script'] ?? null, $selectedType);
        if ($textLanguage !== null) {
            return $textLanguage;
        }

        $scriptLanguage = $this->languageCodeForScript($analysis['script'] ?? null);
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
                return 'high';
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
        $script = is_array($analysis['script'] ?? null) ? (string) $analysis['script']['script'] : '';
        $textLanguage = $this->languageCodeForTextAndScript((string) ($analysis['name'] ?? ''), $analysis['script'] ?? null, (string) ($analysis['selectedType'] ?? ''));
        if ($textLanguage === 'uk') {
            return ['uk', 'ru', 'bg', 'sr', 'mk', 'be'];
        }
        if ($textLanguage === 'be') {
            return ['be', 'ru', 'uk', 'bg', 'sr', 'mk'];
        }
        if ($textLanguage === 'nl') {
            return ['nl', 'en', 'de', 'fr', 'fy'];
        }
        if ($textLanguage === 'sv') {
            return ['sv', 'da', 'no', 'fi', 'de'];
        }

        return match ($script) {
            'Cyrillic' => ['ru', 'uk', 'bg', 'sr', 'mk', 'be'],
            'Latin' => ['en', 'fr', 'de', 'es', 'it', 'nl', 'pt', 'pl', 'cs', 'sv', 'da', 'no', 'fi', 'hu', 'ro'],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function duplicates(array $analysis, string $uiLanguage): string
    {
        $createNewItem = htmlspecialchars($this->t($uiLanguage, 'create_new_item'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $alreadyExists = htmlspecialchars($this->t($uiLanguage, 'already_exists'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tryNotDuplicate = htmlspecialchars($this->t($uiLanguage, 'try_not_duplicate'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rows = '<tr><td colspan="2"><label class="check featured"><input type="radio" name="existing_item" value="" checked data-label="' . $createNewItem . '"><span><strong>' . $createNewItem . '</strong></span></label></td></tr>';
        foreach (array_slice($analysis['sameTypeMatches'], 0, 8) as $match) {
            $id = htmlspecialchars((string) $match['id'], ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars((string) $match['label'], ENT_QUOTES, 'UTF-8');
            $description = htmlspecialchars((string) $match['description'], ENT_QUOTES, 'UTF-8');
            $rows .= "<tr><td colspan=\"2\"><label class=\"check\"><input type=\"radio\" name=\"existing_item\" value=\"$id\" data-label=\"$label\"><span><strong>$label</strong> <a class=\"meta\" href=\"https://www.wikidata.org/wiki/$id\" target=\"_blank\" rel=\"noopener noreferrer\">($id)</a><br><span class=\"meta\">$description</span></span></label></td></tr>";
        }

        return <<<HTML
<section>
    <h2>$alreadyExists</h2>
    <p class="hint">$tryNotDuplicate</p>
    <table>$rows</table>
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

    /**
     * @param array<string, mixed> $analysis
     */
    private function checks(array $analysis, string $language, string $uiLanguage, string $displayLabel): string
    {
        $checks = '';
        $languages = $this->languages();
        $labelLanguage = $this->nativeLabelLanguage($language);

        foreach ($this->labelRows($analysis, $language, $uiLanguage, $displayLabel) as $labelRow) {
            $checks .= $labelRow;
        }
        $checks .= $this->nativeLabelRow((string) $analysis['name'], $labelLanguage, $uiLanguage);

        foreach ($analysis['claims'] as $claim) {
            $title = $this->propertyLabel($uiLanguage, $claim['property'], $claim['propertyLabel']);
            $detail = $claim['valueLabel'];
            $checks .= $this->staticRow($title, $detail);
        }

        if ($language !== '' && isset($languages[$language])) {
            $checks .= $this->check('claim_P407', $this->t($uiLanguage, 'language_of_name'), $languages[$language]['label'], true);
        }

        foreach ($this->affixChecks($analysis, $uiLanguage) as $affixCheck) {
            $checks .= $affixCheck;
        }

        $suggestions = '';
        foreach ($analysis['relationshipSuggestions'] as $suggestion) {
            $suggestions .= $this->relationshipCheck($suggestion, $uiLanguage);
        }

        if ($suggestions !== '') {
            $checks .= '<div class="subhead">' . htmlspecialchars($this->t($uiLanguage, 'suggestions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>' . $suggestions;
        }

        return $checks;
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

    /**
     * @param array<string, mixed> $analysis
     * @return list<string>
     */
    private function labelRows(array $analysis, string $language, string $uiLanguage, string $displayLabel): array
    {
        $name = (string) ($analysis['name'] ?? '');
        $scriptQid = is_array($analysis['script'] ?? null) ? (string) ($analysis['script']['qid'] ?? '') : '';
        $labelLanguages = ['mul' => $displayLabel];

        foreach ($this->languageCodesForScript($scriptQid) as $scriptLanguage) {
            $labelLanguages[$scriptLanguage] = $name;
        }

        $rows = [];
        foreach ($labelLanguages as $labelLanguage => $labelValue) {
            $rows[] = $this->labelRow($uiLanguage, $labelValue, $labelLanguage);
        }

        return $rows;
    }

    private function labelRow(string $uiLanguage, string $displayLabel, string $language): string
    {
        $safeTitle = htmlspecialchars($this->t($uiLanguage, 'label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeDisplayLabel = htmlspecialchars($displayLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLanguage = htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $previewAttribute = $language === 'mul' ? ' data-label-preview="display"' : '';

        return <<<HTML
<div class="check">
    <span></span>
    <span>
        <strong>$safeTitle</strong><br>
        <span class="meta" data-label-preview-language="$safeLanguage"$previewAttribute>$safeDisplayLabel ($safeLanguage)</span>
    </span>
</div>
HTML;
    }

    /**
     * @return list<string>
     */
    private function languageCodesForScript(string $scriptQid): array
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

    private function nativeLabelRow(string $name, string $language, string $uiLanguage): string
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLanguage = htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $nativeLabel = htmlspecialchars($this->t($uiLanguage, 'native_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<div class="check">
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
     * @param array{target: string, targetLabel: string, targetTypes?: list<string>, property: string, propertyLabel: string, value: string, reason: string} $suggestion
     */
    private function relationshipCheck(array $suggestion, string $uiLanguage): string
    {
        $id = 'related_' . $suggestion['target'] . '_' . $suggestion['property'];
        $safeId = htmlspecialchars(preg_replace('/[^a-z0-9_]+/i', '_', $id) ?? $id, ENT_QUOTES, 'UTF-8');
        $value = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        $propertyId = htmlspecialchars($suggestion['property'], ENT_QUOTES, 'UTF-8');
        $property = htmlspecialchars($this->propertyLabel($uiLanguage, $suggestion['property'], $suggestion['propertyLabel']), ENT_QUOTES, 'UTF-8');
        $target = htmlspecialchars($suggestion['target'], ENT_QUOTES, 'UTF-8');
        $targetLabel = htmlspecialchars($suggestion['targetLabel'], ENT_QUOTES, 'UTF-8');
        $targetTypes = htmlspecialchars(implode(', ', $suggestion['targetTypes'] ?? []), ENT_QUOTES, 'UTF-8');
        $typeText = $targetTypes !== '' ? '<br><span class="meta">' . $targetTypes . '</span>' : '';
        $checked = $suggestion['property'] === 'P5278' ? '' : ' checked';

        return <<<HTML
<label class="check" for="$safeId" data-suggestion-target="$target" data-property="$propertyId">
    <input id="$safeId" name="apply[]" value="$value" type="checkbox"$checked>
    <span>
        <strong>$property</strong><br>
        <span class="meta">$targetLabel <a href="https://www.wikidata.org/wiki/$target" target="_blank" rel="noopener noreferrer">($target)</a></span>$typeText
    </span>
</label>
HTML;
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
}
