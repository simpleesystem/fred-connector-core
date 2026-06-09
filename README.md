# simplee/fred-connector-core

Shared, framework-agnostic PHP core for the **Talk to Fred** WordPress
connectors. Consolidates the code that was previously hand-copied between
the WooCommerce vendor connector (`simple-fred-cloud-php-wordpress-vendor-sdk`)
and the Simple Login Connector (`simple-login-connector`).

> Proprietary. Not for public distribution.

## What lives here

Portable, connector-agnostic logic with **zero WordPress/WooCommerce-specific
branding or option keys baked in** — connector-specific values (option keys,
update transient prefix, default audiences) are injected by the consumer.

- `Http/` — HTTP transport abstraction (`HttpClientInterface`) with WordPress
  (`WpHttpClient`), Guzzle (`GuzzleHttpClient`), and in-memory test
  (`InMemoryHttpClient`) implementations.
- `Update/PluginUpdateChecker` — SLS-backed WordPress self-update channel
  (keyless/free + license-gated modes). The update transient prefix is
  injected so each connector keeps its own cache namespace.
- `Oidc/` — OIDC server primitives: signing-key store, client registry,
  authorization-code store, token factory, discovery, PKCE. Connector-specific
  option keys (signing key, clients, login-portal) are injected.
- `Auth/` — handoff principal + role/principal resolution.
- `Resources/` — `Customer`, `QosBundle` value objects.
- `Exceptions/` — the Fred Cloud exception hierarchy.
- `Constants` — protocol/framework constants shared by the above (HTTP codes,
  headers, WP filter/action names, update protocol keys, OIDC defaults).

## Namespace

`Simplee\FredConnector\`

## Consuming connectors

Connectors add this package via a Composer VCS repository and inject their own
option keys / transient prefix when constructing the shared services.

## Quality gates

```bash
composer install
./vendor/bin/phpstan analyse --memory-limit=1G --no-progress
./vendor/bin/phpunit
```
