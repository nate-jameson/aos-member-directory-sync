<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AOS_MS_Admin {

    public function __construct() {
        add_action( 'admin_menu',             [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_aos_ms_save_settings',     [ $this, 'ajax_save_settings' ] );
        add_action( 'wp_ajax_aos_ms_test_civicrm',      [ $this, 'ajax_test_civicrm' ] );
        add_action( 'wp_ajax_aos_ms_load_expired',      [ $this, 'ajax_load_expired' ] );
        add_action( 'wp_ajax_aos_ms_deactivate_listing',[ $this, 'ajax_deactivate_listing' ] );
        add_action( 'wp_ajax_aos_ms_deactivate_bulk',   [ $this, 'ajax_deactivate_bulk' ] );
        add_action( 'wp_ajax_aos_ms_load_new_members',  [ $this, 'ajax_load_new_members' ] );
        add_action( 'wp_ajax_aos_ms_create_draft',       [ $this, 'ajax_create_draft' ] );
        add_action( 'wp_ajax_aos_ms_create_all_drafts',  [ $this, 'ajax_create_all_drafts' ] );
    }

    public function register_menu() {
        add_menu_page(
            'AOS Member Directory Sync',
            'Member Sync',
            'manage_options',
            'aos-member-directory-sync',
            [ $this, 'render_page' ],
            'dashicons-update',
            56
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'aos-member-directory-sync' ) === false ) return;
        wp_enqueue_style( 'aos-ms-style', AOS_MS_URL . 'assets/admin.css', [], AOS_MS_VERSION );
        wp_enqueue_script( 'aos-ms-script', AOS_MS_URL . 'assets/admin.js', [ 'jquery' ], AOS_MS_VERSION, true );
        wp_localize_script( 'aos-ms-script', 'aosMsData', [
            'nonce'               => wp_create_nonce( 'aos_ms_nonce' ),
            'ajaxurl'             => admin_url( 'admin-ajax.php' ),
            'providerDirectoryId' => (int) AOS_MS_Settings::get( 'provider_directory_id', 34 ),
            'practiceDirectoryId' => (int) AOS_MS_Settings::get( 'practice_directory_id', 115 ),
        ] );
    }

    public function render_page() {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
        $tabs = [
            'settings'    => 'Settings',
            'sync'        => 'Sync Expired Members',
            'new_members' => 'New Members',
        ];
        ?>
        <div class="wrap aos-ms-wrap">
            <h1>
                <span class="dashicons dashicons-update"></span>
                AOS Member Directory Sync
            </h1>
            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $slug => $label ) : ?>
                    <a href="?page=aos-member-directory-sync&tab=<?php echo esc_attr( $slug ); ?>"
                       class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="aos-ms-tab-content">
                <?php
                if ( $tab === 'settings' )    $this->render_settings_tab();
                elseif ( $tab === 'sync' )    $this->render_sync_tab();
                elseif ( $tab === 'new_members' ) $this->render_new_members_tab();
                ?>
            </div>
        </div>
        <?php
    }

    /* ─── SETTINGS TAB ──────────────────────────────────────────────── */

    private function render_settings_tab() {
        $sections = AOS_MS_Settings::fields();
        ?>
        <form id="aos-ms-settings-form">
            <?php foreach ( $sections as $section_key => $section ) : ?>
                <div class="aos-ms-card">
                    <h2><?php echo esc_html( $section['label'] ); ?></h2>
                    <?php if ( ! empty( $section['desc'] ) ) : ?>
                        <p class="description"><?php echo esc_html( $section['desc'] ); ?></p>
                    <?php endif; ?>
                    <table class="form-table">
                        <?php foreach ( $section['fields'] as $key => $field ) : ?>
                            <tr>
                                <th><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
                                <td>
                                    <input
                                        type="<?php echo esc_attr( $field['type'] ); ?>"
                                        id="<?php echo esc_attr( $key ); ?>"
                                        name="<?php echo esc_attr( $key ); ?>"
                                        value="<?php echo esc_attr( AOS_MS_Settings::get( $key, $field['default'] ?? '' ) ); ?>"
                                        placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>"
                                        class="regular-text"
                                    />
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php endforeach; ?>

            <div class="aos-ms-actions">
                <button type="submit" class="button button-primary">Save Settings</button>
                <button type="button" id="aos-ms-test-civicrm" class="button">Test CiviCRM Connection</button>
                <span id="aos-ms-settings-status" class="aos-ms-status"></span>
            </div>
        </form>
        <?php
    }

    /* ─── SYNC EXPIRED TAB ──────────────────────────────────────────── */

    private function render_sync_tab() {
        $months = (int) AOS_MS_Settings::get( 'expired_lookback_months', 6 );
        ?>
        <div class="aos-ms-card">
            <h2>Deactivate Listings for Expired Members</h2>
            <p>
                Finds CiviCRM memberships that expired in the last <strong><?php echo $months; ?> months</strong>,
                matches them to directory listings, and sets matched listings to <strong>Draft</strong> (inactive).
            </p>
            <p class="description">Listings without a confident match are shown for manual review and are <em>not</em> automatically changed.</p>
            <div class="aos-ms-actions">
                <button id="aos-ms-load-expired" class="button button-primary">
                    <span class="dashicons dashicons-search"></span> Load Expired Members
                </button>
                <span id="aos-ms-sync-status" class="aos-ms-status"></span>
            </div>
        </div>

        <div id="aos-ms-expired-results" style="display:none;">
            <div class="aos-ms-card">
                <div class="aos-ms-table-header">
                    <h3 id="aos-ms-expired-count"></h3>
                    <div>
                        <button id="aos-ms-deactivate-all" class="button button-primary" style="display:none;">
                            Deactivate All Matched Listings
                        </button>
                    </div>
                </div>
                <table id="aos-ms-expired-table" class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th class="check-column"><input type="checkbox" id="aos-ms-select-all"></th>
                            <th>CiviCRM Member</th>
                            <th>Email</th>
                            <th>Membership Type</th>
                            <th>Expired</th>
                            <th>Provider Listing (#<?php echo (int) AOS_MS_Settings::get('provider_directory_id', 34); ?>)</th>
                            <th>Practice Listing (#<?php echo (int) AOS_MS_Settings::get('practice_directory_id', 115); ?>)</th>
                            <th>Confidence</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="aos-ms-expired-tbody">
                        <tr><td colspan="8">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /* ─── NEW MEMBERS TAB ───────────────────────────────────────────── */

    private function render_new_members_tab() {
        ?>
        <div class="aos-ms-card">
            <h2>Active Members Without Listings</h2>
            <p>
                Finds CiviCRM contacts with an <strong>active or grace</strong> membership who don't yet have a directory listing.
                You can create AI-enriched draft listings for any or all of them for your team to review, complete, and publish.
            </p>
            <div class="aos-ms-actions">
                <button id="aos-ms-load-new" class="button button-primary">
                    <span class="dashicons dashicons-search"></span> Load New Members
                </button>
                <span id="aos-ms-new-status" class="aos-ms-status"></span>
            </div>
        </div>

        <div id="aos-ms-new-results" style="display:none;">
            <div class="aos-ms-card">
                <div class="aos-ms-table-header">
                    <h3 id="aos-ms-new-count"></h3>
                    <div>
                        <button id="aos-ms-create-all-drafts" class="button button-primary" style="display:none;">
                            <span class="dashicons dashicons-edit"></span> Create All Drafts (Provider + Practice, AI Enriched)
                        </button>
                    </div>
                </div>
                <table id="aos-ms-new-table" class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th class="check-column"><input type="checkbox" id="aos-ms-select-all-new"></th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Membership</th>
                            <th>Start</th>
                            <th>End</th>
                            <th>Status</th>
                            <th>Credentialing</th>
                            <th>Location</th>
                            <th>Website</th>
                            <th>Provider Listing (#<?php echo (int) AOS_MS_Settings::get('provider_directory_id', 34); ?>)</th>
                            <th>Practice Listing (#<?php echo (int) AOS_MS_Settings::get('practice_directory_id', 115); ?>)</th>
                        </tr>
                    </thead>
                    <tbody id="aos-ms-new-tbody">
                        <tr><td colspan="12">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /* ─── AJAX HANDLERS ─────────────────────────────────────────────── */

    private function verify_nonce() {
        if ( ! check_ajax_referer( 'aos_ms_nonce', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
    }

    public function ajax_save_settings() {
        $this->verify_nonce();
        $all_fields = [];
        foreach ( AOS_MS_Settings::fields() as $section ) {
            foreach ( $section['fields'] as $key => $def ) {
                $all_fields[] = $key;
            }
        }
        $data = [];
        foreach ( $all_fields as $key ) {
            if ( isset( $_POST[ $key ] ) ) {
                $data[ $key ] = sanitize_text_field( $_POST[ $key ] );
            }
        }
        AOS_MS_Settings::save( $data );
        wp_send_json_success( 'Settings saved.' );
    }

    public function ajax_test_civicrm() {
        $this->verify_nonce();
        $civi = new AOS_MS_CiviCRM();
        if ( ! $civi->is_configured() ) {
            wp_send_json_error( 'CiviCRM is not configured. Please fill in the connection settings.' );
        }
        $result = $civi->test_connection();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( 'Connection successful!' );
    }

    public function ajax_load_expired() {
        $this->verify_nonce();
        $civi         = new AOS_MS_CiviCRM();
        $months       = (int) AOS_MS_Settings::get( 'expired_lookback_months', 6 );
        $provider_dir = (int) AOS_MS_Settings::get( 'provider_directory_id', 34 );
        $practice_dir = (int) AOS_MS_Settings::get( 'practice_directory_id', 115 );

        if ( ! $civi->is_configured() ) {
            wp_send_json_error( 'CiviCRM not configured.' );
        }

        $memberships = $civi->get_expired_memberships( $months );
        if ( is_wp_error( $memberships ) ) {
            wp_send_json_error( 'CiviCRM query failed: ' . $memberships->get_error_message() );
            return;
        }
        if ( empty( $memberships ) ) {
            wp_send_json_success( [ 'rows' => [], 'message' => 'No expired memberships found in the last ' . $months . ' months.' ] );
            return;
        }

        $rows = [];
        foreach ( $memberships as $m ) {
            $all_matches = AOS_MS_Matcher::find_all_listings( $m );

            // Split matches into provider vs practice by directory ID
            $provider_match = null;
            $practice_match = null;
            foreach ( $all_matches as $match ) {
                if ( $match['directory_id'] === $provider_dir && ! $provider_match ) {
                    $provider_match = $match;
                } elseif ( $match['directory_id'] === $practice_dir && ! $practice_match ) {
                    $practice_match = $match;
                }
            }

            // Helper to build listing info
            $make_info = function( $match ) {
                if ( ! $match ) return [ 'post_id' => 0, 'label' => '—', 'edit_url' => '', 'status' => '' ];
                $post = get_post( $match['post_id'] );
                return [
                    'post_id'  => $match['post_id'],
                    'label'    => $post ? $post->post_title : 'Post #' . $match['post_id'],
                    'edit_url' => admin_url( 'post.php?post=' . $match['post_id'] . '&action=edit' ),
                    'status'   => get_post_status( $match['post_id'] ) ?: 'unknown',
                ];
            };

            $provider_info = $make_info( $provider_match );
            $practice_info = $make_info( $practice_match );

            $confidence  = $provider_match ? $provider_match['confidence'] : ( $practice_match ? $practice_match['confidence'] : 'none' );
            $match_method = $provider_match ? $provider_match['method'] : ( $practice_match ? $practice_match['method'] : '' );

            $rows[] = [
                'contact_id'              => $m['contact_id'] ?? '',
                'display_name'            => $m['display_name'] ?? '',
                'email'                   => $m['email'] ?? '',
                'membership_type_id'      => $m['membership_type_id'] ?? '',
                'end_date'                => $m['end_date'] ?? '',
                // Provider listing
                'provider_post_id'        => $provider_info['post_id'],
                'provider_label'          => $provider_info['label'],
                'provider_edit_url'       => $provider_info['edit_url'],
                'provider_status'         => $provider_info['status'],
                // Practice listing
                'practice_post_id'        => $practice_info['post_id'],
                'practice_label'          => $practice_info['label'],
                'practice_edit_url'       => $practice_info['edit_url'],
                'practice_status'         => $practice_info['status'],
                // Legacy single-listing fields for backward compat
                'listing_post_id'         => $provider_info['post_id'] ?: $practice_info['post_id'],
                'listing_label'           => $provider_info['post_id'] ? $provider_info['label'] : $practice_info['label'],
                'listing_edit_url'        => $provider_info['post_id'] ? $provider_info['edit_url'] : $practice_info['edit_url'],
                'listing_status'          => $provider_info['post_id'] ? $provider_info['status'] : $practice_info['status'],
                'confidence'              => $confidence,
                'match_method'            => $match_method,
            ];
        }

        wp_send_json_success( [ 'rows' => $rows ] );
    }

    public function ajax_deactivate_listing() {
        $this->verify_nonce();
        // Accept single post_id or array of post_ids to deactivate at once
        $post_ids = [];
        if ( ! empty( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] ) ) {
            $post_ids = array_map( 'intval', $_POST['post_ids'] );
        } elseif ( ! empty( $_POST['post_id'] ) ) {
            $post_ids = [ (int) $_POST['post_id'] ];
        }
        $post_ids = array_filter( $post_ids );
        if ( empty( $post_ids ) ) wp_send_json_error( 'Invalid post ID(s).' );

        $deactivated = [];
        $errors      = [];
        foreach ( $post_ids as $pid ) {
            $result = AOS_MS_Listing_Creator::deactivate( $pid );
            if ( is_wp_error( $result ) ) {
                $errors[] = $pid;
            } else {
                $deactivated[] = $pid;
            }
        }

        if ( ! empty( $errors ) && empty( $deactivated ) ) {
            wp_send_json_error( 'Failed to deactivate listing(s): ' . implode( ', ', $errors ) );
        }
        wp_send_json_success( [ 'post_ids' => $deactivated, 'message' => 'Listing(s) set to draft.' ] );
    }

    public function ajax_deactivate_bulk() {
        $this->verify_nonce();
        $post_ids = array_map( 'intval', $_POST['post_ids'] ?? [] );
        if ( empty( $post_ids ) ) wp_send_json_error( 'No IDs provided.' );

        $deactivated = 0;
        $errors      = [];
        foreach ( $post_ids as $id ) {
            $result = AOS_MS_Listing_Creator::deactivate( $id );
            if ( is_wp_error( $result ) ) {
                $errors[] = $id;
            } else {
                $deactivated++;
            }
        }

        wp_send_json_success( [
            'deactivated' => $deactivated,
            'errors'      => $errors,
            'message'     => "Deactivated {$deactivated} listings." . ( $errors ? ' Errors on IDs: ' . implode( ', ', $errors ) : '' ),
        ] );
    }

    public function ajax_load_new_members() {
        $this->verify_nonce();
        $civi = new AOS_MS_CiviCRM();
        if ( ! $civi->is_configured() ) wp_send_json_error( 'CiviCRM not configured.' );

        // type_active holds the membership type IDs to query.
        // type_achievement/fellowship/diplomate are for badge-mapping only.
        $active_raw = AOS_MS_Settings::get( 'type_active', '' );
        $all_ids    = array_filter( array_map( 'intval', array_map( 'trim', explode( ',', $active_raw ) ) ) );
        $all_ids    = array_unique( $all_ids );

        $memberships = $civi->get_active_memberships( $all_ids );
        if ( is_wp_error( $memberships ) ) {
            wp_send_json_error( 'CiviCRM query failed: ' . $memberships->get_error_message() );
            return;
        }
        if ( empty( $memberships ) ) {
            wp_send_json_success( [ 'rows' => [], 'message' => 'No active memberships found.' ] );
            return;
        }

        // Show members who are missing at least one directory listing (provider OR practice).
        // Only exclude contacts that have BOTH directories with published (active) listings.
        $provider_dir_id = (int) AOS_MS_Settings::get( 'provider_directory_id', 34 );
        $practice_dir_id = (int) AOS_MS_Settings::get( 'practice_directory_id', 115 );

        $make_listing_info = function( $match ) {
            if ( ! $match ) return [ 'post_id' => 0, 'label' => '', 'edit_url' => '', 'status' => '' ];
            $post = get_post( $match['post_id'] );
            return [
                'post_id'  => $match['post_id'],
                'label'    => $post ? $post->post_title : 'Post #' . $match['post_id'],
                'edit_url' => admin_url( 'post.php?post=' . $match['post_id'] . '&action=edit' ),
                'status'   => get_post_status( $match['post_id'] ) ?: 'unknown',
            ];
        };

        $rows = [];
        $seen_contacts = [];
        foreach ( $memberships as $m ) {
            $cid = $m['contact_id'];
            if ( in_array( $cid, $seen_contacts ) ) continue; // dedupe contacts

            $all_matches = AOS_MS_Matcher::find_all_listings( $m );

            $provider_match = null;
            $practice_match = null;
            foreach ( $all_matches as $match ) {
                if ( $match['directory_id'] === $provider_dir_id && ! $provider_match ) {
                    $provider_match = $match;
                } elseif ( $match['directory_id'] === $practice_dir_id && ! $practice_match ) {
                    $practice_match = $match;
                }
            }

            $prov_info = $make_listing_info( $provider_match );
            $prac_info = $make_listing_info( $practice_match );

            $prov_active = ( $prov_info['post_id'] && $prov_info['status'] === 'publish' );
            $prac_active = ( $prac_info['post_id'] && $prac_info['status'] === 'publish' );

            // Skip only if BOTH directories have active (published) listings
            if ( $prov_active && $prac_active ) continue;

            $seen_contacts[] = $cid;
            $rows[] = [
                'contact_id'           => $cid,
                'display_name'         => $m['display_name'] ?? '',
                'email'                => $m['email'] ?? '',
                'membership_type_id'   => $m['membership_type_id'] ?? '',
                'membership_type_name' => $m['membership_type_name'] ?? '',
                'start_date'           => $m['start_date'] ?? '',
                'end_date'             => $m['end_date'] ?? '',
                'status'               => $m['status'] ?? '',
                'credentialing'        => '', // populated below via second query
                'city'                 => $m['city'] ?? '',
                'state_province'       => $m['state'] ?? $m['state_province'] ?? '',
                'website'              => $m['website'] ?? '',
                'phone'                => $m['phone'] ?? '',
                'street_address'       => $m['street_address'] ?? '',
                'postal_code'          => $m['postal_code'] ?? '',
                'first_name'           => $m['first_name'] ?? '',
                'last_name'            => $m['last_name'] ?? '',
                // Per-directory listing data
                'provider_post_id'     => $prov_info['post_id'],
                'provider_label'       => $prov_info['label'],
                'provider_edit_url'    => $prov_info['edit_url'],
                'provider_status'      => $prov_info['status'],
                'practice_post_id'     => $prac_info['post_id'],
                'practice_label'       => $prac_info['label'],
                'practice_edit_url'    => $prac_info['edit_url'],
                'practice_status'      => $prac_info['status'],
                'needs_provider'       => ! $prov_active,
                'needs_practice'       => ! $prac_active,
            ];
        }

        // Second query: determine which credential memberships are actively held.
        // A member may qualify for the directory via a base "Member" type but hold
        // separate active (or inactive) Achievement/Fellowship/Diplomate memberships.
        $achievement_ids = array_values( array_filter( array_map( 'intval', array_map( 'trim', explode( ',', AOS_MS_Settings::get( 'type_achievement', '' ) ) ) ) ) );
        $fellowship_ids  = array_values( array_filter( array_map( 'intval', array_map( 'trim', explode( ',', AOS_MS_Settings::get( 'type_fellowship', '' ) ) ) ) ) );
        $diplomate_ids   = array_values( array_filter( array_map( 'intval', array_map( 'trim', explode( ',', AOS_MS_Settings::get( 'type_diplomate', '' ) ) ) ) ) );
        $all_cred_ids    = array_unique( array_merge( $achievement_ids, $fellowship_ids, $diplomate_ids ) );

        $credential_map = [];
        if ( ! empty( $all_cred_ids ) && ! empty( $rows ) ) {
            $contact_ids = array_column( $rows, 'contact_id' );
            $cred_result = $civi->get_credential_memberships( $contact_ids, $all_cred_ids );
            if ( ! is_wp_error( $cred_result ) ) {
                $credential_map = $cred_result;
            }
        }

        // Badge label order: Diplomate > Fellowship > Achievement
        $badge_order = [ 'Diplomate' => 0, 'Fellowship' => 1, 'Achievement' => 2 ];
        foreach ( $rows as &$row ) {
            $active_type_ids = $credential_map[ (string) $row['contact_id'] ] ?? [];
            $badges = [];
            foreach ( $active_type_ids as $tid ) {
                if ( in_array( $tid, $diplomate_ids ) )   $badges[] = 'Diplomate';
                elseif ( in_array( $tid, $fellowship_ids ) )  $badges[] = 'Fellowship';
                elseif ( in_array( $tid, $achievement_ids ) ) $badges[] = 'Achievement';
            }
            $badges = array_unique( $badges );
            usort( $badges, fn( $a, $b ) => ( $badge_order[ $a ] ?? 9 ) <=> ( $badge_order[ $b ] ?? 9 ) );
            $row['credentialing'] = implode( ', ', $badges );
        }
        unset( $row );

        wp_send_json_success( [ 'rows' => $rows ] );
    }

    public function ajax_create_draft() {
        $this->verify_nonce();

        $contact = [
            'id'             => intval( $_POST['contact_id'] ?? 0 ),
            'display_name'   => sanitize_text_field( $_POST['display_name'] ?? '' ),
            'email'          => sanitize_email( $_POST['email'] ?? '' ),
            'phone'          => sanitize_text_field( $_POST['phone'] ?? '' ),
            'website'        => esc_url_raw( $_POST['website'] ?? '' ),
            'first_name'     => sanitize_text_field( $_POST['first_name'] ?? '' ),
            'last_name'      => sanitize_text_field( $_POST['last_name'] ?? '' ),
            'street_address' => sanitize_text_field( $_POST['street_address'] ?? '' ),
            'postal_code'    => sanitize_text_field( $_POST['postal_code'] ?? '' ),
            'city'           => sanitize_text_field( $_POST['city'] ?? '' ),
            'state_province' => sanitize_text_field( $_POST['state_province'] ?? '' ),
        ];
        $credentialing = sanitize_text_field( $_POST['credentialing'] ?? '' );
        $type_id       = intval( $_POST['membership_type_id'] ?? 0 );
        $directory_id  = intval( $_POST['directory_id'] ?? 0 );

        // AI enrichment
        $ai_data = [];
        $gemini  = new AOS_MS_Gemini();
        if ( $gemini->is_configured() ) {
            $ai_data = $gemini->enrich_listing( $contact, $contact['website'] );
        }

        $post_id = AOS_MS_Listing_Creator::create_draft( $contact, $credentialing, $ai_data, $directory_id );
        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( $post_id->get_error_message() );
        }

        wp_send_json_success( [
            'post_id'      => $post_id,
            'directory_id' => $directory_id,
            'edit_url'     => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
            'ai_conf'      => $ai_data['confidence'] ?? 'none',
            'message'      => 'Draft created.',
        ] );
    }

    public function ajax_create_all_drafts() {
        $this->verify_nonce();
        $contacts = json_decode( stripslashes( $_POST['contacts'] ?? '[]' ), true );
        if ( empty( $contacts ) ) wp_send_json_error( 'No contacts provided.' );

        $provider_dir = (int) AOS_MS_Settings::get( 'provider_directory_id', 34 );
        $practice_dir = (int) AOS_MS_Settings::get( 'practice_directory_id', 115 );
        $directories  = array_unique( array_filter( [ $provider_dir, $practice_dir ] ) );

        $created = 0;
        $errors  = [];
        $gemini  = new AOS_MS_Gemini();

        foreach ( $contacts as $c ) {
            $contact = [
                'id'             => intval( $c['contact_id'] ?? 0 ),
                'display_name'   => sanitize_text_field( $c['display_name'] ?? '' ),
                'email'          => sanitize_email( $c['email'] ?? '' ),
                'phone'          => sanitize_text_field( $c['phone'] ?? '' ),
                'website'        => esc_url_raw( $c['website'] ?? '' ),
                'first_name'     => sanitize_text_field( $c['first_name'] ?? '' ),
                'last_name'      => sanitize_text_field( $c['last_name'] ?? '' ),
                'street_address' => sanitize_text_field( $c['street_address'] ?? '' ),
                'postal_code'    => sanitize_text_field( $c['postal_code'] ?? '' ),
                'city'           => sanitize_text_field( $c['city'] ?? '' ),
                'state_province' => sanitize_text_field( $c['state_province'] ?? '' ),
            ];
            $credentialing = sanitize_text_field( $c['credentialing'] ?? '' );

            // Determine which directories still need creation for this member
            $needs_provider = isset( $c['needs_provider'] ) ? (bool) $c['needs_provider'] : true;
            $needs_practice = isset( $c['needs_practice'] ) ? (bool) $c['needs_practice'] : true;
            $dirs_to_create = [];
            if ( $needs_provider ) $dirs_to_create[] = $provider_dir;
            if ( $needs_practice ) $dirs_to_create[] = $practice_dir;
            if ( empty( $dirs_to_create ) ) continue;

            // Enrich once, reuse for both listings
            $ai_data = [];
            if ( $gemini->is_configured() ) {
                $ai_data = $gemini->enrich_listing( $contact, $contact['website'] );
            }

            foreach ( $dirs_to_create as $dir_id ) {
                $post_id = AOS_MS_Listing_Creator::create_draft( $contact, $credentialing, $ai_data, $dir_id );
                if ( is_wp_error( $post_id ) ) {
                    $errors[] = ( $contact['display_name'] ?: 'Contact ' . $contact['id'] ) . ' (dir ' . $dir_id . '): ' . $post_id->get_error_message();
                } else {
                    $created++;
                }
            }
        }

        $listings_each = count( $directories );
        wp_send_json_success( [
            'created' => $created,
            'errors'  => $errors,
            'message' => "Created {$created} draft listings ({$listings_each} per member)." . ( $errors ? ' Errors: ' . implode( '; ', array_slice( $errors, 0, 3 ) ) : '' ),
        ] );
    }
}
