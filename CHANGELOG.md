# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- MIT License
- `.gitignore`
- Community health files: CONTRIBUTING.md, SECURITY.md, CODE_OF_CONDUCT.md
- Issue and PR templates
- GitHub repository topics

### Fixed
- Fatal error on load: `includes/audit-log.php` had code before its `namespace` declaration.
- Per-post REST routes (`/v1/scan/post-meta/{id}`, `/v2/scan/social/{id}`) now require `edit_post` on that post, not just `edit_posts`.
- `/v2/audit-log` no longer throws `DivisionByZeroError` for `per_page=0`; `per_page` is clamped to 1–100.
- Missing-alt check no longer treats `data-alt` as an `alt` attribute.
- Empty-link check counts `aria-label`, `aria-labelledby` and image `alt` text as the link's name.

## [1.0.0] - 2024-01-01

### Added
- Initial release
- Automated accessibility scanning for WordPress content
- WCAG 2.1 AA checks
- Admin dashboard with scan results
- REST API endpoint for programmatic access
