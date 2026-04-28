<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AOS_MS_Gemini {

    const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';

    private $api_key;

    public function __construct() {
        $this->api_key = AOS_MS_Settings::get( 'gemini_api_key' );
    }

    public function is_configured() {
        return ! empty( $this->api_key );
    }

    // -------------------------------------------------------------------------
    // Step 1 — find the practice website via DuckDuckGo
    // -------------------------------------------------------------------------

    /**
     * Search DuckDuckGo HTML for a practice/provider website.
     * Returns the first result URL that isn't a social/review/aggregator site.
     *
     * @param  string $name  Doctor or practice name
     * @param  string $city
     * @param  string $state
     * @return string URL or empty string
     */
    public function find_website( $name, $city = '', $state = '' ) {
        $query = trim( "{$name} orthodontist {$city} {$state}" );

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

    // -------------------------------------------------------------------------
    // Step 2 — scrape a URL and return plain text
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
    public function enrich_listing( $contact, $website_url = '' ) {
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
        $prompt = <<<PROMPT
You are helping populate a professional directory listing for an orthodontist.

Doctor: {$name}
Location: {$city}, {$state}
{$website_context}
Based on the information above, write a professional biography paragraph (3–5 sentences) suitable for a member directory listing. Use specific details from the website content if provided. Focus on their professional background, specialties, and what makes their practice distinctive. If no website content was available, write a general professional bio suitable for an orthodontist.

Also suggest:
- A short specialty description (1 line, e.g. "Orthodontics & Dentofacial Orthopedics")

Return your response ONLY as a JSON object with these exact keys:
- "biography": string
- "specialty": string
- "confidence": "high" if website content was provided and used, "low" if generic

No markdown fences. No extra keys. Valid JSON only.
PROMPT;

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
            ];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // Strip accidental markdown fences
        $text = preg_replace( '/^```(?:json)?\s*/i', '', trim( $text ) );
        $text = preg_replace( '/\s*```$/', '', $text );

        $parsed = json_decode( $text, true );
        if ( ! $parsed ) {
            $parsed = [ 'biography' => $text, 'specialty' => '', 'confidence' => 'low' ];
        }

        $parsed['website_url']    = $website;
        $parsed['website_source'] = $website_source;

        return $parsed;
    }
}
