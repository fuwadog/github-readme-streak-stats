# Changelog

This file records the changes made during the current cleanup and hardening
effort. The work is intentionally unreleased until the branch is reviewed,
committed, pushed, and deployed by the repository owner.

## Unreleased

### Vercel and Hobby deployment

- Reduced the Vercel PHP deployment to one serverless function so it fits the
  Hobby plan limit of 12 functions per deployment.
- Preserved the public root, demo, asset, canonical, and legacy URL paths and
  their existing query parameters.
- Added an explicit internal route dispatcher instead of exposing every PHP
  file under `api/` as a Vercel function.
- Preserved the canonical public host:
  `https://github-readme-streak-stats-black-phi.vercel.app`.
- Kept the `streak-stats.demolab.com` hostname documented as a legacy alias.
- Kept production public for GitHub image embeds while documenting protected
  previews and deployment URLs.
- Kept GitHub fork relationships, repository remotes, and deployment metadata
  unchanged.

### API security and resilience

- Made the GitHub username allowlist fail closed when it is missing, empty,
  malformed, or uses an unsupported wildcard configuration.
- Preserved the user-managed allowlist model; the explicitly listed test user
  is `fuwadog`.
- Ensured denied usernames are rejected before GitHub or cache access.
- Added bounded username, year, mode, excluded-day, request-size, and method
  validation.
- Preserved self-hosted rate limiting while requiring an explicit external
  limiter for serverless deployments.
- Added trusted-proxy CIDR handling so forwarded client IP headers are not
  trusted from arbitrary clients.
- Added bounded GitHub retry attempts and an aggregate request deadline with
  cleanup margin for the one-function Vercel runtime.
- Reworked GraphQL requests to use variables rather than interpolated user
  input.
- Added transport status, JSON decoding, retry, timeout, cleanup, and token
  failover validation.
- Removed raw GitHub response bodies, exception traces, renderer diagnostics,
  and tokens from public errors and logs.
- Added generic bounded error responses while preserving the explicit
  whitelist-denial response.
- Standardized cache behavior: successful cards are publicly cacheable, while
  disabled-cache and error responses use `no-store`.
- Normalized and sorted contribution dates before calculating daily and weekly
  streaks.

### SVG, PNG, and JSON output

- Kept SVG static by default; `animation=true` is the only animation opt-in.
- Added animated flame layers, ember effects, shimmer, and actual-value streak
  count-up behavior for compatible SVG variants.
- Added `prefers-reduced-motion` handling that disables animation without
  hiding rendered content.
- Kept PNG and JSON responses static.
- Escaped dynamic SVG text, including error messages.
- Standardized response status, body, and `Content-Type` handling for SVG, PNG,
  and JSON results.
- Preserved the safe SVG fallback for PNG requests on Vercel, where Inkscape is
  unavailable.
- Added validation for PNG signatures, dimensions, response sizes, and
  renderer failures.

### Isolated PNG renderer

- Moved self-hosted Inkscape PNG conversion behind a private Unix-socket
  renderer sidecar.
- Added a bounded HTTP-over-Unix-socket renderer protocol with strict request
  keys, SVG/body limits, dimension and pixel-area limits, deadlines, PNG
  validation, JSON errors, and a health endpoint.
- Kept the renderer non-root, read-only, network-isolated, resource-limited,
  and without a public port.
- Removed the executable test temporary filesystem from the production
  renderer service.
- Added a Compose test profile with the executable temporary filesystem only
  for renderer unit tests; production `/tmp` remains `noexec`.
- Added renderer health, protocol, bounds, timeout, capacity, and invalid PNG
  coverage.

### Tests and verification

- Replaced live GitHub-coupled statistics tests with deterministic fixtures and
  injected test doubles.
- Added API security, demo security, PNG renderer, animation, route, fallback,
  escaping, cache, and direct-PHP exposure coverage.
- Linux container verification passed with 107 PHPUnit tests and 6,768
  assertions.
- Renderer unit tests passed: 11 tests.
- PHPStan, Composer validation/audit/checks, npm audit, Prettier, actionlint,
  Docker Compose validation, image builds, health checks, and local smoke tests
  passed in the available verification environment.
- Verified the allowlisted `fuwadog` canonical endpoint returned HTTP 200 with
  an SVG response and public cache headers.
- Verified denied-user behavior returns HTTP 403 with the whitelist message and
  non-cacheable headers.

### Tooling, containers, and CI

- Standardized the Node.js toolchain on version 24 for development and CI
  tooling.
- Added reproducible npm lockfile installation and formatter validation.
- Expanded Composer scripts for tests, linting, static analysis, coverage, and
  aggregate checks.
- Hardened PHPUnit configuration and deterministic test discovery.
- Modernized CI and release checks with locked dependencies, audit gates,
  protected publication steps, provenance checks, and non-mutating formatting
  validation.
- Expanded dependency automation to Composer, npm, Docker, and GitHub Actions.
- Hardened production and development container layouts, extensions, runtime
  users, health checks, and environment handling.
- Added deployment smoke checks for routes, whitelist behavior, cache headers,
  missing-token handling, direct-PHP exposure, renderer health, and fallback
  behavior.
- Fixed workflow negative-match assertions so grep errors cannot become false
  passes.

### Documentation and repository hygiene

- Updated README, FAQ, and contributor guidance for the canonical deployment,
  Hobby limits, Node 24 tooling, PHP/Vercel limitations, renderer setup,
  allowlist semantics, cache policy, WAF requirements, token handling, and
  preview protection.
- Added deployment and runtime details to the issue template.
- Added ignore rules for generated coverage output, Ruff cache data, Python
  bytecode, and Python cache directories.
- Kept generated/local artifacts such as `coverage.xml` out of version control
  without deleting the local files.

### Remaining release blockers and limitations

- Historical and local raw Trivy reports are unavailable. This changelog is
  historical context only, not scan evidence, and it does not assert output
  from a scan that cannot be retrieved.
- Updated the verification-only Node source from Node 24.7.0 Bookworm
  (`sha256:0104d9447ea3ddf7373643be7f9915fc7b7c896e41d0d33229338e457217cd78`)
  to Node 24.20.0 Trixie
  (`sha256:50c3b2f6988dfc307b86e5301d69611af31f4789bdf232863b07d3b02fe55ae0`),
  and removed its build-only bundled npm tree after verification. No direct
  npm dependency required a bump, so `package.json` and `package-lock.json`
  were intentionally unchanged.
- Updated the Composer binary source to the current verified `2-bin` digest
  (`sha256:536116acd18cd2d99d0351c1dfded22c83f34cef481d83f2747ff2cbca7587cd`).
  The PHP 8.4 Apache Trixie digest was already current and had no affected
  PHP package finding, so it was intentionally unchanged.
- The renderer now pins its explicitly installed Debian packages to one
  immutable `snapshot.debian.org` archive timestamp and fails the image build
  if those package pins do not resolve to the requested versions.
- Future successful CI runs are the authoritative source for Trivy output.
  CI uploads the blocking scan reports plus an unignored, machine-readable
  renderer report and a provenance sidecar containing the repository, commit,
  workflow/run, image digest, Trivy version, scan mode, and `.trivyignore`
  hash. Reports are generated CI artifacts, not committed files.
- `CVE-2026-36849` is the only documented exception: the policy metadata
  identifies renderer `libtiff6` `4.7.0-3+deb13u3` as `will_not_fix`, owned by
  `fuwadog`, expiring `2026-10-06`, with non-root private Unix-socket
  isolation as mitigation. The policy file is not scan evidence.
- No other CVE is present in `.trivyignore`; no additional exception or fix is
  claimed without authoritative upstream or future CI evidence.
- Vercel WAF configuration, production secret provenance, production
  `WHITELIST` provenance, preview protection, and deployed one-function state
  still require manual confirmation after deployment.
- The current Vercel production deployment predates this local work. Its stale
  route and PNG responses are not evidence against the current branch.
- No commit, push, or deployment was performed as part of this effort.
