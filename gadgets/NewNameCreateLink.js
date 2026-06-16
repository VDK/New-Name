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
		P1950: { type: 'family_name', languages: ['es'] },
		P9139: { type: 'family_name', languages: ['pt']  },
		P6978: { type: 'family_name', languages: ['no', 'nn', 'da', 'sv'] }
	};

	var currentField = null;
	var currentInfo = null;
	var updateTimer = null;

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

		if ( info.languages) {
			params.set( 'languages', info.languages.join( ',' ) );
		}

		return toolUrl + '?' + params.toString();
	}

	function updateLists() {
		var value;

		if ( !currentField || !currentInfo ) {
			return;
		}

		value = currentField.val() || '';

		$( '.ui-entityselector-list' ).each( function () {
			var $list = $( this ),
				$more = $list.find( '.ui-entityselector-more' ).not( '.newname-create-link' ).first(),
				$notFound = $list.find( '.ui-entityselector-notfound' ).first(),
				$newName = $list.find( '.newname-create-link' ).first(),
				$link;

			if ( !value.trim() ) {
				$newName.remove();
				return;
			}

			if ( !$newName.length ) {
				$newName = $( '<li>' )
					.addClass( 'ui-ooMenu-item ui-ooMenu-customItem ui-ooMenu-customItem-action ui-entityselector-more newname-create-link' )
					.attr( 'dir', 'auto' )
					.append( $( '<a>' ).attr( 'tabindex', '-1' ) );
			}

			$link = $newName.find( 'a' ).first();

			$link
				.attr( {
					href: newNameUrl( value, currentInfo ),
					target: '_blank',
					rel: 'noopener noreferrer'
				} )
				.text( message( currentInfo.type ) + ': ' + value.trim() );

			if ( $more.length ) {
				$newName.insertBefore( $more );
			} else if ( $notFound.length ) {
				$notFound.replaceWith( $newName );
			} else {
				$list.prepend( $newName );
			}
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
			property = snakview && snakview.value().property;

		currentField = $( event.target );
		currentInfo = propertyInfo[ property ] || null;

		if ( !currentInfo ) {
			return;
		}

		scheduleUpdates();
	}

		function bindInputs() {
			$( document )
				.off(
					'input.newNameCreateLink paste.newNameCreateLink keyup.newNameCreateLink change.newNameCreateLink newnamecreate:update.newNameCreateLink',
					'.valueview-input'
				)
				.on(
					'input.newNameCreateLink paste.newNameCreateLink keyup.newNameCreateLink change.newNameCreateLink newnamecreate:update.newNameCreateLink',
					'.valueview-input',
					inputHandler
				);
		}

	$( function () {
		bindInputs();
	} );
}( jQuery, mediaWiki ) );
