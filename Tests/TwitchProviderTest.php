<?php

namespace Omnishow\Twitch\Tests;

use Omnishow\Exception\InvalidConfigException;
use Omnishow\Exception\ProviderException;
use Omnishow\Http\JsonClient;
use Omnishow\Model\LiveState;
use Omnishow\Model\Stream;
use Omnishow\Twitch\TwitchFactory;
use Omnishow\Twitch\TwitchProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Twitch Helix's answers shaped after its reference
 * (dev.twitch.tv/docs/api/reference, read on 2026-11-09), values written by
 * hand: no client id was used, nothing here was captured from Twitch. Every
 * request is recorded as sent, its query, headers and body included.
 */
final class TwitchProviderTest extends TestCase
{
    private const HELIX = 'https://api.twitch.tv/helix/';
    private const TOKEN = 'https://id.twitch.tv/oauth2/token';

    /** @var list<array{0: string, 1: array<mixed>}> */
    private array $requests = [];

    public function testALiveChannelAndItsStream(): void
    {
        $stream = $this->provider()->live('https://www.twitch.tv/TwitchDev');

        self::assertSame(['POST '.self::TOKEN, 'GET '.self::HELIX.'streams?user_login=twitchdev'], $this->urls());
        self::assertSame('client_id=not-a-real-client&client_secret=not-a-real-secret&grant_type=client_credentials', $this->requests[0][1]['body'] ?? null);
        self::assertSame(['Authorization: Bearer app-token-1234567890', 'Client-Id: not-a-real-client'], $this->headers(1, 'authorization', 'client-id'));
        self::assertNotNull($stream);
        self::assertSame([LiveState::Live, '40952121085', 'Building an extension, written by hand for the tests', 321, 'Science & Technology', 'en', '2026-11-09T18:00:05+00:00'], [$stream->state, $stream->id, $stream->title, $stream->viewers, $stream->category, $stream->language, $stream->started?->format(\DATE_ATOM)]);
        self::assertSame(['141981764', 'TwitchDev', 'twitchdev', 'https://www.twitch.tv/twitchdev'], [$stream->channel?->id, $stream->channel?->title, $stream->channel?->handle, $stream->url]);
        self::assertSame('https://static-cdn.jtvnw.net/previews-ttv/live_user_twitchdev-1280x720.jpg', $stream->thumbnail?->url);
        self::assertSame('https://player.twitch.tv/?channel=twitchdev&parent=example.org', $stream->embed(true, 'example.org'), 'no private variant: the player, with its parent');
    }

    public function testAChannelOffAirIsNull(): void
    {
        self::assertNull($this->provider()->live('@quietchannel'));
        self::assertSame('GET '.self::HELIX.'streams?user_login=quietchannel', $this->urls()[1] ?? null);
    }

    public function testTheScheduleSoonestFirstCancelledSaid(): void
    {
        $schedule = $this->provider()->schedule('twitchdev', 5);

        self::assertSame(['POST '.self::TOKEN, 'GET '.self::HELIX.'users?login=twitchdev', 'GET '.self::HELIX.'schedule?broadcaster_id=141981764&first=5'], $this->urls());
        self::assertSame([
            ['seg-1', LiveState::Upcoming, '2026-11-10T18:00:00+00:00', '2026-11-10T20:00:00+00:00', 'Science & Technology', true],
            ['seg-3', LiveState::Upcoming, '2026-11-12T16:00:00+00:00', '2026-11-12T17:00:00+00:00', null, false],
            ['seg-2', LiveState::Cancelled, '2026-11-17T18:00:00+00:00', '2026-11-17T20:00:00+00:00', 'Science & Technology', true],
        ], array_map(static fn (Stream $s) => [$s->id, $s->state, $s->scheduled?->format(\DATE_ATOM), $s->scheduledEnd?->format(\DATE_ATOM), $s->category, $s->recurring], $schedule));
        self::assertSame('TwitchDev', $schedule[0]->channel?->title);
        self::assertSame('first=25', explode('&', (string) parse_url($this->scheduleUrl($this->provider(), 'twitchdev', 80), \PHP_URL_QUERY))[1] ?? null, 'Twitch answers 25 segments at most');
    }

    public function testNoScheduleOrNoSuchChannelIsEmpty(): void
    {
        self::assertSame([], $this->provider()->schedule('noschedule'), 'a 404: the broadcaster has not created a schedule');
        self::assertSame('GET '.self::HELIX.'schedule?broadcaster_id=999000999&first=10', $this->urls()[2] ?? null);
        $this->requests = [];
        self::assertSame([], $this->provider()->schedule('nobody'));
        self::assertSame(['POST '.self::TOKEN, 'GET '.self::HELIX.'users?login=nobody'], $this->urls(), 'no such login: no schedule asked');
    }

    public function testTheAppTokenIsAskedOnceThenAgainWhenItExpires(): void
    {
        $now = 1_000;
        $provider = new TwitchProvider(new JsonClient($this->http(), 'Twitch'), 'not-a-real-client', 'not-a-real-secret', clock: static function () use (&$now): int {
            return $now;
        });
        $provider->live('twitchdev');
        $provider->live('twitchdev');
        $now += 5_011_271 - 59;
        $provider->live('twitchdev');

        self::assertSame(['POST '.self::TOKEN, 'GET '.self::HELIX.'streams?user_login=twitchdev', 'GET '.self::HELIX.'streams?user_login=twitchdev', 'POST '.self::TOKEN, 'GET '.self::HELIX.'streams?user_login=twitchdev'], $this->urls());
    }

    public function testATokenGivenIsSentAsItIs(): void
    {
        $this->provider(['client_secret' => null, 'token' => 'given-token-12345'])->live('twitchdev');

        self::assertSame(['GET '.self::HELIX.'streams?user_login=twitchdev'], $this->urls());
        self::assertSame(['Authorization: Bearer given-token-12345'], $this->headers(0, 'authorization'));
    }

    public function testARefusedTokenIsAProviderErrorWithoutTheToken(): void
    {
        try {
            $this->provider(['client_secret' => null, 'token' => 'refused-token-123'])->live('twitchdev');
            self::fail('401');
        } catch (ProviderException $e) {
            self::assertStringStartsWith('Twitch answered 401 for GET /helix/streams. {', $e->getMessage());
            self::assertStringContainsString('"message": "Invalid OAuth token"', $e->getMessage());
            self::assertStringNotContainsString('refused-token-123', $e->getMessage());
        }
    }

    public function testAClientIdAndASecretOrATokenAreRequired(): void
    {
        $capabilities = $this->provider()->capabilities();
        self::assertSame(['live'], $capabilities->families());
        self::assertSame(['live'], $capabilities->contracts());
        self::assertTrue($capabilities->schedule);
        foreach ([[[], 'The "twitch" provider needs: client_id.'], [['client_id' => 'x'], 'The "twitch" provider needs: client_secret or token.']] as [$options, $message]) {
            try {
                (new TwitchFactory(new MockHttpClient()))->create($options);
                self::fail($message);
            } catch (InvalidConfigException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    private function scheduleUrl(TwitchProvider $provider, string $channel, int $limit): string
    {
        $this->requests = [];
        $provider->schedule($channel, $limit);

        return (string) preg_replace('~^GET ~', '', $this->urls()[2] ?? '');
    }

    /** @return list<string> */
    private function urls(): array
    {
        return array_map(static fn (array $request) => $request[0], $this->requests);
    }

    /** @return list<string> */
    private function headers(int $request, string ...$names): array
    {
        $headers = $this->requests[$request][1]['headers'] ?? [];
        $kept = [];
        foreach (\is_array($headers) ? $headers : [] as $header) {
            if (\is_string($header) && \in_array(strtolower(explode(':', $header, 2)[0]), $names, true)) {
                $kept[] = $header;
            }
        }
        sort($kept);

        return $kept;
    }

    /** @param array<string, mixed> $options */
    private function provider(array $options = []): TwitchProvider
    {
        $provider = (new TwitchFactory($this->http()))->create(array_filter($options + ['client_id' => 'not-a-real-client', 'client_secret' => 'not-a-real-secret'], static fn ($value) => null !== $value));
        self::assertInstanceOf(TwitchProvider::class, $provider);

        return $provider;
    }

    private function http(): MockHttpClient
    {
        $f = __DIR__.'/Fixtures/';
        $routes = [
            '~^POST https://id\.twitch\.tv/oauth2/token$~' => [200, 'token.json'],
            '~/streams\?user_login=twitchdev$~' => [200, 'streams-live.json'],
            '~/streams\?~' => [200, 'streams-none.json'],
            '~/users\?login=twitchdev$~' => [200, 'users-twitchdev.json'],
            '~/users\?login=noschedule$~' => [200, 'users-noschedule.json'],
            '~/schedule\?broadcaster_id=141981764&~' => [200, 'schedule.json'],
            '~/schedule\?broadcaster_id=999000999&~' => [404, 'schedule-none.json'],
            '~/users\?~' => [200, 'users-none.json'],
        ];

        return new MockHttpClient(function (string $method, string $url, array $sent) use ($routes, $f): MockResponse {
            $this->requests[] = [$method.' '.$url, $sent];
            $headers = \is_array($sent['headers'] ?? null) ? $sent['headers'] : [];
            if (\in_array('Authorization: Bearer refused-token-123', $headers, true)) {
                return new MockResponse((string) file_get_contents($f.'error-401.json'), ['http_code' => 401]);
            }
            foreach ($routes as $pattern => [$status, $file]) {
                if (preg_match($pattern, $method.' '.$url)) {
                    return new MockResponse((string) file_get_contents($f.$file), ['http_code' => $status]);
                }
            }

            return new MockResponse('{"error":"Not Found","status":404,"message":"not recorded"}', ['http_code' => 404]);
        });
    }
}
