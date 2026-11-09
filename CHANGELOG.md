# Changelog

## Unreleased (1.x)

### Added
- The package: Twitch for omnisense/omnishow's live contract through the Helix API -
  `LiveInterface::live()` (`GET /streams?user_login=`) and `schedule()` (`GET /users?login=`, then
  `GET /schedule?broadcaster_id=`, a 404 an empty schedule); an app access token from the client's
  id and secret, asked again before it expires, or a token given. Answers written by hand after the
  reference; no client id was used.
