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

    /**
     * Enrich a listing draft using practice website content.
     * Returns array of suggested field values.
     */
    public function enrich_listing( $contact, $website_url = '' ) {
        $name    = $contact['display_name'] ?? ( ( $contact['first_name'] ?? '' ) . ' ' . ( $contact['last_name'] ?? '' ) );
        $city    = $contact['city'] ?? '';
        $state   = $contact['state_province'] ?? '';
        $website = $website_url ?: $contact['website'] ?? '';

        $website_context = '';
        if ( ! empty( $website ) ) {
            $website_context = "Their practice website is: {$website}\n";
            $website_context .= "Please research this website to find information about the doctor and practice.\n";
        }

        $prompt = <<<PROMPT
You are helping populate a professional directory listing for an orthodontist.

Doctor: {$name}
Location: {$city}, {$state}
{$website_context}

Based on the information available, write a professional biography paragraph (3–5 sentences) suitable for a member directory listing. 
Focus on their professional background, specialties, and what makes their practice distinctive.
If you cannot find specific information, write a general professional bio that could be verified and personalised later.

Also suggest:
- A short specialty description (1 line, e.g. "Orthodontics & Dentofacial Orthopedics")

Return your response as a JSON object with these keys:
- "biography": string (the bio paragraph)
- "specialty": string (the specialty line)
- "confidence": "high" if you found website content, "low" if generic

Only return valid JSON, no markdown fences.
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
            return [ 'error' => $response->get_error_message() ];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // Strip markdown fences if present
        $text = preg_replace( '/^```(?:json)?\s*/i', '', trim( $text ) );
        $text = preg_replace( '/\s*```$/', '', $text );

        $parsed = json_decode( $text, true );
        if ( ! $parsed ) {
            return [ 'biography' => $text, 'specialty' => '', 'confidence' => 'low' ];
        }

        return $parsed;
    }
}
