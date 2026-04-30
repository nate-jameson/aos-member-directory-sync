<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adds an "Enrich From AI" meta box to the Directorist listing edit screen.
 * Two-step flow:
 *   Step 1 — Find Website: locates a candidate URL, lets admin confirm/edit it.
 *   Step 2 — Enrich: scrapes the confirmed URL, runs Gemini, saves fields.
 */
class AOS_MS_Enrich_Metabox {

    public static function init() {
        add_action( 'add_meta_boxes',        [ __CLASS__, 'register_metabox' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
        add_action( 'wp_ajax_aos_ms_find_website',   [ __CLASS__, 'ajax_find_website' ] );
        add_action( 'wp_ajax_aos_ms_enrich_listing', [ __CLASS__, 'ajax_enrich' ] );
    }

    public static function register_metabox() {
        add_meta_box(
            'aos_ms_enrich',
            'AI Enrichment',
            [ __CLASS__, 'render_metabox' ],
            'at_biz_dir',
            'side',
            'high'
        );
    }

    public static function render_metabox( $post ) {
        $gemini = new AOS_MS_Gemini();
        if ( ! $gemini->is_configured() ) {
            echo '<p style="color:#999;font-size:12px;">Configure your Gemini API key in <a href="' . admin_url( 'admin.php?page=aos-member-sync&tab=settings' ) . '">AOS Member Sync → Settings</a> to use AI enrichment.</p>';
            return;
        }
        wp_nonce_field( 'aos_ms_enrich_listing', 'aos_ms_enrich_nonce' );
        $existing_website = get_post_meta( $post->ID, '_website', true );
        ?>
        <div id="aos-enrich-wrap" style="font-size:13px;">
            <p style="margin:0 0 8px;color:#555;">Auto-fill biography, specialty, contact info, and headshot using AI. <strong>Overwrites existing values.</strong></p>

            <!-- Step 1: Find Website -->
            <div id="aos-step-find">
                <button type="button" id="aos-find-btn" class="button button-secondary" style="width:100%;">
                    🔍 Step 1: Find Website
                </button>
            </div>

            <!-- Step 2: Confirm URL then Enrich (hidden until Step 1 completes) -->
            <div id="aos-step-enrich" style="display:none;margin-top:10px;">
                <label style="display:block;margin-bottom:4px;font-weight:600;">Practice Website URL:</label>
                <input type="url" id="aos-website-url" value="<?php echo esc_attr( $existing_website ); ?>"
                       style="width:100%;box-sizing:border-box;margin-bottom:6px;" placeholder="https://example.com" />
                <p id="aos-url-source" style="margin:0 0 8px;font-size:11px;color:#888;font-style:italic;"></p>
                <button type="button" id="aos-enrich-btn" class="button button-primary" style="width:100%;">
                    ✨ Step 2: Enrich From AI
                </button>
            </div>

            <div id="aos-enrich-status" style="margin-top:8px;font-size:12px;color:#555;line-height:1.5;white-space:pre-wrap;word-break:break-word;"></div>
        </div>
        <?php
    }

    public static function enqueue_scripts( $hook ) {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ] ) ) return;
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'at_biz_dir' ) return;

        // Use $_GET['post'] as the most reliable source of the current post ID
        // in an admin_enqueue_scripts context.
        $post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : (int) get_the_ID();

        wp_enqueue_script(
            'aos-ms-enrich-metabox',
            plugins_url( 'assets/js/enrich-metabox.js', AOS_MS_PLUGIN_FILE ),
            [ 'jquery' ],
            AOS_MS_VERSION,
            true
        );

        wp_localize_script( 'aos-ms-enrich-metabox', 'aosMsEnrich', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'aos_ms_enrich_listing' ),
            'postId'  => $post_id,
        ] );
    }

    // -------------------------------------------------------------------------
    // Step 1 AJAX — find candidate website URL
    // -------------------------------------------------------------------------

    public static function ajax_find_website() {
        check_ajax_referer( 'aos_ms_enrich_listing', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( [ 'message' => 'Unauthorized.' ] );

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) wp_send_json_error( [ 'message' => 'No post ID received.' ] );

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'at_biz_dir' ) {
            wp_send_json_error( [
                'message'   => 'Invalid listing.',
                'post_id'   => $post_id,
                'post_type' => $post ? $post->post_type : 'null',
            ] );
        }

        // Already has a website — return it immediately
        $existing = get_post_meta( $post_id, '_website', true );
        if ( $existing ) {
            wp_send_json_success( [
                'url'    => $existing,
                'source' => 'existing',
            ] );
        }

        $title       = $post->post_title;
        $search_name = preg_replace( '/^Dr\.?\s+/i', '', $title );

        [ $city, $state ] = self::extract_city_state( $post_id );

        $gemini = new AOS_MS_Gemini();
        $url    = $gemini->find_website( $search_name, $city, $state );

        if ( $url ) {
            wp_send_json_success( [
                'url'    => $url,
                'source' => 'search',
                'city'   => $city,
                'state'  => $state,
            ] );
        }

        wp_send_json_error( [ 'message' => 'No website found. Enter one manually below.' ] );
    }

    // -------------------------------------------------------------------------
    // Step 2 AJAX — enrich using a confirmed URL
    // -------------------------------------------------------------------------

    public static function ajax_enrich() {
        check_ajax_referer( 'aos_ms_enrich_listing', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( [ 'message' => 'Unauthorized.' ] );

        $post_id     = (int) ( $_POST['post_id'] ?? 0 );
        $website_url = esc_url_raw( trim( $_POST['website_url'] ?? '' ) );

        // --- Diagnostic step: verify post ID and canary meta save -----------
        $diag = [
            'received_post_id'  => $post_id,
            'received_website'  => $website_url,
            'canary_save'       => null,
            'canary_readback'   => null,
        ];

        if ( ! $post_id ) {
            wp_send_json_error( array_merge( $diag, [ 'message' => 'No post ID received from JS.' ] ) );
        }

        // Test that we can write to this post's meta at all
        $canary_val = 'aos_test_' . time();
        $canary_ok  = update_post_meta( $post_id, '_aos_enrich_canary', $canary_val );
        $diag['canary_save']     = $canary_ok !== false ? 'ok' : 'FAILED';
        $diag['canary_readback'] = get_post_meta( $post_id, '_aos_enrich_canary', true );

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'at_biz_dir' ) {
            wp_send_json_error( array_merge( $diag, [
                'message'   => 'Invalid listing.',
                'post_type' => $post ? $post->post_type : 'null',
            ] ) );
        }

        $title       = $post->post_title;
        $search_name = preg_replace( '/^Dr\.?\s+/i', '', $title );
        [ $city, $state ] = self::extract_city_state( $post_id );

        $phone = get_post_meta( $post_id, '_phone', true );
        $email = get_post_meta( $post_id, '_email', true );

        $contact = [
            'display_name'   => $search_name,
            'first_name'     => '',
            'last_name'      => $search_name,
            'email'          => $email,
            'phone'          => $phone,
            'city'           => $city,
            'state_province' => $state,
            'website'        => $website_url,
        ];

        // Detect whether this is a Provider (dir 34) or Practice (dir 115) listing
        $directory_type_id = (int) get_post_meta( $post_id, '_directory_type', true );
        $listing_type      = ( $directory_type_id === 115 ) ? 'practice' : 'provider';

        $gemini  = new AOS_MS_Gemini();
        $ai_data = $gemini->enrich_listing( $contact, $website_url, $listing_type );
        $ai_data['listing_type_used'] = $listing_type; // surface in debug

        if ( isset( $ai_data['error'] ) ) {
            wp_send_json_error( array_merge( $diag, [
                'message' => 'Gemini error: ' . $ai_data['error'],
                'ai_data' => $ai_data,
            ] ) );
        }

        $fields_updated = [];
        $fields_debug   = [];

        // Website — always save if we have one
        $w = $website_url ?: ( $ai_data['website_url'] ?? '' );
        if ( $w ) {
            $r = update_post_meta( $post_id, '_website', sanitize_text_field( $w ) );
            $fields_debug['website'] = [ 'value' => $w, 'result' => $r !== false ? 'ok' : 'no-change' ];
            $fields_updated[] = 'website';
        }

        // Specialty / tagline — always overwrite
        $specialty = trim( $ai_data['specialty'] ?? '' );
        if ( $specialty ) {
            $r = update_post_meta( $post_id, '_tagline', sanitize_text_field( $specialty ) );
            $fields_debug['specialty'] = [ 'value' => $specialty, 'result' => $r !== false ? 'ok' : 'no-change' ];
            $fields_updated[] = 'specialty';
        }

        // Phone — only fill if currently blank
        $ai_phone = trim( $ai_data['phone'] ?? '' );
        if ( $ai_phone && ! $phone ) {
            $r = update_post_meta( $post_id, '_phone', sanitize_text_field( $ai_phone ) );
            $fields_debug['phone'] = [ 'value' => $ai_phone, 'result' => $r !== false ? 'ok' : 'no-change' ];
            $fields_updated[] = 'phone';
        }

        // Address, city, state, zip
        $ai_address = trim( $ai_data['address'] ?? '' );
        $ai_city    = trim( $ai_data['city']    ?? '' );
        $ai_state   = trim( $ai_data['state']   ?? '' );
        $ai_zip     = trim( $ai_data['zip']     ?? '' );

        if ( $ai_address ) {
            // Build full formatted address for the _address meta field
            $full_address = $ai_address;
            if ( $ai_city )  $full_address .= ', ' . $ai_city;
            if ( $ai_state ) $full_address .= ', ' . $ai_state;
            if ( $ai_zip )   $full_address .= ' ' . $ai_zip;
            update_post_meta( $post_id, '_address', sanitize_text_field( $full_address ) );
            $fields_debug['address'] = [ 'value' => $full_address, 'result' => 'ok' ];
            $fields_updated[] = 'address';
        }
        if ( $ai_zip ) {
            update_post_meta( $post_id, '_zip', sanitize_text_field( $ai_zip ) );
            $fields_updated[] = 'zip';
        }

        // Geocode address → lat/lng (only if not already set)
        $existing_lat = get_post_meta( $post_id, '_manual_lat', true );
        if ( $ai_address && ! $existing_lat ) {
            $geo_query = trim( "{$ai_address}, {$ai_city}, {$ai_state} {$ai_zip}" );
            $geocode   = self::geocode_address( $geo_query );
            if ( $geocode ) {
                update_post_meta( $post_id, '_manual_lat', sanitize_text_field( $geocode['lat'] ) );
                update_post_meta( $post_id, '_manual_lng', sanitize_text_field( $geocode['lng'] ) );
                $fields_debug['coordinates'] = $geocode;
                $fields_updated[] = 'coordinates';
            }
        }

        // Gender
        $ai_gender = trim( $ai_data['gender'] ?? '' );
        if ( in_array( $ai_gender, [ 'Male', 'Female' ], true ) ) {
            update_post_meta( $post_id, '_ddoctors-gender', sanitize_text_field( $ai_gender ) );
            $fields_debug['gender'] = $ai_gender;
            $fields_updated[] = 'gender';
        }

        // Accepting new patients
        if ( isset( $ai_data['accepting_new_patients'] ) ) {
            $anp = $ai_data['accepting_new_patients'] ? '1' : '0';
            update_post_meta( $post_id, '_ddoctors-accept-new-patient', $anp );
            $fields_debug['accepting_new_patients'] = $anp;
            $fields_updated[] = 'accepting_new_patients';
        }

        // Education (custom-bullet-list)
        $ai_education = $ai_data['education'] ?? [];
        if ( is_array( $ai_education ) && ! empty( $ai_education ) ) {
            update_post_meta( $post_id, '_custom-bullet-list', sanitize_textarea_field( implode( "\n", $ai_education ) ) );
            $fields_debug['education'] = $ai_education;
            $fields_updated[] = 'education';
        }

        // Awards (custom-bullet-list-2)
        $ai_awards = $ai_data['awards'] ?? [];
        if ( is_array( $ai_awards ) && ! empty( $ai_awards ) ) {
            update_post_meta( $post_id, '_custom-bullet-list-2', sanitize_textarea_field( implode( "\n", $ai_awards ) ) );
            $fields_debug['awards'] = $ai_awards;
            $fields_updated[] = 'awards';
        }

        // Location taxonomy (state)
        if ( $ai_state ) {
            $state_term = get_term_by( 'name', $ai_state, 'at_biz_dir-location' );
            if ( $state_term && ! is_wp_error( $state_term ) ) {
                wp_set_object_terms( $post_id, [ $state_term->term_id ], 'at_biz_dir-location' );
                $fields_debug['location_term'] = $ai_state . ' (id=' . $state_term->term_id . ')';
                $fields_updated[] = 'location';
            } else {
                $fields_debug['location_term'] = 'not found: ' . $ai_state;
            }
        }

        // Categories taxonomy
        $ai_categories = $ai_data['categories'] ?? [];
        if ( is_array( $ai_categories ) && ! empty( $ai_categories ) ) {
            $term_ids = [];
            foreach ( $ai_categories as $cat_name ) {
                $cat_term = get_term_by( 'name', $cat_name, 'at_biz_dir-category' );
                if ( $cat_term && ! is_wp_error( $cat_term ) ) {
                    $term_ids[] = $cat_term->term_id;
                }
            }
            if ( $term_ids ) {
                wp_set_object_terms( $post_id, $term_ids, 'at_biz_dir-category' );
                $fields_debug['categories'] = $ai_categories;
                $fields_updated[] = 'categories';
            }
        }

        // Biography → post_content — always overwrite
        $bio = trim( $ai_data['biography'] ?? '' );
        if ( $bio ) {
            $r = wp_update_post( [ 'ID' => $post_id, 'post_content' => wp_kses_post( $bio ) ] );
            $fields_debug['biography'] = [
                'length' => strlen( $bio ),
                'result' => is_wp_error( $r ) ? $r->get_error_message() : ( $r ? 'ok' : 'FAILED' ),
            ];
            $fields_updated[] = 'biography';
        }

        // Headshot — only if no featured image
        $image_url = $ai_data['image_url'] ?? '';
        if ( $image_url && ! has_post_thumbnail( $post_id ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $attachment_id = media_sideload_image( $image_url, $post_id, $title . ' headshot', 'id' );
            if ( ! is_wp_error( $attachment_id ) ) {
                set_post_thumbnail( $post_id, $attachment_id );
                update_post_meta( $post_id, '_listing_prv_img', $attachment_id );
                $fields_debug['headshot'] = [ 'attachment_id' => $attachment_id, 'result' => 'ok' ];
                $fields_updated[] = 'headshot';
            } else {
                $fields_debug['headshot'] = [ 'result' => 'error: ' . $image_url->get_error_message() ];
            }
        }

        wp_send_json_success( [
            'message'        => empty( $fields_updated )
                                ? 'Gemini returned no usable content — check debug below.'
                                : 'Enriched: ' . implode( ', ', $fields_updated ) . '. Reload the page to see changes.',
            'fields_updated' => $fields_updated,
            'fields_debug'   => $fields_debug,
            'diag'           => $diag,
            'ai_raw'         => $ai_data,
        ] );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Geocode a full address string to lat/lng.
     * Tries Google Geocoding API first (using Places API key), then falls back
     * to Nominatim (OpenStreetMap) which requires no key.
     *
     * @param  string $address  Full formatted address (street, city, state zip)
     * @return array|null       [ 'lat' => float, 'lng' => float ] or null on failure
     */
    private static function geocode_address( $address ) {
        $places_key = AOS_MS_Settings::get( 'places_api_key' );

        if ( $places_key ) {
            $url      = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode( $address ) . '&key=' . urlencode( $places_key );
            $response = wp_remote_get( $url, [ 'timeout' => 10, 'sslverify' => true ] );
            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                $data = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( isset( $data['results'][0]['geometry']['location'] ) ) {
                    $loc = $data['results'][0]['geometry']['location'];
                    return [ 'lat' => (string) $loc['lat'], 'lng' => (string) $loc['lng'] ];
                }
            }
        }

        // Fallback: Nominatim (OpenStreetMap) — free, no key required
        $url      = 'https://nominatim.openstreetmap.org/search?format=json&q=' . urlencode( $address ) . '&limit=1';
        $response = wp_remote_get( $url, [
            'timeout' => 10,
            'sslverify' => false,
            'headers' => [ 'User-Agent' => 'AOS-Member-Sync/1.4.1 (find.orthodontics.com)' ],
        ] );
        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! empty( $data[0]['lat'] ) ) {
                return [ 'lat' => (string) $data[0]['lat'], 'lng' => (string) $data[0]['lon'] ];
            }
        }

        return null;
    }

    /**
     * Extract city and state from listing meta, trying multiple field patterns.
     * Returns [ $city, $state ].
     */
    private static function extract_city_state( $post_id ) {
        $city  = get_post_meta( $post_id, '_city', true )
               ?: get_post_meta( $post_id, '_city_name', true )
               ?: get_post_meta( $post_id, '_atbdp_city', true )
               ?: '';

        $state = get_post_meta( $post_id, '_state', true )
               ?: get_post_meta( $post_id, '_state_name', true )
               ?: get_post_meta( $post_id, '_atbdp_state', true )
               ?: '';

        // If still empty, try to parse from _address: "Street, City, State ZIP"
        if ( ! $city || ! $state ) {
            $address = get_post_meta( $post_id, '_address', true );
            if ( $address ) {
                if ( preg_match( '/,\s*([^,]+),\s*([A-Za-z ]{2,20})\s*\d{0,5}\s*$/', $address, $m ) ) {
                    if ( ! $city )  $city  = trim( $m[1] );
                    if ( ! $state ) $state = trim( $m[2] );
                }
            }
        }

        return [ $city, $state ];
    }
}
