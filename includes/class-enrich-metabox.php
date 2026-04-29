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

            <div id="aos-enrich-status" style="margin-top:8px;font-size:12px;color:#555;line-height:1.5;"></div>
        </div>
        <?php
    }

    public static function enqueue_scripts( $hook ) {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ] ) ) return;
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'at_biz_dir' ) return;

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
            'postId'  => get_the_ID(),
        ] );
    }

    // -------------------------------------------------------------------------
    // Step 1 AJAX — find candidate website URL
    // -------------------------------------------------------------------------

    public static function ajax_find_website() {
        check_ajax_referer( 'aos_ms_enrich_listing', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( [ 'message' => 'Unauthorized.' ] );

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) wp_send_json_error( [ 'message' => 'No post ID.' ] );

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'at_biz_dir' ) wp_send_json_error( [ 'message' => 'Invalid listing.' ] );

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
    // Step 2 AJAX — enrich using a confirmed URL (always overwrites)
    // -------------------------------------------------------------------------

    public static function ajax_enrich() {
        check_ajax_referer( 'aos_ms_enrich_listing', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( [ 'message' => 'Unauthorized.' ] );

        $post_id     = (int) ( $_POST['post_id'] ?? 0 );
        $website_url = esc_url_raw( trim( $_POST['website_url'] ?? '' ) );

        if ( ! $post_id ) wp_send_json_error( [ 'message' => 'No post ID.' ] );

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'at_biz_dir' ) wp_send_json_error( [ 'message' => 'Invalid listing.' ] );

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

        $gemini  = new AOS_MS_Gemini();
        $ai_data = $gemini->enrich_listing( $contact, $website_url );

        if ( isset( $ai_data['error'] ) ) {
            wp_send_json_error( [ 'message' => 'Gemini error: ' . $ai_data['error'] ] );
        }

        $fields_updated = [];

        // Website — always save if we have one
        $w = $website_url ?: ( $ai_data['website_url'] ?? '' );
        if ( $w ) {
            update_post_meta( $post_id, '_website', sanitize_text_field( $w ) );
            $fields_updated[] = 'website';
        }

        // Specialty / tagline — always overwrite
        $specialty = trim( $ai_data['specialty'] ?? '' );
        if ( $specialty ) {
            update_post_meta( $post_id, '_tagline', sanitize_text_field( $specialty ) );
            $fields_updated[] = 'specialty';
        }

        // Phone — only fill if currently blank (don't overwrite known-good phone)
        $ai_phone = trim( $ai_data['phone'] ?? '' );
        if ( $ai_phone && ! $phone ) {
            update_post_meta( $post_id, '_phone', sanitize_text_field( $ai_phone ) );
            $fields_updated[] = 'phone';
        }

        // Biography → post_content — always overwrite
        $bio = trim( $ai_data['biography'] ?? '' );
        if ( $bio ) {
            wp_update_post( [ 'ID' => $post_id, 'post_content' => wp_kses_post( $bio ) ] );
            $fields_updated[] = 'biography';
        }

        // Headshot — only if no featured image already
        $image_url = $ai_data['image_url'] ?? '';
        if ( $image_url && ! has_post_thumbnail( $post_id ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $attachment_id = media_sideload_image( $image_url, $post_id, $title . ' headshot', 'id' );
            if ( ! is_wp_error( $attachment_id ) ) {
                set_post_thumbnail( $post_id, $attachment_id );
                update_post_meta( $post_id, '_listing_prv_img', $attachment_id );
                $fields_updated[] = 'headshot';
            }
        }

        if ( empty( $fields_updated ) ) {
            // Gemini returned nothing — surface the raw response for debugging
            wp_send_json_error( [
                'message'    => 'Gemini returned no usable content.',
                'debug'      => [
                    'bio_length' => strlen( $bio ),
                    'specialty'  => $specialty,
                    'website'    => $w,
                    'confidence' => $ai_data['confidence'] ?? null,
                    'raw'        => $ai_data,
                ],
            ] );
        }

        wp_send_json_success( [
            'message'    => 'Enriched: ' . implode( ', ', $fields_updated ) . '. Reload the page to see changes.',
            'fields'     => $fields_updated,
            'confidence' => $ai_data['confidence'] ?? null,
        ] );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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
