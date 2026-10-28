/*
 * 1pay.cx settings panel: the live parts.
 *
 * The page is a complete plain form without this file. Every switch, pill and address field
 * is a real input that posts under woocommerce_onepaycx_<field>, and the CSS reads the inputs'
 * state. This script adds: the mode cards switching what is shown, live address checks, the
 * "no wallet yet" lock, the method counter, "Show more", the Solana/TRON card switches, the
 * API-key box and Disconnect. It sends nothing anywhere and loads nothing.
 */
( function () {
	'use strict';

	// Runs in the <head> before the panel exists: switches off the no-script layout before first paint.
	document.documentElement.classList.add( 'onepaycx-js' );

	function ready( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	function closest( el, selector ) {
		while ( el && el.nodeType === 1 ) {
			if ( el.matches( selector ) ) {
				return el;
			}
			el = el.parentNode;
		}
		return null;
	}

	function all( root, selector ) {
		return Array.prototype.slice.call( root.querySelectorAll( selector ) );
	}

	ready( function () {
		var panel = document.getElementById( 'onepaycx-panel' );
		if ( ! panel ) {
			return;
		}

		function walletMode() {
			return panel.classList.contains( 'is-wallet' );
		}

		/** The address with all whitespace removed (what gets saved), checked against the pattern the server rendered. */
		function addressState( input ) {
			var value = input.value.replace( /\s+/g, '' );
			if ( value === '' ) {
				return 'empty';
			}
			try {
				return new RegExp( input.getAttribute( 'data-re' ) ).test( value ) ? 'valid' : 'invalid';
			} catch ( e ) {
				return 'valid'; // never block a save on a pattern this browser cannot read; the server decides
			}
		}

		// ---- "no wallet yet" lock (wallet mode, no valid EVM address) --------------------------
		function blocked() {
			return walletMode() && panel.classList.contains( 'evm-missing' );
		}

		function applyBlocked() {
			var isBlocked = blocked();
			var title = panel.getAttribute( 'data-blocked-title' ) || '';
			all( panel, '.op-blockable' ).forEach( function ( box ) {
				var input = box.querySelector( 'input.op-flag' );
				if ( input ) {
					if ( isBlocked ) {
						input.setAttribute( 'tabindex', '-1' );
						input.setAttribute( 'aria-disabled', 'true' );
					} else {
						input.removeAttribute( 'tabindex' );
						input.removeAttribute( 'aria-disabled' );
					}
				}
				var target = box.querySelector( '.op-sw' ) || box;
				if ( isBlocked && title ) {
					target.setAttribute( 'title', title );
				} else {
					target.removeAttribute( 'title' );
				}
			} );
		}

		// Clicks and the space bar on a locked switch do nothing (their state is kept for later).
		panel.addEventListener( 'click', function ( e ) {
			if ( blocked() && closest( e.target, '.op-blockable' ) ) {
				e.preventDefault();
			}
		}, true );

		// ---- mode cards -------------------------------------------------------------------------
		function setMode( mode ) {
			panel.classList.toggle( 'is-wallet', mode === 'wallet' );
			panel.classList.toggle( 'is-account', mode !== 'wallet' );
			applyBlocked();
		}

		all( panel, 'input[type="radio"][name$="_mode"]' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				if ( radio.checked ) {
					setMode( radio.value );
				}
			} );
		} );

		// ---- EVM wallet address ------------------------------------------------------------------
		var evm = document.getElementById( 'onepaycx-evm' );
		var evmCard = document.getElementById( 'op-wallet-card' );
		var evmShort = document.getElementById( 'op-evm-short' );

		function renderEvm() {
			var state = addressState( evm );
			var value = evm.value.replace( /\s+/g, '' );
			evmCard.setAttribute( 'data-evm', state );
			panel.classList.toggle( 'evm-missing', state !== 'valid' );
			if ( evmShort ) {
				evmShort.textContent = state === 'valid' ? value.slice( 0, 6 ) + '…' + value.slice( -4 ) : '';
			}
			applyBlocked();
		}

		if ( evm && evmCard ) {
			evm.addEventListener( 'input', renderEvm );
		}

		// ---- card and wallet methods -----------------------------------------------------------------
		var countOn = document.getElementById( 'op-count-on' );

		function renderCount() {
			if ( ! countOn ) {
				return;
			}
			var flags = all( panel, 'input.op-flag[type="checkbox"][name$="fiat_disabled[]"]' );
			countOn.textContent = String( flags.filter( function ( flag ) {
				return ! flag.checked;
			} ).length );
		}

		all( panel, 'input.op-flag[type="checkbox"][name$="fiat_disabled[]"]' ).forEach( function ( flag ) {
			flag.addEventListener( 'change', renderCount );
		} );

		var more = document.getElementById( 'op-more-btn' );
		if ( more ) {
			more.setAttribute( 'aria-expanded', 'false' );
			more.addEventListener( 'click', function () {
				var open = panel.classList.toggle( 'show-all' );
				more.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			} );
		}

		// ---- coins that need their own address ------------------------------------------------------------
		/**
		 * 1pay.cx never removes a payout address: a blank field over a saved one only means "keep it"
		 * (the server keeps it on save), so it is shown as "kept", not as a missing address.
		 */
		function coinState( coin, input ) {
			var state = addressState( input );
			return state === 'empty' && coin.getAttribute( 'data-saved' ) === '1' ? 'kept' : state;
		}

		function focusAddress( coin ) {
			var input = coin && coin.querySelector( '.op-addr input.op-in' );
			if ( input && walletMode() && coinState( coin, input ) === 'empty' ) {
				window.setTimeout( function () {
					input.focus();
				}, 0 );
			}
		}

		all( panel, '.op-coin' ).forEach( function ( coin ) {
			var input = coin.querySelector( '.op-addr input.op-in' );
			if ( input ) {
				input.addEventListener( 'input', function () {
					coin.setAttribute( 'data-addr', coinState( coin, input ) );
				} );
			}
		} );

		all( panel, '.op-coinbox' ).forEach( function ( box ) {
			var flag = box.querySelector( 'input.op-flag' );
			var coin = box.querySelector( '.op-coin' );
			if ( ! flag || ! coin ) {
				return;
			}
			var subs = all( box, '.op-subp input[type="checkbox"]' );
			flag.addEventListener( 'change', function () {
				if ( subs.length ) {
					// Solana and TRON: one address covers several tokens. Switching the card on offers the
					// usual tokens, switching it off turns them all off.
					if ( flag.checked && ! subs.some( function ( sub ) {
						return sub.checked;
					} ) ) {
						subs.forEach( function ( sub ) {
							sub.checked = sub.getAttribute( 'data-default' ) === '1';
						} );
					} else if ( ! flag.checked ) {
						subs.forEach( function ( sub ) {
							sub.checked = false;
						} );
					}
				}
				if ( flag.checked ) {
					focusAddress( coin );
				}
			} );
		} );

		// ---- 1pay.cx account ---------------------------------------------------------------------------------
		var keyBox = document.getElementById( 'op-keybox' );
		var keyToggle = document.getElementById( 'op-key-toggle' );
		var keyInput = document.getElementById( 'onepaycx-api-key' );

		if ( keyBox && keyInput && keyInput.value !== '' && ! panel.classList.contains( 'is-connected' ) ) {
			keyBox.classList.remove( 'is-collapsed' );
		}
		if ( keyBox && keyToggle ) {
			keyToggle.addEventListener( 'click', function () {
				keyBox.classList.toggle( 'is-collapsed' );
				if ( ! keyBox.classList.contains( 'is-collapsed' ) && keyInput ) {
					keyInput.focus();
				}
			} );
		}

		var disconnect = document.getElementById( 'op-disconnect' );
		if ( disconnect ) {
			// Takes effect when the form is saved: the key is cleared and the account card goes back to Connect.
			disconnect.addEventListener( 'click', function () {
				if ( keyInput ) {
					keyInput.value = '';
				}
				if ( keyBox ) {
					keyBox.classList.add( 'is-collapsed' );
				}
				panel.classList.remove( 'is-connected' );
			} );
		}

		// ---- save ---------------------------------------------------------------------------------------------------
		// An account that never narrowed its coins keeps "every coin" (an unset list) when nothing was changed,
		// so coins the platform adds later are still offered. Disabled inputs are not posted, and the server keeps
		// the saved value of a field that is not posted.
		var form = closest( panel, 'form' );
		if ( form ) {
			form.addEventListener( 'submit', function () {
				if ( panel.getAttribute( 'data-unset-crypto' ) !== '1' || walletMode() ) {
					return;
				}
				var named = all( panel, 'input[name$="crypto_accepted[]"]' );
				var untouched = named.every( function ( input ) {
					return input.type === 'hidden' || input.disabled || input.checked;
				} );
				if ( ! untouched ) {
					return;
				}
				var off = named.filter( function ( input ) {
					return ! input.disabled;
				} );
				off.forEach( function ( input ) {
					input.disabled = true;
				} );
				window.setTimeout( function () {
					off.forEach( function ( input ) {
						input.disabled = false;
					} );
				}, 0 );
			} );
		}

		applyBlocked();
		renderCount();
	} );
}() );
