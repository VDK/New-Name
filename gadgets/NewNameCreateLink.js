/**
 * NewNameCreateLink
 *
 * Adds a link to New Name on Toolforge in Wikibase item selectors when no matching
 * item exists yet.
 *
 * Supported properties:
 * - given names (P735)
 * - family names (P734, P1950, P6978, P9139)
 *
 * The link opens New Name with the entered value prefilled, the appropriate
 * name type selected, and a language hint when the property implies one.
 *
 * Located at [[User:1Veertje/NewNameCreateLink.js]]
 *
 * @author Vera de Kok (1Veertje)
 * @license MIT
 * @version 1.0.0 (2026-06-15)
 */
( function ( $, mw ) {
	'use strict';
	var toolUrl = 'https://nn.toolforge.org/';
	var propertyInfo = {
		P735: { type: 'given_name' },
		P734: { type: 'family_name' },
		P1533: { type: 'family_name' },
		P1950: { type: 'family_name', languages: [ 'es' ] },
		P9139: { type: 'family_name', languages: [ 'pt' ]  },
		P6978: { type: 'family_name', languages: [ 'no', 'nn', 'da', 'sv' ] }
	};
	var currentField = null;
	var currentInfo = null;
	var updateTimer = null;
	mw.util.addCSS(
		'.newname-create-link-wrapper {' +
            'padding:4px 12px 4px 0;' +
            'border-top:1px solid #eaecf0;' +
            'background:#fff;' +
       '}'+
		'.newname-create-link-wrapper a {'+
		'    color:#36c;'+
		'    text-decoration:none;'+
		'    display:flex;'+
		'    justify-content:space-between;'+
		'    align-items:center;'+
		'}'+
		'.newname-create-link-wrapper a:hover {' +
			'text-decoration:underline;' +
		'}' +
		'.newname-shortcut {' +
			'color:#72777d;' +
			'font-size:0.9em;' +
			'flex-shrink:0;' +
		'}'
	);
	function message( type ) {
		var messages = {
			given_name: {
				nl: 'Maak een nieuw voornaam-item',
				de: 'Neues Vornamen-Item erstellen',
				fr: 'Créer un nouvel élément de prénom',
				es: 'Crear un elemento de nombre de pila nuevo',
				en: 'Create a new given name item'
			},
			family_name: {
				nl: 'Maak een nieuw achternaam-item',
				de: 'Neues Familiennamen-Item erstellen',
				fr: 'Créer un nouvel élément de nom de famille',
				es: 'Crear un elemento de apellido nuevo',
				en: 'Create a new family name item'
			}
		};
		return (
			messages[ type ] &&
			messages[ type ][ mw.config.get( 'wgUserLanguage' ) ]
		) || messages[ type ].en;
	}
	function newNameUrl( value, info ) {
		var params = new URLSearchParams( {
			name: value.trim(),
			type: info.type,
			ui: mw.config.get( 'wgUserLanguage' )
		} );
		if ( info.languages ) {
			params.set( 'languages', info.languages.join( ',' ) );
		}
		if ( info._equivalent ) {
			params.set( '_equivalent', info._equivalent );
		}
		return toolUrl + '?' + params.toString();
	}
	function updateLists() {
		var value = currentField ? currentField.val() || '' : '';
		$( '.newname-create-link-wrapper' ).remove();
		if ( !currentField || !currentInfo || !value.trim() ) {
			return;
		}
		$( '.ui-entityselector-list' ).each( function () {
			var $list = $( this ),
				$wrapper,
				$link;
			$wrapper = $( '<div>' )
				.addClass( 'newname-create-link-wrapper' );
			$link = $( '<a>' )
				.attr( {
					href: newNameUrl( value, currentInfo ),
					target: '_blank',
					rel: 'noopener noreferrer'
				} )
				.append(
					document.createTextNode(
						message( currentInfo.type ) + ': ' + value.trim() + ' '
					)
				)
				.append(
					$( '<span>' )
						.addClass( 'newname-shortcut' )
						.text( '(Alt+Enter)' )
				);
			$wrapper.append( $link );
			$list.prepend( $wrapper );
		} );
	}
	function scheduleUpdates() {
		var runs = 0;
		if ( updateTimer ) {
			clearInterval( updateTimer );
		}
		updateTimer = setInterval( function () {
			updateLists();
			runs++;
			if ( runs >= 15 ) {
				clearInterval( updateTimer );
				updateTimer = null;
			}
		}, 100 );
	}
	function inputHandler( event ) {
		var $valueview = $( event.target ).closest( '.valueview' ),
			$snakview = $valueview.closest( '.wikibase-snakview' ),
			snakview = $snakview.data( 'snakview' ),
			property = snakview && snakview.value().property,
			info;
		currentField = $( event.target );
		info = propertyInfo[ property ] || null;
		if ( !info ) {
			currentInfo = null;
			return;
		}

		// Don't show in qualifier fields
		if ( $( event.target ).closest( '.wikibase-statementview-qualifiers' ).length ) {
			currentInfo = null;
			return;
		}

		currentInfo = $.extend( {}, info );
		if ( property === 'P1533' ) {
			currentInfo._equivalent = mw.config.get( 'wbEntityId' );
		}
		scheduleUpdates();
	}
	function bindInputs() {
		$( document )
			.off(
				'input.newNameCreateLink paste.newNameCreateLink keyup.newNameCreateLink change.newNameCreateLink newnamecreate:update.newNameCreateLink keydown.newNameCreateLink',
				'.valueview-input'
			)
			.on(
				'input.newNameCreateLink paste.newNameCreateLink keyup.newNameCreateLink change.newNameCreateLink newnamecreate:update.newNameCreateLink',
				'.valueview-input',
				inputHandler
			)
			.on( 'keydown.newNameCreateLink', '.valueview-input', keydownHandler );
	}
	function keydownHandler( event ) {
		var value;
		if ( !currentField || !currentInfo ) {
			return;
		}
		if ( event.key !== 'Enter' || !event.altKey ) {
			return;
		}
		value = currentField.val() || '';
		if ( !value.trim() ) {
			return;
		}
		event.preventDefault();
		window.open( newNameUrl( value, currentInfo ), '_blank', 'noopener' );
	}
	$( function () {
		bindInputs();
	} );
}( jQuery, mediaWiki ) );