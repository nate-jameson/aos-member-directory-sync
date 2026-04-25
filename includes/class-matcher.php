<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Matches CiviCRM contacts to Directorist listings.
 */
class AOS_MS_Matcher {

    /**
     * Find a matching listing post for a CiviCRM contact.
     * Returns [ 'post_id' => int, 'confidence' => 'high'|'medium'|'low', 'method' => string ] or null.
     * Only returns the first match — use find_all_listings() to get all matches across both directories.
     */
    public static function find_listing( $contact ) {
        $all = self::find_all_listings( $contact );
        return ! empty( $all ) ? $all[0] : null;
    }

    /**
     * Find ALL matching listing posts for a CiviCRM contact across all directories.
     * Returns array of [ 'post_id' => int, 'directory_id' => int, 'confidence' => string, 'method' => string ].
     */
    public static function find_all_listings( $contact ) {
        $results = [];

        // 1. Email match (high confidence)
        if ( ! empty( $contact['email'] ) ) {
            $ids = self::find_all_by_email( $contact['email'] );
            foreach ( $ids as $pid ) {
                // Directorist stores directory ID in 'directory_type' meta (native).
                // Our plugin also writes '_atbdp_listing_type' as a fallback.
                $dir_id = (int) get_post_meta( $pid, 'directory_type', true );
                if ( ! $dir_id ) {
                    $dir_id = (int) get_post_meta( $pid, '_atbdp_listing_type', true );
                }
                $results[ $pid ] = [ 'post_id' => $pid, 'directory_id' => $dir_id, 'confidence' => 'high', 'method' => 'email' ];
            }
        }

        // 2. Name match (medium confidence) — only if no email matches found
        if ( empty( $results ) ) {
            $name = '';
            if ( ! empty( $contact['display_name'] ) ) {
                $name = $contact['display_name'];
            } elseif ( ! empty( $contact['first_name'] ) && ! empty( $contact['last_name'] ) ) {
                $name = $contact['first_name'] . ' ' . $contact['last_name'];
            }
            if ( $name ) {
                $ids = self::find_all_by_name( $name );
                foreach ( $ids as $pid ) {
                    // Directorist stores directory ID in 'directory_type' meta (native).
                // Our plugin also writes '_atbdp_listing_type' as a fallback.
                $dir_id = (int) get_post_meta( $pid, 'directory_type', true );
                if ( ! $dir_id ) {
                    $dir_id = (int) get_post_meta( $pid, '_atbdp_listing_type', true );
                }
                    $results[ $pid ] = [ 'post_id' => $pid, 'directory_id' => $dir_id, 'confidence' => 'medium', 'method' => 'name' ];
                }
            }
        }

        return array_values( $results );
    }

    private static function find_all_by_email( $email ) {
        global $wpdb;
        $ids = [];

        // Check _atbdp_email post meta (all matches)
        $meta_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_atbdp_email' AND meta_value = %s",
            sanitize_email( $email )
        ) );
        foreach ( $meta_ids as $id ) $ids[] = (int) $id;

        // Also check listings where author has this email
        $user = get_user_by( 'email', $email );
        if ( $user ) {
            $author_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d AND post_type = 'at_biz_dir' AND post_status NOT IN ('trash','auto-draft')",
                $user->ID
            ) );
            foreach ( $author_ids as $id ) {
                $int_id = (int) $id;
                if ( ! in_array( $int_id, $ids, true ) ) $ids[] = $int_id;
            }
        }

        return $ids;
    }

    private static function find_all_by_name( $name ) {
        global $wpdb;
        $clean       = sanitize_text_field( $name );
        $search_name = preg_replace( '/^(Dr\.?\s*)/i', '', $clean );

        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'at_biz_dir'
             AND post_status NOT IN ('trash','auto-draft')
             AND (post_title LIKE %s OR post_title LIKE %s)",
            '%' . $wpdb->esc_like( $search_name ) . '%',
            '%' . $wpdb->esc_like( $clean ) . '%'
        ) ) );
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
