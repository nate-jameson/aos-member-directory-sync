<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CiviCRM API v3 REST client.
 *
 * Uses the WordPress REST endpoint /wp-json/civicrm/v3/rest, which authenticates
 * via `key` (site key) and `api_key` (contact API key) query parameters.
 * This matches the pattern used by the AOS CiviCRM R/W connection and has been
 * confirmed to work for Membership, Contact, and other entities.
 */
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
     * Make a CiviCRM API v3 REST call.
     *
     * @param string $entity  e.g. 'Membership', 'Contact'
     * @param string $action  e.g. 'get', 'getsingle', 'create'
     * @param array  $params  v3-style params (field => value, return, options, etc.)
     * @return array|WP_Error  Returns the 'values' array on success.
     */
    public function request( $entity, $action, $params = [] ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'not_configured', 'CiviCRM API is not configured.' );
        }

        $url = add_query_arg( [
            'entity'  => $entity,
            'action'  => $action,
            'json'    => wp_json_encode( $params ),
            'key'     => $this->site_key,
            'api_key' => $this->api_key,
        ], $this->base_url . '/wp-json/civicrm/v3/rest' );

        $response = wp_remote_get( $url, [ 'timeout' => 30 ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code !== 200 ) {
            if ( isset( $data['error_message'] ) ) {
                $msg = 'HTTP ' . $code . ' — ' . $data['error_message'];
            } elseif ( isset( $data['message'] ) ) {
                $msg = 'HTTP ' . $code . ' — ' . $data['message'];
            } else {
                $stripped = trim( wp_strip_all_tags( $body ) );
                $msg = 'HTTP ' . $code . ( $stripped ? ' — ' . substr( $stripped, 0, 200 ) : '' );
            }
            return new WP_Error( 'civicrm_http_error', $msg, [ 'code' => $code, 'body' => substr( $body, 0, 500 ) ] );
        }

        if ( $data === null ) {
            return new WP_Error( 'json_error', 'Failed to decode API response.', [ 'body' => substr( $body, 0, 500 ) ] );
        }

        if ( ! empty( $data['is_error'] ) ) {
            return new WP_Error( 'civicrm_api_error', $data['error_message'] ?? 'Unknown CiviCRM error.', $data );
        }

        return $data['values'] ?? [];
    }

    /**
     * Test the connection by fetching one Contact.
     *
     * @return true|WP_Error
     */
    public function test_connection() {
        $result = $this->request( 'Contact', 'get', [
            'return'  => 'id',
            'options' => [ 'limit' => 1 ],
        ] );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return true;
    }

    /**
     * Get expired memberships within the last N months.
     *
     * Filters by end_date range and status IDs (4=Expired, 6=Cancelled, 7=Deceased).
     * Scopes to configured credentialing type IDs if any are set in settings.
     *
     * @param int $months  How far back to look (default 6).
     * @return array|WP_Error
     */
    public function get_expired_memberships( $months = 6 ) {
        $since = date( 'Y-m-d', strtotime( "-{$months} months" ) );
        $today = date( 'Y-m-d' );

        $params = [
            'status_id' => [ 'IN' => [ 4, 6, 7 ] ],
            'end_date'  => [ '>=' => $since, '<=' => $today ],
            'return'    => 'id,contact_id,membership_type_id,end_date,status_id',
            'options'   => [ 'limit' => 1000 ],
        ];

        // Scope to the active membership type IDs configured in settings.
        // type_achievement/fellowship/diplomate are used only for UI badge-mapping, not for queries.
        $type_ids = array_filter( array_map( 'intval', array_map( 'trim', explode( ',', AOS_MS_Settings::get( 'type_active', '' ) ) ) ) );
        if ( ! empty( $type_ids ) ) {
            $params['membership_type_id'] = [ 'IN' => array_values( $type_ids ) ];
        }

        $memberships = $this->request( 'Membership', 'get', $params );

        if ( is_wp_error( $memberships ) ) {
            return $memberships;
        }

        if ( empty( $memberships ) ) {
            return [];
        }

        // Collect contact IDs and fetch contact details in one call
        $contact_ids = array_unique( array_column( $memberships, 'contact_id' ) );
        $contacts    = $this->get_contacts_by_ids( $contact_ids );
        if ( is_wp_error( $contacts ) ) {
            $contacts = [];
        }
        // Index by contact_id
        $contacts_map = [];
        foreach ( $contacts as $c ) {
            $contacts_map[ $c['id'] ] = $c;
        }

        // Build status & type name maps
        $status_map = $this->get_membership_status_map();
        $type_map   = $this->get_membership_type_map();

        $result = [];
        foreach ( $memberships as $m ) {
            $cid = $m['contact_id'];
            $c   = $contacts_map[ $cid ] ?? [];
            $result[] = [
                'membership_id'        => $m['id'],
                'contact_id'           => $cid,
                'membership_type_id'   => $m['membership_type_id'],
                'membership_type_name' => $type_map[ $m['membership_type_id'] ] ?? $m['membership_type_id'],
                'end_date'             => $m['end_date'],
                'status'               => $status_map[ $m['status_id'] ] ?? $m['status_id'],
                'first_name'           => $c['first_name'] ?? '',
                'last_name'            => $c['last_name'] ?? '',
                'display_name'         => $c['display_name'] ?? '',
                'email'                => $c['email'] ?? '',
            ];
        }

        return $result;
    }

    /**
     * Get active memberships, optionally filtered by type IDs.
     *
     * @param int[] $type_ids  Optional membership type IDs to filter by.
     * @return array|WP_Error
     */
    public function get_active_memberships( $type_ids = [] ) {
        // Status IDs: 1=New, 2=Current, 3=Grace
        $params = [
            'status_id' => [ 'IN' => [ 1, 2, 3 ] ],
            'return'    => 'id,contact_id,membership_type_id,end_date,status_id',
            'options'   => [ 'limit' => 2000 ],
        ];

        if ( ! empty( $type_ids ) ) {
            $params['membership_type_id'] = [ 'IN' => array_map( 'intval', $type_ids ) ];
        }

        $memberships = $this->request( 'Membership', 'get', $params );

        if ( is_wp_error( $memberships ) ) {
            return $memberships;
        }

        if ( empty( $memberships ) ) {
            return [];
        }

        // Fetch contact details
        $contact_ids  = array_unique( array_column( $memberships, 'contact_id' ) );
        $contacts     = $this->get_contacts_by_ids( $contact_ids );
        if ( is_wp_error( $contacts ) ) {
            $contacts = [];
        }
        $contacts_map = [];
        foreach ( $contacts as $c ) {
            $contacts_map[ $c['id'] ] = $c;
        }

        $status_map = $this->get_membership_status_map();
        $type_map   = $this->get_membership_type_map();

        $result = [];
        foreach ( $memberships as $m ) {
            $cid = $m['contact_id'];
            $c   = $contacts_map[ $cid ] ?? [];
            $result[] = [
                'membership_id'        => $m['id'],
                'contact_id'           => $cid,
                'membership_type_id'   => $m['membership_type_id'],
                'membership_type_name' => $type_map[ $m['membership_type_id'] ] ?? $m['membership_type_id'],
                'end_date'             => $m['end_date'],
                'status'               => $status_map[ $m['status_id'] ] ?? $m['status_id'],
                'first_name'           => $c['first_name'] ?? '',
                'last_name'            => $c['last_name'] ?? '',
                'display_name'         => $c['display_name'] ?? '',
                'email'                => $c['email'] ?? '',
                'street_address'       => $c['street_address'] ?? '',
                'city'                 => $c['city'] ?? '',
                'state'                => $c['state_province'] ?? '',
                'postal_code'          => $c['postal_code'] ?? '',
                'phone'                => $c['phone'] ?? '',
                'website'              => $c['website'] ?? '',
            ];
        }

        return $result;
    }

    /**
     * Get active credential memberships for specific contact IDs.
     *
     * Used to determine which credential badges (Achievement/Fellowship/Diplomate)
     * each contact currently holds, independently of their base "member" type.
     *
     * @param int[] $contact_ids  Contact IDs to check.
     * @param int[] $type_ids     Credential membership type IDs to look for.
     * @return array|WP_Error     Map of contact_id (string) => int[] active type_ids.
     */
    public function get_credential_memberships( $contact_ids, $type_ids ) {
        if ( empty( $contact_ids ) || empty( $type_ids ) ) return [];

        $params = [
            'sequential'         => 1,
            'membership_type_id' => [ 'IN' => array_values( array_map( 'intval', $type_ids ) ) ],
            'contact_id'         => [ 'IN' => array_values( array_map( 'intval', $contact_ids ) ) ],
            'status_id'          => [ 'IN' => [ 1, 2, 3 ] ], // New, Current, Grace
            'return'             => 'contact_id,membership_type_id',
            'options'            => [ 'limit' => 0 ],
        ];

        $response = $this->request( 'Membership', 'get', $params );
        if ( is_wp_error( $response ) ) return $response;

        $map = [];
        foreach ( $response as $m ) {
            $cid = (string) $m['contact_id'];
            if ( ! isset( $map[ $cid ] ) ) $map[ $cid ] = [];
            $map[ $cid ][] = (int) $m['membership_type_id'];
        }
        return $map;
    }

    /**
     * Fetch contact details for a list of contact IDs.
     * Batches in chunks to avoid query limits.
     *
     * @param int[] $ids
     * @return array|WP_Error
     */
    private function get_contacts_by_ids( $ids ) {
        if ( empty( $ids ) ) {
            return [];
        }

        $all = [];
        foreach ( array_chunk( $ids, 100 ) as $chunk ) {
            $result = $this->request( 'Contact', 'get', [
                'id'      => [ 'IN' => $chunk ],
                'return'  => 'id,first_name,last_name,display_name,email,street_address,city,state_province,postal_code,phone,website',
                'options' => [ 'limit' => count( $chunk ) ],
            ] );
            if ( ! is_wp_error( $result ) ) {
                foreach ( $result as $c ) {
                    $all[] = $c;
                }
            }
        }

        return $all;
    }

    /**
     * Returns a map of status_id => status_name.
     *
     * @return array
     */
    private function get_membership_status_map() {
        $result = $this->request( 'MembershipStatus', 'get', [
            'return'  => 'id,name',
            'options' => [ 'limit' => 50 ],
        ] );
        $map = [];
        if ( ! is_wp_error( $result ) ) {
            foreach ( $result as $s ) {
                $map[ $s['id'] ] = $s['name'];
            }
        }
        return $map;
    }

    /**
     * Returns a map of membership_type_id => type_name.
     *
     * @return array
     */
    private function get_membership_type_map() {
        $result = $this->request( 'MembershipType', 'get', [
            'return'  => 'id,name',
            'options' => [ 'limit' => 50 ],
        ] );
        $map = [];
        if ( ! is_wp_error( $result ) ) {
            foreach ( $result as $t ) {
                $map[ $t['id'] ] = $t['name'];
            }
        }
        return $map;
    }
}
