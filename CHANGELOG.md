# Changelog

All notable SDK changes are documented here. Each ecosystem can be released
independently, so entries identify the affected packages.

## 0.2.0 - Unreleased

### Python

- Moved the existing SDK into the multi-package repository layout.
- Preserved the existing public client and model API.
- Added client behavior tests and publishing metadata.

### Node.js

- Added the initial typed Node.js SDK with partner and tenant token caching.
- Added automatic authentication refresh, DTOs, typed errors, tests, and npm
  publishing metadata.

### Laravel

- Added the initial Laravel SDK with configuration, service-provider
  auto-discovery, facade support, immutable data objects, and typed errors.
- Added an automated subtree-split release for Packagist.

### Shared

- Added an OpenAPI 3.1 contract, shared response fixtures, CI, and independent
  release workflows.
