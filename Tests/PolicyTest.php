<?php

namespace Omnishow\Twitch\Tests;

use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\Permission;
use Omnishow\Exception\OmnishowException;
use Omnishow\Exception\UsageLimitReachedException;
use Omnishow\Registry;
use Omnishow\Testing\RecordedHttpClient;
use Omnishow\Twitch\TwitchFactory;
use Omnishow\Twitch\TwitchProvider;
use PHPUnit\Framework\TestCase;

/**
 * TwitchProvider's usage policy (policy.json) through omnishow's registry:
 * as declared, a site's limit counted with the call over it never made,
 * a 429 and its Retry-After holding every next call, a looser setting
 * refused. No network: recorded answers only.
 */
final class PolicyTest extends TestCase
{
    private const OPTIONS = ['client_id' => 'not-a-real-twitch-client', 'token' => 'not-a-real-twitch-token'];

    public function testThePolicyIsTheOneDeclared(): void
    {
        $policy = (new Registry([new TwitchFactory(RecordedHttpClient::routes([]))], ['films' => ['factory' => 'twitch', 'options' => self::OPTIONS]]))->policy('films');

        self::assertSame('twitch', $policy->provider);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertFileExists(TwitchProvider::POLICY);
        self::assertNull($policy->rateLimits, 'Helix\'s bucket size is not stated as policy');
        self::assertSame(86400, $policy->rights->caching->maxTtl, 'a day at most');
        self::assertSame(Permission::Forbidden, $policy->rights->redistribution);
    }

    public function testASitesLimitIsCountedAndTheCallOverItIsNeverMade(): void
    {
        $http = RecordedHttpClient::routes([]);
        $registry = new Registry([new TwitchFactory($http)], ['films' => ['factory' => 'twitch', 'options' => self::OPTIONS, 'policy' => ['rateLimits' => [['max' => 1, 'window' => 'day', 'operations' => ['live']]]]]]);

        try {
            $registry->live('films')->live('example');
        } catch (UsageLimitReachedException $e) {
            throw $e;
        } catch (OmnishowException) {
            // Nothing recorded: the provider's own answer to that, counted all the same.
        }
        $made = \count($http->requests);
        self::assertGreaterThan(0, $made);
        try {
            $registry->live('films')->live('example');
            self::fail('The call over the site\'s limit was made.');
        } catch (UsageLimitReachedException $e) {
            self::assertInstanceOf(OmnishowException::class, $e, 'the family\'s catch holds it');
            self::assertSame('1/day/application/live', $e->limit?->id());
        }
        self::assertCount($made, $http->requests, 'the refused call reached nothing');
    }

    public function testA429AndItsRetryAfterHoldEveryCallUntilTheWaitIsOver(): void
    {
        $http = RecordedHttpClient::routes(['~.~' => [429, '{"error":"too many requests"}', ['retry-after' => ['120']]]]);
        $registry = new Registry([new TwitchFactory($http)], ['films' => ['factory' => 'twitch', 'options' => self::OPTIONS]]);

        try {
            $registry->live('films')->live('example');
            self::fail('A 429 was read as an answer.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(120, $e->retryAfter);
            self::assertSame('twitch answered 429 Too Many Requests: try again in 120 s.', $e->getMessage());
        }
        try {
            $registry->live('films')->live('example');
            self::fail('A call went out while the provider asked to wait.');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThanOrEqual(119, $e->retryAfter);
        }
        self::assertCount(1, $http->requests, 'the wait held the next request back');
    }

    public function testASiteCannotLoosenThePolicy(): void
    {
        $registry = new Registry([new TwitchFactory(RecordedHttpClient::routes([]))], ['films' => ['factory' => 'twitch', 'options' => self::OPTIONS, 'policy' => ['rights' => ['redistribution' => 'allowed']]]]);

        $this->expectException(InvalidPolicyException::class);
        $this->expectExceptionMessage('redistribution');
        $registry->live('films');
    }
}
