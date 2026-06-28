<?php

namespace App\Service;

final class UniversalNameSearch
{
    /**
     * @param array{
     *     action: string,
     *     ui: string,
     *     label: string,
     *     inputId: string,
     *     suggestionsId: string,
     *     itemId: string,
     *     typeId: string,
     *     analyzeId: string,
     *     actionsId: string,
     *     matchId: string,
     *     createLabel: string,
     *     progressLabel: string,
     *     updateLabel: string,
     *     matchLabel: string,
     *     value?: string,
     *     autofocus?: bool,
     *     formId?: string,
     *     formClass?: string,
     *     wrapperClass?: string,
     *     suggestionsClass?: string,
     *     selectionActive?: bool,
     *     itemValue?: string,
     *     typeValue?: string,
     *     disabled?: bool,
     *     readonly?: bool
     * } $options
     */
    public static function render(array $options, NameFlowState $state = NameFlowState::SEARCH): string
    {
        $action = self::escape($options['action']);
        $ui = self::escape($options['ui']);
        $label = self::escape($options['label']);
        $inputId = self::escape($options['inputId']);
        $suggestionsId = self::escape($options['suggestionsId']);
        $itemId = self::escape($options['itemId']);
        $typeId = self::escape($options['typeId']);
        $analyzeId = self::escape($options['analyzeId']);
        $actionsId = self::escape($options['actionsId']);
        $matchId = self::escape($options['matchId']);
        $createLabel = self::escape($options['createLabel']);
        $progressLabel = self::escape($options['progressLabel']);
        $updateLabel = self::escape($options['updateLabel']);
        $matchLabel = self::escape($options['matchLabel']);
        $value = self::escape($options['value'] ?? '');
        $itemValue = self::escape($options['itemValue'] ?? '');
        $typeValue = self::escape($options['typeValue'] ?? '');
        $formId = isset($options['formId']) ? ' id="' . self::escape($options['formId']) . '"' : '';
        $formClass = self::escape($options['formClass'] ?? 'search');
        $wrapperClass = self::escape($options['wrapperClass'] ?? 'name-search-wrap');
        $suggestionsClass = self::escape($options['suggestionsClass'] ?? 'name-suggestions');
        $autofocus = !empty($options['autofocus']) ? ' autofocus' : '';
        $disabled = !empty($options['disabled']) ? ' disabled' : '';
        $readonly = !empty($options['readonly']) ? ' readonly' : '';
        $selected = $options['selectionActive'] ?? ($state === NameFlowState::UPDATE);
        $analyzeDisplay = $selected ? 'none' : 'inline-flex';
        $actionsDisplay = $selected ? 'flex' : 'none';

        return <<<HTML
<div class="search-card" data-name-flow-state="{$state->value}">
    <label class="search-label" for="$inputId">$label</label>
    <form$formId class="$formClass" method="get" action="$action" autocomplete="off">
        <input type="hidden" name="ui" value="$ui">
        <input id="$itemId" name="existing_item" type="hidden" value="$itemValue">
        <input id="$typeId" name="type" type="hidden" value="$typeValue">
        <div class="$wrapperClass">
            <input id="$inputId" name="name" type="text" value="$value" autocomplete="off"$autofocus$readonly required role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="$suggestionsId">
            <ul id="$suggestionsId" class="$suggestionsClass" role="listbox"></ul>
        </div>
        <div class="search-actions">
            <button id="$analyzeId" type="submit" style="display:$analyzeDisplay"$disabled><span class="search-button-spinner" aria-hidden="true"></span><span class="search-default-label">$createLabel</span><span class="search-progress-label">$progressLabel</span></button>
            <span id="$actionsId" class="search-actions is-split" style="display:$actionsDisplay">
                <button type="submit"$disabled>$updateLabel</button>
                <button id="$matchId" type="button"$disabled>$matchLabel</button>
            </span>
        </div>
    </form>
</div>
HTML;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
