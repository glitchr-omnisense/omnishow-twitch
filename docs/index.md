# omnishow/twitch

Twitch for [omnisense/omnishow](https://github.com/glitchr-omnisense/omnishow)'s live contract,
through the Helix API. Every endpoint below was read in the reference on 2026-11-09
([API reference](https://dev.twitch.tv/docs/api/reference), [getting tokens](https://dev.twitch.tv/docs/authentication/getting-tokens-oauth/),
[embedding](https://dev.twitch.tv/docs/embed/video-and-clips/)); no client id was used, the tests run
on answers written by hand after it.

## The calls

| Contract | Call |
|---|---|
| the app token, without one given | `POST https://id.twitch.tv/oauth2/token`, form body `client_id`, `client_secret`, `grant_type=client_credentials`; kept until a minute before `expires_in` runs out (an app token cannot be refreshed: a new one is asked) |
| `live()` | `GET https://api.twitch.tv/helix/streams?user_login=<login>` |
| `schedule()` | `GET .../users?login=<login>` (its id), then `GET .../schedule?broadcaster_id=<id>&first=<limit>` (25 at most) |

Every Helix call sends `Client-Id: <client_id>` and `Authorization: Bearer <token>`.

## How a stream is read

| Field | Read from |
|---|---|
| `state` | live when `data[0].type` is `live`; an offline channel is absent from `data`: null |
| `id`, `title`, `viewers`, `started`, `language` | `id`, `title`, `viewer_count`, `started_at`, `language` |
| `category` | `game_name` (empty: null) |
| `channel` | `user_id`, `user_name`, `user_login` |
| `thumbnail` | `thumbnail_url`, its `{width}x{height}` as `1280x720` |
| `url`, `embedUrl` | `https://www.twitch.tv/<login>`, `https://player.twitch.tv/?channel=<login>` - `embed(parent: <domain>)` adds the `parent=` Twitch requires |

A schedule's segment: `id`, `title`, `start_time` and `end_time` as scheduled, `category.name`,
`is_recurring`; `canceled_until` set: `LiveState::Cancelled`. Sorted by start, `limit` at most. A
broadcaster without a schedule answers 404 ("The broadcaster has not created a streaming
schedule."): an empty list. `vacation` is not read.

## Usage policy

The package declares Twitch's usage policy as data, in [policy.json](../policy.json)
(omnisense/contracts' schema, read at its sources on 2026-11-10): rate limits Helix's points bucket,
refilled every minute, whose size is not stated as policy: unknown (a site that knows its bucket
declares it in points, `{"max": 800, "window": "minute", "unit": "points", "cost": {"schedule": 2}}`:
schedule makes two requests); `Ratelimit-Remaining`
and `Ratelimit-Reset` (a Unix time) read on every answer, an empty bucket holding every call until
its reset; Twitch content cached 24 hours
at most, no re-syndication nor redistribution, no selling or monetizing its data without an
agreement, a clear path to the source from every display. Through omnishow's registry every call is
held to it: a site's own limit is counted; a 429 holds every call until its wait is over, a 429 and
its `Retry-After` hold every next call until the wait is over (`UsageLimitReachedException`), and a
site may tighten the policy in its configuration (`'policy' => [...]`), never loosen it. What the
terms do not say is declared unknown; the sources and the details are in the file's `provenance`.

## Errors

An unknown login: null, or an empty schedule. Any other error status is a `ProviderException`
naming the path (a refused token: 401, `Invalid OAuth token`); the token never appears in it.

## Verified, and not

- Verified (the omnishow harness, PHP 8.4, 2026-11-09): every call above recorded as sent (query,
  headers, the token's form body), the token kept then asked again at expiry, each answer's
  reading, the errors, `BareTest`, PHPStan at max.
- Not verified live: no client id was used.
