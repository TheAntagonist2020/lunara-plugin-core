( function ( $, wp ) {
	'use strict';

	if ( ! wp || ! wp.media ) {
		return;
	}

	var config = window.LunaraReviewImageStudio || {};

	function selectImage( card ) {
		var frame = wp.media( {
			title: config.title || 'Choose Review artwork',
			button: { text: config.button || 'Use this image' },
			library: { type: 'image' },
			multiple: false
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			var previewUrl = attachment.sizes && attachment.sizes.medium_large
				? attachment.sizes.medium_large.url
				: attachment.url;

			card.find( '.lunara-image-studio-id' ).val( attachment.id ).trigger( 'change' );
			card.find( '.lunara-image-studio-clear' ).val( '0' );
			card.find( '.lunara-image-studio-mode' ).val( 'custom' ).trigger( 'change' );
			card.find( '.lunara-image-studio-preview' ).html( '<img src="' + previewUrl + '" alt="">' );
		} );

		frame.open();
	}

	$( document ).on( 'click', '.lunara-image-studio-select', function ( event ) {
		event.preventDefault();
		selectImage( $( this ).closest( '.lunara-image-studio-card' ) );
	} );

	$( document ).on( 'click', '.lunara-image-studio-remove', function ( event ) {
		event.preventDefault();
		var card = $( this ).closest( '.lunara-image-studio-card' );
		card.find( '.lunara-image-studio-id' ).val( '0' ).trigger( 'change' );
		card.find( '.lunara-image-studio-clear' ).val( '1' );
		card.find( '.lunara-image-studio-preview' ).html( '<span>' + ( config.empty || 'No image selected' ) + '</span>' );
	} );

	$( document ).on( 'change', '.lunara-image-studio-mode', function () {
		var card = $( this ).closest( '.lunara-image-studio-card' );
		card.attr( 'data-mode', $( this ).val() );
	} );

	$( '.lunara-image-studio-mode' ).trigger( 'change' );
}( window.jQuery, window.wp ) );

