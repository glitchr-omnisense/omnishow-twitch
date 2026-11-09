# omnishow/twitch

[omnisense/omnishow](https://github.com/glitchr-omnisense/omnishow)'s Twitch, under any PHP host, through
the [Helix API](https://dev.twitch.tv/docs/api/reference) with an app access token. It implements
the **live** contract:

- **whether a channel is live and its stream** (`GET /streams?user_login=`): title, category,
  viewers, start, language, thumbnail, its page and its player;
- **its schedule** (`GET /users?login=` for its id, then `GET /schedule?broadcaster_id=`), soonest
  first, a cancelled segment said (`LiveState::Cancelled`), a weekly one marked recurring.

```php
use Omnishow\Twitch\TwitchFactory;

$twitch = (new TwitchFactory($httpClient))->create(['client_id' => getenv('TWITCH_CLIENT_ID'), 'client_secret' => getenv('TWITCH_CLIENT_SECRET')]);
$now = $twitch->live('twitchdev');                 // null when off air
echo $now?->embed(parent: 'example.org');         // https://player.twitch.tv/?channel=twitchdev&parent=example.org
$next = $twitch->schedule('twitchdev', 5);
```

A channel is named by its login (`twitch.tv/<login>`; an address or a leading `@` are read too).
Twitch's player needs the embedding page's domain (`parent=`), and has no privacy-friendly variant.

Options: `client_id` (required), and `client_secret` (an app access token is asked with it,
`POST https://id.twitch.tv/oauth2/token`, and asked again a minute before it expires) or `token` (an
access token got elsewhere, sent as it is); `api` (`https://api.twitch.tv/helix/`), `token_url`. See
[docs/index.md](docs/index.md).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Twitch Interactive, Inc. It reaches their service on behalf of whoever runs it, and it
does not redistribute their data beyond the short excerpts written by hand as test fixtures. Its MIT
licence covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with Twitch's terms ([Developer
Services Agreement](https://legal.twitch.com/legal/developer-agreement/), [Terms of
Service](https://www.twitch.tv/p/legal/terms-of-service/)), for obtaining whatever they require (an
application, its client id and secret, a rate limit to respect), and for any use made of what it
returns. The authors publish it free of charge, make no commercial use of it, and accept no
liability for how it is used (see [LICENSE](LICENSE)).

## Licence

MIT.
