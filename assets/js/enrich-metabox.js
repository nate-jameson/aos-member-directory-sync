/* AOS Member Sync — Enrich From AI metabox (two-step flow) */
jQuery( function ( $ ) {

    var findBtn    = $( '#aos-find-btn' );
    var enrichBtn  = $( '#aos-enrich-btn' );
    var stepEnrich = $( '#aos-step-enrich' );
    var urlInput   = $( '#aos-website-url' );
    var urlSource  = $( '#aos-url-source' );
    var status     = $( '#aos-enrich-status' );

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
                status.css( 'color', '#c00' ).text( res.data.message || 'Website not found.' );
                // Still show the URL input so admin can enter one manually
                urlInput.val( '' );
                urlSource.text( 'No website found automatically. Enter one below.' );
                stepEnrich.slideDown( 200 );
                findBtn.prop( 'disabled', false ).text( '\uD83D\uDD0D Step 1: Find Website' );
            }
        } )
        .fail( function () {
            status.css( 'color', '#c00' ).text( 'Request failed. Check your network and try again.' );
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
            if ( res.success ) {
                status.css( 'color', '#2a9e4f' ).text( res.data.message );
                enrichBtn.text( '\u2713 Done \u2014 reload to see changes' );
            } else {
                var msg = res.data.message || 'Unknown error';
                if ( res.data.debug ) {
                    msg += ' (bio_length=' + res.data.debug.bio_length + ', specialty="' + res.data.debug.specialty + '", website="' + res.data.debug.website + '")';
                }
                status.css( 'color', '#c00' ).text( 'Error: ' + msg );
                enrichBtn.prop( 'disabled', false ).text( '\u2728 Step 2: Enrich From AI' );
            }
        } )
        .fail( function () {
            clearTimeout( stageTimer );
            status.css( 'color', '#c00' ).text( 'Request failed. Check your network and try again.' );
            enrichBtn.prop( 'disabled', false ).text( '\u2728 Step 2: Enrich From AI' );
        } );
    } );

} );
