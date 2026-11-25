/* Bazantik Photo Gallery – administrace složek alb: zobrazení polí podle soukromí, rychlá úprava, označení odkazu, tlačítko Zrušit. */
( function ( $ ) {
    'use strict';

    function syncFields( $scope, vis ) {
        $scope.find( '.bazantik-fld-unlisted' ).toggle( 'unlisted' === vis );
        $scope.find( '.bazantik-fld-password' ).toggle( 'password' === vis );
    }

    // Přidání a úprava složky
    var $main = $( '#bazantik_folder_visibility' );
    if ( $main.length ) {
        var $scope = $main.closest( 'form' );
        var update = function () {
            syncFields( $scope, $main.val() );
        };
        $main.on( 'change', update );
        update();
        // Po přidání složky přes AJAX WordPress vyprázdní formulář
        $( document ).ajaxComplete( function () {
            window.setTimeout( update, 50 );
        } );
    }

    // Tlačítko Update je aktivní jen při skutečné změně (porovnání s výchozím stavem formuláře)
    function formState( $scope ) {
        return $scope.find( ':input' ).not( ':button, [type="submit"]' ).serialize();
    }
    function watchChanges( $scope, $button, baseline ) {
        var refresh = function () {
            $button.prop( 'disabled', formState( $scope ) === baseline );
        };
        $scope.on( 'input change', refresh );
        refresh();
        return refresh;
    }
    var $editForm = $( '#edittag' );
    if ( $editForm.length ) {
        var $update = $editForm.find( 'input[type="submit"].button-primary' ).first();
        var refreshEdit = watchChanges( $editForm, $update, formState( $editForm ) );
        $( window ).on( 'load', refreshEdit );
        $editForm.on( 'submit', function ( e ) {
            if ( $update.prop( 'disabled' ) ) {
                e.preventDefault();
            }
        } );
    }

    // Zaheslovaná složka musí mít heslo: vlastní bublina u pole hesla (nativní bublinu prohlížeče nelze stylovat), složka se neuloží
    function requirePassword( field ) {
        var message = window.bazantikFolders && window.bazantikFolders.pwRequired ? window.bazantikFolders.pwRequired : 'A password-protected folder must have a password.';
        var $wrap = $( field ).closest( '.bazantik-pw-wrap' );
        $wrap.find( '.bazantik-bubble' ).remove();
        var $bubble = $( '<div class="bazantik-bubble" role="alert"></div>' ).text( message ).appendTo( $wrap );
        field.focus();
        var clear = function () {
            $bubble.remove();
            $( field ).off( 'input blur', clear );
            $( document ).off( 'change', clear );
        };
        $( field ).on( 'input blur', clear );
        $( document ).on( 'change', clear );
    }
    function lacksPassword( vis, field, hashed ) {
        // Prázdné pole u uloženého hesla je v pořádku, dokud ho uživatel sám neupravil (neodstranil)
        var changed = field ? $( field ).closest( '.bazantik-pw-wrap' ).next( '.bazantik-pw-changed' ).val() : '';
        return 'password' === vis && field && '' === field.value && ( ! hashed || '1' === changed );
    }
    var addForm = document.getElementById( 'addtag' );
    if ( addForm ) {
        // Přidání složky odesílá WordPress přes AJAX, proto se kontroluje už při kliknutí (před jeho obsluhou)
        addForm.addEventListener( 'click', function ( e ) {
            var field = addForm.querySelector( 'input[name="bazantik_folder_pw"]' );
            if ( e.target.closest && e.target.closest( '#submit' ) && lacksPassword( $( '#bazantik_folder_visibility' ).val(), field, false ) ) {
                e.preventDefault();
                e.stopPropagation();
                requirePassword( field );
            }
        }, true );
    }
    var editForm = document.getElementById( 'edittag' );
    if ( editForm ) {
        editForm.addEventListener( 'submit', function ( e ) {
            var field = editForm.querySelector( 'input[name="bazantik_folder_pw"]' );
            if ( lacksPassword( $( '#bazantik_folder_visibility' ).val(), field, $( '#bazantik_folder_hashed' ).length ) ) {
                e.preventDefault();
                e.stopPropagation();
                requirePassword( field );
            }
        }, true );
    }

    // Roletka nadřazené složky v Quick Editu se po přidání nebo smazání složky (AJAX) srovná s roletkou formuláře přidání, které WordPress aktualizuje sám
    function syncQuickEditParents() {
        var $source = $( '#addtag #parent' );
        if ( ! $source.length ) {
            return;
        }
        var ids = {};
        $source.find( 'option' ).each( function () {
            ids[ this.value ] = true;
        } );
        $( '.bazantik-qe-parent' ).each( function () {
            var $qe = $( this );
            $qe.find( 'option' ).each( function () {
                if ( '0' !== this.value && ! ids[ this.value ] ) {
                    $( this ).remove();
                }
            } );
            var $previous = $qe.find( 'option[value="0"]' ).first();
            $source.find( 'option' ).each( function () {
                if ( parseInt( this.value, 10 ) <= 0 ) {
                    return;
                }
                var $own = $qe.find( 'option[value="' + this.value + '"]' );
                if ( ! $own.length ) {
                    var level = Math.floor( ( this.textContent.match( /^[\s\u00a0]*/ )[ 0 ].length ) / 3 );
                    $own = $( this ).clone().prop( 'selected', false ).attr( 'class', 'level-' + level ).insertAfter( $previous );
                }
                $previous = $own;
            } );
        } );
    }
    $( document ).ajaxSuccess( function ( event, xhr, settings ) {
        if ( settings && 'string' === typeof settings.data && /action=(add|delete)-tag/.test( settings.data ) ) {
            window.setTimeout( syncQuickEditParents, 0 );
        }
    } );

    // Přidání skryté složky: vzniklý tajný odkaz se zkopíruje do schránky a zobrazí v oznámení
    var knownLinks = null;
    var pendingCopy = null;
    function rowLinks() {
        var links = [];
        $( '#the-list .bazantik-vis' ).each( function () {
            var l = $( this ).data( 'link' );
            if ( l ) {
                links.push( String( l ) );
            }
        } );
        return links;
    }
    $( '#submit' ).on( 'click', function () {
        knownLinks = null;
        pendingCopy = null;
        if ( 'unlisted' !== $( '#bazantik_folder_visibility' ).val() || ! $( '#tag-name' ).val() ) {
            return;
        }
        knownLinks = rowLinks();
        if ( window.ClipboardItem && navigator.clipboard && navigator.clipboard.write ) {
            // Zápis se zahájí ještě v rámci kliknutí (Safari to vyžaduje), text doplní odpověď serveru
            pendingCopy = {};
            var promise = new Promise( function ( resolve, reject ) {
                pendingCopy.resolve = resolve;
                pendingCopy.reject = reject;
            } );
            pendingCopy.result = navigator.clipboard.write( [ new ClipboardItem( {
                'text/plain': promise.then( function ( t ) {
                    return new Blob( [ t ], { type: 'text/plain' } );
                } )
            } ) ] ).then( function () {
                return true;
            }, function () {
                return false;
            } );
        }
    } );
    $( document ).ajaxComplete( function ( event, xhr, settings ) {
        if ( null === knownLinks || ! settings || 'string' !== typeof settings.data || -1 === settings.data.indexOf( 'action=add-tag' ) ) {
            return;
        }
        window.setTimeout( function () {
            var fresh = rowLinks().filter( function ( l ) {
                return -1 === knownLinks.indexOf( l );
            } )[ 0 ];
            var copy = pendingCopy;
            knownLinks = null;
            pendingCopy = null;
            if ( ! fresh ) {
                if ( copy ) {
                    copy.reject( new Error( 'no link' ) );
                }
                return;
            }
            $( '.bazantik-link-notice' ).remove();
            var $notice = $( '<div class="notice notice-success inline bazantik-link-notice"><p><strong class="bazantik-link-status"></strong><br><input type="text" readonly class="bazantik-select-on-click"></p></div>' );
            $notice.find( 'input' ).val( fresh );
            $( '#addtag' ).after( $notice );
            var setStatus = function ( ok ) {
                $notice.find( '.bazantik-link-status' ).text( ok
                    ? ( window.bazantikFolders ? window.bazantikFolders.copied : 'The secret link was copied to the clipboard.' )
                    : ( window.bazantikFolders ? window.bazantikFolders.notCopied : 'The secret link could not be copied automatically. Please copy it manually.' ) );
            };
            if ( copy ) {
                copy.resolve( fresh );
                copy.result.then( setStatus );
            } else if ( navigator.clipboard && navigator.clipboard.writeText ) {
                navigator.clipboard.writeText( fresh ).then( function () {
                    setStatus( true );
                }, function () {
                    setStatus( false );
                } );
            } else {
                setStatus( false );
            }
        }, 0 );
    } );

    // Změna soukromí na Skrytá (úprava i rychlá úprava): odkaz je k dispozici hned, při uložení se zkopíruje do schránky
    function copyLink( link ) {
        if ( link && navigator.clipboard && navigator.clipboard.writeText ) {
            navigator.clipboard.writeText( link ).then( function () {}, function () {} );
        }
    }
    if ( $main.length && $( '#edittag' ).length ) {
        var initialVis = $main.val();
        $( '#edittag' ).on( 'click', 'input[type="submit"]', function () {
            if ( 'unlisted' === $main.val() && 'unlisted' !== initialVis ) {
                copyLink( $( '#bazantik_folder_link' ).val() );
            }
        } );
    }

    // Tlačítko Zrušit vedle Update
    var $cancel = $( '#bazantik-folder-cancel' );
    var $actions = $( '.edit-tag-actions' );
    if ( $cancel.length && $actions.length ) {
        $cancel.removeClass( 'button' ).css( { 'margin': '0 12px', 'display': 'inline-block' } );
        $actions.append( $cancel );
        $( '#delete-link' ).appendTo( $actions );
        $( '#bazantik-folder-cancel-wrap' ).remove();
        $actions.addClass( 'bazantik-ready' );
    }

    // Seznam složek se načte na pozadí znovu a vymění se jím tabulka (bez obnovy stránky); při selhání zůstane stávající stav
    function refreshList() {
        if ( ! window.fetch ) {
            return;
        }
        window.fetch( window.location.href, { credentials: 'same-origin' } )
            .then( function ( response ) {
                return response.text();
            } )
            .then( function ( html ) {
                var fresh = new DOMParser().parseFromString( html, 'text/html' ).getElementById( 'the-list' );
                var $list = $( '#the-list' );
                if ( fresh && $list.length && ! $list.find( '.inline-edit-row' ).length ) {
                    $list.html( fresh.innerHTML );
                    syncQuickEditParents();
                }
            } )
            .catch( function () {} );
    }

    // Po smazání složky s podsložkami WordPress nepřepočítá odsazení podsložek; seznam se proto načte znovu
    var deletedHadChildren = false;
    var rowLevel = function ( $row ) {
        var m = $.trim( $row.find( '.row-title' ).first().text() ).match( /^(?:[\u2014\u2013-]\s*)*/ );
        return m ? m[ 0 ].replace( /\s/g, '' ).length : 0;
    };
    $( document ).on( 'ajaxSend', function ( event, xhr, settings ) {
        if ( ! settings || 'string' !== typeof settings.data || -1 === settings.data.indexOf( 'action=delete-tag' ) ) {
            return;
        }
        var id = ( settings.data.match( /(?:^|&)tag_ID=(\d+)/ ) || [] )[ 1 ];
        var $row = id ? $( '#tag-' + id ) : $();
        deletedHadChildren = $row.length > 0 && rowLevel( $row.next( 'tr' ) ) > rowLevel( $row );
    } ).on( 'ajaxSuccess', function ( event, xhr, settings ) {
        if ( deletedHadChildren && settings && 'string' === typeof settings.data && -1 !== settings.data.indexOf( 'action=delete-tag' ) && '1' === $.trim( xhr.responseText ) ) {
            deletedHadChildren = false;
            window.setTimeout( refreshList, 500 );
        }
    } );

    // Změna nadřazené složky v Quick Editu: WordPress přepíše jen jeden řádek, proto se seznam složek načte na pozadí znovu (bez obnovy stránky)
    var parentBefore = null;
    $( document ).on( 'ajaxSend', function ( event, xhr, settings ) {
        if ( ! settings || 'string' !== typeof settings.data || -1 === settings.data.indexOf( 'action=inline-save-tax' ) ) {
            return;
        }
        var id = ( settings.data.match( /(?:^|&)tax_ID=(\d+)/ ) || [] )[ 1 ];
        parentBefore = id ? String( $( '#inline_' + id + ' .parent' ).text() ) : null;
    } ).on( 'ajaxSuccess', function ( event, xhr, settings ) {
        if ( null === parentBefore || ! settings || 'string' !== typeof settings.data || -1 === settings.data.indexOf( 'action=inline-save-tax' ) ) {
            return;
        }
        var before = parentBefore;
        parentBefore = null;
        var after = decodeURIComponent( ( settings.data.match( /(?:^|&)parent=([^&]*)/ ) || [ '', '' ] )[ 1 ].replace( /\+/g, ' ' ) );
        if ( '-1' === after ) {
            after = '0';
        }
        if ( after === before || ! window.fetch ) {
            return;
        }
        window.setTimeout( refreshList, 300 );
    } );

    // Nový klíč skryté složky: tlačítko jen přepíná příznak, klíč se vymění až při uložení
    function setNewKey( $box, on ) {
        var texts = window.bazantikFolders || {};
        $box.find( 'input[type="hidden"]' ).val( on ? '1' : '0' ).trigger( 'change' );
        $box.find( '.bazantik-newkey-note' ).toggle( on );
        $box.find( '.bazantik-newkey-btn' ).text( on ? ( texts.keepKey || 'Keep current link' ) : ( texts.newKey || 'Generate new link' ) );
    }
    $( document ).on( 'click', '.bazantik-newkey-btn', function () {
        var $box = $( this ).closest( '.bazantik-newkey' );
        setNewKey( $box, '1' !== $box.find( 'input[type="hidden"]' ).val() );
    } );

    // Označení celého odkazu po kliknutí
    $( document ).on( 'focus click', '.bazantik-select-on-click', function () {
        this.select();
    } ).on( 'mouseup', '.bazantik-select-on-click', function ( e ) {
        e.preventDefault();
    } );

    // Rychlá úprava složky
    $( document ).on( 'change', '.bazantik-qe-vis', function () {
        syncFields( $( this ).closest( 'tr' ), $( this ).val() );
    } );
    if ( window.inlineEditTax && window.inlineEditTax.save ) {
        // Tlačítko Update ve Quick Editu WordPress obsluhuje přímo a zastaví šíření události, proto se zabalí jeho funkce uložení
        var originalSave = window.inlineEditTax.save;
        window.inlineEditTax.save = function ( id ) {
            var termId = 'object' === typeof id ? this.getId( id ) : id;
            var $row = $( '#edit-' + termId );
            if ( $row.length && $row.data( 'bazantikBaseline' ) === formState( $row ) ) {
                return false;
            }
            if ( $row.length && lacksPassword( $row.find( '.bazantik-qe-vis' ).val(), $row.find( '.bazantik-qe-pw' )[ 0 ], $row.data( 'bazantikHashed' ) ) ) {
                requirePassword( $row.find( '.bazantik-qe-pw' )[ 0 ] );
                return false;
            }
            if ( $row.length && 'unlisted' === $row.find( '.bazantik-qe-vis' ).val() && 'unlisted' !== $row.data( 'bazantikVis' ) ) {
                copyLink( $row.find( '.bazantik-qe-link' ).val() );
            }
            // Odhalené nezměněné heslo se do požadavku neodesílá (QE neodesílá událost submit)
            $row.find( '.bazantik-qe-pw' ).each( function () {
                if ( this.value && '0' === $( this ).closest( '.bazantik-pw-wrap' ).next( '.bazantik-pw-changed' ).val() ) {
                    this.value = '';
                }
            } );
            return originalSave.apply( this, arguments );
        };
    }
    if ( window.inlineEditTax && window.inlineEditTax.edit ) {
        var original = window.inlineEditTax.edit;
        window.inlineEditTax.edit = function ( id ) {
            original.apply( this, arguments );
            var termId = 'object' === typeof id ? this.getId( id ) : id;
            var $info = $( '#tag-' + termId ).find( '.bazantik-vis' );
            var $row = $( '#edit-' + termId );
            if ( ! $row.length ) {
                return;
            }
            // Nadřazená složka: hodnota ze skrytých dat řádku; sama složka a její podsložky se nenabízejí
            var $parent = $row.find( '.bazantik-qe-parent' );
            $parent.find( 'option' ).prop( 'disabled', false );
            var $self = $parent.find( 'option[value="' + termId + '"]' );
            if ( $self.length ) {
                var levelOf = function ( $o ) {
                    var m = /level-(\d+)/.exec( $o.attr( 'class' ) || '' );
                    return m ? parseInt( m[ 1 ], 10 ) : 0;
                };
                var selfLevel = levelOf( $self );
                $self.prop( 'disabled', true ).nextAll( 'option' ).each( function () {
                    if ( levelOf( $( this ) ) <= selfLevel ) {
                        return false;
                    }
                    $( this ).prop( 'disabled', true );
                } );
            }
            $parent.val( $( '#inline_' + termId + ' .parent' ).text() || '0' );
            setNewKey( $row.find( '.bazantik-newkey' ), false );
            $row.data( 'bazantikVis', $info.data( 'vis' ) || 'public' );
            $row.data( 'bazantikHashed', !! $info.data( 'hashed' ) );
            $row.find( '.bazantik-qe-vis' ).val( $info.data( 'vis' ) || 'public' ).trigger( 'change' );
            $row.find( '.bazantik-qe-link' ).val( $info.data( 'link' ) || '' );
            var $pw = $row.find( '.bazantik-qe-pw' );
            var texts = window.bazantikFolders || {};
            $pw.attr( 'placeholder', $info.data( 'hashed' ) ? texts.pwKeep : texts.pwEnter );
            $pw.val( '' ).removeClass( 'bazantik-pw-reveal' ).attr( 'data-bazantik-id', $info.data( 'reveal' ) ? termId : '0' );
            $pw.closest( '.bazantik-pw-wrap' ).next( '.bazantik-pw-changed' ).val( '0' );
            if ( ! $row.data( 'bazantikWatching' ) ) {
                $row.data( 'bazantikWatching', true );
                $row.on( 'input change', function () {
                    $row.find( '.button-primary.save' ).prop( 'disabled', formState( $row ) === $row.data( 'bazantikBaseline' ) );
                } );
            }
            $row.data( 'bazantikBaseline', formState( $row ) );
            $row.find( '.button-primary.save' ).prop( 'disabled', true );
        };
    }
}( jQuery ) );
