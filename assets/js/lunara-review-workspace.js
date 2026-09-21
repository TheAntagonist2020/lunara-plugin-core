( function () {
	'use strict';

	var workspace = document.querySelector( '[data-lunara-review-workspace]' );
	if ( ! workspace ) {
		return;
	}

	var stage = workspace.querySelector( '[data-lunara-review-stage]' );
	var empty = workspace.querySelector( '[data-lunara-review-empty]' );
	var tabs = Array.prototype.slice.call( workspace.querySelectorAll( '[data-lunara-review-tab]' ) );
	var storageKey = 'lunaraReviewStudioActiveTab';
	var sources = {
		intake: [ '#lunara_review_draft_import' ],
		details: [ '#lunara_debrief_meta', '#lunara_review_details_meta' ],
		debrief: [ '#acf-group_lunara_review_trinity', '.acf-postbox[data-key="group_lunara_review_trinity"]' ],
		images: [ '#lunara-review-image-studio' ]
	};
	var panels = {};
	var availableTabs = [];

	if ( ! stage || ! tabs.length ) {
		return;
	}

	function uniqueNodes( selectors ) {
		var nodes = [];
		selectors.forEach( function ( selector ) {
			Array.prototype.forEach.call( document.querySelectorAll( selector ), function ( node ) {
				if ( nodes.indexOf( node ) === -1 ) {
					nodes.push( node );
				}
			} );
		} );
		return nodes;
	}

	tabs.forEach( function ( tab ) {
		var slug = tab.getAttribute( 'data-lunara-review-tab' );
		var boxes = uniqueNodes( sources[ slug ] || [] );
		if ( ! boxes.length ) {
			tab.hidden = true;
			return;
		}

		var panel = document.createElement( 'section' );
		panel.className = 'lunara-review-workspace__panel';
		panel.id = 'lunara-review-workspace-panel-' + slug;
		panel.setAttribute( 'role', 'tabpanel' );
		panel.setAttribute( 'aria-labelledby', tab.id );
		panel.hidden = true;

		boxes.forEach( function ( box ) {
			box.classList.add( 'lunara-review-workspace__box' );
			box.classList.remove( 'closed' );
			box.classList.remove( 'hide-if-js' );
			box.hidden = false;
			box.style.display = '';
			box.setAttribute( 'data-lunara-review-workspace-box', slug );
			var toggle = box.querySelector( '.handlediv' );
			if ( toggle ) {
				toggle.setAttribute( 'aria-expanded', 'true' );
			}
			panel.appendChild( box );
		} );

		stage.appendChild( panel );
		panels[ slug ] = panel;
		availableTabs.push( tab );
	} );

	if ( ! availableTabs.length ) {
		workspace.hidden = false;
		if ( empty ) {
			empty.hidden = false;
		}
		return;
	}

	function remember( slug ) {
		try {
			window.sessionStorage.setItem( storageKey, slug );
		} catch ( error ) {
			// Storage can be disabled without affecting the workspace.
		}
	}

	function activate( slug, focusTab ) {
		if ( ! panels[ slug ] ) {
			return;
		}

		availableTabs.forEach( function ( tab ) {
			var tabSlug = tab.getAttribute( 'data-lunara-review-tab' );
			var selected = tabSlug === slug;
			tab.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
			tab.setAttribute( 'tabindex', selected ? '0' : '-1' );
			tab.classList.toggle( 'is-active', selected );
			panels[ tabSlug ].hidden = ! selected;
		} );

		remember( slug );
		if ( focusTab ) {
			var activeTab = workspace.querySelector( '[data-lunara-review-tab="' + slug + '"]' );
			if ( activeTab ) {
				activeTab.focus();
			}
		}
	}

	availableTabs.forEach( function ( tab, index ) {
		tab.addEventListener( 'click', function () {
			activate( tab.getAttribute( 'data-lunara-review-tab' ), false );
		} );

		tab.addEventListener( 'keydown', function ( event ) {
			var nextIndex = index;
			if ( event.key === 'ArrowRight' ) {
				nextIndex = ( index + 1 ) % availableTabs.length;
			} else if ( event.key === 'ArrowLeft' ) {
				nextIndex = ( index - 1 + availableTabs.length ) % availableTabs.length;
			} else if ( event.key === 'Home' ) {
				nextIndex = 0;
			} else if ( event.key === 'End' ) {
				nextIndex = availableTabs.length - 1;
			} else {
				return;
			}
			event.preventDefault();
			activate( availableTabs[ nextIndex ].getAttribute( 'data-lunara-review-tab' ), true );
		} );
	} );

	stage.addEventListener( 'invalid', function ( event ) {
		var panel = event.target.closest( '.lunara-review-workspace__panel' );
		if ( panel ) {
			activate( panel.id.replace( 'lunara-review-workspace-panel-', '' ), false );
		}
	}, true );

	var observer = new MutationObserver( function () {
		var errorField = stage.querySelector( '.acf-error, .form-invalid, [aria-invalid="true"]' );
		var errorPanel = errorField ? errorField.closest( '.lunara-review-workspace__panel' ) : null;
		if ( errorPanel ) {
			activate( errorPanel.id.replace( 'lunara-review-workspace-panel-', '' ), false );
		}
	} );
	observer.observe( stage, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'class', 'aria-invalid' ] } );

	var requested = '';
	try {
		requested = window.sessionStorage.getItem( storageKey ) || '';
	} catch ( error ) {
		requested = '';
	}
	if ( window.location.hash === '#lunara-artwork' ) {
		requested = 'images';
	}
	if ( ! panels[ requested ] ) {
		requested = availableTabs[ 0 ].getAttribute( 'data-lunara-review-tab' );
	}

	activate( requested, false );
	workspace.hidden = false;
	document.body.classList.add( 'lunara-review-workspace-ready' );
}() );
