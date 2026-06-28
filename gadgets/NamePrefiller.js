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
 * Personal config:
 * importScript( 'User:YOU/NamePrefiller_config.js' );
 * importScript( 'User:1Veertje/NamePrefiller.js' );
 *
 * @author Jon Harald Søby
 * @author Vera de Kok (1Veertje)
 * @license GPL-2.0-or-later
 * @version 3.0.2
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
}( jQuery ) );