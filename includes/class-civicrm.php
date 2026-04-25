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
     * Make a CiviCRM API3 call.
     */
    public function api3( $entity, $action, $params = [] ) {
        $params['api_key']    = $this->api_key;
        $params['key']        = $this->site_key;
        $params['json']       = 1;
        $params['sequential'] = 1;

        $url = $this->base_url . '/civicrm/ajax/rest?entity=' . urlencode( $entity ) . '&action=' . urlencode( $action );

        $response = wp_remote_post( $url, [
            'timeout' => 30,
            'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
            'body'    => $params,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'is_error' => 1, 'error_message' => $response->get_error_message() ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body ?: [ 'is_error' => 1, 'error_message' => 'Invalid JSON response' ];
    }

    /**
     * Get expired memberships within the last N months.
     * Returns array of [ contact_id, membership_type_id, end_date, contact details... ]
     */
    public function get_expired_memberships( $months = 6 ) {
        $since = date( 'Y-m-d', strtotime( "-{$months} months" ) );
        $today = date( 'Y-m-d' );

        // Get memberships with end_date between $since and today
        $result = $this->api3( 'Membership', 'get', [
            'end_date'    => [ '>=' => $since, '<=' => $today ],
            'is_deleted'  => 0,
            'return'      => 'id,contact_id,membership_type_id,end_date,status_id',
            'options'     => [ 'limit' => 1000 ],
        ] );

        if ( ! empty( $result['is_error'] ) || empty( $result['values'] ) ) {
            return [];
        }

        // Fetch associated contacts in bulk
        $contact_ids = array_unique( array_column( $result['values'], 'contact_id' ) );
        $contacts    = $this->get_contacts_by_ids( $contact_ids );

        $memberships = [];
        foreach ( $result['values'] as $m ) {
            $cid = $m['contact_id'];
            $memberships[] = array_merge( $m, $contacts[ $cid ] ?? [] );
        }

        return $memberships;
    }

    /**
     * Get active memberships (current members).
     */
    public function get_active_memberships( $type_ids = [] ) {
        $params = [
            'is_deleted' => 0,
            'status_id'  => [ 'IN' => [ 'Current', 'Grace' ] ],
            'return'     => 'id,contact_id,membership_type_id,end_date,status_id',
            'options'    => [ 'limit' => 2000 ],
        ];

        if ( ! empty( $type_ids ) ) {
            $params['membership_type_id'] = [ 'IN' => array_map( 'intval', $type_ids ) ];
        }

        $result = $this->api3( 'Membership', 'get', $params );

        if ( ! empty( $result['is_error'] ) || empty( $result['values'] ) ) {
            return [];
        }

        $contact_ids = array_unique( array_column( $result['values'], 'contact_id' ) );
        $contacts    = $this->get_contacts_by_ids( $contact_ids );

        $memberships = [];
        foreach ( $result['values'] as $m ) {
            $cid = $m['contact_id'];
            $memberships[] = array_merge( $m, $contacts[ $cid ] ?? [] );
        }

        return $memberships;
    }

    /**
     * Get a map of contact_id => contact data for an array of IDs.
     */
    public function get_contacts_by_ids( $ids ) {
        if ( empty( $ids ) ) return [];

        $result = $this->api3( 'Contact', 'get', [
            'id'      => [ 'IN' => array_values( array_map( 'intval', $ids ) ) ],
            'return'  => 'id,first_name,last_name,display_name,email,phone,address_name,street_address,city,state_province,postal_code,country,website',
            'options' => [ 'limit' => count( $ids ) + 10 ],
        ] );

        if ( ! empty( $result['is_error'] ) || empty( $result['values'] ) ) {
            return [];
        }

        $map = [];
        foreach ( $result['values'] as $c ) {
            $map[ $c['id'] ] = $c;
        }
        return $map;
    }

    /**
     * Test the connection. Returns true on success or WP_Error.
     */
    public function test_connection() {
        $result = $this->api3( 'System', 'get', [ 'options' => [ 'limit' => 1 ] ] );
        if ( ! empty( $result['is_error'] ) ) {
            return new WP_Error( 'civicrm_error', $result['error_message'] ?? 'Unknown error' );
        }
        return true;
    }
}
