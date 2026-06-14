<?php

namespace App\Service;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

final class WikimediaOAuthClient
{
    private const AUTHORIZE_URL = 'https://www.wikidata.org/w/rest.php/oauth2/authorize';
    private const ACCESS_TOKEN_URL = 'https://www.wikidata.org/w/rest.php/oauth2/access_token';
    private const PROFILE_URL = 'https://www.wikidata.org/w/rest.php/oauth2/resource/profile';
    public const WIKIDATA_API_URL = 'https://www.wikidata.org/w/api.php';

    public function __construct(
        private readonly RequestStack $requestStack,
        #[Autowire('%env(WIKIMEDIA_OAUTH_CONSUMER_KEY)%')]
        private readonly string $consumerKey,
        #[Autowire('%env(WIKIMEDIA_OAUTH_CONSUMER_SECRET)%')]
        private readonly string $consumerSecret,
        #[Autowire('%env(WIKIMEDIA_OAUTH_CALLBACK_URL)%')]
        private readonly string $callbackUrl,
    ) {
    }

    public function isAuthorized(): bool
    {
        $session = $this->requestStack->getSession();

        return $session->has('oauth2_access_token') || $this->envAccessToken() !== '';
    }

    public function getAuthorizationUrl(): string
    {
        $session = $this->requestStack->getSession();
        $state = bin2hex(random_bytes(16));
        $session->set('oauth2_state', $state);

        return self::AUTHORIZE_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->consumerKey,
            'redirect_uri' => $this->callbackUrl,
            'state' => $state,
        ]);
    }

    public function completeAuthorization(string $code, string $state): void
    {
        $session = $this->requestStack->getSession();
        $expectedState = (string) $session->get('oauth2_state', '');

        if ($expectedState === '' || !hash_equals($expectedState, $state)) {
            throw new RuntimeException('Invalid OAuth state.');
        }

        $token = $this->requestJson('POST', self::ACCESS_TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->consumerKey,
            'client_secret' => $this->consumerSecret,
            'redirect_uri' => $this->callbackUrl,
        ]);

        if (!isset($token['access_token']) || !is_string($token['access_token'])) {
            throw new RuntimeException('Wikimedia OAuth did not return an access token.');
        }

        $session->remove('oauth2_state');
        $session->set('oauth2_access_token', $token['access_token']);
        if (isset($token['refresh_token']) && is_string($token['refresh_token'])) {
            $session->set('oauth2_refresh_token', $token['refresh_token']);
        }
    }

    public function logout(): void
    {
        $session = $this->requestStack->getSession();
        $session->remove('oauth2_state');
        $session->remove('oauth2_access_token');
        $session->remove('oauth2_refresh_token');
    }

    /**
     * @param array<string, scalar> $data
     * @return array<string, mixed>
     */
    public function signedPost(string $url, array $data): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->accessToken(),
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: New-Name/0.1 (https://new-name.toolforge.org/)',
        ];

        return $this->requestJson('POST', $url, $data, $headers);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCsrfToken(): string
    {
        $data = $this->signedPost(self::WIKIDATA_API_URL, [
            'action' => 'query',
            'meta' => 'tokens',
            'type' => 'csrf',
            'format' => 'json',
        ]);

        $token = $data['query']['tokens']['csrftoken'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('Could not fetch Wikidata CSRF token.');
        }

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserInfo(): array
    {
        return $this->requestJson('GET', self::PROFILE_URL, [], [
            'Authorization: Bearer ' . $this->accessToken(),
            'User-Agent: New-Name/0.1 (https://new-name.toolforge.org/)',
        ]);
    }

    public function getUsername(): string
    {
        $name = $this->getUserInfo()['username'] ?? '';

        return is_string($name) ? $name : '';
    }

    private function envAccessToken(): string
    {
        return (string) ($_ENV['WIKIMEDIA_OAUTH2_ACCESS_TOKEN'] ?? $_SERVER['WIKIMEDIA_OAUTH2_ACCESS_TOKEN'] ?? '');
    }

    private function accessToken(): string
    {
        $token = (string) $this->requestStack->getSession()->get('oauth2_access_token', $this->envAccessToken());
        if ($token === '') {
            throw new RuntimeException('Not authorized with Wikimedia OAuth 2.0.');
        }

        return $token;
    }

    /**
     * @param array<string, scalar> $data
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $url, array $data = [], array $headers = []): array
    {
        $requestUrl = $method === 'GET' && $data !== [] ? $url . '?' . http_build_query($data) : $url;
        $ch = curl_init($requestUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'New-Name/0.1 (https://new-name.toolforge.org/)');
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $message = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Wikimedia request failed: ' . $message);
        }

        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Wikimedia returned a non-JSON response with HTTP ' . $status . '.');
        }
        if (isset($decoded['error'])) {
            if (is_array($decoded['error'])) {
                $code = is_string($decoded['error']['code'] ?? null) ? $decoded['error']['code'] : 'unknown error';
                $info = is_string($decoded['error']['info'] ?? null) ? $decoded['error']['info'] : $code;
                $message = $code . ': ' . $info;
            } else {
                $message = (string) $decoded['error'];
            }
            throw new RuntimeException('Wikimedia returned an error: ' . $message);
        }

        return $decoded;
    }
}
