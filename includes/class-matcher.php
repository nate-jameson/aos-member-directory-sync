<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Matches CiviCRM contacts to Directorist listings.
 */
class AOS_MS_Matcher {

    /**
     * Find a matching listing post for a CiviCRM contact.
     * Returns [ 'post_id' => int, 'confidence' => 'high'|'medium'|'low', 'method' => string ] or null.
     */
    public static function find_listing( $contact ) {
        // 1. Email match (high confidence)
        if ( ! empty( $contact['email'] ) ) {
            $match = self::find_by_email( $contact['email'] );
            if ( $match ) {
                return [ 'post_id' => $match, 'confidence' => 'high', 'method' => 'email' ];
            }
        }

        // 2. Full name match in post title (medium confidence)
        if ( ! empty( $contact['display_name'] ) ) {
            $match = self::find_by_name( $contact['display_name'] );
            if ( $match ) {
                return [ 'post_id' => $match, 'confidence' => 'medium', 'method' => 'name' ];
            }
        }

        // 3. First + last name match
        if ( ! empty( $contact['first_name'] ) && ! empty( $contact['last_name'] ) ) {
            $name  = $contact['first_name'] . ' ' . $contact['last_name'];
            $match = self::find_by_name( $name );
            if ( $match ) {
                return [ 'post_id' => $match, 'confidence' => 'medium', 'method' => 'name' ];
            }
        }

        return null;
    }

    private static function find_by_email( $email ) {
        global $wpdb;
        // Check _atbdp_email post meta
        $post_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_atbdp_email' AND meta_value = %s LIMIT 1",
            sanitize_email( $email )
        ) );
        if ( $post_id ) return (int) $post_id;

        // Also check listings where author has this email
        $user = get_user_by( 'email', $email );
        if ( $user ) {
            $post_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d AND post_type = 'at_biz_dir' AND post_status NOT IN ('trash','auto-draft') LIMIT 1",
                $user->ID
            ) );
            if ( $post_id ) return (int) $post_id;
        }

        return null;
    }

    private static function find_by_name( $name ) {
        global $wpdb;
        $clean = sanitize_text_field( $name );
        // Strip Dr./Dr prefix for search
        $search_name = preg_replace( '/^(Dr\.?\s*)/i', '', $clean );

        $post_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'at_biz_dir'
             AND post_status NOT IN ('trash','auto-draft')
             AND (post_title LIKE %s OR post_title LIKE %s)
             LIMIT 1",
            '%' . $wpdb->esc_like( $search_name ) . '%',
            '%' . $wpdb->esc_like( $clean ) . '%'
        ) );

        return $post_id ? (int) $post_id : null;
    }

    /**
     * Get all listing post IDs (for "has listing" check).
     */
    public static function get_all_listing_emails() {
        global $wpdb;
        return $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_atbdp_email'
             AND meta_value != ''"
        );
    }

    /**
     * Get all listing post titles (normalised, no Dr. prefix).
     */
    public static function get_all_listing_names() {
        global $wpdb;
        $rows = $wpdb->get_col(
            "SELECT post_title FROM {$wpdb->posts}
             WHERE post_type = 'at_biz_dir'
             AND post_status NOT IN ('trash','auto-draft')"
        );
        return array_map( function( $t ) {
            return strtolower( preg_replace( '/^(Dr\.?\s*)/i', '', $t ) );
        }, $rows );
    }
}
