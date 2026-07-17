# Contributing to WP Simple A11y Scanner

Contributions are welcome! Please read this guide before submitting.

## Prerequisites

- PHP 7.4+
- Composer
- WordPress development environment (optional, for manual testing)

## Setup

```bash
git clone https://github.com/nadimtuhin/wp-simple-a11y-scanner.git
cd wp-simple-a11y-scanner
composer install
```

## Running Tests

```bash
./vendor/bin/phpunit
```

## Pull Request Process

1. Fork the repo and create a branch from `main`.
2. Write tests for any new functionality.
3. Ensure all tests pass: `./vendor/bin/phpunit`
4. Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/).
5. Update `CHANGELOG.md` under the `[Unreleased]` section.
6. Open a PR with a clear description of the change and why.

## Reporting Bugs

Use [GitHub Issues](https://github.com/nadimtuhin/wp-simple-a11y-scanner/issues) with the **bug** label.

## Accessibility Standards

This plugin targets WCAG 2.1 AA. When adding new checks, reference the relevant WCAG success criterion.

## Code of Conduct

By participating, you agree to abide by our [Code of Conduct](CODE_OF_CONDUCT.md).
