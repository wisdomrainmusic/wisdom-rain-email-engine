# Wisdom Rain Email Engine

The Wisdom Rain Email Engine is a foundational WordPress plugin that lays the groundwork for email templating and delivery logic across Wisdom Rain projects.

## Features

- Defines the core plugin bootstrap with version `1.0.0`.
- Provides scaffolding directories for core logic, admin functionality, email templates, and assets.
- Registers a WP-CLI command `codex test` that queues an admin notice reading "Hello Email Engine" for verification purposes.

## Development

1. Install dependencies using the WordPress environment of your choice.
2. Activate the plugin via the WordPress admin or WP-CLI (`wp plugin activate wisdom-rain-email-engine`).
3. Run `wp codex test` to trigger the Codex test command and confirm the admin notice appears in the dashboard.
