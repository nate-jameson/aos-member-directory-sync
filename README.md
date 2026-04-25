# AOS Member Directory Sync

WordPress plugin that syncs CiviCRM memberships with the Directorist member directory on find.orthodontics.com.

## Features

- **Sync Expired Members** — Queries CiviCRM for memberships expired in the past N months, matches to directory listings by email/name, and deactivates them
- **New Members Without Listings** — Surfaces active CiviCRM members with no directory listing; one-click AI-enriched draft creation using Gemini + practice website scraping
- **Settings** — Configurable CiviCRM URL/API keys, Gemini API key, membership type IDs per credentialing level (Achievement, Fellowship, Diplomate), lookback period, and target directory

## Settings

Configure under **AOS Directory Sync → Settings**:

| Setting | Description |
|---|---|
| CiviCRM Site URL | Base URL of your CiviCRM site |
| CiviCRM Site Key | `CIVICRM_SITE_KEY` |
| CiviCRM API Key | User API key |
| Gemini API Key | Google Gemini API key for AI enrichment |
| Achievement Type ID | CiviCRM membership type ID for Achievement level |
| Fellowship Type ID | CiviCRM membership type ID for Fellowship level |
| Diplomate Type ID | CiviCRM membership type ID for Diplomate level |
| Active Member Type IDs | Comma-separated list of all active member type IDs |
| Expiry Lookback (months) | How far back to look for expired memberships (default: 6) |
| Default Directory ID | Directorist directory ID to assign new listings to |

## Matching Logic

Expired member → listing matching uses:
1. **Email** (primary) — exact match on listing contact email field
2. **Name** (fallback) — first + last name fuzzy match with confidence scoring

Match confidence is shown in the UI before any deactivation is performed.

## Requirements

- WordPress 6.0+
- Directorist plugin
- CiviCRM (remote, via REST API)
- Google Gemini API key
