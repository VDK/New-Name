"""Build the complete transliteration-gadget.js from pywikibot transliteration.py.

Usage: python build_transliteration_js.py <path-to-transliteration.py> <output-js-path>
"""
import json
import re
import sys
import os

# Add the directory of this script to the path
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from convert_trans import main as parse_pywikibot


JS_HEADER = '''/**
 * Transliteration data extracted from pywikibot
 * Source: https://github.com/wikimedia/pywikibot/blob/master/pywikibot/userinterfaces/transliteration.py
 *
 * License: MIT (as pywikibot license)
 */
//<nowiki>
window.transliterateTool = (function () {
	'use strict';
	var dictionary = 
'''


JS_MIDDLE = ''';
	var hanguldict = ["g", "gg", "n", "d", "dd", "l", "m", "b", "bb", "s", "ss", "", "j", "jj", "ch", "k", "t", "p", "h", "a", "ae", "ya", "yae", "eo", "e", "yeo", "ye", "o", "wa", "wae", "oe", "yo", "u", "weo", "we", "wi", "yu", "eu", "ui", "i", "", "g", "gg", "gs", "n", "nj", "nh", "d", "l", "lg", "lm", "lb", "ls", "lt", "lp", "lh", "m", "b", "bs", "s", "ss", "ng", "j", "ch", "k", "t", "p", "h"];
	var nuktas = {"क": "qa", "ख": "k\\u1E0Dha", "ग": "\\u0121a", "ज": "za", "ड": "\\u1E5Ba", "ढ": "\\u1E5Bha", "फ": "fa", "य": "\\u1E47a", "ড": "\\u1E5Ba", "ঢ": "\\u1E5Bha", "য": "\\u1E47a", "ਖ": "k\\u1E0Dha", "ਗ": "\\u0121a", "ਜ": "za", "ਡ": "\\u1E5Ba", "ਫ": "fa", "ଡ": "\\u1E5Ba", "ଢ": "\\u1E5Bha", "ଯ": "\\u1E47a"};
	function transliterate(input, lang_in) {
		var i, array, charcode, choseong, jungseong, jongseong;
		if (Array.from && window.Symbol && window.Symbol.iterator) {
			array = Array.from(input[window.Symbol.iterator]());
		} else {
			array = input.split('');
		}
		for (i = 0; i < array.length; ++i) {
			// any language-specific handling should come before the block processing Hangul
			if (array[i] >= '\\uAC00' && array[i] <= '\\uD7A3') { // Hangul syllables
				charcode = array[i].charCodeAt(0) - 44032;
				choseong = hanguldict[parseInt(charcode / 588)];
				jungseong = hanguldict[19 + parseInt((charcode % 588) / 28)];
				jongseong = hanguldict[40 + parseInt((charcode % 588) % 28)];
				array[i] = choseong + jungseong + jongseong;
			} else if (dictionary[array[i]] && dictionary[array[i + 1]] && dictionary[array[i + 1]] === '\\u0323' ){ // combine nuktas
				if(nuktas[array[i]]) {
					array[i+1] = nuktas[array[i]];
					array[i] = '';
				}
			} else if (dictionary[array[i]] && dictionary[array[i]][0] === '\\u25CB' && array[i - 1]) { // delete preceding inherent a
				if(array[i - 1][array[i - 1].length - 1] === 'a'){
					array[i - 1] = array[i - 1].slice(0, -1);
					array[i] = dictionary[array[i]].slice(1);
				}
			} else {
				array[i] = dictionary[array[i]] || array[i];
			}
		}
		return array.join('');
	}
	
	return { transliterate: transliterate };
}());

mw.hook('wikipage.content').add(function () {
	'use strict';
	
	var end = $(\\'html\\').attr(\\'dir\\') === 'ltr' ? 'right' : 'left';
	
	function transliterateNodeGen(text, transliterated) {
		return $(\\'<abbr>\\', {
			dir: 'ltr',
			lang: 'en',
			title: 'approximate Latin transliteration of "' + text + '"',
			text: transliterated
		}).css({
			'font-family': 'sans-serif',
			'margin': '0 1em',
			'float': end
		});
	}
	
	$(\\'.wikibase-sitelinkview-link\\').each(function (i, x) {
		var $link = $(x).find(\\'.wikibase-sitelinkview-page\\'),
			text = $link.text(),
			langIn = $link.attr("lang"),
			transliterated = window.transliterateTool.transliterate(text, langIn),
			noteToAppend;
		if (text === transliterated) { return; }
		// return nothing if transliteration not made any change
		$link.after(transliterateNodeGen(text, transliterated));
	});
	
	$(\\'tr > td.diff-lineno:first\\').filter(function (x) { 
		return (/.* \\/ .* \\/ .*/).test($(this).text());
	}).each(function (i, x) {
		$(x).parent().next().find(\\'.diffchange\\').each(function (i, y) {
			var text = $(y).text(),
				langIn = $(y).children().attr("hreflang"),
				transliterated = window.transliterateTool.transliterate(text, langIn);
			if (text === transliterated) { return; }
			$(y).after(transliterateNodeGen(text, transliterated));
		});
	});
});
//</nowiki>
'''


def format_js_dict(data: dict) -> str:
    """Format a Python dict as a JavaScript object literal string."""
    items = []
    for key, value in data.items():
        # JSON-encode key and value
        k = json.dumps(key, ensure_ascii=False)
        v = json.dumps(value, ensure_ascii=False)
        items.append(f'\t{k}: {v}')
    return '{\n' + ',\n'.join(items) + '\n}'


def main():
    if len(sys.argv) < 3:
        print(f'Usage: {sys.argv[0]} <path-to-transliteration.py> <output-js-path>', file=sys.stderr)
        sys.exit(1)
    
    pywikibot_path = sys.argv[1]
    output_path = sys.argv[2]
    
    # Parse the pywikibot dictionary
    trans = parse_pywikibot(pywikibot_path)
    print(f'Parsed {len(trans)} entries from pywikibot', file=sys.stderr)
    
    # Format as JS object
    js_dict = format_js_dict(trans)
    
    # Assemble the complete JS file
    js_content = JS_HEADER + js_dict + JS_MIDDLE
    
    with open(output_path, 'w', encoding='utf-8') as f:
        f.write(js_content)
    
    print(f'Written {len(js_content)} bytes to {output_path}', file=sys.stderr)


if __name__ == '__main__':
    main()
