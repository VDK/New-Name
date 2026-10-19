/**
 * NamePrefiller
 *
 * Gadget to automatically fill statement value fields based on item labels.
 *
 * Supports configurable rules, for example for people and taxa.
 *
 * Rules can use built-in modes or custom modes defined via
 * mw.NamePrefiller.customModes (string → function lookup) or by
 * passing a function directly as `fill[property].mode`.
 *
 * Qualifiers are prefilled as well: series ordinal (P1545), and for
 * author name string (P2093) statements the author given names (P9687)
 * and author last names (P9688), split from the statement value.
 *
 * Personal config:
 * importScript( 'User:YOU/NamePrefiller_config.js' );
 * importScript( 'User:1Veertje/NamePrefiller.js' );
 *
 * @author Jon Harald Søby
 * @author Vera de Kok (1Veertje)
 * @license GPL-2.0-or-later
 * @version 3.2.0
 */

if ( mw.NamePrefiller === undefined ) {
	mw.NamePrefiller = {};
}

( function ( $ ) {
	var config,
		defaultConfig = {
			useDefaultRules: true,

			lastnamePrefixes: [
				'aan', 'af', 'bij', 'de', 'den', 'der', 'des', 'het',
				'in', 'op', 'over', 'ten', 'ter', 'tot', 'uit', 'van',
				'vande', 'vanden', 'vander',
				'von', 'zu', 'zum', 'zur',
				'da', 'del', 'della', 'di', 'du', 'la', 'le',
				"'t", '\u2019t'
			],

			// Trailing name suffixes, dropped before splitting a name so that
			// "John Smith Jr." does not turn "Jr." into a last name.
			nameSuffixes: [ 'jr.', 'jr', 'sr.', 'sr', 'ii', 'iii', 'iv' ],

			// Prefill author given names (P9687) and author last names (P9688)
			// qualifiers on author name string (P2093) statements.
			fillAuthorNameQualifiers: true,

			rules: [
				{
					name: 'person names',
					fill: {
						P735: { mode: 'consecutiveFirstnames', allowMultiple: true },
						P734: { mode: 'lastname' },
						P1950: { mode: 'lastname' },
						P6978: { mode: 'secondLastname' },
						P9139: { mode: 'secondLastname' },
						P460: { mode: 'whole', onlyIfNoExistingClaim: true },
						P1533: { mode: 'whole', onlyIfNoExistingClaim: true },
						P1889: { mode: 'whole', onlyIfNoExistingClaim: true }
					}
				},
				{
					name: 'hyphenated family name',
					if: {
						instanceOfAny: [ 'Q101352', 'Q106319018' ],
						labelContains: '-'
					},
					fill: {
						P527: { mode: 'hyphenPartsWithSeparator', allowMultiple: true }
					}
				},
				{
					name: 'double family name',
					if: {
						instanceOfAny: [ 'Q101352', 'Q29042997' ]
					},
					fill: {
						P527: { mode: 'consecutiveLastnames', allowMultiple: true }
					}
				},
				{
					name: 'follows / followed by',
					fill: {
						P155: { mode: 'adjacentLabel', direction: -1 },
						P156: { mode: 'adjacentLabel', direction: +1 }
					}
				}
			]
		};

	function getConfig() {
		if ( config ) {
			return config;
		}

		var userConfig = mw.NamePrefiller.config || {};

		config = $.extend(
			true,
			{},
			defaultConfig,
			userConfig
		);

		if ( userConfig.useDefaultRules === false ) {
			config.rules = userConfig.rules || [];
		} else {
			config.rules = defaultConfig.rules.concat(
				userConfig.rules || []
			);
		}

		// $.extend( true, ... ) merges arrays index-wise, so a user list could
		// never be emptied (e.g. [] to stop stripping name suffixes). Replace
		// these lists wholesale instead.
		[ 'lastnamePrefixes', 'nameSuffixes' ].forEach( function ( key ) {
			if ( $.isArray( userConfig[ key ] ) ) {
				config[ key ] = userConfig[ key ];
			}
		} );

		return config;
	}

	function setExpertInput( expert, value ) {
		expert.$input.val( value );

		expert.$input
			.trigger( 'input' )
			.trigger( 'change' )
			.trigger( 'keyup' )
			.trigger( 'newnamecreate:update' );

		expert._viewNotifier.notify( 'change' );
	}

	function getEntity() {
		var entity = mw.config.get( 'wbEntity' );

		if ( typeof entity === 'string' ) {
			try {
				entity = JSON.parse( entity );
			} catch ( e ) {
				return null;
			}
		}

		return entity || null;
	}

	function getClaims() {
		var entity = getEntity();

		return entity && entity.claims ? entity.claims : {};
	}

	function statementHasValue( statement ) {
		var $statement = $( statement ),
			statementview = $statement.data( 'statementview' ),
			statementValue = statementview && statementview.value ?
				statementview.value() :
				null,
			hasInputValue;

		if (
			statementValue &&
			( statementValue.id || statementValue.mainsnak )
		) {
			return true;
		}

		hasInputValue = $statement.find( 'input, textarea' ).filter( function () {
			return normalizeWhitespace( $( this ).val() );
		} ).length > 0;

		if ( hasInputValue ) {
			return true;
		}

		return $statement.find( '.wikibase-snakview-value' ).filter( function () {
			return normalizeWhitespace( $( this ).text() );
		} ).length > 0;
	}

	function getClaimCount( propertyId, $valueview ) {
		var claims = getClaims(),
			$currentStatement = $valueview ?
				$valueview.closest( '.wikibase-statementview' ) :
				$(),
			entityCount = claims[ propertyId ] ? claims[ propertyId ].length : 0,
			domCount;

		domCount = $( '.wikibase-statementgroupview#' + propertyId )
			.find( '.wikibase-statementview' )
			.filter( function () {
				return !$currentStatement.length || this !== $currentStatement[ 0 ];
			} )
			.filter( function () {
				return !$( this ).is(
					'.wikibase-newentity, .wb-new, .wikibase-statementview-new'
				);
			} )
			.filter( function () {
				return statementHasValue( this );
			} )
			.length;

		return Math.max( entityCount, domCount );
	}

	function hasClaim( propertyId, $valueview ) {
		return getClaimCount( propertyId, $valueview ) > 0;
	}

	function getClaimIndex( propertyId, $valueview ) {
		var $currentStatement = $valueview.closest( '.wikibase-statementview' ),
			index = 0,
			foundCurrent = false;

		if ( !$currentStatement.length ) {
			return getClaimCount( propertyId, $valueview );
		}

		$( '.wikibase-statementgroupview#' + propertyId )
			.find( '.wikibase-statementview' )
			.each( function () {
				if ( this === $currentStatement[ 0 ] ) {
					foundCurrent = true;
					return false;
				}

				if (
					!$( this ).is(
						'.wikibase-newentity, .wb-new, .wikibase-statementview-new'
					) &&
					statementHasValue( this )
				) {
					index++;
				}
			} );

		return foundCurrent ?
			index :
			getClaimCount( propertyId, $valueview );
	}

	function hasDomItemClaimValue( propertyId, itemId ) {
		return $( '.wikibase-statementgroupview#' + propertyId )
			.find( '.wikibase-statementview' )
			.find( 'a[href$="/wiki/' + itemId + '"], a[href$="/entity/' + itemId + '"]' )
			.length > 0;
	}

	function hasItemClaimValue( propertyId, itemId ) {
		var claims = getClaims(),
			hasEntityClaim;

		hasEntityClaim = $.grep( claims[ propertyId ] || [], function ( claim ) {
			var value = claim.mainsnak &&
				claim.mainsnak.datavalue &&
				claim.mainsnak.datavalue.value;

			return value && (
				value.id === itemId ||
				'Q' + value[ 'numeric-id' ] === itemId
			);
		} ).length > 0;

		return hasEntityClaim || hasDomItemClaimValue( propertyId, itemId );
	}

	function getPageLabel() {
		var $label = $( '.wikibase-title-label' ).first().clone();

		$label.find( 'sup' ).remove();

		return normalizeWhitespace( $label.text() );
	}

	function normalizeWhitespace( text ) {
		return String( text || '' ).trim().replace( /\s+/g, ' ' );
	}

	function getLabel( languageCode ) {
		var entity = getEntity();

		if (
			languageCode &&
			entity &&
			entity.labels &&
			entity.labels[ languageCode ] &&
			entity.labels[ languageCode ].value
		) {
			return normalizeWhitespace( entity.labels[ languageCode ].value );
		}

		return getPageLabel();
	}

	function getLastnameSegment( label, offsetFromEnd, allowSingleRemainingPart ) {
		var currentConfig = getConfig(),
			parts = label.split( ' ' ),
			prefixes = currentConfig.lastnamePrefixes,
			end = parts.length,
			offset = offsetFromEnd || 0,
			start;

		while ( offset >= 0 ) {
			if ( end <= 0 || ( end === 1 && !allowSingleRemainingPart ) ) {
				return '';
			}

			start = end - 1;

			while (
				start > 0 &&
				$.inArray( parts[ start - 1 ].toLowerCase(), prefixes ) !== -1
			) {
				start--;
			}

			if ( offset === 0 ) {
				return parts.slice( start, end ).join( ' ' );
			}

			end = start;
			offset--;
		}

		return '';
	}

	function getLastName( label ) {
		return getLastnameSegment( label, 0 );
	}

	function getFirstnames( label ) {
		var lastname = getLastName( label ),
			lastnameStart = label.length - lastname.length;

		if (
			lastname &&
			lastnameStart > 0 &&
			label.slice( lastnameStart ).toLowerCase() === lastname.toLowerCase()
		) {
			return label.slice( 0, lastnameStart ).trim().split( ' ' );
		}

		return [ label.split( ' ' )[ 0 ] ];
	}

	function normalizeNameSuffix( suffix ) {
		return String( suffix || '' ).toLowerCase().replace( /\.$/, '' );
	}

	function stripNameSuffixes( name ) {
		var currentConfig = getConfig(),
			suffixes = $.map( currentConfig.nameSuffixes || [], normalizeNameSuffix ),
			parts = normalizeWhitespace( name ).split( ' ' ),
			lastPart;

		while ( parts.length > 1 ) {
			lastPart = normalizeNameSuffix( parts[ parts.length - 1 ] );

			if ( $.inArray( lastPart, suffixes ) === -1 ) {
				break;
			}

			parts.pop();
		}

		return parts.join( ' ' );
	}

	/**
	 * Split a personal name into the portions a bibliographic reference file
	 * (BibTeX) would store: "Lastname, Given names", trailing suffixes
	 * ("Jr.", "III") and last-name prefixes ("van", "Di", "'t") are handled.
	 * Returns empty strings when the name cannot be split: a single word, an
	 * empty value, or a value holding several authors ("Smith, J.; Doe, J."),
	 * which has no unambiguous breakdown. No qualifiers are prefilled then.
	 *
	 * Exposed as mw.NamePrefiller.getNameParts for reuse and testing.
	 */
	function getNameParts( name ) {
		var cleaned = stripNameSuffixes( name ).replace( /^[\s,]+|[\s,]+$/g, '' ),
			commaIndex = cleaned.indexOf( ',' ),
			givenNames,
			lastNames;

		if ( !cleaned || /[;&]|\band\b|\bet al\b/i.test( cleaned ) ) {
			return { givenNames: '', lastNames: '' };
		}

		// "Last name, First name Second name" is the shape handled below.
		// A second comma left after dropping the suffixes ("Holm, Anders, Bo",
		// "Holm,, Anders") is not that shape: the split would be a guess, so
		// nothing is prefilled.
		if ( cleaned.split( ',' ).length > 2 ) {
			return { givenNames: '', lastNames: '' };
		}

		if ( commaIndex > 0 ) {
			// Suffixes can sit on either side of the comma ("Holm Jr., Anders").
			lastNames = normalizeWhitespace(
				stripNameSuffixes( cleaned.slice( 0, commaIndex ) )
			);
			givenNames = normalizeWhitespace(
				stripNameSuffixes( cleaned.slice( commaIndex + 1 ) )
			);

			if ( givenNames && lastNames ) {
				return { givenNames: givenNames, lastNames: lastNames };
			}
		}

		if ( cleaned.split( ' ' ).length < 2 ) {
			return { givenNames: '', lastNames: '' };
		}

		return {
			givenNames: getFirstnames( cleaned ).join( ' ' ),
			lastNames: getLastnameSegment( cleaned, 0 )
		};
	}

	function getOrdinalSuffix( number ) {
		if ( number % 100 >= 11 && number % 100 <= 13 ) {
			return 'th';
		}

		switch ( number % 10 ) {
			case 1: return 'st';
			case 2: return 'nd';
			case 3: return 'rd';
			default: return 'th';
		}
	}

	function getAdjacentLabel( label, direction ) {
		// Find the first standalone figure in the label (e.g. "2014" or "2nd")
		// and shift it by `direction` (+1 / -1). When the figure is an ordinal
		// the suffix is recomputed, so "2nd" → "1st"/"3rd" and "2014" → "2013"/"2015".
		var match = label.match( /\b(\d+)(st|nd|rd|th)?\b/i );

		if ( !match ) {
			return '';
		}

		var number = parseInt( match[ 1 ], 10 ),
			next = number + direction;

		if ( next < 1 ) {
			return '';
		}

		var replacement = match[ 2 ] ?
			next + getOrdinalSuffix( next ) :
			String( next );

		return label.slice( 0, match.index ) +
			replacement +
			label.slice( match.index + match[ 0 ].length );
	}

	function getModeValue( fillRule, label, index, propertyId, $valueview ) {
		var parts = label.split( ' ' ),
			mode = fillRule.mode,
			customModes = mw.NamePrefiller.customModes || {};

		if ( fillRule.value ) {
			return fillRule.value;
		}

		if ( typeof mode === 'function' ) {
			return mode( label, index, propertyId, $valueview );
		}

		if ( typeof mode === 'string' && typeof customModes[ mode ] === 'function' ) {
			return customModes[ mode ]( label, index, propertyId, $valueview );
		}

		if ( mode === 'whole' ) {
			return label;
		}

		if ( mode === 'first' ) {
			return parts[ 0 ] || '';
		}

		if ( mode === 'second' ) {
			return parts[ 1 ] || '';
		}

		if ( mode === 'firstN' ) {
			var n = fillRule.n || 1;
			return parts.slice( 0, n ).join( ' ' );
		}

		if ( mode === 'lastN' ) {
			var n = fillRule.n || 1;
			return parts.slice( -n ).join( ' ' );
		}

		if ( mode === 'last' ) {
			return parts[ parts.length - 1 ] || '';
		}

		if ( mode === 'secondFromLast' ) {
			return parts[ parts.length - 2 ] || '';
		}

		if ( mode === 'atIndex' ) {
			var idx = fillRule.n || 0;
			if ( idx < 0 ) idx = parts.length + idx;
			return parts[ idx ] || '';
		}

		if ( mode === 'consecutive' ) {
			var dir = fillRule.direction,
				exclude = fillRule.exclude || 0;

			if ( dir === 'reverse' ) {
				return parts[ parts.length - 1 - index - exclude ] || '';
			}

			return parts[ index + exclude ] || '';
		}

		if ( mode === 'consecutiveFirstnames' ) {
			return getFirstnames( label )[ index ] || '';
		}

		if ( mode === 'consecutiveLastnames' ) {
			if ( index > 1 ) {
				return '';
			}

			return getLastnameSegment( label, 1 - index, true );
		}

		if ( mode === 'lastname' ) {
			if ( propertyId === 'P734' && hasClaim( 'P1950', $valueview ) ) {
				return getLastnameSegment( label, 1 );
			}

			return getLastnameSegment( label, 0 );
		}

		if ( mode === 'secondLastname' ) {
			return getLastnameSegment( label, 1 );
		}

		if ( mode === 'hyphenParts' ) {
			return label.split( '-' )[ index ] || '';
		}

		if ( mode === 'hyphenPartsWithSeparator' ) {
			return label.split( '-' ).reduce( function ( result, part, partIndex ) {
				if ( partIndex > 0 ) {
					result.push( 'Q76425243' );
				}

				result.push( part.trim() );

				return result;
			}, [] )[ index ] || '';
		}

		if ( mode === 'adjacentLabel' ) {
			return getAdjacentLabel( label, fillRule.direction || 1 );
		}

		return '';
	}

	function ruleMatches( rule ) {
		var condition = rule.if || {},
			label = getLabel( condition.labelLang ),
			parts = label ? label.split( ' ' ) : [];

		if (
			condition.instanceOf &&
			!hasAllItemClaimValues( 'P31', condition.instanceOf )
		) {
			return false;
		}

		if (
			condition.instanceOfAny &&
			!hasAnyItemClaimValue( 'P31', condition.instanceOfAny )
		) {
			return false;
		}

		if (
			condition.notInstanceOf &&
			hasAnyItemClaimValue( 'P31', condition.notInstanceOf )
		) {
			return false;
		}

		if (
			condition.labelContains &&
			label.indexOf( condition.labelContains ) === -1
		) {
			return false;
		}

		if ( condition.labelLang && !label ) {
			return false;
		}

		if ( condition.wordCount && parts.length !== condition.wordCount ) {
			return false;
		}

		return true;
	}

	function hasAllItemClaimValues( propertyId, itemIds ) {
		itemIds = $.isArray( itemIds ) ? itemIds : [ itemIds ];

		return $.grep( itemIds, function ( itemId ) {
			return !hasItemClaimValue( propertyId, itemId );
		} ).length === 0;
	}

	function hasAnyItemClaimValue( propertyId, itemIds ) {
		itemIds = $.isArray( itemIds ) ? itemIds : [ itemIds ];

		return $.grep( itemIds, function ( itemId ) {
			return hasItemClaimValue( propertyId, itemId );
		} ).length > 0;
	}

	function getFillValue( rule, propertyId, $valueview ) {
		var fillRule = rule.fill && rule.fill[ propertyId ],
			condition = rule.if || {},
			label = getLabel( condition.labelLang ),
			index = getClaimIndex( propertyId, $valueview ),
			value;

		if ( !fillRule ) {
			return '';
		}

		if ( fillRule.onlyIfNoExistingClaim && hasClaim( propertyId, $valueview ) ) {
			return '';
		}

		if ( hasClaim( propertyId, $valueview ) && !fillRule.allowMultiple ) {
			return '';
		}

		value = getModeValue(
			fillRule,
			label,
			index,
			propertyId,
			$valueview
		);

		return value || '';
	}

	function getPropertyId( $valueview ) {
		var $snakview = $valueview.closest( '.wikibase-snakview' ),
			snakview = $snakview.data( 'snakview' );

		return snakview && snakview.value() ?
			snakview.value().property :
			'';
	}

	function getPropertyIdForStatement( $statementview ) {
		var $mainSnak = $statementview
				.find( '.wikibase-statementview-mainsnak' )
				.first()
				.find( '.wikibase-snakview' )
				.first(),
			snakview = $mainSnak.data( 'snakview' ),
			$group;

		if ( snakview && snakview.value() ) {
			return snakview.value().property;
		}

		$group = $statementview.closest( '.wikibase-statementgroupview' );

		return $group.attr( 'data-property-id' ) || $group.attr( 'id' ) || '';
	}

	function unwrapDataValue( dataValue ) {
		var value = dataValue,
			depth = 0;

		while (
			value &&
			typeof value !== 'string' &&
			typeof value.getValue === 'function' &&
			depth < 3
		) {
			value = value.getValue();
			depth++;
		}

		return typeof value === 'string' ? value : '';
	}

	/**
	 * Value of a statement's main snak as plain text, read from the statement
	 * model first (so saved statements work in read mode) and from the DOM as
	 * a fallback (so a value being typed in edit mode is picked up as well).
	 */
	function getStatementMainStringValue( $statement ) {
		var statementview = $statement.data( 'statementview' ),
			$mainSnak = $statement
				.find( '.wikibase-statementview-mainsnak' )
				.first(),
			claim,
			mainSnak,
			value = '';

		try {
			if ( statementview && typeof statementview.value === 'function' ) {
				claim = statementview.value().getClaim();
				mainSnak = claim && claim.getMainSnak ? claim.getMainSnak() : null;
				value = normalizeWhitespace( unwrapDataValue(
					mainSnak && mainSnak.getValue ? mainSnak.getValue() : null
				) );
			}
		} catch ( e ) {
			value = '';
		}

		if ( value ) {
			return value;
		}

		value = normalizeWhitespace( $mainSnak.find( 'input, textarea' ).first().val() );

		if ( value ) {
			return value;
		}

		return normalizeWhitespace(
			$mainSnak.find( '.wikibase-snakview-value' ).first().text()
		);
	}

	function getQualifierFillValue( propertyId, $valueview ) {
		var $statement = $valueview.closest( '.wikibase-statementview' ),
			nameParts;

		if ( propertyId === 'P1545' ) {
			return String( getP1545Ordinal( $valueview ) );
		}

		if (
			( propertyId !== 'P9687' && propertyId !== 'P9688' ) ||
			!getConfig().fillAuthorNameQualifiers
		) {
			return '';
		}

		if ( getPropertyIdForStatement( $statement ) !== 'P2093' ) {
			return '';
		}

		nameParts = getNameParts( getStatementMainStringValue( $statement ) );

		return propertyId === 'P9687' ? nameParts.givenNames : nameParts.lastNames;
	}

	function statementHasP1545Qualifier( $statement ) {
		var $qualifiers = $statement.find( '.wikibase-statementview-qualifiers' );
		if ( !$qualifiers.length ) {
			return false;
		}

		// 1. Edit-mode: snakview widget data
		var hasSnakview = false;
		$qualifiers.find( '.wikibase-snakview' ).each( function () {
			var snakview = $( this ).data( 'snakview' );
			if (
				snakview &&
				snakview.value &&
				snakview.value()
			) {
				var v = snakview.value();
				if ( v.property === 'P1545' || v.propertyId === 'P1545' ) {
					hasSnakview = true;
					return false;
				}
			}
		} );
		if ( hasSnakview ) {
			return true;
		}

		// 2. Read-mode: property link/label for P1545 in the qualifiers section
		if (
			$qualifiers.find( '.wikibase-snakview-property a[href*="P1545"]' ).length ||
			$qualifiers.find( '.wikibase-snakview-property' ).filter( function () {
				return ( $( this ).text() || '' ).indexOf( 'P1545' ) !== -1;
			} ).length
		) {
			return true;
		}

		// 3. Data model via statementview value (includes saved qualifiers)
		try {
			var statementview = $statement.data( 'statementview' );
			if ( statementview && statementview.value ) {
				var sv = statementview.value();
				if ( sv && sv.getClaim && sv.getClaim() ) {
					var quals = sv.getClaim().getQualifiers();
					if ( quals && quals.getItemByPropertyId ) {
						var existing = quals.getItemByPropertyId( 'P1545' );
						if ( existing && existing.length ) {
							return true;
						}
					}
				}
			}
		} catch ( e ) {
			// ignore
		}

		// 4. Edit-mode fallback: property selector input shows P1545 / series ordinal
		if ( $qualifiers.find( '.wikibase-snakview-property input' ).filter( function () {
			var val = $( this ).val() || '';
			return val.indexOf( 'P1545' ) !== -1 ||
				val.indexOf( 'series ordinal' ) !== -1;
		} ).length ) {
			return true;
		}

		return false;
	}

	function getP1545Ordinal( $valueview ) {
		// 1-based ordinal: count how many statements in the current statement
		// group (before the current one) already have a P1545 qualifier, then add 1.
		// Saved statements are counted from the entity claims (falling back to the
		// DOM if the config is stale); new (unsaved) statements are counted from the
		// DOM so in-session additions are picked up too.
		var $currentStatement = $valueview.closest( '.wikibase-statementview' ),
			$group = $currentStatement.closest( '.wikibase-statementgroupview' ),
			propertyId = getPropertyIdForStatement( $currentStatement ) ||
				$group.attr( 'data-property-id' ) ||
				$group.attr( 'id' ),
			entityCount = 0,
			domSavedCount = 0,
			domNewCount = 0;

		if ( !propertyId ) {
			console.log( '[NamePrefiller] getP1545Ordinal: no propertyId for current statement' );
			return 1;
		}

		var claims = getClaims() || {},
			groupClaims = claims[ propertyId ] || [];
		for ( var i = 0; i < groupClaims.length; i++ ) {
			var c = groupClaims[ i ];
			if (
				c &&
				c.qualifiers &&
				c.qualifiers.P1545 &&
				c.qualifiers.P1545.length
			) {
				entityCount++;
			}
		}

		$( '.wikibase-statementgroupview#' + propertyId + ' .wikibase-statementview' ).each( function () {
			if ( $currentStatement.length && this === $currentStatement[ 0 ] ) {
				return false;
			}
			if ( !statementHasP1545Qualifier( $( this ) ) ) {
				return true;
			}
			if ( $( this ).is( '.wikibase-newentity, .wb-new, .wikibase-statementview-new' ) ) {
				domNewCount++;
			} else {
				domSavedCount++;
			}
		} );

		var count = Math.max( entityCount, domSavedCount ) + domNewCount;
		console.log( '[NamePrefiller] getP1545Ordinal: property=' + propertyId + ', entity=' + entityCount + ', domSaved=' + domSavedCount + ', domNew=' + domNewCount + ', ordinal=' + ( count + 1 ) );
		return count + 1;
	}

	function handleValueViewAfterStartEditing( event ) {
		var currentConfig = getConfig(),
			$valueview = $( event.target ),
			valueview = $valueview.data( 'valueview' ),
			propertyId = getPropertyId( $valueview ),
			expert,
			value = '';

		if ( !valueview || !propertyId ) {
			return;
		}

		// Qualifier fields: fill series ordinal (P1545) and the author name
		// qualifiers (P9687/P9688) of author name string statements. Any other
		// qualifier field is left alone.
		if ( $valueview.closest( '.wikibase-statementview-qualifiers' ).length ) {
			expert = valueview.expert();

			if (
				!expert ||
				!expert.$input ||
				normalizeWhitespace( expert.$input.val() )
			) {
				return;
			}

			value = getQualifierFillValue( propertyId, $valueview );

			if ( value ) {
				setExpertInput( expert, value );
			}

			return;
		}

		expert = valueview.expert();

		if (
			expert.$input &&
			normalizeWhitespace( expert.$input.val() )
		) {
			return;
		}

		$.each( currentConfig.rules, function ( i, rule ) {
			if ( !ruleMatches( rule ) ) {
				return true;
			}

			value = getFillValue( rule, propertyId, $valueview );

			return !value;
		} );

		if ( value ) {
			setExpertInput( expert, value );

			if ( expert.$input && expert.$input[ 0 ] ) {
				expert.$input[ 0 ].focus();
			}
		}
	}

	mw.loader.using( 'oojs-ui' ).then( function () {
		if ( $( '.wb-empty .wikibase-title-label' ).length ) {
			return;
		}

		$( '.wikibase-statementgrouplistview' ).on(
			'valueviewafterstartediting',
			handleValueViewAfterStartEditing
		);
	} );

	mw.NamePrefiller.getNameParts = getNameParts;
}( jQuery ) );