(function () {
    'use strict';

    if (mw.config.get('wgNamespaceNumber') !== 0) return;
    var qid = mw.config.get('wgTitle');

    function isHuman() {
        var stmts = document.querySelectorAll(
            '.wikibase-statementview[property="P31"] .wikibase-snakview-value a'
        );
        for (var i = 0; i < stmts.length; i++) {
            if (stmts[i].getAttribute('href') && stmts[i].getAttribute('href').indexOf('Q5') !== -1) return true;
        }
        return false;
    }

    function inject() {
        if (!isHuman()) return;
        if (document.querySelector('.wikibase-statementview[property="P214"]')) return;

        var groups = document.querySelectorAll('.wikibase-statementgroupview');
        var identifiers = null;
        for (var i = groups.length - 1; i >= 0; i--) {
            if (groups[i].querySelector('.wikibase-statementgroupview-property[property*="identifier"]')) {
                identifiers = groups[i];
                break;
            }
        }
        if (!identifiers) return;

        var label = '';
        var titleEl = document.querySelector('.wikibase-title-label');
        if (titleEl) label = titleEl.textContent.trim();
        if (!label) label = qid;

        var searchUrl = 'https://viaf.org/search?query=' + encodeURIComponent(label);

        var div = document.createElement('div');
        div.className = 'wikibase-statementgroupview viaf-skeleton';
        div.innerHTML =
            '<div class="wikibase-statementgroupview-property" property="P214">' +
                '<a title="Property:P214" href="/wiki/Property:P214">VIAF cluster ID</a>' +
            '</div>' +
            '<div class="wikibase-statementgroupview-statementgroup">' +
                '<div class="wikibase-statementview" property="P214">' +
                    '<div class="wikibase-statementview-container">' +
                        '<div class="wikibase-snakview">' +
                            '<div class="wikibase-snakview-body">' +
                                '<a class="viaf-search-link" href="' + searchUrl + '" target="_blank">' +
                                    'Search VIAF for ' + label +
                                '</a>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<' + 'div class="wikibase-statementview-rankselector">' +
                        '<span class="wikibase-rankselector wikibase-rankselector-preferred"></span>' +
                    '</div>' +
                '</div>' +
            '</div>';

        var list = identifiers.querySelector('.wikibase-statementlistview');
        if (list) {
            list.insertBefore(div, list.firstChild);
        } else {
            identifiers.parentNode.insertBefore(div, identifiers);
        }
    }

    $(inject);
    setTimeout(inject, 2000);
})();
