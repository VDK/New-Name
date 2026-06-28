/**
 * Example NamePrefiller configuration
 *
 * Add this to [[Special:MyPage/common.js]]:
 *   importScript( 'User:1Veertje/NamePrefiller_config.js' );
 *   importScript( 'User:1Veertje/NamePrefiller.js' );
 *
 * Or write your own config: copy to
 * [[Special:MyPage/NamePrefiller_config.js]], edit, then add to
 * [[Special:MyPage/common.js]]:
 *   importScript( 'User:YOU/NamePrefiller_config.js' );
 *   importScript( 'User:1Veertje/NamePrefiller.js' );
 *
 * This config adds taxon-related name-prefill rules on top of the built-in
 * person-name rules (useDefaultRules: true).  It also demonstrates how to
 * add a custom mode — taxonAbbrev — so the main gadget script doesn't
 * need to be touched.
 */

if ( mw.NamePrefiller === undefined ) {
	mw.NamePrefiller = {};
}

/**
 * Custom modes: define a name → function lookup.  Each function receives
 * (label, index, propertyId, $valueview) and must return a string.
 *
 * Once registered, a custom mode can be used in any rule as
 * `fill: { PROPERTY: { mode: 'modeName' } }` exactly like a built-in mode.
 */
mw.NamePrefiller.customModes = {
	/**
	 * Abbreviate first word: "Abies alba" → "A. alba".
	 * Used for P1813 (short name) in botanical taxa.
	 */
	taxonAbbrev: function ( label ) {
		var parts = label.split( ' ' );

		if ( parts.length >= 2 ) {
			return parts[ 0 ].charAt( 0 ) + '. ' + parts.slice( 1 ).join( ' ' );
		}

		return '';
	}
};

mw.NamePrefiller.config = {
	/**
	 * Keep the built-in person-name rules active.
	 * Set to false if you only want your own rules.
	 */
	useDefaultRules: true,

	rules: [
		{
			name: 'species taxon',

			/**
			 * Condition: this rule fires only when all criteria match.
			 * Every field below is optional — omit what you don't need.
			 *
			 * instanceOf    – P31 must include this QID (or ALL of these QIDs if array)
			 * instanceOfAny – P31 must include at least one of these QIDs (array)
			 * notInstanceOf – P31 must NOT include this QID (or ANY of these if array)
			 * labelLang     – label in this language must exist
			 * labelContains – label must contain this substring
			 * wordCount     – label must have exactly this many words
			 */
			if: {
				instanceOf: 'Q16521',   // taxon
				labelLang: 'mul',       // scientific name
				wordCount: 2            // binomial like "Abies alba"
			},

			/**
			 * Fill: each property can use a mode, a fixed value, or both.
			 * Every property below is optional — fill only what you need.
			 *
			 * value     – fixed QID or string (no label parsing)
			 * mode      – built-in mode, custom mode name, or function(label,index,propId,$valueview)
			 * allowMultiple – let the mode return multiple values (boolean)
			 *
			 * Single-value modes (one string per property):
			 *   whole               – entire label
			 *   first / last        – shorthand for atIndex n:0 / n:-1
			 *   second / secondFromLast – shorthand for atIndex n:1 / n:-2
			 *   atIndex, n: N       – word at position N (negative = from end)
			 *   firstN, n: N        – first N words joined  e.g. "Abies alba"
			 *   lastN,  n: N        – last  N words joined  e.g. "alba nebrodensis"
			 *
			 * Multi-value modes (use with allowMultiple):
			 *   consecutive                   – each word, indexed forward
			 *     direction: 'reverse'         – each word, indexed backward
			 *     exclude: N                   – skip first N words
			 *   consecutiveFirstnames          – all words except last (prefix-aware)
			 *   consecutiveLastnames           – all words except first (prefix-aware)
			 *
			 * Name-segment modes (single value, prefix-aware):
			 *   lastname, secondLastname, hyphenPartsWithSeparator
			 */
			fill: {
				P1813: { mode: 'taxonAbbrev' },   // short name: "A. alba"
				P225: { mode: 'whole' },           // taxon name: full label
				P171: { mode: 'first' },           // parent taxon: first word
				P105: { value: 'Q7432' }           // rank: species (fixed value)
			}
		},
		{
			name: 'subspecies taxon',

			if: {
				instanceOf: 'Q16521',   // taxon
				labelLang: 'mul',
				wordCount: 3            // trinomial like "Abies alba nebrodensis"
			},
			fill: {
				P225: { mode: 'whole' },           // taxon name: full label
				P171: { mode: 'firstN', n: 2 },           // parent taxon: first two words
				P105: { value: 'Q68947' }          // rank: subspecies (fixed value)
			}
		}
	]
};