/* global window */
( function () {
	'use strict';
	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settingsApi = window.wc && window.wc.wcSettings;
	var element = window.wp && window.wp.element;
	if ( ! registry || ! element ) {
		return;
	}

	var data = ( settingsApi && settingsApi.getSetting( 'onepaycx_data', {} ) ) || {};
	var label = data.title || 'Pay by card or crypto';
	var createElement = element.createElement;

	var Content = function () {
		return createElement( 'div', null, data.description || '' );
	};

	registry.registerPaymentMethod( {
		name: 'onepaycx',
		label: label,
		ariaLabel: label,
		canMakePayment: function () {
			return true;
		},
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		supports: {
			features: ( data.supports ) || [ 'products' ],
		},
	} );
} )();
