<?php

namespace App\Controller;

use App\Data\LanguageHeuristics;
use App\Service\PersonNameEditService;
use App\Service\OAuthAuthorizationRequired;
use App\Service\NameFlowState;
use App\Service\UniversalNameSearch;
use App\Service\WikidataClient;
use App\Service\WikimediaOAuthClient;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PersonNameController
{
    #[Route('/api/name-items', name: 'name_item_search', methods: ['GET'])]
    public function searchNameItems(Request $request, WikidataClient $wikidataClient): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        $language = $this->interfaceLanguage((string) $request->query->get('ui', 'en'));
        if ($query === '') {
            return $this->noStoreJson([]);
        }

        if (preg_match('/^Q\d+$/i', $query)) {
            $item = $wikidataClient->nameItem(strtoupper($query));

            return $this->noStoreJson($item ? [$item] : []);
        }

        return $this->noStoreJson($wikidataClient->searchNameItems($query, $language));
    }

    #[Route('/api/people', name: 'person_match_search', methods: ['GET'])]
    public function searchPeople(Request $request, WikidataClient $wikidataClient): JsonResponse
    {
        $nameItemId = strtoupper(trim((string) $request->query->get('name_item', '')));
        $query = trim((string) $request->query->get('q', ''));
        $offset = max(0, (int) $request->query->get('offset', 0));
        $excluded = array_values(array_filter(
            explode(',', (string) $request->query->get('exclude', '')),
            static fn (string $id): bool => preg_match('/^Q\d+$/', $id) === 1
        ));
        $nameItem = $wikidataClient->nameItem($nameItemId);
        if (!$nameItem || $query === '') {
            return $this->noStoreJson(['matches' => [], 'nextOffset' => $offset, 'hasMore' => false]);
        }

        $page = $wikidataClient->personMatchesPage(
            $query,
            $nameItem['label'],
            $nameItem['script'],
            $nameItem['languageCode'],
            $nameItemId,
            $nameItem['type'],
            12,
            $offset,
            $excluded,
            $nameItem['equivalents'],
            $nameItem['sameAs']
        );

        return $this->noStoreJson([
            'matches' => $page['matches'],
            'nextOffset' => $page['nextOffset'],
            'hasMore' => $page['hasMore'],
            'personOptions' => $this->personOptions($nameItem),
            'personActions' => $this->personActionButtons($nameItem),
        ]);
    }

    #[Route('/people', name: 'person_name', methods: ['GET'])]
    public function index(Request $request, WikidataClient $wikidataClient): Response
    {
        $nameItemId = strtoupper(trim((string) $request->query->get('name_item', '')));
        $query = trim((string) $request->query->get('person_query', ''));
        $status = (string) $request->query->get('status', '');
        $personId = strtoupper(trim((string) $request->query->get('person', '')));
        $nameItem = $nameItemId !== '' ? $wikidataClient->nameItem($nameItemId) : null;
        $page = $nameItem && $query !== ''
            ? $wikidataClient->personMatchesPage(
                $query,
                $nameItem['label'],
                $nameItem['script'],
                $nameItem['languageCode'],
                $nameItemId,
                $nameItem['type'],
                12,
                0,
                [],
                $nameItem['equivalents'],
                $nameItem['sameAs']
            )
            : ['matches' => [], 'nextOffset' => 0, 'hasMore' => false];

        $response = new Response($this->page($request, $nameItemId, $nameItem, $query, $page['matches'], $page['nextOffset'], $page['hasMore'], $status, $personId));
        $response->headers->set('Cache-Control', 'no-store, max-age=0');

        return $response;
    }

    #[Route('/people/save', name: 'person_name_save', methods: ['POST'])]
    public function save(
        Request $request,
        WikimediaOAuthClient $oauthClient,
        WikidataClient $wikidataClient,
        PersonNameEditService $editService,
        UrlGeneratorInterface $urlGenerator,
    ): RedirectResponse|Response {
        $nameItemId = strtoupper(trim((string) $request->request->get('name_item', '')));
        $personId = strtoupper(trim((string) $request->request->get('person', '')));
        $property = (string) $request->request->get('property', '');
        $ordinal = trim((string) $request->request->get('ordinal', ''));
        $action = (string) $request->request->get('edit_action', 'add');
        $query = trim((string) $request->request->get('person_query', ''));
        $ui = (string) $request->request->get('ui', 'en');
        $nameItem = $wikidataClient->nameItem($nameItemId);

        $ajax = (string) $request->request->get('ajax', '') === '1';
        if (!$nameItem || !preg_match('/^Q\d+$/', $personId)) {
            if ($ajax) {
                return new JsonResponse(['error' => 'Invalid name or person item.'], 400);
            }
            return new Response('Invalid name or person item.', 400);
        }
        $allowed = $this->allowedProperties($nameItem);
        if (!in_array($property, $allowed, true)) {
            if ($ajax) {
                return new JsonResponse(['error' => 'Property does not match the name type.'], 400);
            }
            return new Response('Property does not match the name type.', 400);
        }

        $returnParams = [
            'name_item' => $nameItemId,
            'person_query' => $query,
            'ui' => $ui,
        ];
        if (!$oauthClient->isAuthorized()) {
            $return = $urlGenerator->generate('person_name', $returnParams);
            $login = $urlGenerator->generate('oauth_login', ['return' => $return]);
            if ($ajax) {
                return new JsonResponse(['login' => $login], 401);
            }

            return new RedirectResponse($login);
        }

        try {
            $result = $action === 'remove'
                ? $editService->remove($personId, $nameItemId, $property, $ordinal !== '' ? $ordinal : null)
                : $editService->add($personId, $nameItemId, $property, $ordinal !== '' ? $ordinal : null);
        } catch (OAuthAuthorizationRequired) {
            $returnParams['auth'] = 'expired';
            $return = $urlGenerator->generate('person_name', $returnParams);
            $login = $urlGenerator->generate('oauth_login', ['return' => $return]);
            if ($ajax) {
                return new JsonResponse(['login' => $login], 401);
            }

            return new RedirectResponse($login);
        } catch (\Throwable $e) {
            if ($ajax) {
                return new JsonResponse(['error' => $e->getMessage()], 502);
            }
            return new Response(
                '<!doctype html><meta charset="utf-8"><title>Wikidata edit failed</title><p>' .
                htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>',
                502
            );
        }

        if ($ajax) {
            return $this->noStoreJson($result + ['action' => $action]);
        }

        $returnParams['status'] = $result['changed'] ? 'changed' : 'exists';
        $returnParams['person'] = $personId;

        return new RedirectResponse($urlGenerator->generate('person_name', $returnParams));
    }

    /**
     * @param array{id: string, label: string, description: string, type: string, script: string, languageCode: string}|null $nameItem
     * @param list<array{id: string, label: string, description: string, matchedLabel: string}> $matches
     */
    private function page(Request $request, string $nameItemId, ?array $nameItem, string $query, array $matches, int $nextOffset, bool $hasMore, string $status, string $personId): string
    {
        $base = htmlspecialchars($request->getBaseUrl(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $uiLanguage = $this->interfaceLanguage((string) $request->query->get('ui', 'en'));
        $safeNameItemId = htmlspecialchars($nameItemId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeQuery = htmlspecialchars($query, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $ui = htmlspecialchars($uiLanguage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tagline = match ($uiLanguage) {
            'nl' => 'Maak Wikidata-items<span class="tagline-break"><br></span> voor voor-&nbsp;en&nbsp;achternamen.',
            'de' => 'Neue Wikidata-Items<span class="tagline-break"><br></span> für Vor-&nbsp;und&nbsp;Nachnamen erstellen.',
            'fr' => 'Créer des éléments Wikidata<span class="tagline-break"><br></span> pour les prénoms et les noms de famille.',
            'es' => 'Crear elementos de Wikidata<span class="tagline-break"><br></span> para nombres de pila y apellidos.',
            default => 'Create Wikidata items<span class="tagline-break"><br></span> for given and family names.',
        };
        $labels = [
            'en' => ['name' => 'Name', 'create' => 'Create', 'analyze' => 'Analyze', 'update' => 'Update', 'match' => 'Match', 'possible' => 'This looks like a person’s full name.', 'confirm' => 'I confirm that this is a given or family name.'],
            'nl' => ['name' => 'Naam', 'create' => 'Aanmaken', 'analyze' => 'Analyseren', 'update' => 'Bijwerken', 'match' => 'Koppelen', 'possible' => 'Dit lijkt op de volledige naam van een persoon.', 'confirm' => 'Ik bevestig dat dit een voor- of achternaam is.'],
            'de' => ['name' => 'Name', 'create' => 'Erstellen', 'analyze' => 'Analysieren', 'update' => 'Aktualisieren', 'match' => 'Zuordnen', 'possible' => 'Dies sieht wie der vollständige Name einer Person aus.', 'confirm' => 'Ich bestätige, dass dies ein Vor- oder Nachname ist.'],
            'fr' => ['name' => 'Nom', 'create' => 'Créer', 'analyze' => 'Analyser', 'update' => 'Mettre à jour', 'match' => 'Associer', 'possible' => 'Cela ressemble au nom complet d’une personne.', 'confirm' => 'Je confirme qu’il s’agit d’un prénom ou d’un nom de famille.'],
            'es' => ['name' => 'Nombre', 'create' => 'Crear', 'analyze' => 'Analizar', 'update' => 'Actualizar', 'match' => 'Vincular', 'possible' => 'Esto parece el nombre completo de una persona.', 'confirm' => 'Confirmo que es un nombre de pila o un apellido.'],
        ][$uiLanguage];
        $nameAffixes = json_encode($this->nameAffixes(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $message = '';
        if ($status === 'changed' && preg_match('/^Q\d+$/', $personId)) {
            $safePersonId = htmlspecialchars($personId, ENT_QUOTES, 'UTF-8');
            $message = '<p class="success">Changed at <a href="https://www.wikidata.org/wiki/' . $safePersonId . '">' . $safePersonId . '</a>.</p>';
        } elseif ($status === 'exists' && preg_match('/^Q\d+$/', $personId)) {
            $safePersonId = htmlspecialchars($personId, ENT_QUOTES, 'UTF-8');
            $message = '<p class="notice">This name statement already exists on <a href="https://www.wikidata.org/wiki/' . $safePersonId . '">' . $safePersonId . '</a>.</p>';
        }

        $results = '';
        $selectedItemBlock = '';
        if ($nameItem) {
            $safeLabel = htmlspecialchars($nameItem['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $typeLabel = $nameItem['type'] === 'given' ? 'given name' : 'family name';
            $safeDescription = htmlspecialchars($nameItem['description'] !== '' ? $nameItem['description'] : $typeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $equivalentBlock = '';
            foreach ($nameItem['equivalents'] as $equivalent) {
                $equivalentId = htmlspecialchars($equivalent['id'], ENT_QUOTES, 'UTF-8');
                $equivalentLabel = htmlspecialchars($equivalent['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $equivalentType = $equivalent['type'] === 'given' ? 'Given name equivalent' : 'Family name equivalent';
                $equivalentDescription = htmlspecialchars(
                    (string) ($equivalent['description'] ?? ($equivalent['type'] === 'given' ? 'given name' : 'family name')),
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
                $equivalentBlock .= '<div class="equivalent-item"><h3>' . $equivalentType . '</h3><div class="selected-item-card"><div class="selected-item-details"><strong>' . $equivalentLabel . '</strong><span class="muted">' . $equivalentDescription . '</span>' . $equivalentDetails . '<a href="https://www.wikidata.org/wiki/' . $equivalentId . '">' . $equivalentId . '</a></div></div></div>';
            }
            $selectedItemBlock = <<<HTML
<section class="selected-item-section">
    <h2>Selected item</h2>
    <div class="selected-item-card">
        <div class="selected-item-details">
            <strong>$safeLabel</strong>
            <span class="muted">$safeDescription</span>
            <a href="https://www.wikidata.org/wiki/$safeNameItemId">$safeNameItemId</a>
        </div>
    </div>
    $equivalentBlock
</section>
HTML;
            foreach ($matches as $match) {
                $id = htmlspecialchars($match['id'], ENT_QUOTES, 'UTF-8');
                $label = htmlspecialchars($match['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $description = htmlspecialchars($match['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $matchedLabel = htmlspecialchars($match['matchedLabel'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $personOptions = $this->personOptions($nameItem);
                $personActions = $this->personActionButtons($nameItem);
                $personActionClass = substr_count($personActions, '<button') > 1 ? ' person-actions has-equivalent' : ' person-actions';
                $detail = $description !== '' ? '<br><span class="muted">' . $description . '</span>' : '';
                $scriptMatch = $matchedLabel !== $label ? '<br><span class="muted">Matched label: ' . $matchedLabel . '</span>' : '';
                $results .= <<<HTML
<form class="person person-match" method="post" action="$base/people/save" data-person-id="$id">
    <input type="hidden" name="person" value="$id">
    <input type="hidden" name="person_query" value="$safeQuery">
    <input type="hidden" name="ui" value="$ui">
    <input type="hidden" name="ajax" value="1">
    <div><strong>$label</strong> <a href="https://www.wikidata.org/wiki/$id">($id)</a>$detail$scriptMatch</div>
    $personOptions
    <div class="$personActionClass">$personActions</div>
</form>
HTML;
            }
            if ($query !== '' && $results === '') {
                $results = '<p class="notice">No potential matches found.</p>';
            }
        }

        $hasBothNameTypes = $nameItem && $this->hasBothNameTypes($nameItem);
        $resultHeading = $nameItem
            ? ($hasBothNameTypes
                ? 'Match people to these names'
                : 'Match people to this ' . ($nameItem['type'] === 'given' ? 'given name' : 'family name'))
            : '';
        $loadMore = $hasMore ? '<button id="load-more-people" class="load-more" type="button">Load more people</button>' : '';
        $resultBlock = $nameItem
            ? '<section id="people-results" data-next-offset="' . $nextOffset . '"><h2>' . $resultHeading . '</h2><div id="people-list">' . $results . '</div>' . $loadMore . '</section>'
            : '';
        $invalid = !$nameItem && $nameItemId !== '' ? '<p class="notice">Select a Wikidata item for a given or family name.</p>' : '';
        $universalSearch = UniversalNameSearch::render([
            'action' => $request->getBaseUrl() . '/',
            'ui' => $uiLanguage,
            'label' => $labels['name'],
            'inputId' => 'name-item-search',
            'suggestionsId' => 'name-item-suggestions',
            'itemId' => 'name-item-value',
            'typeId' => 'name-item-type',
            'analyzeId' => 'people-analyze',
            'actionsId' => 'people-match-actions',
            'matchId' => 'people-match',
            'createLabel' => $labels['create'],
            'progressLabel' => $labels['analyze'],
            'updateLabel' => $labels['update'],
            'matchLabel' => $labels['match'],
            'formId' => 'people-name-search',
            'wrapperClass' => 'suggest-wrap',
            'suggestionsClass' => 'suggestions',
            'selectionActive' => false,
        ], NameFlowState::MATCH);

        return <<<HTML
<!doctype html>
<html lang="$ui">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>New Name</title>
<style>
body{margin:0;background:#f8f9fa;color:#202122;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
main{width:min(720px,calc(100% - 32px));margin:40px auto;display:grid;gap:16px}
.brand{text-align:center;margin-bottom:6px}.brand h1{margin:0 0 8px;font-size:44px;font-weight:400}.brand p{margin:0;color:#54595d}.tagline-break{display:none}
section{background:#fff;border:1px solid #c8ccd1;padding:18px}h2{margin:0 0 12px}
.search-card>label{display:block;margin-bottom:6px;font-weight:700}.search{display:grid;grid-template-columns:1fr;gap:8px}.search input,.ordinal{width:100%;box-sizing:border-box;min-height:44px;padding:6px 9px;border:1px solid #a2a9b1;font-size:16px}
.search-actions{display:flex;justify-content:flex-end;gap:8px;width:100%}.search-actions>button,.search-actions>.search-actions.is-split{width:100%}.search-actions.is-split>button{flex:1 1 50%;min-width:0}
.full-name-confirmation{display:none;margin:0;padding:10px 12px;border-left:4px solid #fc3;background:#fef6e7;font-size:14px;line-height:1.4}.full-name-confirmation label{display:flex;gap:8px;align-items:flex-start;font-weight:400}.search .full-name-confirmation input{flex:0 0 auto;width:18px;min-width:18px;height:18px;margin:1px 0 0;padding:0;box-shadow:none}
.search-button-spinner{display:none;width:16px;height:16px;margin-right:7px;border:2px solid rgba(255,255,255,.55);border-top-color:#fff;border-radius:50%;animation:spin .75s linear infinite}.search-progress-label{display:none}#people-analyze.is-searching .search-button-spinner{display:inline-block}#people-analyze.is-searching .search-default-label{display:none}#people-analyze.is-searching .search-progress-label{display:inline}
button{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:8px 14px;border:1px solid #36c;border-radius:2px;background:#36c;color:#fff;font-weight:700;text-align:center;cursor:pointer;white-space:nowrap}
.load-more{margin-top:12px;border-color:#72777d;background:#fff;color:#202122;border-radius:2px}.load-more:hover{background:#eaecf0}
.equivalent-item{margin-top:16px}.equivalent-item h3{margin:0 0 8px;font-size:16px}.person{display:grid;gap:10px;padding:14px 0;border-top:1px solid #c8ccd1}.person:first-child{border-top:0}
.selected-item-card{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px;border:1px solid #c8ccd1;background:#fff}.selected-item-details{min-width:0}.selected-item-card .muted{display:block;margin-top:3px}.selected-item-card .item-detail{display:block;margin-top:5px;font-size:13px}.selected-item-card .item-detail b{color:#54595d;margin-right:5px}.selected-item-card a{display:block;width:fit-content;margin-top:5px}
.options{display:flex;flex-wrap:wrap;gap:10px}.muted{color:#54595d;font-size:13px}.notice{background:#f8f9fa;padding:10px}.success{border-left:4px solid #14866d;padding:10px;background:#edfaf7}
.person-actions{display:flex;gap:8px}.person-actions button{flex:1 1 100%;min-width:0;min-height:40px;padding:6px 10px}.person-actions.has-equivalent button{flex-basis:50%}
.button-spinner{display:none;width:14px;height:14px;margin-right:6px;border:2px solid rgba(255,255,255,.55);border-top-color:#fff;border-radius:50%;animation:spin .75s linear infinite}.add-name.is-loading .button-spinner{display:inline-block}.add-name.is-success{border-color:#14866d;background:#14866d}.add-name.is-present,.add-name.is-present:hover{border-color:#a2a9b1;background:#eaecf0;color:#54595d;cursor:not-allowed}.add-name.is-failure{border-color:#b32424;background:#b32424}
@keyframes spin{to{transform:rotate(360deg)}}
.suggest-wrap{position:relative}.suggestions{display:none;position:absolute;z-index:20;top:calc(100% + 2px);left:0;right:0;max-height:280px;overflow:auto;margin:0;padding:4px 0;list-style:none;border:1px solid #a2a9b1;background:#fff;box-shadow:0 2px 6px rgba(0,0,0,.15)}
.suggestions li{display:flex;flex-direction:column;gap:2px;align-items:stretch;padding:8px 10px;cursor:pointer;border-top:1px solid #a2a9b1}.suggestions li:first-child{border-top:0}.suggestions li:hover,.suggestions li.active{background:#eaecf0}.suggestions strong{display:block}.suggestions .muted{display:block}
a{color:#36c;text-decoration:none}@media(max-width:620px){.suggestions{position:static;margin-top:2px}.person button{width:100%}}@media(max-width:480px){.tagline-break{display:inline}}
</style>
</head>
<body><main>
<div class="brand"><h1>New Name</h1><p>$tagline</p></div>
$universalSearch
$selectedItemBlock$invalid$message$resultBlock
</main>
<script>
(function () {
    var input = document.getElementById('name-item-search');
    var value = document.getElementById('name-item-value');
    var type = document.getElementById('name-item-type');
    var list = document.getElementById('name-item-suggestions');
    var analyze = document.getElementById('people-analyze');
    var actions = document.getElementById('people-match-actions');
    var match = document.getElementById('people-match');
    if (!input || !value || !type || !list || !analyze || !actions || !match) return;
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

    function select(item) {
        input.value = item.label;
        value.value = item.id;
        type.value = item.type;
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
        items.forEach(function (item, index) {
            var option = document.createElement('li');
            option.setAttribute('role', 'option');
            var title = document.createElement('strong');
            title.textContent = item.label;
            var detail = document.createElement('span');
            detail.className = 'muted';
            var typeLabel = item.type === 'given' ? 'given name' : 'family name';
            detail.textContent = item.description
                || (item.label.toLowerCase().indexOf(typeLabel) === -1 ? typeLabel : '');
            option.appendChild(title);
            option.appendChild(detail);
            option.addEventListener('click', function (event) {
                event.preventDefault();
                select(item);
            });
            option.addEventListener('mouseenter', function () { active = index; });
            list.appendChild(option);
        });
        list.style.display = 'block';
        input.setAttribute('aria-expanded', 'true');
    }

    input.addEventListener('input', function () {
        value.value = '';
        type.value = '';
        analyze.style.display = 'inline-flex';
        actions.style.display = 'none';
        clearTimeout(timer);
        if (input.value.trim().length < 2) {
            searchPending = false;
            analyze.disabled = false;
            analyze.classList.remove('is-searching');
            close();
            return;
        }
        searchPending = true;
        analyze.disabled = true;
        analyze.classList.add('is-searching');
        timer = setTimeout(function () {
            fetch('$base/api/name-items?q=' + encodeURIComponent(input.value.trim()) + '&ui=' + encodeURIComponent('$ui'))
                .then(function (response) {
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
    input.addEventListener('keydown', function (event) {
        if (!items.length) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            active = event.key === 'ArrowDown'
                ? (active + 1) % items.length
                : (active <= 0 ? items.length - 1 : active - 1);
            Array.prototype.forEach.call(list.children, function (child, index) {
                child.classList.toggle('active', index === active);
            });
        } else if (event.key === 'Enter' && active >= 0) {
            event.preventDefault();
            select(items[active]);
        } else if (event.key === 'Escape') {
            close();
        }
    });
    input.form.addEventListener('submit', function () {
        if (value.value) return;
        close();
        analyze.classList.add('is-searching');
        analyze.disabled = true;
    });
    match.addEventListener('click', function () {
        if (!value.value) return;
        window.location = '$base/people?name_item=' + encodeURIComponent(value.value)
            + '&person_query=' + encodeURIComponent(input.value.trim())
            + '&ui=' + encodeURIComponent('$ui');
    });
    document.addEventListener('click', function (event) {
        if (!input.parentElement.contains(event.target)) close();
    });
}());

function wirePersonForm(form) {
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
                add.title = error.message;
            }
            if (label) label.textContent = 'Failed';
        });
    });
}
document.querySelectorAll('.person-match').forEach(function(form) {
    wirePersonForm(form);
});

(function () {
    var button = document.getElementById('load-more-people');
    var section = document.getElementById('people-results');
    var list = document.getElementById('people-list');
    if (!button || !section || !list || !'$safeNameItemId') return;
    var loading = false;

    var personOptions = '';
    var personActions = '';
    function appendPerson(person) {
        if (document.querySelector('[data-person-id="' + person.id + '"]')) return;
        var form = document.createElement('form');
        var notice = list.querySelector('.notice');
        if (notice) notice.remove();
        form.className = 'person person-match';
        form.method = 'post';
        form.action = '$base/people/save';
        form.dataset.personId = person.id;
        form.innerHTML =
            '<input type="hidden" name="person" value="' + person.id + '">' +
            '<input type="hidden" name="person_query" value="$safeQuery">' +
            '<input type="hidden" name="ui" value="$ui">' +
            '<input type="hidden" name="ajax" value="1">';
        var identity = document.createElement('div');
        var strong = document.createElement('strong');
        strong.textContent = person.label;
        var link = document.createElement('a');
        link.href = 'https://www.wikidata.org/wiki/' + person.id;
        link.textContent = ' (' + person.id + ')';
        identity.appendChild(strong);
        identity.appendChild(link);
        if (person.description) {
            identity.appendChild(document.createElement('br'));
            var description = document.createElement('span');
            description.className = 'muted';
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
        wirePersonForm(form);
    }

    button.addEventListener('click', function () {
        if (loading) return;
        loading = true;
        button.disabled = true;
        button.textContent = 'Loading...';
        var offset = parseInt(section.dataset.nextOffset || '0', 10);
        fetch('$base/api/people?name_item=$safeNameItemId&q=' + encodeURIComponent('$safeQuery')
            + '&ui=' + encodeURIComponent('$ui')
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
                section.dataset.nextOffset = String(data.nextOffset || offset + 36);
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

    /**
     * @param array{id: string, label: string, type: string, script: string, languageCode: string} $nameItem
     * @return list<string>
     */
    private function allowedProperties(array $nameItem): array
    {
        if ($nameItem['type'] === 'given') {
            return ['P735'];
        }

        return match ($nameItem['languageCode']) {
            'es' => ['P734', 'P1950'],
            'pt' => ['P734', 'P9139'],
            default => ['P734'],
        };
    }

    /**
     * @param array{id: string, label: string, type: string, script: string, languageCode: string} $nameItem
     */
    private function personOptions(array $nameItem): string
    {
        $hasGivenName = $nameItem['type'] === 'given';
        foreach ($nameItem['equivalents'] ?? [] as $equivalent) {
            $hasGivenName = $hasGivenName || ($equivalent['type'] ?? '') === 'given';
        }

        return $hasGivenName
            ? '<div class="options"><label>Series ordinal <span class="muted">(optional)</span><input class="ordinal" name="ordinal" inputmode="numeric" pattern="[1-9][0-9]*"></label></div>'
            : '';
    }

    private function personActionButtons(array $nameItem): string
    {
        $actions = [
            $nameItem['type'] => [
                'id' => (string) $nameItem['id'],
                'type' => (string) $nameItem['type'],
            ],
        ];
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

    private function hasBothNameTypes(array $nameItem): bool
    {
        $types = [(string) ($nameItem['type'] ?? '') => true];
        foreach ($nameItem['equivalents'] ?? [] as $equivalent) {
            $types[(string) ($equivalent['type'] ?? '')] = true;
        }

        return isset($types['given'], $types['family']);
    }

    private function interfaceLanguage(string $language): string
    {
        return in_array($language, ['en', 'nl', 'de', 'fr', 'es'], true) ? $language : 'en';
    }

    private function noStoreJson(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store, max-age=0']);
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
