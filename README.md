```
 _      ______   _____ 
| | /| / / __ \ / ___/ 
| |/ |/ / /_/ / \__ \  
|__/|__/\____/ /____/  
  Simple A11y Scanner
```

A clean, testable WordPress plugin that scans post content for accessibility issues.

## Features

| Check | Description |
|---|---|
| Missing `alt` | Flags `<img>` tags with no `alt` attribute |
| Empty link | Flags `<a>` tags with no visible text content |
| Vague link | Flags links whose text is "click here", "read more", "here", "more", "this", "link", or "learn more" |

## Installation

1. Clone or download this repository into your WordPress `wp-content/plugins/` directory.
2. Run `composer install` inside the plugin folder.
3. Activate **Simple A11y Scanner** from the WordPress admin → Plugins page.

## Admin UI

After activation, two UI entry points are registered:

- **Admin menu page**: *A11y Scanner* in the WordPress sidebar — shows check descriptions and API usage.
- **Dashboard widget**: Quick summary widget on the WordPress Dashboard.

## REST API

Base URL: `{your-site}/wp-json/simple-a11y/v1`

### `POST /scan`

Scan HTML content and return a list of accessibility issues.

**Request body** (JSON):

```json
{ "content": "<img src=\"photo.jpg\"><a href=\"#\">click here</a>" }
```

**Response** (200):

```json
{
  "issues": [
    {
      "type":    "missing_alt",
      "message": "Image missing alt attribute.",
      "element": "<img src=\"photo.jpg\">"
    },
    {
      "type":    "vague_link",
      "message": "Link text \"click here\" is vague and not descriptive.",
      "element": "<a href=\"#\">click here</a>"
    }
  ],
  "count": 2
}
```

**Error** (400) when `content` is empty:

```json
{ "error": "Empty content" }
```

### `POST /scan/summary`

Same as `/scan` but returns counts grouped by issue type instead of the full issue list.

**Response** (200):

```json
{
  "summary": {
    "total":       2,
    "missing_alt": 1,
    "empty_link":  0,
    "vague_link":  1
  }
}
```

## Scan Rules

### Images missing `alt` (`missing_alt`)

Every `<img>` tag must include an `alt` attribute. Decorative images should use `alt=""`. Images without any `alt` attribute are flagged.

### Empty link text (`empty_link`)

`<a>` elements whose visible text content (after stripping inner HTML tags) is blank or whitespace-only. Screen readers cannot describe these links.

### Vague link text (`vague_link`)

`<a>` elements whose text (case-insensitive) exactly matches one of the following non-descriptive phrases:

`click here` · `read more` · `here` · `more` · `this` · `link` · `learn more`

Replace these with text that describes the link destination, e.g. *"Download the accessibility guide"*.

## Running Tests

```bash
composer install
./vendor/bin/phpunit
```

Expected output:

```
OK (20 tests, 33 assertions)
```

## Project Structure

```
wp-simple-a11y-scanner/
├── includes/
│   ├── scanner.php          # Scanner class — all accessibility checks
│   └── api.php              # Api class — REST route registration
├── tests/
│   ├── ScannerTest.php      # Unit tests for Scanner
│   └── ApiTest.php          # Unit tests for Api (with WP stubs)
├── wp-simple-a11y-scanner.php  # Plugin entry point, admin menu, dashboard widget
├── composer.json
└── phpunit.xml
```

## Changelog

### 1.1.0
- Added empty link text check (`empty_link`)
- Added vague link text check (`vague_link`) for common non-descriptive phrases
- Improved `missing_alt` to use regex matching per `<img>` tag (supports multiple images)
- Added `/scan/summary` REST endpoint returning grouped counts
- Registered admin menu page and dashboard widget
- Extended PHPUnit test suite to 20 tests

### 1.0.0
- Initial release: `missing_alt` check, `/scan` REST endpoint
