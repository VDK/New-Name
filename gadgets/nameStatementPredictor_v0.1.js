/**
 * Suggest statements from a person's label.
 *
 * Adds one-click buttons for:
 * - given name (P735), with series ordinal (P1545)
 * - family name (P734)
 * - sex or gender (P21), inferred from a matched given/family name
 *
 * Heuristic references use based on heuristic (P887).
 */
( function ( mw, $ ) {
	'use strict';

	var CONFIG = {
		properties: {
			instanceOf: 'P31',
			sexOrGender: 'P21',
			givenName: 'P735',
			familyName: 'P734',
			seriesOrdinal: 'P1545',
			basedOnHeuristic: 'P887'
		},
		items: {
			human: 'Q5',
			male: 'Q6581097',
			female: 'Q6581072',
			givenNameClass: 'Q202444',
			femaleGivenNameClass: 'Q11879590',
			maleGivenNameClass: 'Q12308941',
			unisexGivenNameClass: 'Q3409032',
			familyNameClass: 'Q101352',
			compoundSurnameClass: 'Q66480858',
			inferredFromGivenName: 'Q69652498',
			inferredFromFullName: 'Q97033143'
		},
		prefixes: [
			'aan', 'af', 'bij', 'de', 'den', 'der', 'des', 'het',
			'in', 'op', 'over', 'ten', 'ter', 'tot', 'uit', 'van',
			'vande', 'vanden', 'vander',
			'von', 'zu', 'zum', 'zur',
			'da', 'del', 'della', 'di', 'du', 'la', 'le',
			"'t", '\u2019t'
		],
		cacheMaxAge: 7 * 24 * 60 * 60 * 1000,
		cachePrefix: 'nameStatementPredictor-label-',
		debug: true,
		dockUseAsRefInfoIcons: false,
		maxSearchResults: 5
	};

	var FALLBACK_TEXT = {
		add: 'Add',
		title: 'Name suggestions',
		loading: 'Looking up name items...',
		none: 'No name suggestions found.',
		added: 'Statement added. Reload the page to see the change.',
		error: 'Could not add statement.'
	};

	function uiLanguage() {
		return mw.config.get( 'wgUserLanguage' ) ||
			mw.config.get( 'wgContentLanguage' ) ||
			'en';
	}

	function interfaceText( key ) {
		if (
			key === 'add' &&
			mw.message &&
			mw.message( 'wikibase-add' ).exists()
		) {
			return mw.msg( 'wikibase-add' );
		}

		return FALLBACK_TEXT[ key ] || key;
	}

	function debugLog() {
		if ( !CONFIG.debug || !window.console || !console.log ) {
			return;
		}

		console.log.apply( console, [ '[nameStatementPredictor]' ].concat(
			Array.prototype.slice.call( arguments )
		) );
	}

	function debugWarn() {
		if ( !CONFIG.debug || !window.console || !console.warn ) {
			return;
		}

		console.warn.apply( console, [ '[nameStatementPredictor]' ].concat(
			Array.prototype.slice.call( arguments )
		) );
	}

	function labelCacheKey( id, lang ) {
		return CONFIG.cachePrefix + lang + '-' + id;
	}

	function getCachedLabel( id, lang ) {
		var cached;

		try {
			cached = JSON.parse( localStorage.getItem( labelCacheKey( id, lang ) ) );
		} catch ( e ) {
			return null;
		}

		if (
			!cached ||
			!cached.label ||
			Date.now() - cached.time > CONFIG.cacheMaxAge
		) {
			return null;
		}

		return cached.label;
	}

	function setCachedLabel( id, lang, label ) {
		try {
			localStorage.setItem(
				labelCacheKey( id, lang ),
				JSON.stringify( {
					label: label,
					time: Date.now()
				} )
			);
		} catch ( e ) {}
	}

	function uniqueIds( ids ) {
		var seen = {};

		return ids.filter( function ( id ) {
			if ( !id || seen[ id ] ) {
				return false;
			}

			seen[ id ] = true;
			return true;
		} );
	}

	function getLabels( ids ) {
		var lang = uiLanguage(),
			labels = {},
			missing = [],
			deferred = $.Deferred(),
			api = new mw.Api();

		uniqueIds( ids ).forEach( function ( id ) {
			var cached = getCachedLabel( id, lang );

			if ( cached ) {
				labels[ id ] = cached;
			} else {
				missing.push( id );
			}
		} );

		if ( !missing.length ) {
			return deferred.resolve( labels ).promise();
		}

		api.get( {
			action: 'wbgetentities',
			ids: missing.join( '|' ),
			props: 'labels',
			languages: lang + '|en',
			languagefallback: 1,
			format: 'json'
		} ).then( function ( data ) {
			$.each( data.entities || {}, function ( id, entity ) {
				var label = id;

				if ( entity.labels ) {
					label =
						( entity.labels[ lang ] && entity.labels[ lang ].value ) ||
						( entity.labels.en && entity.labels.en.value ) ||
						id;
				}

				labels[ id ] = label;
				setCachedLabel( id, lang, label );
			} );

			deferred.resolve( labels );
		}, function () {
			missing.forEach( function ( id ) {
				labels[ id ] = id;
			} );
			deferred.resolve( labels );
		} );

		return deferred.promise();
	}

	function label( labels, id ) {
		return labels[ id ] || id;
	}

	function panelTitle( labels ) {
		return label( labels, CONFIG.properties.givenName ) + ' / ' +
			label( labels, CONFIG.properties.familyName );
	}

	function itemValue( qid ) {
		return {
			'entity-type': 'item',
			'id': qid,
			'numeric-id': Number( qid.replace( 'Q', '' ) )
		};
	}

	function statementSnak( property, qid ) {
		return {
			snaktype: 'value',
			property: property,
			datatype: 'wikibase-item',
			datavalue: {
				type: 'wikibase-entityid',
				value: itemValue( qid )
			}
		};
	}

	function stringQualifier( property, value ) {
		return {
			snaktype: 'value',
			property: property,
			datatype: 'string',
			datavalue: {
				type: 'string',
				value: value
			}
		};
	}

	function heuristicReference( heuristicQid ) {
		return [ {
			'snaks-order': [ CONFIG.properties.basedOnHeuristic ],
			snaks: {
				P887: [ statementSnak( CONFIG.properties.basedOnHeuristic, heuristicQid ) ]
			}
		} ];
	}

	function makeClaim( property, valueQid, options ) {
		var claim = {
			type: 'claim',
			rank: 'normal',
			mainsnak: statementSnak( property, valueQid )
		};

		if ( options && options.qualifiers ) {
			claim.qualifiers = options.qualifiers;
			claim[ 'qualifiers-order' ] = options.qualifierOrder || Object.keys( options.qualifiers );
		}

		if ( options && options.references ) {
			claim.references = options.references;
		}

		return claim;
	}

	function randomGuid() {
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( char ) {
			var random = Math.random() * 16 | 0,
				value = char === 'x' ? random : ( random & 0x3 | 0x8 );

			return value.toString( 16 );
		} );
	}

	function claimGuid() {
		var qid = mw.config.get( 'wbEntityId' );

		if (
			window.wikibase &&
			wikibase.utilities &&
			wikibase.utilities.ClaimGuidGenerator
		) {
			return new wikibase.utilities.ClaimGuidGenerator( qid.toLowerCase() ).newGuid();
		}

		return qid + '$' + randomGuid();
	}

	function getEntity() {
		var entity = mw.config.get( 'wbEntity' );

		if ( typeof entity === 'string' ) {
			try {
				entity = JSON.parse( entity );
			} catch ( e ) {
				entity = null;
			}
		}

		return entity || {};
	}

	function getClaims() {
		return getEntity().claims || {};
	}

	function domClaimCount( property ) {
		return $( '.wikibase-statementgroupview#' + property )
			.find( '.wikibase-statementview' )
			.filter( function () {
				return !$( this ).is( '.wikibase-newentity, .wb-new, .wikibase-statementview-new' );
			} )
			.length;
	}

	function hasClaim( property ) {
		return claimCount( property ) > 0;
	}

	function claimCount( property ) {
		var claims = getClaims();
		return claims[ property ] ? claims[ property ].length : domClaimCount( property );
	}

	function hasClaimValue( property, qid ) {
		var claims = getClaims()[ property ] || [];

		return claims.some( function ( claim ) {
			var value = claim.mainsnak &&
				claim.mainsnak.datavalue &&
				claim.mainsnak.datavalue.value;

			return value && value.id === qid;
		} );
	}

	function claimValueIds( property ) {
		return ( getClaims()[ property ] || [] ).map( function ( claim ) {
			var value = claim.mainsnak &&
				claim.mainsnak.datavalue &&
				claim.mainsnak.datavalue.value;

			return value && value.id;
		} ).filter( Boolean );
	}

	function isHuman() {
		return hasClaimValue( CONFIG.properties.instanceOf, CONFIG.items.human ) ||
			$( '#P31 .wikibase-snakview-value a[href$="/Q5"]' ).length > 0;
	}

	function getCleanLabel() {
		var $label = $( '.wikibase-title-label' ).first().clone();

		$label.find( 'sup' ).remove();

		return $label.text().trim().replace( /\s+/g, ' ' );
	}

	function splitName( label ) {
		var parts = label.split( ' ' ),
			i = parts.length - 1;

		while (
			i > 0 &&
			$.inArray( parts[ i - 1 ].toLowerCase(), CONFIG.prefixes ) !== -1
		) {
			i--;
		}

		var split = {
			label: label,
			givenNames: parts.slice( 0, i ),
			familyName: parts.slice( i ).join( ' ' )
		};

		debugLog( 'Split name', split );

		return split;
	}

	function searchEntities( api, name ) {
		debugLog( 'API search', name );

		return api.get( {
			action: 'wbsearchentities',
			search: name,
			language: uiLanguage(),
			uselang: uiLanguage(),
			type: 'item',
			limit: CONFIG.maxSearchResults,
			format: 'json'
		} ).then( function ( data ) {
			debugLog( 'API search results', name, data.search || [] );
			return {
				name: name,
				qids: ( data.search || [] ).map( function ( result ) {
					return result.id;
				} )
			};
		} );
	}

	function searchEntitiesByClass( api, name, allowedClasses ) {
		var searches = allowedClasses.map( function ( classQid ) {
			var query = 'haswbstatement:P31=' + classQid + ' "' + name.replace( /"/g, '\\"' ) + '"';

			debugLog( 'API class search', query );

			return api.get( {
				action: 'query',
				list: 'search',
				srsearch: query,
				srlimit: CONFIG.maxSearchResults,
				format: 'json'
			} ).then( function ( data ) {
				debugLog( 'API class search results', classQid, name, data.query && data.query.search || [] );
				return ( data.query && data.query.search || [] ).map( function ( result ) {
					return result.title;
				} ).filter( function ( title ) {
					return /^Q\d+$/.test( title );
				} );
			} );
		} );

		return $.when.apply( $, searches ).then( function () {
			var results = Array.prototype.slice.call( arguments ),
				qids = [];

			if ( allowedClasses.length === 1 ) {
				results = [ results[ 0 ] || results ];
			}

			results.forEach( function ( result ) {
				qids = qids.concat( result || [] );
			} );

			return uniqueIds( qids );
		} );
	}

	function getEntityClaimsAndLabels( api, qids ) {
		qids = uniqueIds( qids );

		if ( !qids.length ) {
			return $.Deferred().resolve( {} ).promise();
		}

		debugLog( 'API wbgetentities', qids );

		return api.get( {
			action: 'wbgetentities',
			ids: qids.join( '|' ),
			props: 'labels|claims',
			languages: uiLanguage() + '|en',
			languagefallback: 1,
			format: 'json'
		} ).then( function ( data ) {
			debugLog( 'API entity data', data.entities || {} );
			return data.entities || {};
		} );
	}

	function getEntityLabel( entity, fallback ) {
		var lang = uiLanguage();

		if ( entity.labels ) {
			return (
				entity.labels[ lang ] &&
				entity.labels[ lang ].value
			) || (
				entity.labels.en &&
				entity.labels.en.value
			) || fallback;
		}

		return fallback;
	}

	function getP31Classes( entity ) {
		return ( entity.claims && entity.claims.P31 || [] ).map( function ( claim ) {
			var value = claim.mainsnak &&
				claim.mainsnak.datavalue &&
				claim.mainsnak.datavalue.value;

			return value && value.id;
		} ).filter( Boolean );
	}

	function labelsMatch( entity, name ) {
		var lang = uiLanguage(),
			labels = entity.labels || {},
			values = [];

		if ( labels[ lang ] ) {
			values.push( labels[ lang ].value );
		}
		if ( labels.en ) {
			values.push( labels.en.value );
		}

		return values.some( function ( value ) {
			return value.toLowerCase() === name.toLowerCase();
		} );
	}

	function lookupNameItems( names, allowedClasses ) {
		var api = new mw.Api(),
			deferred = $.Deferred(),
			searches = names.map( function ( name ) {
				return $.when(
					searchEntities( api, name ),
					searchEntitiesByClass( api, name, allowedClasses )
				).then( function ( entitySearch, classSearchQids ) {
					return {
						name: name,
						qids: uniqueIds( ( entitySearch.qids || [] ).concat( classSearchQids || [] ) ),
						classSearchQids: classSearchQids || []
					};
				} );
			} );

		$.when.apply( $, searches ).then( function () {
			var searchResults = Array.prototype.slice.call( arguments ),
				allQids = [];

			if ( names.length === 1 ) {
				searchResults = [ searchResults[ 0 ] || searchResults ];
			}

			searchResults.forEach( function ( result ) {
				allQids = allQids.concat( result.qids || [] );
			} );

			getEntityClaimsAndLabels( api, allQids ).then( function ( entities ) {
				var grouped = {};

				searchResults.forEach( function ( result ) {
					grouped[ result.name ] = [];

					( result.qids || [] ).forEach( function ( qid ) {
						var entity = entities[ qid ],
							classes;

						if ( !entity || entity.missing ) {
							return;
						}

						classes = getP31Classes( entity );

						if (
							!labelsMatch( entity, result.name ) &&
							$.inArray( qid, result.classSearchQids ) === -1
						) {
							debugWarn( 'Rejected candidate because labels did not match', {
								name: result.name,
								qid: qid,
								label: getEntityLabel( entity, qid )
							} );
							return;
						}

						if (
							!classes.some( function ( classQid ) {
								return $.inArray( classQid, allowedClasses ) !== -1;
							} )
						) {
							debugWarn( 'Rejected candidate because P31 did not match allowed classes', {
								name: result.name,
								qid: qid,
								classes: classes,
								allowedClasses: allowedClasses
							} );
							return;
						}

						grouped[ result.name ].push( {
							qid: qid,
							label: getEntityLabel( entity, qid ),
							classes: classes
						} );
					} );
				} );

				debugLog( 'Grouped API lookup results', grouped );

				if ( grouped.Herke ) {
					debugLog( 'Herke candidates', grouped.Herke );
					if ( !grouped.Herke.some( function ( candidate ) {
						return candidate.qid === 'Q21146903';
					} ) ) {
						debugWarn( 'Herke did not include expected Q21146903', grouped.Herke );
					}
				}

				deferred.resolve( grouped );
			}, deferred.reject );
		}, deferred.reject );

		return deferred.promise();
	}

	function lookupExistingGivenNameItems() {
		var api = new mw.Api(),
			qids = claimValueIds( CONFIG.properties.givenName ),
			deferred = $.Deferred();

		if ( hasClaim( CONFIG.properties.sexOrGender ) || !qids.length ) {
			return deferred.resolve( [] ).promise();
		}

		getEntityClaimsAndLabels( api, qids ).then( function ( entities ) {
			var candidates = [];

			uniqueIds( qids ).forEach( function ( qid ) {
				var entity = entities[ qid ];

				if ( !entity || entity.missing ) {
					return;
				}

				candidates.push( {
					qid: qid,
					label: getEntityLabel( entity, qid ),
					classes: getP31Classes( entity )
				} );
			} );

			debugLog( 'Existing given name candidates', candidates );
			deferred.resolve( candidates );
		}, function () {
			debugWarn( 'Could not look up existing given name items' );
			deferred.resolve( [] );
		} );

		return deferred.promise();
	}

	function hasAnyClass( candidate, classes ) {
		return classes.some( function ( classQid ) {
			return $.inArray( classQid, candidate.classes ) !== -1;
		} );
	}

	function chooseCandidate( candidates, preferredClasses ) {
		var matches = candidates || [];

		debugLog( 'Choosing candidate', {
			candidates: candidates,
			preferredClasses: preferredClasses
		} );

		preferredClasses.some( function ( classQid ) {
			var classMatches = matches.filter( function ( candidate ) {
				return $.inArray( classQid, candidate.classes ) !== -1;
			} );

			if ( classMatches.length ) {
				matches = classMatches;
				return true;
			}

			return false;
		} );

		if ( matches.length === 1 ) {
			debugLog( 'Chosen candidate', matches[ 0 ] );
			return matches[ 0 ];
		}

		if ( matches.length > 1 ) {
			debugWarn( 'Ambiguous candidate list; no suggestion made', matches );
		} else {
			debugWarn( 'No candidate chosen', {
				candidates: candidates,
				preferredClasses: preferredClasses
			} );
		}

		return null;
	}

	function addClaim( claim, $button ) {
		var api = new mw.Api();

		if ( !claim.id ) {
			claim.id = claimGuid();
		}

		$button.prop( 'disabled', true ).addClass( 'nsp-is-saving' );

		api.postWithEditToken( {
			action: 'wbsetclaim',
			claim: JSON.stringify( claim ),
			summary: 'Added with [[User:1Veertje/nameStatementPredictor.js|nameStatementPredictor]]'
		} ).then( function () {
			$button.addClass( 'nsp-is-added' ).text( interfaceText( 'added' ) );
			mw.notify( interfaceText( 'added' ), {
				type: 'success',
				title: interfaceText( 'title' )
			} );
		}, function ( error ) {
			$button.prop( 'disabled', false ).removeClass( 'nsp-is-saving' );
			mw.notify( interfaceText( 'error' ) + ' ' + error, {
				type: 'error',
				title: interfaceText( 'title' )
			} );
		} );
	}

	function makeButton( label, meta, claim ) {
		var $button = $( '<button>' )
			.addClass( 'nsp-button' )
			.attr( 'type', 'button' )
			.append( $( '<span>' ).addClass( 'nsp-button-label' ).text( label ) );

		if ( meta ) {
			$button.append( $( '<span>' ).addClass( 'nsp-button-meta' ).text( meta ) );
		}

		$button.on( 'click', function () {
			addClaim( claim, $button );
		} );

		return $button;
	}

	function renderSuggestionGroup( $container, title, buttons ) {
		if ( !buttons.length ) {
			return;
		}

		var $group = $( '<div>' ).addClass( 'nsp-group' );

		$group
			.append( $( '<div>' ).addClass( 'nsp-group-title' ).text( title ) )
			.append( $( '<div>' ).addClass( 'nsp-buttons' ).append( buttons ) );

		$container.append( $group );
	}

	function buildSuggestions( nameParts, grouped ) {
		var givenGrouped = grouped.given || {},
			familyGrouped = grouped.family || {},
			existingGivenNames = grouped.existingGivenNames || [];
		var suggestions = {
				givenNames: [],
				familyNames: [],
				genders: []
			},
			currentGivenNameCount = claimCount( CONFIG.properties.givenName ),
			firstGenderSource = null;

		debugLog( 'Build suggestions input', {
			nameParts: nameParts,
			givenGrouped: givenGrouped,
			familyGrouped: familyGrouped,
			existingGivenNames: existingGivenNames,
			currentGivenNameCount: currentGivenNameCount
		} );

		nameParts.givenNames.forEach( function ( name, index ) {
			var candidate = chooseCandidate( givenGrouped[ name ], [
					CONFIG.items.femaleGivenNameClass,
					CONFIG.items.maleGivenNameClass,
					CONFIG.items.givenNameClass,
					CONFIG.items.unisexGivenNameClass
				] ),
				ordinal = String( index + 1 );

			if ( candidate && !firstGenderSource ) {
				firstGenderSource = {
					name: name,
					candidate: candidate
				};
			}

			if (
				!candidate ||
				index < currentGivenNameCount ||
				hasClaimValue( CONFIG.properties.givenName, candidate.qid )
			) {
				debugWarn( 'Skipping given name suggestion', {
					name: name,
					index: index,
					candidate: candidate,
					currentGivenNameCount: currentGivenNameCount,
					alreadyHasValue: candidate ?
						hasClaimValue( CONFIG.properties.givenName, candidate.qid ) :
						false
				} );
				return;
			}

			suggestions.givenNames.push( {
				name: name,
				candidate: candidate,
				ordinal: ordinal,
				claim: makeClaim( CONFIG.properties.givenName, candidate.qid, {
					qualifiers: {
						P1545: [ stringQualifier( CONFIG.properties.seriesOrdinal, ordinal ) ]
					},
					qualifierOrder: [ CONFIG.properties.seriesOrdinal ],
					references: heuristicReference( CONFIG.items.inferredFromFullName )
				} )
			} );
		} );

		if (
			nameParts.givenNames.length &&
			nameParts.familyName &&
			!hasClaim( CONFIG.properties.familyName )
		) {
			var familyCandidate = chooseCandidate(
				familyGrouped[ nameParts.familyName ],
				[
					CONFIG.items.familyNameClass,
					CONFIG.items.compoundSurnameClass
				]
			);

			if ( familyCandidate ) {
				suggestions.familyNames.push( {
					name: nameParts.familyName,
					candidate: familyCandidate,
					claim: makeClaim( CONFIG.properties.familyName, familyCandidate.qid, {
						references: heuristicReference( CONFIG.items.inferredFromFullName )
					} )
				} );
			}
		} else {
			debugLog( 'Skipping family name suggestions because P734 already exists' );
		}

		if ( !hasClaim( CONFIG.properties.sexOrGender ) ) {
			var genderSource = firstGenderSource;

			if (
				!genderSource ||
				!hasAnyClass( genderSource.candidate, [
					CONFIG.items.maleGivenNameClass,
					CONFIG.items.femaleGivenNameClass
				] )
			) {
				existingGivenNames.some( function ( candidate ) {
					if (
						hasAnyClass( candidate, [
							CONFIG.items.maleGivenNameClass,
							CONFIG.items.femaleGivenNameClass
						] )
					) {
						genderSource = {
							name: candidate.label,
							candidate: candidate
						};
						return true;
					}

					return false;
				} );
			}

			if (
				genderSource &&
				hasAnyClass( genderSource.candidate, [
					CONFIG.items.maleGivenNameClass,
					CONFIG.items.femaleGivenNameClass
				] )
			) {
				var genderQid = hasAnyClass( genderSource.candidate, [ CONFIG.items.femaleGivenNameClass ] ) ?
					CONFIG.items.female :
					CONFIG.items.male;

				suggestions.genders.push( {
					source: genderSource.name,
					genderQid: genderQid,
					claim: makeClaim( CONFIG.properties.sexOrGender, genderQid, {
						references: heuristicReference( CONFIG.items.inferredFromGivenName )
					} )
				} );
			}
		} else {
			debugLog( 'Skipping gender suggestion because P21 already exists' );
		}

		debugLog( 'Built suggestions', suggestions );

		return suggestions;
	}

	function collectLabelIds( suggestions ) {
		var ids = [
			CONFIG.properties.sexOrGender,
			CONFIG.properties.givenName,
			CONFIG.properties.familyName,
			CONFIG.properties.seriesOrdinal,
			CONFIG.properties.basedOnHeuristic,
			CONFIG.items.inferredFromGivenName,
			CONFIG.items.inferredFromFullName
		];

		suggestions.givenNames.forEach( function ( suggestion ) {
			ids.push( suggestion.candidate.qid );
		} );
		suggestions.familyNames.forEach( function ( suggestion ) {
			ids.push( suggestion.candidate.qid );
		} );
		suggestions.genders.forEach( function ( suggestion ) {
			ids.push( suggestion.genderQid );
		} );

		return uniqueIds( ids );
	}

	function renderSuggestions( $panel, suggestions, labels ) {
		var rendered = 0,
			$useAsRefInfo = $panel.find( '.UAR_info' ).detach();

		$panel.empty().append( $( '<h2>' ).text( panelTitle( labels ) ) );
		ensurePanelHeader( $panel ).find( '.nsp-uar-info-slot' ).append( $useAsRefInfo );

		renderSuggestionGroup(
			$panel,
			label( labels, CONFIG.properties.givenName ),
			suggestions.givenNames.map( function ( suggestion ) {
				rendered++;
				return makeButton(
					interfaceText( 'add' ) + ' ' +
						label( labels, CONFIG.properties.givenName ) + ': ' +
						label( labels, suggestion.candidate.qid ),
					label( labels, CONFIG.properties.seriesOrdinal ) + ' ' +
						suggestion.ordinal + ' | ' +
						label( labels, CONFIG.properties.basedOnHeuristic ) + ': ' +
						label( labels, CONFIG.items.inferredFromFullName ),
					suggestion.claim
				);
			} )
		);

		renderSuggestionGroup(
			$panel,
			label( labels, CONFIG.properties.familyName ),
			suggestions.familyNames.map( function ( suggestion ) {
				rendered++;
				return makeButton(
					interfaceText( 'add' ) + ' ' +
						label( labels, CONFIG.properties.familyName ) + ': ' +
						label( labels, suggestion.candidate.qid ),
					label( labels, CONFIG.properties.basedOnHeuristic ) + ': ' +
						label( labels, CONFIG.items.inferredFromFullName ),
					suggestion.claim
				);
			} )
		);

		renderSuggestionGroup(
			$panel,
			label( labels, CONFIG.properties.sexOrGender ),
			suggestions.genders.map( function ( suggestion ) {
				rendered++;
				return makeButton(
					interfaceText( 'add' ) + ' ' +
						label( labels, CONFIG.properties.sexOrGender ) + ': ' +
						label( labels, suggestion.genderQid ),
					label( labels, CONFIG.properties.basedOnHeuristic ) + ': ' +
						label( labels, CONFIG.items.inferredFromGivenName ) +
						' (' + suggestion.source + ')',
					suggestion.claim
				);
			} )
		);

		if ( !rendered ) {
			$panel.append( $( '<p>' ).addClass( 'nsp-empty' ).text( interfaceText( 'none' ) ) );
		}
	}

	function addStyles() {
		mw.util.addCSS( [
			'#nameStatementPredictor {',
			'  border: 1px solid #a2a9b1;',
			'  border-radius: 2px;',
			'  margin: 0.8em 0;',
			'  padding: 0.8em;',
			'  background: #f8f9fa;',
			'}',
			'#nameStatementPredictor h2 {',
			'  border: 0;',
			'  font-size: 1.1em;',
			'  margin: 0 0 0.6em;',
			'  padding: 0;',
			'}',
			'.nsp-group { margin-top: 0.7em; }',
			'.nsp-group-title {',
			'  color: #54595d;',
			'  font-size: 0.9em;',
			'  font-weight: bold;',
			'  margin-bottom: 0.3em;',
			'}',
			'.nsp-buttons { display: flex; flex-wrap: wrap; gap: 0.4em; }',
			'.nsp-button {',
			'  background: #fff;',
			'  border: 1px solid #a2a9b1;',
			'  border-radius: 2px;',
			'  color: #202122;',
			'  cursor: pointer;',
			'  padding: 0.35em 0.55em;',
			'  text-align: left;',
			'}',
			'.nsp-button:hover { background: #f1f4fd; border-color: #36c; }',
			'.nsp-button:disabled { cursor: default; opacity: 0.7; }',
			'.nsp-button-label { display: block; font-weight: bold; }',
			'.nsp-button-meta { color: #54595d; display: block; font-size: 0.85em; }',
			'.nsp-is-saving { background: #fff4d6; }',
			'.nsp-is-added { background: #d5fdf4; }',
			'.nsp-empty { color: #54595d; margin: 0; }',
			'.nsp-header {',
			'  align-items: center;',
			'  display: flex;',
			'  gap: 0.5em;',
			'  justify-content: space-between;',
			'}',
			'.nsp-header h2 { flex: 1 1 auto; }',
			'.nsp-uar-info-slot {',
			'  display: flex;',
			'  flex: 0 0 auto;',
			'  gap: 0.3em;',
			'}',
			'.nsp-uar-info-slot .UAR_info {',
			'  float: none !important;',
			'  position: static !important;',
			'}'
		].join( '\n' ) );
	}

	function getStatementsHeading() {
		return $( 'h2.wikibase-statements' ).filter( function () {
			return !$( this ).hasClass( 'wikibase-statements-identifiers' );
		} ).first();
	}

	function ensurePanelHeader( $panel ) {
		var $heading = $panel.children( 'h2' ).first(),
			$header = $panel.children( '.nsp-header' ).first();

		if ( !$header.length ) {
			$header = $( '<div>' ).addClass( 'nsp-header' );
			if ( $heading.length ) {
				$heading.before( $header );
				$header.append( $heading );
			} else {
				$header.append( $( '<h2>' ).text( interfaceText( 'title' ) ) );
				$panel.prepend( $header );
			}
			$header.append( $( '<div>' ).addClass( 'nsp-uar-info-slot' ) );
		}

		return $header;
	}

	function dockUseAsRefInfoIcons( $panel ) {
		var $heading,
			$slot;

		if ( !CONFIG.dockUseAsRefInfoIcons || !$panel || !$panel.length ) {
			return;
		}

		$heading = getStatementsHeading();
		$slot = ensurePanelHeader( $panel ).find( '.nsp-uar-info-slot' );

		$heading.nextUntil( '.wikibase-statementgrouplistview, #nameStatementPredictor' )
			.filter( '.UAR_info' )
			.appendTo( $slot );

		$panel.nextUntil( '.wikibase-statementgrouplistview' )
			.filter( '.UAR_info' )
			.appendTo( $slot );
	}

	function placePanel( $panel ) {
		var $heading = getStatementsHeading();

		if ( $heading.length ) {
			if ( $heading.next()[ 0 ] !== $panel[ 0 ] ) {
				$heading.after( $panel );
			}
		} else {
			$( '.wikibase-statementgrouplistview' ).first().before( $panel );
		}

		dockUseAsRefInfoIcons( $panel );
	}

	function watchHeaderInsertions( $panel ) {
		var main = $( '.wikibase-entityview-main' )[ 0 ],
			observer;

		if ( !main || typeof MutationObserver === 'undefined' ) {
			return;
		}

		observer = new MutationObserver( function () {
			placePanel( $panel );
		} );

		observer.observe( main, {
			childList: true
		} );

		setTimeout( function () {
			observer.disconnect();
		}, 6000 );
	}

	function init() {
		var qid = mw.config.get( 'wbEntityId' ),
			label = getCleanLabel(),
			nameParts = splitName( label ),
			canSuggestFromLabel = !!( nameParts.givenNames.length >= 1 && nameParts.familyName ),
			canSuggestFromExistingGivenName =
				!hasClaim( CONFIG.properties.sexOrGender ) &&
				claimCount( CONFIG.properties.givenName ) > 0,
			$panel;

		debugLog( 'Init', {
			qid: qid,
			namespace: mw.config.get( 'wgNamespaceNumber' ),
			label: label,
			nameParts: nameParts,
			isHuman: isHuman()
		} );

		if (
			!qid ||
			mw.config.get( 'wgNamespaceNumber' ) !== 0 ||
			$( '.wb-empty .wikibase-title-label' ).length ||
			!isHuman() ||
			!label ||
			(
				!canSuggestFromLabel &&
				!canSuggestFromExistingGivenName
			) ||
			$( '#nameStatementPredictor' ).length
		) {
			debugWarn( 'Init stopped by guard', {
				qid: qid,
				namespace: mw.config.get( 'wgNamespaceNumber' ),
				emptyLabel: $( '.wb-empty .wikibase-title-label' ).length,
				isHuman: isHuman(),
				label: label,
				givenNameCount: nameParts.givenNames.length,
				familyName: nameParts.familyName,
				canSuggestFromExistingGivenName: canSuggestFromExistingGivenName,
				alreadyRendered: $( '#nameStatementPredictor' ).length
			} );
			return;
		}

		addStyles();

		$panel = $( '<div>' )
			.attr( 'id', 'nameStatementPredictor' )
			.append( $( '<h2>' ).text( interfaceText( 'title' ) ) )
			.append( $( '<p>' ).addClass( 'nsp-empty' ).text( interfaceText( 'loading' ) ) );

		placePanel( $panel );
		watchHeaderInsertions( $panel );
		setTimeout( function () {
			placePanel( $panel );
		}, 750 );
		setTimeout( function () {
			placePanel( $panel );
		}, 2000 );

		getLabels( [
			CONFIG.properties.givenName,
			CONFIG.properties.familyName
		] ).then( function ( labels ) {
			$panel.find( 'h2' ).text( panelTitle( labels ) );
		} );

		debugLog( 'Lookup allowed classes', {
			given: [
				CONFIG.items.givenNameClass,
				CONFIG.items.femaleGivenNameClass,
				CONFIG.items.maleGivenNameClass,
				CONFIG.items.unisexGivenNameClass
			],
			family: [
				CONFIG.items.familyNameClass,
				CONFIG.items.compoundSurnameClass
			]
		} );

		$.when(
			lookupNameItems( nameParts.givenNames, [
				CONFIG.items.givenNameClass,
				CONFIG.items.femaleGivenNameClass,
				CONFIG.items.maleGivenNameClass,
				CONFIG.items.unisexGivenNameClass
			] ),
			lookupNameItems( canSuggestFromLabel ? [ nameParts.familyName ] : [], [
				CONFIG.items.familyNameClass,
				CONFIG.items.compoundSurnameClass
			] ),
			lookupExistingGivenNameItems()
		).then( function ( givenResults, familyResults, existingGivenResults ) {
			var suggestions = buildSuggestions( nameParts, {
				given: givenResults,
				family: familyResults,
				existingGivenNames: existingGivenResults
			} );

			getLabels( collectLabelIds( suggestions ) ).then( function ( labels ) {
				renderSuggestions( $panel, suggestions, labels );
			} );
		}, function () {
			$panel.empty()
				.append( $( '<h2>' ).text( interfaceText( 'title' ) ) )
				.append( $( '<p>' ).addClass( 'nsp-empty' ).text( interfaceText( 'none' ) ) );
		} );
	}

	$.when(
		mw.loader.using( [ 'mediawiki.api', 'mediawiki.util' ] ),
		$.ready
	).then( init );
}( mediaWiki, jQuery ) );
