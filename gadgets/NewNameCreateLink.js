/**
 * Add a New Name link to Wikibase item selectors when no item was found.
 *
 * Suggested gadget page:
 * MediaWiki:Gadget-NewNameCreateLink.js
 */
( function ( $, mw ) {
	'use strict';

	var toolUrl = 'https://new-name.toolforge.org/';

	function message() {
		switch ( mw.config.get( 'wgUserLanguage' ) ) {
			case 'nl':
				return 'Maak een nieuw naamitem';
			case 'de':
				return 'Neues Namensitem erstellen';
			case 'fr':
				return 'Créer un nouvel élément de nom';
			case 'es':
				return 'Crear un elemento de nombre nuevo';
			default:
				return 'Create a new name item';
		}
	}

	function newNameUrl( value ) {
		return toolUrl + '?name=' + encodeURIComponent( value.trim() );
	}

	function init() {
		var currentField = $( 'input' );
		var pendingSelectorUpdate = false;

		$( document ).on( 'input propertychange paste', '.wikibase-snakview-value-container:has(.ui-suggester-input)', function () {
			currentField = $( this ).find( '.ui-suggester-input' ).first();
			pendingSelectorUpdate = true;
		} );

		$( document ).on( 'DOMSubtreeModified', '.ui-entityselector-list', function () {
			if ( !pendingSelectorUpdate ) {
				return;
			}

			var firstLi = $( this ).find( 'li' ).first();
			if ( !firstLi.hasClass( 'ui-entityselector-notfound' ) ) {
				return;
			}

			pendingSelectorUpdate = false;
			var currentValue = currentField.val() || '';
			var $link = firstLi.find( 'a' ).first();
			$link
				.attr( {
					href: newNameUrl( currentValue ),
					target: '_blank',
					rel: 'noopener noreferrer'
				} )
				.text( $link.text() + '. ' + message() + '...' );
		} );
	}

	$( function () {
		mw.hook( 'wikibase.entityPage.entityLoaded' ).add( init );
	} );
}( jQuery, mediaWiki ) );
