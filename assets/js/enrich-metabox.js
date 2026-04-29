/* AOS Member Sync — Enrich From AI metabox */
jQuery( function ( $ ) {
    var btn    = $( '#aos-enrich-btn' );
    var status = $( '#aos-enrich-status' );

    var stages = [
        'Searching web for practice website\u2026',
        'Scraping practice pages\u2026',
        'Asking Gemini to write bio\u2026',
        'Looking for headshot\u2026',
        'Saving fields\u2026'
    ];
    var stageIndex = 0;
    var stageTimer = null;

    function nextStage() {
        if ( stageIndex < stages.length ) {
            status.text( stages[ stageIndex++ ] );
            stageTimer = setTimeout( nextStage, 5000 );
        }
    }

    btn.on( 'click', function () {
        btn.prop( 'disabled', true ).text( 'Working\u2026' );
        status.text( '' );
        stageIndex = 0;
        nextStage();

        $.post( aosMsEnrich.ajaxUrl, {
            action:   'aos_ms_enrich_listing',
            nonce:    aosMsEnrich.nonce,
            post_id:  aosMsEnrich.postId
        } )
        .done( function ( res ) {
            clearTimeout( stageTimer );
            if ( res.success ) {
                var msg = res.data.message;
                if ( res.data.website_found ) {
                    msg += ' (source: ' + res.data.website_found + ')';
                }
                status.css( 'color', '#2a9e4f' ).text( msg );
                btn.text( '\u2713 Done \u2014 reload to see changes' );
            } else {
                status.css( 'color', '#c00' ).text( 'Error: ' + ( res.data.message || 'Unknown error' ) );
                btn.prop( 'disabled', false ).text( '\u2728 Enrich From AI' );
            }
        } )
        .fail( function () {
            clearTimeout( stageTimer );
            status.css( 'color', '#c00' ).text( 'Request failed. Check your network and try again.' );
            btn.prop( 'disabled', false ).text( '\u2728 Enrich From AI' );
        } );
    } );
} );
