<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GoogleAuthService
{
    public function __construct(
        #[Autowire(env: 'GOOGLE_CLIENT_ID')]
        private readonly string $clientId,
        #[Autowire(env: 'GOOGLE_CLIENT_SECRET')]
        private readonly string $clientSecret,
        #[Autowire(env: 'GOOGLE_REDIRECT_URI')]
        private readonly string $redirectUri,
        private readonly HttpClientInterface $httpClient,
    ) {}

    public function getAuthorizationUrl(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        ]);
    }

    /**
     * Exchanges an authorization code for Google user info.
     *
     * @return array{sub: string, email: string, name: string, picture: string}
     * @throws \RuntimeException on token exchange or userinfo failure
     */
    public function fetchUserInfo(string $code): array
    {
        $tokenData = $this->httpClient->request('POST', 'https://oauth2.googleapis.com/token', [
            'body' => [
                'code'          => $code,
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri'  => $this->redirectUri,
                'grant_type'    => 'authorization_code',
            ],
        ])->toArray();

        if (!isset($tokenData['access_token'])) {
            throw new \RuntimeException('Google token exchange failed');
        }

        return $this->httpClient->request('GET', 'https://www.googleapis.com/oauth2/v3/userinfo', [
            'headers' => ['Authorization' => 'Bearer ' . $tokenData['access_token']],
        ])->toArray();
    }
}
