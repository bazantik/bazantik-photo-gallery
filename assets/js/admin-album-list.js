/* Bazantik Photo Gallery – Quick Edit alb: předvyplnění soukromí, odkazu a hesla, skrytí nativních polí Password a Private. */
( function ( $ ) {
    'use strict';

    function sync( $row, vis ) {
        $row.find( '.bazantik-fld-unlisted' ).toggle( 'unlisted' === vis );
        $row.find( '.bazantik-fld-password' ).toggle( 'password' === vis );
    }

    $( document ).on( 'change', '.bazantik-qe-vis', function () {
        sync( $( this ).closest( 'tr' ), $( this ).val() );
    } );
    $( document ).on( 'focus click', '.bazantik-select-on-click', function () {
        this.select();
    } );

    // Tlačítko Update je aktivní jen při skutečné změně (porovnání s výchozím stavem formuláře)
    function formState( $row ) {
        return $row.find( ':input' ).not( ':button, [type="submit"]' ).serialize();
    }
    function watch( $row ) {
        if ( $row.data( 'bazantikWatching' ) ) {
            return;
        }
        $row.data( 'bazantikWatching', true );
        $row.on( 'input change', function () {
            $row.find( '.button-primary.save' ).prop( 'disabled', formState( $row ) === $row.data( 'bazantikBaseline' ) );
        } );
    }
    if ( window.inlineEditPost && window.inlineEditPost.save ) {
        // WordPress odesílá Quick Edit i klávesou Enter, proto se zabalí funkce uložení, ne jen tlačítko
        var originalSave = window.inlineEditPost.save;
        window.inlineEditPost.save = function ( id ) {
            var postId = 'object' === typeof id ? this.getId( id ) : id;
            var $saveRow = $( '#edit-' + postId );
            if ( $saveRow.length && undefined !== $saveRow.data( 'bazantikBaseline' ) && $saveRow.data( 'bazantikBaseline' ) === formState( $saveRow ) ) {
                return false;
            }
            // Album s ochranou heslem musí mít heslo (dosavadní, nebo nové): jinak upozornění u pole a nic se neuloží
            var $pwField = $saveRow.find( '.bazantik-qe-pw' );
            if ( $pwField.length && 'password' === $saveRow.find( '.bazantik-qe-vis' ).val() && '' === $pwField.val() &&
                ( '1' === $pwField.closest( '.bazantik-pw-wrap' ).next( '.bazantik-pw-changed' ).val() || ! $saveRow.data( 'bazantikHasPw' ) ) &&
                window.bazantikRequirePassword ) {
                window.bazantikRequirePassword( $pwField[ 0 ], bazantikAlbumList.pwRequired );
                return false;
            }
            // Odhalené nezměněné heslo se do požadavku neodesílá (QE neodesílá událost submit)
            $saveRow.find( '.bazantik-qe-pw' ).each( function () {
                if ( this.value && '0' === $( this ).closest( '.bazantik-pw-wrap' ).next( '.bazantik-pw-changed' ).val() ) {
                    this.value = '';
                }
            } );
            return originalSave.apply( this, arguments );
        };
    }
    if ( window.inlineEditPost && window.inlineEditPost.edit ) {
        var original = window.inlineEditPost.edit;
        window.inlineEditPost.edit = function ( id ) {
            var postId = 'object' === typeof id ? this.getId( id ) : id;
            // Řádek alba se při úpravě skryje, proto se poloha přebalu a názvu změří dřív
            var $post = $( '#post-' + postId );
            var $img = $post.find( '.column-bazantik_cover img' ).first();
            var cover = null;
            if ( $img.length && $img.is( ':visible' ) && $post.find( '.row-title' ).length ) {
                var rowPos = $post.offset();
                var imgPos = $img.offset();
                cover = {
                    $clone: $img.clone().addClass( 'bazantik-qe-cover' ),
                    left: imgPos.left - rowPos.left,
                    titleLeft: $post.find( '.row-title' ).offset().left - rowPos.left
                };
            }
            original.apply( this, arguments );
            var $row = $( '#edit-' + postId );
            var $cell = $row.children( 'td' ).first();
            $cell.find( '.bazantik-qe-cover' ).remove();
            $cell.css( { position: '', paddingLeft: '' } );
            if ( cover && $cell.length ) {
                // Přebal zůstane na místě sloupce a pole Quick Edit začínají v úrovni názvu alba
                var inner = $row.find( 'fieldset' ).first().offset().left - $cell.offset().left;
                $cell.css( {
                    position: 'relative',
                    paddingLeft: ( parseFloat( $cell.css( 'padding-left' ) ) || 0 ) + Math.max( 0, cover.titleLeft - inner ) + 'px'
                } );
                cover.$clone.css( { position: 'absolute', left: cover.left + 'px', top: '34px' } ).appendTo( $cell );
            }
            var $info = $( '#post-' + postId ).find( '.bazantik-vis' );
            if ( ! $row.length || ! $row.find( '.bazantik-qe-vis' ).length ) {
                return;
            }
            watch( $row );
            var $native = $row.find( 'input[name="post_password"]' );
            // Heslo se do pole nevyplňuje (správce hesel by nabízel uložení); načte se až tlačítkem oko
            var hasPassword = 'password' === $info.data( 'vis' );
            $row.data( 'bazantikHasPw', hasPassword );
            var $pw = $row.find( '.bazantik-qe-pw' );
            $pw.val( '' ).removeClass( 'bazantik-pw-reveal' ).attr( 'data-bazantik-id', hasPassword ? postId : '0' )
                .attr( 'placeholder', hasPassword ? bazantikAlbumList.pwSet : bazantikAlbumList.pwEnter );
            $pw.closest( '.bazantik-pw-wrap' ).next( '.bazantik-pw-changed' ).val( '0' );
            $native.val( '' ).closest( '.inline-edit-group' ).hide();
            $row.find( '.bazantik-qe-vis' ).val( $info.data( 'vis' ) || 'public' ).trigger( 'change' );
            $row.find( '.bazantik-qe-link' ).val( $info.data( 'link' ) || '' );
            $row.data( 'bazantikBaseline', formState( $row ) );
            $row.find( '.button-primary.save' ).prop( 'disabled', true );
        };
    }
}( jQuery ) );
