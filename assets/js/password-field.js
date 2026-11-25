/* Bazantik Photo Gallery – přepínání viditelnosti hesla (tlačítko oko uvnitř pole). */
( function () {
    'use strict';

    // Pole s načteným heslem, které uživatel nepřepsal (příznak změny je „0“)
    function bazantikUnchanged( field ) {
        var wrap = field.closest( '.bazantik-pw-wrap' );
        var flag = wrap && wrap.nextElementSibling;
        return !! ( flag && flag.classList.contains( 'bazantik-pw-changed' ) && '0' === flag.value );
    }

    document.addEventListener( 'click', function ( event ) {
        var target = event.target;
        var button = target && target.closest ? target.closest( '.bazantik-pw-toggle' ) : null;
        if ( ! button ) {
            return;
        }
        var wrap = button.closest( '.bazantik-pw-wrap' );
        var input = wrap ? wrap.querySelector( 'input' ) : null;
        if ( ! input ) {
            return;
        }
        if ( input.hasAttribute( 'data-bazantik-mask' ) && ! input.hasAttribute( 'data-bazantik-native' ) ) {
            var reveal = ! input.classList.contains( 'bazantik-pw-reveal' );
            // Uložené heslo se do pole nevkládá při načtení stránky (správce hesel by nabízel uložení), načte se až na požádání
            var type = input.getAttribute( 'data-bazantik-reveal' );
            var postId = input.getAttribute( 'data-bazantik-id' );
            if ( reveal && type && postId && '0' !== postId && '' === input.value && window.bazantikPwReveal && window.fetch ) {
                var body = new URLSearchParams( { action: 'bazantik_reveal_password', _wpnonce: window.bazantikPwReveal.nonce, type: type, id: postId } );
                window.fetch( window.bazantikPwReveal.url, { method: 'POST', body: body, credentials: 'same-origin' } )
                    .then( function ( response ) {
                        return response.json();
                    } )
                    .then( function ( result ) {
                        if ( ! result || ! result.success || ! result.data ) {
                            throw new Error( 'no password' );
                        }
                        input.value = result.data.password;
                        input.classList.add( 'bazantik-pw-reveal' );
                        button.setAttribute( 'aria-pressed', 'true' );
                    } )
                    .catch( function () {
                        input.setAttribute( 'placeholder', window.bazantikPwReveal.error );
                    } );
                return;
            }
            input.classList.toggle( 'bazantik-pw-reveal', reveal );
            button.setAttribute( 'aria-pressed', reveal ? 'true' : 'false' );
            input.focus();
            return;
        }
        var show = 'password' === input.type;
        input.type = show ? 'text' : 'password';
        button.setAttribute( 'aria-pressed', show ? 'true' : 'false' );
        input.focus();
    } );

    // Úprava hesla uživatelem (programové vyplnění po kliknutí na oko událost „input“ nevyvolá)
    document.addEventListener( 'input', function ( event ) {
        var field = event.target;
        if ( ! field || ! field.hasAttribute || ! field.hasAttribute( 'data-bazantik-reveal' ) ) {
            return;
        }
        var wrap = field.closest( '.bazantik-pw-wrap' );
        var flag = wrap && wrap.nextElementSibling;
        if ( flag && flag.classList.contains( 'bazantik-pw-changed' ) ) {
            flag.value = '1';
        }
    } );

    // Před odesláním formuláře se neupravené načtené heslo z pole odstraní; server při příznaku „0“ ponechá uložené heslo
    document.addEventListener( 'submit', function ( event ) {
        var form = event.target;
        if ( ! form || ! form.querySelectorAll ) {
            return;
        }
        Array.prototype.forEach.call( form.querySelectorAll( 'input[data-bazantik-reveal]' ), function ( field ) {
            if ( '' !== field.value && bazantikUnchanged( field ) ) {
                field.value = '';
            }
        } );
    }, true );

    // Upozornění u pole hesla (vlastní bublina; nativní bublinu prohlížeče nelze stylovat). Zmizí při úpravě pole, odchodu z něj nebo změně soukromí.
    window.bazantikRequirePassword = function ( field, message ) {
        var wrap = field.closest( '.bazantik-pw-wrap' );
        if ( ! wrap ) {
            return;
        }
        var old = wrap.querySelector( '.bazantik-bubble' );
        if ( old ) {
            old.remove();
        }
        var bubble = document.createElement( 'div' );
        bubble.className = 'bazantik-bubble';
        bubble.setAttribute( 'role', 'alert' );
        bubble.textContent = message;
        wrap.appendChild( bubble );
        field.focus();
        var clear = function () {
            bubble.remove();
            field.removeEventListener( 'input', clear );
            field.removeEventListener( 'blur', clear );
            document.removeEventListener( 'change', clear );
        };
        field.addEventListener( 'input', clear );
        field.addEventListener( 'blur', clear );
        document.addEventListener( 'change', clear );
    };

    // Prohlížeč bez podpory zakrytí textu přes CSS: pole se vrátí na běžné type="password"
    var masked = document.querySelectorAll( 'input[data-bazantik-mask]' );
    if ( masked.length && ! ( window.CSS && CSS.supports && ( CSS.supports( '-webkit-text-security', 'disc' ) || CSS.supports( 'text-security', 'disc' ) ) ) ) {
        Array.prototype.forEach.call( masked, function ( field ) {
            field.type = 'password';
            field.setAttribute( 'data-bazantik-native', '1' );
        } );
    }

    // Odemknutí na webu: výsledek se zpracuje na pozadí a stránka se nahradí v historii (tlačítko Zpět pak nevrací na formulář)
    document.addEventListener( 'submit', function ( event ) {
        var form = event.target;
        if ( ! form || ! form.matches || ! form.matches( '.bazantik-pw-form, .bazantik-folder-password form' ) || ! window.fetch || ! window.FormData ) {
            return;
        }
        event.preventDefault();
        var data = new FormData( form );
        data.append( 'bazantik_ajax', '1' );
        var submit = form.querySelector( 'input[type="submit"]' );
        if ( submit ) {
            submit.disabled = true;
        }
        // form.action je zastíněné polem se jménem „action“
        window.fetch( form.getAttribute( 'action' ), { method: 'POST', body: data, credentials: 'same-origin' } )
            .then( function ( response ) {
                return response.json();
            } )
            .then( function ( result ) {
                if ( ! result || ! result.url ) {
                    throw new Error( 'bad response' );
                }
                window.location.replace( result.url );
            } )
            .catch( function () {
                // Záložní řešení: běžné odeslání formuláře
                HTMLFormElement.prototype.submit.call( form );
            } );
    } );
}() );
