<?php

namespace Omnishow\Twitch;

use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnishow\Capabilities;
use Omnishow\Exception\ProviderException;
use Omnishow\Http\Data;
use Omnishow\Http\JsonClient;
use Omnishow\LiveInterface;
use Omnishow\Model\Channel;
use Omnishow\Model\Image;
use Omnishow\Model\ImageKind;
use Omnishow\Model\LiveState;
use Omnishow\Model\Platform;
use Omnishow\Model\Stream;
use Omnishow\ProviderInterface;

/**
 * Twitch through the Helix API with an app access token (client
 * credentials: no user signed in): whether a channel is live and its
 * stream (GET /streams?user_login=), its schedule (GET /users?login= for
 * its id, then GET /schedule?broadcaster_id=; a broadcaster without a
 * schedule answers 404, an empty one). A channel is named by its login,
 * the name in its address (twitch.tv/<login>). Its player needs the
 * embedding page's domain: Stream::embed(parent: 'example.org').
 */
final class TwitchProvider implements ProviderInterface, LiveInterface, DeclaresUsagePolicyInterface
{
    /** Twitch's usage policy, as its terms read on the day it says (provenance.verifiedAt). */
    public const POLICY = __DIR__.'/policy.json';

    private static ?UsagePolicy $policy = null;

    private ?string $appToken = null;

    private int $expires = 0;

    /** @param ?\Closure(): int $clock the time now, in seconds (time() by default) */
    public function __construct(
        private readonly JsonClient $json,
        private readonly string $clientId,
        private readonly ?string $clientSecret = null,
        private readonly ?string $token = null,
        private readonly string $api = 'https://api.twitch.tv/helix/',
        private readonly string $tokenUrl = 'https://id.twitch.tv/oauth2/token',
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function getName(): string
    {
        return 'twitch';
    }

    public function getTitle(): string
    {
        return 'Twitch';
    }

    public function usagePolicy(): UsagePolicy
    {
        return self::$policy ??= UsagePolicy::fromFile(self::POLICY);
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            images: true,
            terms: ['https://legal.twitch.com/legal/developer-agreement/', 'https://www.twitch.tv/p/legal/terms-of-service/'],
            live: true,
            schedule: true,
            platforms: [Platform::Twitch],
            policy: $this->usagePolicy(),
        );
    }

    public function live(string $channel): ?Stream
    {
        $login = self::login($channel);
        $item = $this->call('streams', ['user_login' => $login])?->list('data')[0] ?? null;
        if (null === $item || 'live' !== $item->string('type')) {
            return null;
        }
        $login = $item->string('user_login') ?? $login;
        $thumbnail = $item->string('thumbnail_url');

        return new Stream(
            platform: Platform::Twitch,
            state: LiveState::Live,
            source: 'twitch',
            channel: new Channel(Platform::Twitch, (string) $item->string('user_id'), (string) ($item->string('user_name') ?? $login), $login, url: 'https://www.twitch.tv/'.$login, source: 'twitch'),
            title: $item->string('title'),
            id: $item->string('id'),
            started: $item->dateTime('started_at'),
            viewers: $item->int('viewer_count'),
            category: $item->string('game_name'),
            language: $item->string('language'),
            thumbnail: null === $thumbnail ? null : new Image(ImageKind::Thumbnail, str_replace('{width}x{height}', '1280x720', $thumbnail), 1280, 720),
            url: 'https://www.twitch.tv/'.$login,
            embedUrl: 'https://player.twitch.tv/?channel='.rawurlencode($login),
        );
    }

    public function schedule(string $channel, int $limit = 10): array
    {
        $user = $this->call('users', ['login' => self::login($channel)])?->list('data')[0] ?? null;
        $id = $user?->string('id');
        if (null === $id) {
            return [];
        }
        $schedule = $this->call('schedule', ['broadcaster_id' => $id, 'first' => max(1, min($limit, 25))])?->data('data');
        if (null === $schedule) {
            return [];
        }
        $login = $schedule->string('broadcaster_login') ?? (string) $user->string('login');
        $owner = new Channel(Platform::Twitch, $id, (string) ($schedule->string('broadcaster_name') ?? $user->string('display_name') ?? $login), $login, url: 'https://www.twitch.tv/'.$login, source: 'twitch');
        $streams = [];
        foreach ($schedule->list('segments') as $segment) {
            $streams[] = new Stream(
                platform: Platform::Twitch,
                state: null === $segment->string('canceled_until') ? LiveState::Upcoming : LiveState::Cancelled,
                source: 'twitch',
                channel: $owner,
                title: $segment->string('title'),
                id: $segment->string('id'),
                scheduled: $segment->dateTime('start_time'),
                scheduledEnd: $segment->dateTime('end_time'),
                category: $segment->string('category.name'),
                url: 'https://www.twitch.tv/'.$login,
                embedUrl: 'https://player.twitch.tv/?channel='.rawurlencode($login),
                recurring: true === $segment->bool('is_recurring'),
            );
        }
        usort($streams, static fn (Stream $a, Stream $b) => $a->scheduled <=> $b->scheduled);

        return \array_slice($streams, 0, max(1, $limit));
    }

    /** A login as Twitch keeps it: lower case, without "@" or the address around it. */
    private static function login(string $channel): string
    {
        $channel = trim($channel);
        if (preg_match('~twitch\.tv/([A-Za-z0-9_]+)~', $channel, $m)) {
            $channel = $m[1];
        }

        return strtolower(ltrim($channel, '@'));
    }

    /** @param array<string, string|int> $query */
    private function call(string $resource, array $query): ?Data
    {
        return $this->json->get(rtrim($this->api, '/').'/'.$resource, $query, ['Client-Id' => $this->clientId, 'Authorization' => 'Bearer '.$this->bearer()]);
    }

    /** The token given, or the app access token, asked again a minute before it expires. */
    private function bearer(): string
    {
        if (null !== $this->token) {
            return $this->token;
        }
        $now = null === $this->clock ? time() : ($this->clock)();
        if (null !== $this->appToken && $now < $this->expires) {
            return $this->appToken;
        }
        $answer = $this->json->request('POST', $this->tokenUrl, ['body' => ['client_id' => $this->clientId, 'client_secret' => (string) $this->clientSecret, 'grant_type' => 'client_credentials']]);
        $this->appToken = $answer?->string('access_token') ?? throw ProviderException::answered('Twitch', 'POST /oauth2/token', 200, 'No access_token in the answer.');
        $this->expires = $now + max(0, ($answer->int('expires_in') ?? 0) - 60);

        return $this->appToken;
    }
}
