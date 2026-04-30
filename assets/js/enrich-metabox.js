/* AOS Member Sync — Enrich From AI metabox (two-step flow) */
jQuery( function ( $ ) {

    var findBtn    = $( '#aos-find-btn' );
    var enrichBtn  = $( '#aos-enrich-btn' );
    var stepEnrich = $( '#aos-step-enrich' );
    var urlInput   = $( '#aos-website-url' );
    var urlSource  = $( '#aos-url-source' );
    var status     = $( '#aos-enrich-status' );

    // Sanity-check: confirm aosMsEnrich is present
    if ( typeof aosMsEnrich === 'undefined' ) {
        status.css( 'color', '#c00' ).text( 'ERROR: aosMsEnrich not defined — script localisation failed.' );
        return;
    }

    status.css( 'color', '#888' ).text( 'post_id=' + aosMsEnrich.postId );

    // If the listing already has a website, skip Step 1 and show Step 2 immediately
    if ( urlInput.val().length > 0 ) {
        urlSource.text( 'Using existing website on file.' );
        stepEnrich.show();
    }

    // -----------------------------------------------------------------------
    // Step 1 — Find Website
    // -----------------------------------------------------------------------
    findBtn.on( 'click', function () {
        findBtn.prop( 'disabled', true ).text( 'Searching\u2026' );
        status.css( 'color', '#555' ).text( 'Searching for practice website via Google Places\u2026' );
        urlSource.text( '' );
        stepEnrich.hide();

        $.post( aosMsEnrich.ajaxUrl, {
            action:  'aos_ms_find_website',
            nonce:   aosMsEnrich.nonce,
            post_id: aosMsEnrich.postId
        } )
        .done( function ( res ) {
            if ( res.success ) {
                urlInput.val( res.data.url );
                var src = res.data.source === 'existing'
                    ? 'From existing listing data.'
                    : 'Found via web search' + ( res.data.city ? ' (' + res.data.city + ', ' + res.data.state + ')' : '' ) + '. Confirm or edit before enriching.';
                urlSource.text( src );
                status.text( '' );
                stepEnrich.slideDown( 200 );
                findBtn.prop( 'disabled', false ).text( '\uD83D\uDD0D Step 1: Find Website' );
            } else {
                status.css( 'color', '#c00' ).text( JSON.stringify( res.data ) );
                urlInput.val( '' );
                urlSource.text( 'No website found automatically. Enter one below.' );
                stepEnrich.slideDown( 200 );
                findBtn.prop( 'disabled', false ).text( '\uD83D\uDD0D Step 1: Find Website' );
            }
        } )
        .fail( function ( xhr ) {
            status.css( 'color', '#c00' ).text( 'AJAX failed (' + xhr.status + '): ' + xhr.responseText.slice( 0, 200 ) );
            findBtn.prop( 'disabled', false ).text( '\uD83D\uDD0D Step 1: Find Website' );
        } );
    } );

    // -----------------------------------------------------------------------
    // Step 2 — Enrich From AI
    // -----------------------------------------------------------------------
    var enrichStages = [
        'Scraping practice website\u2026',
        'Asking Gemini to write bio\u2026',
        'Looking for headshot\u2026',
        'Saving fields\u2026'
    ];
    var stageIndex = 0;
    var stageTimer = null;

    function nextStage() {
        if ( stageIndex < enrichStages.length ) {
            status.css( 'color', '#555' ).text( enrichStages[ stageIndex++ ] );
            stageTimer = setTimeout( nextStage, 6000 );
        }
    }

    enrichBtn.on( 'click', function () {
        var url = urlInput.val().trim();
        if ( ! url ) {
            status.css( 'color', '#c00' ).text( 'Please enter a website URL first.' );
            return;
        }

        enrichBtn.prop( 'disabled', true ).text( 'Enriching\u2026' );
        stageIndex = 0;
        nextStage();

        $.post( aosMsEnrich.ajaxUrl, {
            action:      'aos_ms_enrich_listing',
            nonce:       aosMsEnrich.nonce,
            post_id:     aosMsEnrich.postId,
            website_url: url
        } )
        .done( function ( res ) {
            clearTimeout( stageTimer );
            var debug = '';
            if ( res.data && res.data.diag ) {
                debug += '\nDIAG: post_id=' + res.data.diag.received_post_id
                       + ' canary=' + res.data.diag.canary_save
                       + ' readback=' + res.data.diag.canary_readback;
            }
            if ( res.data && res.data.fields_debug ) {
                debug += '\nSAVED: ' + JSON.stringify( res.data.fields_debug );
            }
            if ( res.data && res.data.ai_raw ) {
                var ai = res.data.ai_raw;
                debug += '\nAI: bio=' + ( ai.biography ? ai.biography.length + 'ch' : '0' )
                       + ' specialty="' + ( ai.specialty || '' ) + '"'
                       + ' confidence=' + ( ai.confidence || '?' );
            }
            if ( res.success ) {
                status.css( 'color', '#2a9e4f' ).text( res.data.message + debug );
                enrichBtn.text( '\u2713 Done \u2014 reload to see changes' );
            } else {
                status.css( 'color', '#c00' ).text( ( res.data.message || 'Unknown error' ) + debug );
                enrichBtn.prop( 'disabled', false ).text( '\u2728 Step 2: Enrich From AI' );
            }
        } )
        .fail( function ( xhr ) {
            clearTimeout( stageTimer );
            status.css( 'color', '#c00' ).text( 'AJAX failed (' + xhr.status + '): ' + xhr.responseText.slice( 0, 300 ) );
            enrichBtn.prop( 'disabled', false ).text( '\u2728 Step 2: Enrich From AI' );
        } );
    } );

} );
