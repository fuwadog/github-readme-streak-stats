# Operations runbook

This runbook describes the current deployment without changing public routes or
Vercel environment-variable names or values. It applies to the PHP application,
the optional self-hosted PNG renderer, and the canonical Vercel deployment.

## Contract index

The following names refer to the implemented contracts. They are documentation
identifiers, not new environment variables or feature flags:

- **API_RESPONSE_V1** — the API's status, body, and media type are treated as a
  single response contract. Successful SVG, PNG, and JSON responses are
  validated; errors are bounded and are not cacheable.
- **RATE_LIMIT_V1** — self-hosting uses the file-based limit of 100 requests
  per minute per client IP. A serverless process has no shared file limiter and
  fails closed unless `EXTERNAL_RATE_LIMITER=true` asserts that an upstream
  limiter is active.
- **CACHE_KEY_V1** — successful cards use the implementation's normalized
  request cache key. `CACHE_TTL` takes precedence over
  `CACHE_TTL_DEFAULT`; `DISABLE_CACHE=true` and errors use `no-store`.
- **RENDERER_PROTOCOL_V1** — private Unix-socket HTTP/1.1 `GET /health` and
  `POST /render` with bounded JSON input; successful render responses are
  `image/png`, and failures are bounded JSON errors.
- **PREVIEW_REQUEST_V1** — preview inputs remain bounded and are exercised with
  fixture data; preview/demo checks must not require a GitHub token or contact
  GitHub.

Do not infer production behavior from a repository test alone. Retain the
release evidence described below.

## Renderer startup and health

1. Start the renderer as the non-root `renderer` user with its read-only image,
   no network egress, no public TCP listener, and the private socket volume
   shared only with the PHP process. The default socket is
   `/run/streak-renderer/renderer.sock`.
2. Confirm the socket exists and is owned/mode-limited for the application and
   renderer. Do not publish it or mount the host filesystem broadly.
3. Send `GET /health` over the Unix socket. The expected status is `200` and
   the JSON body is `{"status":"ok"}`. A missing socket, timeout, or other
   response is unhealthy; do not route PNG traffic to an Internet endpoint.
4. Confirm Inkscape is installed inside the renderer image and that its
   temporary directory is writable only for the renderer. Production `/tmp`
   remains non-executable. The renderer bounds body/SVG size, dimensions,
   pixel area, PNG size, deadline, and concurrency.

Example from a host with a Unix-socket HTTP client:

```bash
curl --unix-socket /run/streak-renderer/renderer.sock http://renderer/health
```

The PHP client uses `PNG_RENDERER_SOCKET`, then its compatibility socket aliases
if configured. Keep the existing deployment configuration; do not add a public
renderer listener.

## Renderer limits and cleanup

`POST /render` accepts only the SVG, width, height, and bounded deadline fields.
Reject malformed methods, headers, duplicate JSON keys, oversized bodies or
SVGs, invalid dimensions, excessive pixel areas, and deadlines outside the
configured bound. Validate the returned PNG signature, media type, dimensions,
and size before serving it.

On timeout, capacity exhaustion, invalid output, or renderer failure, inspect
the health result and renderer logs without exposing SVG contents, tokens, or
filesystem paths. Restart the sidecar only after stopping the old process and
removing its stale socket. Verify the socket is absent before startup and
present after readiness. Remove only the sidecar's temporary files; never use
an emergency writable host mount or disable the resource limits.

## Cache, tokens, and rate limits

- Cache only successful cards. A positive `CACHE_TTL` overrides
  `CACHE_TTL_DEFAULT`; the checked-in local template uses five hours and the
  runtime fallback is one day when neither is set. `DISABLE_CACHE=true` and
  every error response send `no-store`.
- Self-hosted traffic is limited to 100 requests per minute per client IP using
  the file limiter. Trusted forwarded-IP headers require configured trusted
  proxy CIDRs; arbitrary client headers must not redefine the IP.
- On Vercel/serverless, the filesystem limiter is unavailable. Keep the
  existing `EXTERNAL_RATE_LIMITER` setting and verify the upstream WAF/API
  gateway before relying on the assertion. For Vercel Hobby, retain evidence of
  the single project rule's selected client-IP or JA4 key, 10-second-to-10-minute
  window, and regional scope.
- `TOKEN` and `TOKEN2` through `TOKEN100` are server-side failover credentials.
  A rate-limited token is removed from the current process pool and another is
  tried; if none remain the API returns `429`. Rotate by adding the replacement,
  redeploying and verifying, then revoking/removing the old value. Never log or
  record token values.

## Vercel versus self-hosted output

Vercel runs the existing one-function PHP application. Node 24.x is tooling for
formatting, verification, and optional renderer work; changing Node does not
change PHP runtime behavior. The canonical Vercel output is SVG. A Vercel PNG
request returns the controlled SVG error-card fallback with HTTP `500` and
`image/svg+xml`; this is not a successful PNG response. Use `type=svg` for
public embeds.

Self-hosted deployments can provide PNG by running the isolated Inkscape
renderer behind the private socket. Only the PHP application is public. SVG and
JSON remain available, and PNG is static; SVG animation is enabled only by
`animation=true`, with `disable_animations=true` and reduced-motion behavior
overriding it.

## Smoke checks and release evidence

Before release, run repository checks and fixed, sanitized requests against the
candidate. Verify `/`, `/demo/`, and demo assets; allowlisted and denied users;
status and content type; SVG structure; cache headers; the controlled Vercel
PNG fallback; renderer health and PNG output where self-hosted; and that direct
PHP files and the demo do not expose secrets or reach GitHub. Never print full
request URLs or response bodies containing user data.

Retain the source commit, project and deployment IDs, production branch,
previous-known-good deployment ID, test timestamps, sanitized results, renderer
health result, and WAF dashboard evidence. Do not retain token values,
credentials, or unredacted request URLs. Repository tests are not proof of
Vercel deployment state.

## Rollback

If any smoke check, WAF assertion, route check, or renderer health check fails,
stop the release and promote the recorded previous-known-good Vercel
deployment, or restore the previous self-hosted image/configuration. Then rerun
all smoke checks and record the result. Do not change environment-variable
names or values as a rollback workaround. Investigate the candidate only after
the known-good service is serving traffic.
