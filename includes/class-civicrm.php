<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AOS_MS_CiviCRM {

    private $base_url;
    private $site_key;
    private $api_key;

    public function __construct() {
        $this->base_url = rtrim( AOS_MS_Settings::get( 'civicrm_base_url' ), '/' );
        $this->site_key = AOS_MS_Settings::get( 'civicrm_site_key' );
        $this->api_key  = AOS_MS_Settings::get( 'civicrm_api_key' );
    }

    public function is_configured() {
        return ! empty( $this->base_url ) && ! empty( $this->site_key ) && ! empty( $this->api_key );
    }

    /**
     * Make a CiviCRM API v4 call.
     * Matches the pattern used in aos-civicrm-sync.
     *
     * @param string $entity  e.g. 'Membership', 'Contact'
     * @param string $action  e.g. 'get', 'create'
     * @param array  $params  API v4 params array
     * @return array|WP_Error
     */
    public function request( $entity, $action, $params = [] ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'not_configured', 'CiviCRM API is not configured' );
        }

        if ( ! isset( $params['checkPermissions'] ) ) {
            $params['checkPermissions'] = false;
        }

        $url = $this->base_url . '/civicrm/ajax/api4/' . $entity . '/' . $action;

        $response = wp_remote_post( $url, [
            'timeout' => 30,
            'headers' => [
                'X-Civi-Auth'  => 'Bearer ' . $this->api_key,
                'X-Civi-Key'   => $this->site_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'params' => wp_json_encode( $params ),
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code !== 200 ) {
            // Surface as much detail as possible for debugging
            if ( isset( $data['error_message'] ) ) {
                $error_message = 'HTTP ' . $code . ' — ' . $data['error_message'];
            } elseif ( isset( $data['message'] ) ) {
                $error_message = 'HTTP ' . $code . ' — ' . $data['message'];
            } elseif ( ! empty( $body ) ) {
                // Strip HTML tags and trim to 200 chars for display
                $stripped = trim( wp_strip_all_tags( $body ) );
                $snippet  = $stripped ? substr( $stripped, 0, 200 ) : 'empty body';
                $error_message = 'HTTP ' . $code . ' — ' . $snippet;
            } else {
                $error_message = 'HTTP ' . $code;
            }
            return new WP_Error( 'civicrm_error', $error_message, [ 'code' => $code, 'response' => $data, 'url' => $url ] );
        }

        if ( $data === null ) {
            return new WP_Error( 'json_error', 'Failed to decode API response', [ 'body' => substr( $body, 0, 500 ) ] );
        }

        return $data['values'] ?? $data;
    }

    /**
     * Test the connection.
     */
    public function test_connection() {
        $result = $this->request( 'Contact', 'get', [
            'limit'  => 1,
            'select' => [ 'id' ],
        ] );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return true;
    }

    /**
     * Get expired memberships within the last N months.
     * Returns memberships whose end_date has passed and status is Expired/Cancelled.
     *
     * Uses numeric status IDs (4=Expired, 6=Cancelled, 7=Deceased) for reliable API v4 filtering.
     * Optionally scopes to configured credentialing membership type IDs.
     *
     * @return array|WP_Error
     */
    public function get_expired_memberships( $months = 6 ) {
        $since = date( 'Y-m-d', strtotime( "-{$months} months" ) );
        $today = date( 'Y-m-d' );

        $where = [
            [ 'end_date', '>=', $since ],
            [ 'end_date', '<=', $today ],
            // Use numeric IDs: 4=Expired, 6=Cancelled, 7=Deceased
            [ 'status_id', 'IN', [ 4, 6, 7 ] ],
        ];

        // Scope to configured credentialing type IDs if any are set
        $type_ids = array_filter( array_map( 'intval', [
            AOS_MS_Settings::get( 'achievement_type_id' ),
            AOS_MS_Settings::get( 'fellowship_type_id' ),
            AOS_MS_Settings::get( 'diplomate_type_id' ),
        ] ) );
        if ( ! empty( $type_ids ) ) {
            $where[] = [ 'membership_type_id', 'IN', array_values( $type_ids ) ];
        }

        $memberships = $this->request( 'Membership', 'get', [
            'select' => [
                'id',
                'contact_id',
                'membership_type_id',
                'membership_type_id.name',
                'end_date',
                'status_id:name',
                'contact_id.first_name',
                'contact_id.last_name',
                'contact_id.display_name',
                'contact_id.email_primary.email',
            ],
            'where' => $where,
            'limit' => 1000,
        ] );

        if ( is_wp_error( $memberships ) ) {
            return $memberships; // Surface the error instead of silently returning []
        }

        $result = [];
        foreach ( $memberships as $m ) {
            $result[] = [
                'membership_id'        => $m['id'],
                'contact_id'           => $m['contact_id'],
                'membership_type_id'   => $m['membership_type_id'],
                'membership_type_name' => $m['membership_type_id.name'] ?? '',
                'end_date'             => $m['end_date'],
                'status'               => $m['status_id:name'] ?? '',
                'first_name'           => $m['contact_id.first_name'] ?? '',
                'last_name'            => $m['contact_id.last_name'] ?? '',
                'display_name'         => $m['contact_id.display_name'] ?? '',
                'email'                => $m['contact_id.email_primary.email'] ?? '',
            ];
        }

        return $result;
    }

    /**
     * Get active memberships, optionally filtered by type IDs.
     */
    public function get_active_memberships( $type_ids = [] ) {
        $where = [
            [ 'status_id:name', 'IN', [ 'New', 'Current', 'Grace' ] ],
        ];

        if ( ! empty( $type_ids ) ) {
            $where[] = [ 'membership_type_id', 'IN', array_map( 'intval', $type_ids ) ];
        }

        $memberships = $this->request( 'Membership', 'get', [
            'select' => [
                'id',
                'contact_id',
                'membership_type_id',
                'membership_type_id.name',
                'end_date',
                'status_id:name',
                'contact_id.first_name',
                'contact_id.last_name',
                'contact_id.display_name',
                'contact_id.email_primary.email',
                'contact_id.address_primary.street_address',
                'contact_id.address_primary.city',
                'contact_id.address_primary.state_province_id:label',
                'contact_id.address_primary.postal_code',
                'contact_id.phone_primary.phone',
                'contact_id.website_primary.url',
            ],
            'where'  => $where,
            'limit'  => 2000,
        ] );

        if ( is_wp_error( $memberships ) ) {
            return [];
        }

        $result = [];
        foreach ( $memberships as $m ) {
            $result[] = [
                'membership_id'        => $m['id'],
                'contact_id'           => $m['contact_id'],
                'membership_type_id'   => $m['membership_type_id'],
                'membership_type_name' => $m['membership_type_id.name'] ?? '',
                'end_date'             => $m['end_date'],
                'status'               => $m['status_id:name'] ?? '',
                'first_name'           => $m['contact_id.first_name'] ?? '',
                'last_name'            => $m['contact_id.last_name'] ?? '',
                'display_name'         => $m['contact_id.display_name'] ?? '',
                'email'                => $m['contact_id.email_primary.email'] ?? '',
                'street_address'       => $m['contact_id.address_primary.street_address'] ?? '',
                'city'                 => $m['contact_id.address_primary.city'] ?? '',
                'state'                => $m['contact_id.address_primary.state_province_id:label'] ?? '',
                'postal_code'          => $m['contact_id.address_primary.postal_code'] ?? '',
                'phone'                => $m['contact_id.phone_primary.phone'] ?? '',
                'website'              => $m['contact_id.website_primary.url'] ?? '',
            ];
        }

        return $result;
    }
}
