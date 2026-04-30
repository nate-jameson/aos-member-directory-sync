<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AOS_MS_Settings {

    const OPTION_KEY = 'aos_ms_settings';

    public static function get( $key = null, $default = '' ) {
        $options = get_option( self::OPTION_KEY, [] );
        if ( $key === null ) return $options;
        return isset( $options[ $key ] ) ? $options[ $key ] : $default;
    }

    public static function save( $data ) {
        $current = get_option( self::OPTION_KEY, [] );
        $merged  = array_merge( $current, $data );
        update_option( self::OPTION_KEY, $merged );
    }

    public static function fields() {
        return [
            'civicrm' => [
                'label'  => 'CiviCRM Connection',
                'fields' => [
                    'civicrm_base_url'  => [ 'label' => 'CiviCRM Base URL',  'type' => 'url',      'placeholder' => 'https://yoursite.org' ],
                    'civicrm_site_key'  => [ 'label' => 'Site Key',          'type' => 'password', 'placeholder' => '' ],
                    'civicrm_api_key'   => [ 'label' => 'API Key (User Key)','type' => 'password', 'placeholder' => '' ],
                ],
            ],
            'gemini' => [
                'label'  => 'AI Enrichment',
                'fields' => [
                    'gemini_api_key' => [ 'label' => 'Gemini API Key',          'type' => 'password', 'placeholder' => '' ],
                    'places_api_key' => [ 'label' => 'Google Places API Key',   'type' => 'password', 'placeholder' => '' ],
                    'gemini_model'   => [
                        'label'   => 'Gemini Model',
                        'type'    => 'select',
                        'default' => 'gemini-2.5-flash',
                        'options' => [
                            'gemini-2.5-flash'     => 'Gemini 2.5 Flash (recommended)',
                            'gemini-2.5-pro'       => 'Gemini 2.5 Pro',
                            'gemini-2.0-flash-lite' => 'Gemini 2.0 Flash Lite',
                            'gemini-1.5-flash'     => 'Gemini 1.5 Flash (stable)',
                            'gemini-1.5-pro'       => 'Gemini 1.5 Pro',
                        ],
                    ],
                ],
            ],
            'membership_types' => [
                'label'  => 'Membership Type IDs',
                'desc'   => 'Enter the numeric CiviCRM Membership Type ID for each level. Separate multiple IDs with commas.',
                'fields' => [
                    'type_active'      => [ 'label' => 'Active Member (any active membership)', 'type' => 'text', 'placeholder' => 'e.g. 1,2,3' ],
                    'type_achievement' => [ 'label' => 'Achievement',                           'type' => 'text', 'placeholder' => 'e.g. 5' ],
                    'type_fellowship'  => [ 'label' => 'Fellowship',                            'type' => 'text', 'placeholder' => 'e.g. 6' ],
                    'type_diplomate'   => [ 'label' => 'Diplomate',                             'type' => 'text', 'placeholder' => 'e.g. 7' ],
                ],
            ],
            'sync' => [
                'label'  => 'Sync Settings',
                'fields' => [
                    'expired_lookback_months' => [ 'label' => 'Expired Lookback (months)',         'type' => 'number', 'placeholder' => '6',   'default' => '6' ],
                    'provider_directory_id'   => [ 'label' => 'Provider Directory ID (Dentists)',  'type' => 'number', 'placeholder' => '34',  'default' => '34' ],
                    'practice_directory_id'   => [ 'label' => 'Practice Directory ID (Practices)', 'type' => 'number', 'placeholder' => '115', 'default' => '115' ],
                ],
            ],
        ];
    }
}
