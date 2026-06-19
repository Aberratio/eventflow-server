# Testing Strategy

## Gates

- Unit PR gate: `composer lint && composer test -- --testsuite Unit`
- Integration gate: run migrations and seed against a test database, then `composer test -- --testsuite Integration`

## Backend Layers

- Use PHPUnit Unit for isolated logic such as JWT, UUIDs, validation helpers, and calculations.
- Use PHPUnit Integration for repositories and HTTP route contracts.
- HTTP contract tests should verify status codes, stable JSON shape, RBAC behavior, and important domain visibility rules.
- Integration tests may skip locally when the database is unavailable, but CI must provide a migrated and seeded MySQL database.

## Contract Rule

Any API payload or response shape change should update the route/repository implementation and a backend HTTP contract or repository integration test.
