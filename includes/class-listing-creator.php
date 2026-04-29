<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AOS_MS_Listing_Creator {

    /**
     * Map CiviCRM membership_type_id to credentialing level field name.
     */
    public static function get_credentialing_level( $type_id ) {
        $achievement = array_filter( array_map( 'trim', explode( ',', AOS_MS_Settings::get( 'type_achievement', '' ) ) ) );
        $fellowship  = array_filter( array_map( 'trim', explode( ',', AOS_MS_Settings::get( 'type_fellowship', '' ) ) ) );
        $diplomate   = array_filter( array_map( 'trim', explode( ',', AOS_MS_Settings::get( 'type_diplomate', '' ) ) ) );

        if ( in_array( (string) $type_id, array_map( 'strval', $achievement ) ) ) return 'Achievement';
        if ( in_array( (string) $type_id, array_map( 'strval', $fellowship ) ) )  return 'Fellowship';
        if ( in_array( (string) $type_id, array_map( 'strval', $diplomate ) ) )   return 'Diplomate';

        return '';
    }

    /**
     * Create a draft Directorist listing from CiviCRM contact + optional AI enrichment.
     *
     * @param array  $contact        CiviCRM contact data
     * @param string $credentialing  Pre-resolved credential label(s), e.g. 'Diplomate' or 'Fellowship, Diplomate'
     * @param array  $ai_data        Optional Gemini enrichment output
     * @param int    $directory_id   Directorist directory ID (34 = Provider, 115 = Practice)
     * @return int|WP_Error  Post ID on success.
     */
    public static function create_draft( $contact, $credentialing = '', $ai_data = [], $directory_id = 0 ) {
        if ( ! $directory_id ) {
            $directory_id = (int) AOS_MS_Settings::get( 'provider_directory_id', 34 );
        }

        $name  = $contact['display_name'] ?? trim( ( $contact['first_name'] ?? '' ) . ' ' . ( $contact['last_name'] ?? '' ) );
        $title = 'Dr. ' . $name;
        $title = preg_replace( '/^(Dr\.?\s+)+/i', 'Dr. ', $title );

        $bio           = $ai_data['biography'] ?? '';
        $specialty     = $ai_data['specialty'] ?? '';
        $credentialing = sanitize_text_field( $credentialing );

        // Use AI-discovered website if CiviCRM doesn't have one
        $website = $ai_data['website_url'] ?? $contact['website'] ?? '';

        // Build a full address string for Directorist
        $address_parts = array_filter( [
            $contact['street_address']  ?? '',
            $contact['city']            ?? '',
            $contact['state_province']  ?? '',
            $contact['postal_code']     ?? '',
        ] );
        $full_address = implode( ', ', $address_parts );

        $post_id = wp_insert_post( [
            'post_title'   => $title,
            'post_content' => $bio,
            'post_status'  => 'draft',
            'post_type'    => 'at_biz_dir',
        ], true );

        if ( is_wp_error( $post_id ) ) return $post_id;

        // ── Directorist directory type ─────────────────────────────────────────
        // _directory_type is the post meta key Directorist uses (confirmed from DB).
        // ATBDP_TYPE is the taxonomy constant defined by Directorist (at_biz_dir-type).
        update_post_meta( $post_id, '_directory_type', $directory_id );
        $atbdp_type_taxonomy = defined( 'ATBDP_TYPE' ) ? ATBDP_TYPE : 'at_biz_dir-type';
        wp_set_object_terms( $post_id, $directory_id, $atbdp_type_taxonomy );

        // ── Contact fields — meta keys derived from Directorist field_key names ──
        // Pattern: form field name → '_' + field_name in post meta.
        update_post_meta( $post_id, '_email',   sanitize_email( $contact['email'] ?? '' ) );
        update_post_meta( $post_id, '_phone',   sanitize_text_field( $contact['phone'] ?? '' ) );
        update_post_meta( $post_id, '_website', esc_url_raw( $website ) );
        update_post_meta( $post_id, '_address', sanitize_text_field( $full_address ) );
        update_post_meta( $post_id, '_zip',     sanitize_text_field( $contact['postal_code'] ?? '' ) );

        // ── Custom fields ──────────────────────────────────────────────────────
        // _tagline = specialty (the "tagline" field in Directorist form builder)
        if ( $specialty ) {
            update_post_meta( $post_id, '_tagline', sanitize_text_field( $specialty ) );
        }

        // _custom-radio = credentialing level (Achievement / Fellowship / Diplomate)
        if ( $credentialing ) {
            update_post_meta( $post_id, '_custom-radio', sanitize_text_field( $credentialing ) );
        }

        // ── CiviCRM tracking ──────────────────────────────────────────────────
        update_post_meta( $post_id, '_aos_civicrm_contact_id', intval( $contact['id'] ?? 0 ) );
        update_post_meta( $post_id, '_aos_ai_confidence', $ai_data['confidence'] ?? 'none' );

        // ── Featured image — download & attach if AI found one ─────────────────
        $image_url = $ai_data['image_url'] ?? '';
        if ( $image_url ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $attachment_id = media_sideload_image( $image_url, $post_id, $title, 'id' );
            if ( ! is_wp_error( $attachment_id ) ) {
                set_post_thumbnail( $post_id, $attachment_id );
                // Also set Directorist profile image meta
                update_post_meta( $post_id, '_listing_prv_img', $attachment_id );
            }
        }

        // ── Geocode address ────────────────────────────────────────────────────
        self::geocode_and_save( $post_id, $contact );

        return $post_id;
    }

    /**
     * Set listing to inactive (draft).
     */
    public static function deactivate( $post_id ) {
        return wp_update_post( [
            'ID'          => $post_id,
            'post_status' => 'draft',
        ] );
    }

    /**
     * Geocode the contact address and save _manual_lat / _manual_lng.
     * Directorist reads these meta keys to display the map pin.
     */
    private static function geocode_and_save( $post_id, $contact ) {
        $address_parts = array_filter( [
            $contact['street_address']  ?? '',
            $contact['city']            ?? '',
            $contact['state_province']  ?? '',
            $contact['postal_code']     ?? '',
        ] );
        if ( empty( $address_parts ) ) return;

        $query    = implode( ', ', $address_parts );
        $response = wp_remote_get(
            'https://nominatim.openstreetmap.org/search?q=' . urlencode( $query ) . '&format=json&limit=1',
            [ 'timeout' => 10, 'headers' => [ 'User-Agent' => 'AOS-Member-Sync/1.0' ] ]
        );

        if ( is_wp_error( $response ) ) return;
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data[0]['lat'] ) ) return;

        $lat = (float) $data[0]['lat'];
        $lng = (float) $data[0]['lon'];

        // Directorist uses _manual_lat / _manual_lng for map pins
        update_post_meta( $post_id, '_manual_lat', $lat );
        update_post_meta( $post_id, '_manual_lng', $lng );
        // Also set the legacy keys Directorist may still read in older versions
        update_post_meta( $post_id, '_latitude',  $lat );
        update_post_meta( $post_id, '_longitude', $lng );
    }
}
