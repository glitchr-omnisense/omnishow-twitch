<?php

namespace Omnishow\Twitch\Tests;

use Omnishow\Testing\BareTestCase;
use Omnishow\Twitch\TwitchProvider;

/** omnishow/twitch's classes load with omnisense/omnishow and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return TwitchProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
