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
     * @param array  $contact          CiviCRM contact data
     * @param int    $membership_type_id
     * @param array  $ai_data          Optional Gemini enrichment output
     * @param int    $directory_id     Directorist directory ID
     * @return int|WP_Error  Post ID on success.
     */
    public static function create_draft( $contact, $membership_type_id = 0, $ai_data = [], $directory_id = 0 ) {
        if ( ! $directory_id ) {
            $directory_id = (int) AOS_MS_Settings::get( 'provider_directory_id', 34 );
        }

        $name  = $contact['display_name'] ?? trim( ( $contact['first_name'] ?? '' ) . ' ' . ( $contact['last_name'] ?? '' ) );
        $title = 'Dr. ' . $name;
        $title = preg_replace( '/^(Dr\.?\s+)+/i', 'Dr. ', $title );

        $bio      = $ai_data['biography'] ?? '';
        $specialty = $ai_data['specialty'] ?? '';
        $credentialing = self::get_credentialing_level( $membership_type_id );

        $post_id = wp_insert_post( [
            'post_title'   => $title,
            'post_content' => $bio,
            'post_status'  => 'draft',
            'post_type'    => 'at_biz_dir',
        ], true );

        if ( is_wp_error( $post_id ) ) return $post_id;

        // Directorist directory
        update_post_meta( $post_id, '_atbdp_listing_type', $directory_id );
        wp_set_object_terms( $post_id, $directory_id, 'atbdp_listing_type' );

        // Contact info
        update_post_meta( $post_id, '_atbdp_email',   sanitize_email( $contact['email'] ?? '' ) );
        update_post_meta( $post_id, '_atbdp_phone',   sanitize_text_field( $contact['phone'] ?? '' ) );
        update_post_meta( $post_id, '_atbdp_website', esc_url_raw( $contact['website'] ?? '' ) );

        // Address
        update_post_meta( $post_id, '_atbdp_address',  sanitize_text_field( $contact['street_address'] ?? '' ) );
        update_post_meta( $post_id, '_atbdp_zip',      sanitize_text_field( $contact['postal_code'] ?? '' ) );
        update_post_meta( $post_id, '_atbdp_city',     sanitize_text_field( $contact['city'] ?? '' ) );
        update_post_meta( $post_id, '_atbdp_state',    sanitize_text_field( $contact['state_province'] ?? '' ) );
        update_post_meta( $post_id, '_atbdp_country',  sanitize_text_field( $contact['country'] ?? '' ) );

        // Custom fields
        if ( $specialty ) {
            update_post_meta( $post_id, '_atbdp_custom_specialty', $specialty );
        }
        if ( $credentialing ) {
            update_post_meta( $post_id, '_atbdp_custom_credentialing_level', $credentialing );
        }

        // Store CiviCRM contact ID for future syncing
        update_post_meta( $post_id, '_aos_civicrm_contact_id', intval( $contact['id'] ?? 0 ) );
        update_post_meta( $post_id, '_aos_ai_confidence', $ai_data['confidence'] ?? 'none' );

        // Geocode address if possible
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

    private static function geocode_and_save( $post_id, $contact ) {
        $address_parts = array_filter( [
            $contact['street_address'] ?? '',
            $contact['city'] ?? '',
            $contact['state_province'] ?? '',
            $contact['postal_code'] ?? '',
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

        update_post_meta( $post_id, '_atbdp_latitude',  (float) $data[0]['lat'] );
        update_post_meta( $post_id, '_atbdp_longitude', (float) $data[0]['lon'] );
        update_post_meta( $post_id, '_atbdp_post_latitude',  (float) $data[0]['lat'] );
        update_post_meta( $post_id, '_atbdp_post_longitude', (float) $data[0]['lon'] );
    }
}
