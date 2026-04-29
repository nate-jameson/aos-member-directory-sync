<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adds an "Enrich From AI" meta box to the Directorist listing edit screen.
 */
class AOS_MS_Enrich_Metabox {

    public static function init() {
        add_action( 'add_meta_boxes', [ __CLASS__, 'register_metabox' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
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
        ?>
        <div id="aos-enrich-wrap" style="font-size:13px;">
            <p style="margin:0 0 8px;color:#555;">Auto-fill biography, specialty, contact info, and headshot using AI.</p>
            <button type="button" id="aos-enrich-btn" class="button button-primary" style="width:100%;">
                ✨ Enrich From AI
            </button>
            <div id="aos-enrich-status" style="margin-top:8px;font-style:italic;color:#666;min-height:18px;"></div>
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

    public static function ajax_enrich() {
        check_ajax_referer( 'aos_ms_enrich_listing', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
        }

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'No post ID provided.' ] );
        }

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'at_biz_dir' ) {
            wp_send_json_error( [ 'message' => 'Invalid listing.' ] );
        }

        // Gather existing meta to use as enrichment seed
        $title  = $post->post_title;
        $city   = get_post_meta( $post_id, '_city', true )
                  ?: get_post_meta( $post_id, '_address', true );
        $state  = get_post_meta( $post_id, '_state', true );
        $email  = get_post_meta( $post_id, '_email', true );
        $phone  = get_post_meta( $post_id, '_phone', true );

        // Strip "Dr." prefix to get a cleaner search name
        $search_name = preg_replace( '/^Dr\.?\s+/i', '', $title );

        // Build a minimal contact array from existing meta
        $contact = [
            'display_name'   => $search_name,
            'first_name'     => '',
            'last_name'      => $search_name,
            'email'          => $email,
            'phone'          => $phone,
            'city'           => $city,
            'state_province' => $state,
            'website'        => get_post_meta( $post_id, '_website', true ),
        ];

        // Run enrichment
        $gemini      = new AOS_MS_Gemini();
        $website_url = $gemini->find_website( $search_name, $city, $state );
        $ai_data     = $gemini->enrich_listing( $contact, $website_url );

        if ( is_wp_error( $ai_data ) ) {
            wp_send_json_error( [ 'message' => $ai_data->get_error_message() ] );
        }

        // Save enriched fields — only overwrite blank fields
        $fields_updated = [];

        $mappings = [
            '_phone'   => $phone    ?: ( $ai_data['phone']   ?? '' ),
            '_website' => get_post_meta( $post_id, '_website', true ) ?: ( $ai_data['website_url'] ?? $website_url ),
            '_tagline' => get_post_meta( $post_id, '_tagline', true ) ?: ( $ai_data['specialty']   ?? '' ),
        ];

        foreach ( $mappings as $key => $value ) {
            if ( $value ) {
                update_post_meta( $post_id, $key, sanitize_text_field( $value ) );
                $fields_updated[] = ltrim( $key, '_' );
            }
        }

        // Biography — update post content if blank
        $bio = $ai_data['biography'] ?? '';
        if ( $bio && empty( trim( $post->post_content ) ) ) {
            wp_update_post( [ 'ID' => $post_id, 'post_content' => wp_kses_post( $bio ) ] );
            $fields_updated[] = 'biography';
        }

        // Headshot — only if no featured image set
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

        $website_used = $ai_data['website_url'] ?? $website_url ?? '';

        wp_send_json_success( [
            'message'       => 'Enriched: ' . implode( ', ', $fields_updated ) . '. Reload the page to see changes.',
            'fields'        => $fields_updated,
            'website_found' => $website_used,
            'confidence'    => $ai_data['confidence'] ?? null,
        ] );
    }
}
