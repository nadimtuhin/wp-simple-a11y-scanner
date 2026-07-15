# Roadmap

Future features planned for WP Simple A11y Scanner.

---

## Milestone 1 — Settings & Configuration

### Plugin Settings Page
Admin UI to configure scanner behaviour without touching code.

- Choose which post types to scan (pages, posts, CPTs)
- Set severity thresholds (errors only vs. warnings too)
- Toggle individual checks on/off
- Save settings to `wp_options`

---

## Milestone 2 — CLI & Automation

### WP-CLI Command Support
Run accessibility scans from the command line or CI pipelines.

```bash
wp a11y scan              # scan all published content
wp a11y scan --post=42    # scan a single post
wp a11y report            # print summary table
```

- Machine-readable `--format=json` output
- Exit code `1` on errors (CI-friendly)
- Integrate with GitHub Actions / Bitbucket Pipelines

---

## Milestone 3 — Alerting & Reporting

### Email Alerts
Notify site admins when new accessibility issues are detected.

- Daily or weekly digest emails
- Per-post alert when a post is published with A11y errors
- Configurable recipient list (default: admin email)
- HTML email template with issue summary and links

### CSV / PDF Reports
Export scan results for audits and compliance documentation.

- Download full site report from the admin UI
- Filter by severity, post type, date range

---

## Milestone 4 — Developer Experience

### REST API Endpoint
`GET /wp-json/a11y-scanner/v1/issues` — query issues programmatically.

### Webhook Support
POST scan results to an external URL (Slack, Teams, custom endpoint).

### GitHub Actions Integration
Official workflow file to run scans in CI on every PR.

---

## Future / Backlog

- Gutenberg block editor sidebar panel showing live A11y hints
- Multisite network scan support
- Integration with axe-core for client-side checks
- WCAG 2.2 / 3.0 rule updates
