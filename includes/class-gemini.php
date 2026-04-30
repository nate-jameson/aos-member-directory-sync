<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AOS_MS_Gemini {

    const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';

    private $api_key;
    private $places_api_key;

    public function __construct() {
        $this->api_key        = AOS_MS_Settings::get( 'gemini_api_key' );
        $this->places_api_key = AOS_MS_Settings::get( 'places_api_key' );
    }

    public function is_configured() {
        return ! empty( $this->api_key );
    }

    // -------------------------------------------------------------------------
    // Step 1 — find the practice website (Places API → DuckDuckGo fallback)
    // -------------------------------------------------------------------------

    /**
     * Try Google Places "Find Place from Text" to get the practice website.
     * Returns the website URL, or '' if not found / API key not configured.
     *
     * @param  string $name  Doctor or practice name
     * @param  string $city
     * @param  string $state
     * @return string URL or empty string
     */
    private function find_website_via_places( $name, $city = '', $state = '' ) {
        if ( empty( $this->places_api_key ) ) return '';

        $query = trim( "{$name} dentist {$city} {$state}" );

        // New Places API (v1) — single Text Search request
        // Docs: https://developers.google.com/maps/documentation/places/web-service/text-search
        $response = wp_remote_post(
            'https://places.googleapis.com/v1/places:searchText',
            [
                'timeout'   => 15,
                'sslverify' => true,
                'headers'   => [
                    'Content-Type'     => 'application/json',
                    'X-Goog-Api-Key'   => $this->places_api_key,
                    'X-Goog-FieldMask' => 'places.websiteUri,places.displayName',
                ],
                'body' => wp_json_encode( [
                    'textQuery'  => $query,
                    'maxResults' => 1,
                ] ),
            ]
        );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) return '';

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data['places'][0]['websiteUri'] ?? '';
    }

    /**
     * Search DuckDuckGo HTML for a practice/provider website.
     * Returns the first result URL that isn't a social/review/aggregator site.
     *
     * @param  string $name  Doctor or practice name
     * @param  string $city
     * @param  string $state
     * @return string URL or empty string
     */
    private function find_website_via_duckduckgo( $name, $city = '', $state = '' ) {
        $query = trim( "{$name} dentist {$city} {$state}" );

        $response = wp_remote_get(
            'https://html.duckduckgo.com/html/?q=' . urlencode( $query ),
            [
                'timeout'   => 20,
                'sslverify' => false,
                'headers'   => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ' .
                                    'AppleWebKit/537.36 (KHTML, like Gecko) ' .
                                    'Chrome/122.0 Safari/537.36',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ],
            ]
        );

        if ( is_wp_error( $response ) )                               return '';
        if ( wp_remote_retrieve_response_code( $response ) !== 200 )  return '';

        $html = wp_remote_retrieve_body( $response );

        // DuckDuckGo HTML wraps result links as redirect URLs:
        //   <a class="result__a" href="/l/?uddg=https%3A%2F%2F...&rut=...">
        // Extract uddg= values first.
        $urls = [];
        if ( preg_match_all( '/uddg=([^&"\'> ]+)/i', $html, $m ) ) {
            foreach ( $m[1] as $enc ) {
                $u = urldecode( $enc );
                if ( preg_match( '/^https?:\/\//i', $u ) ) $urls[] = $u;
            }
        }

        // Fallback: plain href values
        if ( empty( $urls ) ) {
            if ( preg_match_all( '/href=["\']?(https?:\/\/[^"\'> ]+)/i', $html, $m2 ) ) {
                $urls = array_merge( $urls, $m2[1] );
            }
        }

        // Domains to skip — directories, social, review sites
        $skip_domains = [
            'duckduckgo.com', 'facebook.com', 'twitter.com', 'instagram.com',
            'linkedin.com', 'youtube.com', 'wikipedia.org',
            'yelp.com', 'healthgrades.com', 'zocdoc.com', 'vitals.com',
            'ratemds.com', 'webmd.com', 'doximity.com', 'sharecare.com',
            'usnews.com', 'castleconnolly.com', 'ada.org', 'aaoinfo.org',
            'dentalplans.com', 'dental.com', '1-800-dentist.com',
            'findadentist.ada.org', 'opencare.com', 'birdeye.com',
            'orthodontics.com', 'find.orthodontics.com',
        ];

        foreach ( $urls as $url ) {
            $skip = false;
            foreach ( $skip_domains as $domain ) {
                if ( stripos( $url, $domain ) !== false ) { $skip = true; break; }
            }
            if ( ! $skip ) return $url;
        }

        return '';
    }

    /**
     * Find a practice website for the given doctor.
     * Tries Google Places API first (most accurate — returns GMB website).
     * Falls back to DuckDuckGo search if Places API is not configured or returns nothing.
     *
     * @param  string $name
     * @param  string $city
     * @param  string $state
     * @return string URL or empty string
     */
    public function find_website( $name, $city = '', $state = '' ) {
        // 1 — Google Places (GMB website)
        $url = $this->find_website_via_places( $name, $city, $state );
        if ( $url ) return $url;

        // 2 — DuckDuckGo fallback
        return $this->find_website_via_duckduckgo( $name, $city, $state );
    }

    // -------------------------------------------------------------------------
    // Step 2 — image discovery helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch raw HTML from a URL (no tag-stripping).
     */
    private function fetch_html( $url ) {
        $response = wp_remote_get( $url, [
            'timeout'   => 15,
            'sslverify' => false,
            'headers'   => [
                'User-Agent' => 'Mozilla/5.0 (compatible; AOS-Member-Sync/1.0)',
            ],
        ] );
        if ( is_wp_error( $response ) )                               return '';
        if ( wp_remote_retrieve_response_code( $response ) !== 200 )  return '';
        return wp_remote_retrieve_body( $response );
    }

    /**
     * Extract og:image content from raw HTML.
     */
    private function extract_og_image( $html ) {
        // Both attribute orders
        if ( preg_match(
            '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']|' .
            '<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i',
            $html, $m
        ) ) {
            return trim( $m[1] ?: $m[2] );
        }
        return '';
    }

    /**
     * Find candidate About/Team/Doctor/Meet page links in HTML.
     * Returns up to 5 absolute URLs.
     */
    private function find_about_links( $html, $base_url ) {
        $keywords = [ 'about', 'team', 'doctor', 'meet', 'staff', 'provider', 'bio', 'dr-', 'our-' ];
        $links    = [];

        if ( preg_match_all( '/href=["\']([^"\'#?][^"\']*)["\']/', $html, $m ) ) {
            foreach ( $m[1] as $href ) {
                $href_lower = strtolower( $href );
                foreach ( $keywords as $kw ) {
                    if ( strpos( $href_lower, $kw ) !== false ) {
                        $links[] = $this->resolve_url( $href, $base_url );
                        break;
                    }
                }
            }
        }

        return array_unique( array_slice( $links, 0, 5 ) );
    }

    /**
     * Resolve a potentially-relative URL against a base URL.
     */
    private function resolve_url( $url, $base_url ) {
        if ( preg_match( '/^https?:\/\//i', $url ) ) return $url;
        if ( substr( $url, 0, 2 ) === '//' )          return 'https:' . $url;

        $parsed = parse_url( $base_url );
        $scheme = $parsed['scheme'] ?? 'https';
        $host   = $parsed['host']   ?? '';

        if ( substr( $url, 0, 1 ) === '/' ) {
            return $scheme . '://' . $host . $url;
        }

        $path = isset( $parsed['path'] ) ? dirname( $parsed['path'] ) : '';
        return $scheme . '://' . $host . rtrim( $path, '/' ) . '/' . ltrim( $url, '/' );
    }

    /**
     * Find the <img> src closest in DOM position to mentions of $doctor_name.
     * Skips obvious logos, icons, SVGs, and GIFs.
     *
     * @return string Absolute URL or ''
     */
    private function find_image_near_name( $html, $doctor_name, $base_url ) {
        if ( ! preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $img_m, PREG_OFFSET_CAPTURE ) ) {
            return '';
        }

        // Positions where the doctor's name appears
        $name_parts  = array_filter( explode( ' ', $doctor_name ), fn( $p ) => strlen( $p ) > 2 );
        $name_positions = [];
        foreach ( $name_parts as $part ) {
            $pos = stripos( $html, $part );
            if ( $pos !== false ) $name_positions[] = $pos;
        }

        $best_src  = '';
        $best_dist = PHP_INT_MAX;

        foreach ( $img_m[1] as [ $src, $img_pos ] ) {
            // Skip decorative / non-photo images
            if ( preg_match( '/logo|icon|arrow|button|banner|sprite|pixel|bg-/i', $src ) ) continue;
            if ( preg_match( '/\.(svg|gif)(\?|$)/i', $src ) ) continue;

            if ( empty( $name_positions ) ) {
                // No name match — just return first qualifying image
                return $this->resolve_url( $src, $base_url );
            }

            foreach ( $name_positions as $name_pos ) {
                $dist = abs( $img_pos - $name_pos );
                if ( $dist < $best_dist ) {
                    $best_dist = $dist;
                    $best_src  = $src;
                }
            }
        }

        return $best_src ? $this->resolve_url( $best_src, $base_url ) : '';
    }

    /**
     * Find the best doctor/practice photo for a listing.
     *
     * Priority order:
     *  1. og:image on an About/Team/Doctor/Meet sub-page
     *  2. Nearest <img> to doctor name on that sub-page
     *  3. og:image on the homepage
     *  4. Nearest <img> to doctor name on the homepage
     *
     * @param  string $base_url     Homepage or practice website URL
     * @param  string $doctor_name  Doctor name for proximity scoring
     * @return string Absolute image URL, or '' if nothing usable found
     */
    public function find_best_image( $base_url, $doctor_name = '' ) {
        $homepage_html = $this->fetch_html( $base_url );
        if ( ! $homepage_html ) return '';

        $about_links = $this->find_about_links( $homepage_html, $base_url );

        // Check About/Team/Doctor sub-pages first
        foreach ( array_slice( $about_links, 0, 3 ) as $about_url ) {
            $about_html = $this->fetch_html( $about_url );
            if ( ! $about_html ) continue;

            $og = $this->extract_og_image( $about_html );
            if ( $og ) return $og;

            $near = $this->find_image_near_name( $about_html, $doctor_name, $about_url );
            if ( $near ) return $near;
        }

        // Fallback to homepage
        $og = $this->extract_og_image( $homepage_html );
        if ( $og ) return $og;

        return $this->find_image_near_name( $homepage_html, $doctor_name, $base_url );
    }

    // -------------------------------------------------------------------------
    // Step 3 — scrape a URL and return plain text
    // -------------------------------------------------------------------------

    /**
     * Fetch a URL and return stripped text content (max 6 000 chars).
     */
    public function scrape_url( $url ) {
        $response = wp_remote_get( $url, [
            'timeout'   => 20,
            'sslverify' => false,
            'headers'   => [
                'User-Agent' => 'Mozilla/5.0 (compatible; AOS-Member-Sync/1.0)',
            ],
        ] );

        if ( is_wp_error( $response ) )                                return '';
        if ( wp_remote_retrieve_response_code( $response ) !== 200 )   return '';

        $html = wp_remote_retrieve_body( $response );
        // Strip scripts, styles, navigation chrome
        $html = preg_replace( '/<(script|style|nav|header|footer|noscript)[^>]*>.*?<\/\1>/is', '', $html );
        $text = wp_strip_all_tags( $html );
        $text = preg_replace( '/[ \t]+/', ' ', $text );
        $text = preg_replace( '/(\r?\n){2,}/', "\n", trim( $text ) );
        return substr( $text, 0, 6000 );
    }

    // -------------------------------------------------------------------------
    // Step 3 — enrich listing via Gemini
    // -------------------------------------------------------------------------

    /**
     * Enrich a listing draft.
     * 1. If no website URL is known, search DuckDuckGo for one.
     * 2. Scrape the found URL.
     * 3. Ask Gemini to generate bio + specialty.
     *
     * Returns array with keys:
     *   biography, specialty, confidence (high|low),
     *   website_url (discovered or supplied), website_source (civicrm|search|none)
     */
    public function enrich_listing( $contact, $website_url = '', $listing_type = 'provider' ) {
        $name  = $contact['display_name'] ?? trim( ( $contact['first_name'] ?? '' ) . ' ' . ( $contact['last_name'] ?? '' ) );
        $city  = $contact['city']           ?? '';
        $state = $contact['state_province'] ?? '';

        // --- Resolve website URL -------------------------------------------
        $website_source = 'none';
        $website        = $website_url ?: ( $contact['website'] ?? '' );

        if ( ! empty( $website ) ) {
            $website_source = 'civicrm';
        } else {
            // Search DuckDuckGo
            $found = $this->find_website( $name, $city, $state );
            if ( $found ) {
                $website        = $found;
                $website_source = 'search';
            }
        }

        // --- Scrape website -----------------------------------------------
        $website_context = '';
        if ( ! empty( $website ) ) {
            $text = $this->scrape_url( $website );
            if ( $text ) {
                $website_context = "Content scraped from their practice website ({$website}):\n\n{$text}\n\n";
            } else {
                $website_context = "Their practice website is: {$website} (page could not be scraped — write a professional general bio).\n";
            }
        }

        // --- Build Gemini prompt ------------------------------------------
        if ( $listing_type === 'practice' ) {
            $prompt = <<<PROMPT
You are helping populate a professional directory listing for a dental practice that is a member of the American Orthodontic Society (AOS). AOS members are dental practices providing orthodontic services — they are NOT exclusively orthodontic-only offices.

Practice: {$name}
Location: {$city}, {$state}
{$website_context}
Based on the information above, write a professional practice description (3–5 sentences) suitable for a member directory listing. Use specific details from the website content if provided. Focus on the services offered, the team, patient experience, and what makes this practice distinctive. If no website content was available, write a general professional description for a dental practice offering orthodontic services.

Also suggest:
- A short specialty/tagline description (1 line, e.g. "Family Dentistry & Orthodontics")

Return your response ONLY as a JSON object with these exact keys:
- "biography": string (the practice description)
- "specialty": string
- "confidence": "high" if website content was provided and used, "low" if generic

No markdown fences. No extra keys. Valid JSON only.
PROMPT;
        } else {
            $prompt = <<<PROMPT
You are helping populate a professional directory listing for a dental provider who is a member of the American Orthodontic Society (AOS). AOS members are dentists and pediatric dentists who provide orthodontic services — they are NOT necessarily orthodontists by specialty.

Doctor: {$name}
Location: {$city}, {$state}
{$website_context}
Based on the information above, write a professional biography paragraph (3–5 sentences) suitable for a member directory listing. Use specific details from the website content if provided. Focus on their professional background, the services they offer, and what makes their practice distinctive. If no website content was available, write a general professional bio for a dental provider offering orthodontic services.

Also suggest:
- A short specialty description (1 line, e.g. "Orthodontics & Dentofacial Orthopedics")

Return your response ONLY as a JSON object with these exact keys:
- "biography": string
- "specialty": string
- "confidence": "high" if website content was provided and used, "low" if generic

No markdown fences. No extra keys. Valid JSON only.
PROMPT;
        }

        $body = [
            'contents' => [
                [ 'parts' => [ [ 'text' => $prompt ] ] ]
            ],
            'generationConfig' => [
                'temperature'     => 0.4,
                'maxOutputTokens' => 600,
            ],
        ];

        $response = wp_remote_post(
            self::API_URL . '?key=' . urlencode( $this->api_key ),
            [
                'timeout' => 30,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( $body ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [
                'error'          => $response->get_error_message(),
                'website_url'    => $website,
                'website_source' => $website_source,
                'scrape_length'  => strlen( $website_context ),
            ];
        }

        $http_code  = wp_remote_retrieve_response_code( $response );
        $raw_body   = wp_remote_retrieve_body( $response );

        if ( $http_code !== 200 ) {
            return [
                'error'          => "Gemini HTTP {$http_code}",
                'gemini_http'    => $http_code,
                'gemini_body'    => substr( $raw_body, 0, 800 ),
                'website_url'    => $website,
                'website_source' => $website_source,
                'scrape_length'  => strlen( $website_context ),
            ];
        }

        $data = json_decode( $raw_body, true );
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // Strip accidental markdown fences
        $text = preg_replace( '/^```(?:json)?\s*/i', '', trim( $text ) );
        $text = preg_replace( '/\s*```$/', '', $text );

        $parsed = json_decode( $text, true );
        if ( ! $parsed ) {
            // Return raw text as biography so we can see what Gemini actually said
            $parsed = [ 'biography' => $text, 'specialty' => '', 'confidence' => 'low', 'gemini_raw_text' => substr( $raw_body, 0, 500 ) ];
        }
        $parsed['scrape_length'] = strlen( $website_context );

        $parsed['website_url']    = $website;
        $parsed['website_source'] = $website_source;

        // --- Find best image -----------------------------------------------
        $image_url = '';
        if ( ! empty( $website ) ) {
            $image_url = $this->find_best_image( $website, $name );
        }
        $parsed['image_url'] = $image_url;

        return $parsed;
    }
}
