<?php

namespace Omnishow\Twitch;

use Omnishow\Config;
use Omnishow\Exception\InvalidConfigException;
use Omnishow\Http\JsonClient;
use Omnishow\ProviderFactory;
use Omnishow\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "twitch": options client_id (required), and client_secret (an app access
 * token is asked with it, and asked again when it expires) or token (an
 * access token got elsewhere); api ("https://api.twitch.tv/helix/"),
 * token_url ("https://id.twitch.tv/oauth2/token").
 */
final class TwitchFactory extends ProviderFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnishow.factory_name' => 'twitch',
            'omnishow.factory_title' => 'Twitch',
            'omnishow.required_options' => ['client_id'],
            'client_secret' => null,
            'token' => null,
            'api' => 'https://api.twitch.tv/helix/',
            'token_url' => 'https://id.twitch.tv/oauth2/token',
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        if (null === $c->string('client_secret') && null === $c->string('token')) {
            throw new InvalidConfigException('The "twitch" provider needs: client_secret or token.');
        }

        return new TwitchProvider(new JsonClient($this->http ?? HttpClient::create(['timeout' => 20]), 'Twitch', ['Accept' => 'application/json']), (string) $c->string('client_id'), $c->string('client_secret'), $c->string('token'), (string) $c->string('api'), (string) $c->string('token_url'));
    }
}
