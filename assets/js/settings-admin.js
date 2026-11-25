/* Bazantik Photo Gallery – chování stránky Nastavení. */
( function () {
    'use strict';

    // Pole tempa zpracování jsou jen pro čtení, dokud je omezení vypnuté
    ( function () {
        var check = document.getElementById( 'bazantik_throttle_enabled' );
        if ( ! check ) {
            return;
        }
        var fields = document.querySelectorAll( '.bazantik-throttle-field' );
        function sync() {
            for ( var i = 0; i < fields.length; i++ ) {
                fields[ i ].readOnly = ! check.checked;
                fields[ i ].style.opacity = check.checked ? '' : '.5';
            }
        }
        check.addEventListener( 'change', sync );
        sync();
    }() );

    // Pole poskytovatele map se zobrazují podle vybrané předvolby
    ( function () {
        var provider = document.getElementById( 'bazantik_map_provider' );
        if ( ! provider ) {
            return;
        }
        var custom = document.querySelectorAll( '.bazantik-custom-map' );
        var keyRows = document.querySelectorAll( '.bazantik-key-map' );
        var home = document.getElementById( 'bazantik_map_home' );
        var optional = document.getElementById( 'bazantik_map_key_optional' );
        var keyInputs = document.querySelectorAll( '.bazantik-key-input' );
        function update() {
            var i;
            for ( i = 0; i < keyInputs.length; i++ ) {
                keyInputs[ i ].style.display = keyInputs[ i ].getAttribute( 'data-provider' ) === provider.value ? '' : 'none';
            }
            var option = provider.options[ provider.selectedIndex ];
            var needsKey = null !== option.getAttribute( 'data-key' );
            for ( i = 0; i < custom.length; i++ ) {
                custom[ i ].style.display = 'custom' === provider.value ? '' : 'none';
            }
            for ( i = 0; i < keyRows.length; i++ ) {
                keyRows[ i ].style.display = needsKey ? '' : 'none';
            }
            if ( optional ) {
                optional.style.display = 'optional' === option.getAttribute( 'data-key' ) ? '' : 'none';
            }
            if ( home ) {
                var url = option.getAttribute( 'data-home' );
                home.style.display = url ? '' : 'none';
                home.querySelector( 'a' ).href = url || '#';
            }
        }
        provider.addEventListener( 'change', update );
        update();
    }() );

    // Uložit je aktivní jen po změně a odchod s neuloženou změnou hlásí prohlížeč (jeho vlastním textem)
    window.addEventListener( 'load', function () {
        var form = document.getElementById( 'bazantik-settings-form' );
        if ( ! form || ! window.FormData || ! window.URLSearchParams ) {
            return;
        }
        var button = form.querySelector( '#submit' );
        var saving = false;
        function state() {
            return new URLSearchParams( new FormData( form ) ).toString();
        }
        var base = state();
        function dirty() {
            return state() !== base;
        }
        function sync() {
            if ( button ) {
                button.disabled = ! dirty();
            }
        }
        sync();
        form.addEventListener( 'input', sync );
        form.addEventListener( 'change', sync );
        form.addEventListener( 'submit', function () {
            saving = true;
        } );
        var zoom = document.getElementById( 'bazantik-zoom-apply-all' );
        if ( zoom ) {
            zoom.addEventListener( 'submit', function () {
                saving = true;
            } );
        }
        window.addEventListener( 'beforeunload', function ( event ) {
            if ( saving || ! dirty() ) {
                return;
            }
            event.preventDefault();
            event.returnValue = '';
        } );
    } );

    // Potvrzení nevratného smazání ručních voleb zdroje zoomu u alb
    document.addEventListener( 'DOMContentLoaded', function () {
        var form = document.getElementById( 'bazantik-zoom-apply-all' );
        if ( ! form || ! window.bazantikSettings ) {
            return;
        }
        form.addEventListener( 'submit', function ( event ) {
            if ( ! window.confirm( window.bazantikSettings.confirmZoom ) ) {
                event.preventDefault();
            }
        } );
    } );
}() );
