# Changelog

## Unreleased (1.x)

### Changed
- The factory's JsonClient reads Twitch's `Ratelimit-Remaining` and `Ratelimit-Reset` as legacy
  fields, the reset a Unix time (`Backoff::withLegacyHeaders(LegacyReset::Epoch)`; the API guide:
  "A Unix epoch timestamp"): a bucket with no point left holds every call until its reset, before
  Twitch answers 429. The policy's note says how a site declares its bucket in points (`"unit":
  "points"`, `schedule` costing 2). Needs omnisense/contracts at 5bac28b or later (still
  `^1.0@dev`, untagged).

### Added
- Twitch's usage policy declared as data in `policy.json` (omnisense/contracts' schema, read on
  2026-11-10): the points bucket's size unknown (not stated as policy), content cached 24 hours at
  most, no redistribution, a clear path to the source. `TwitchProvider` implements
  `DeclaresUsagePolicyInterface`, the factory gives its JsonClient the provider's Backoff (a 429 and
  its `Retry-After` hold every call). `composer.json` names the policy (`extra.omnisense.policy`)
  and requires `omnisense/contracts`.
- The package: Twitch for omnisense/omnishow's live contract through the Helix API -
  `LiveInterface::live()` (`GET /streams?user_login=`) and `schedule()` (`GET /users?login=`, then
  `GET /schedule?broadcaster_id=`, a 404 an empty schedule); an app access token from the client's
  id and secret, asked again before it expires, or a token given. Answers written by hand after the
  reference; no client id was used.
