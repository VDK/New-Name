# New Name

New Name is a small Symfony application for creating and updating Wikidata items for given names and family names.

The app starts with a single name input, analyzes likely Wikidata properties, checks for existing same-type items, and prepares a create/update edit using Wikimedia OAuth 2.0.

Source code: <https://github.com/VDK/New-Name>

Production: <https://nn.toolforge.org/>

Created by [Vera de Kok](https://www.veradekok.nl/). Licensed under the MIT License.

## Features

- Detects likely name type: family name, given name, and male/female/unisex given name.
- Detects writing system from Unicode script.
- Suggests transliterations for non-Latin scripts using the bundled Wikidata transliteration gadget data.
- Suggests Wikidata matches for the selected name type to avoid duplicates.
- Suggests related name statements:
  - `said to be the same as (P460)`
  - `given name version for other gender (P1560)`
  - `surname for other gender (P5278)`
- Adds fixed core statements for new name items:
  - `label` in `mul`
  - `native label (P1705)`
  - `instance of (P31)`
  - `writing system (P282)`
- Optionally adds `language of work or name (P407)`.
- Uses local TSV caches for language and affix lookup.

## Requirements

- PHP 8.2 or newer
- Composer
- Symfony 7.4 dependencies from `composer.lock`
- Wikimedia OAuth 2.0 client with scopes:
  - `basic`
  - `editpage`
  - `createeditmovepage`

## Setup

Install dependencies:

```bash
composer install
```

Create local environment overrides in `.env.local`:

```dotenv
APP_SECRET=change-me
WIKIMEDIA_OAUTH_CONSUMER_KEY=your-oauth2-client-key
WIKIMEDIA_OAUTH_CONSUMER_SECRET=your-oauth2-client-secret
WIKIMEDIA_OAUTH_CALLBACK_URL=https://new-name.toolforge.org/index.php/oauth/callback
WIKIMEDIA_OAUTH2_ACCESS_TOKEN=
```

For local development with a single-user OAuth 2.0 token, set `WIKIMEDIA_OAUTH2_ACCESS_TOKEN`.

The normal browser login flow requires the callback URL registered on the Wikimedia OAuth client. If the registered callback is the Toolforge URL, local browser callback testing needs either the Toolforge URL or a separately registered localhost callback.

## Run Locally

From the project root:

```bash
php -S 127.0.0.1:8001 -t public
```

Open:

```text
http://127.0.0.1:8001/index.php
```

If running under XAMPP Apache from `htdocs`, the local URL is typically:

```text
http://192.168.178.2/new-name/public/
```

Production is intended to run at:

```text
https://new-name.toolforge.org/
```

On Toolforge, the web document root is normally `public_html`. Deploy the Symfony public entrypoint there, for example by copying or symlinking the contents of `public/` into `public_html/` according to the tool account setup.

## Data Caches

The app keeps local TSV and gadget caches in `data/`:

- `data/languages.tsv`
- `data/affixes.tsv`
- `data/script-languages.tsv`
- `data/transliteration-gadget.js`
- `data/autoedit-descriptions.js`

Refresh them with:

```bash
php bin/console app:refresh-data
```

This command queries Wikidata Query Service, imports the bundled Wikidata gadget sources, and writes files atomically. It is intended for a Toolforge cron job.

To refresh only the gadget sources:

```bash
php bin/console app:refresh-data --only-gadgets
```

On Toolforge, replace the scheduled refresh job with:

```bash
toolforge jobs delete refresh-data
toolforge jobs run refresh-data \
  --command "php bin/console app:refresh-data" \
  --image php8.2 \
  --schedule "0 3 * * 0"
```

## OAuth 2.0

The app authenticates Wikidata API writes with OAuth 2.0 Bearer tokens.

Important environment variables:

- `WIKIMEDIA_OAUTH_CONSUMER_KEY`
- `WIKIMEDIA_OAUTH_CONSUMER_SECRET`
- `WIKIMEDIA_OAUTH_CALLBACK_URL`
- `WIKIMEDIA_OAUTH2_ACCESS_TOKEN`

The edit summary links back to the app URL so Wikidata edits remain attributable to the tool.

## Wikidata Gadget

`gadgets/NewNameCreateLink.js` is an optional Wikidata gadget snippet. It changes the Wikibase entity selector's "not found" link into a link to New Name with the current input prefilled:

```text
https://new-name.toolforge.org/?name=...
```

## Project Structure

- `src/Controller/HomeController.php` renders the main analyze/review/create flow.
- `src/Controller/LanguageSearchController.php` provides local language typeahead search.
- `src/Controller/OAuthController.php` handles OAuth login/status/logout.
- `src/Controller/WikidataSaveController.php` handles save completion.
- `src/Service/NameAnalyzer.php` performs type, match, and relationship suggestions.
- `src/Data/LanguageHeuristics.php` keeps prefix, suffix, and pattern hints for likely name languages.
- `src/Service/WikidataEditService.php` builds and sends Wikidata edits.
- `src/Service/WikimediaOAuthClient.php` performs OAuth 2.0 API calls.
- `src/Command/RefreshDataCommand.php` refreshes TSV caches.

## Notes

`vendor/`, `var/`, `.env.local`, and other local secret files are ignored. Do not commit OAuth credentials or access tokens.

## License

MIT. See [LICENSE](LICENSE).
