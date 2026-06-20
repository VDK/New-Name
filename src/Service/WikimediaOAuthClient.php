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
        if ($this->envAccessToken() !== '') {
            return true;
        }
        if (!$session->has('oauth2_access_token')) {
            return false;
        }

        $expiresAt = (int) $session->get('oauth2_expires_at', 0);
        if ($expiresAt > 0 && $expiresAt <= time() + 30) {
            try {
                $this->refreshAccessToken();
            } catch (OAuthAuthorizationRequired) {
                return false;
            }
        }

        return true;
    }

    public function getAuthorizationUrl(): string
    {
        $session = $this->requestStack->getSession();
        $session->remove('oauth2_access_token');
        $session->remove('oauth2_refresh_token');
        $session->remove('oauth2_expires_at');
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
        $this->storeTokenResponse($token);
    }

    public function logout(): void
    {
        $session = $this->requestStack->getSession();
        $session->remove('oauth2_state');
        $session->remove('oauth2_access_token');
        $session->remove('oauth2_refresh_token');
        $session->remove('oauth2_expires_at');
    }

    /**
     * @param array<string, scalar> $data
     * @return array<string, mixed>
     */
    public function signedPost(string $url, array $data): array
    {
        return $this->authorizedRequest('POST', $url, $data, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCsrfToken(): string
    {
        $data = $this->signedPost(self::WIKIDATA_API_URL, [
            'action' => 'query',
            'meta' => 'tokens|userinfo',
            'type' => 'csrf',
            'format' => 'json',
        ]);

        $token = $data['query']['tokens']['csrftoken'] ?? null;
        $userInfo = $data['query']['userinfo'] ?? [];
        if (
            !is_array($userInfo)
            || isset($userInfo['anon'])
            || !is_string($token)
            || $token === ''
            || $token === '+\\'
        ) {
            $this->logout();
            throw new OAuthAuthorizationRequired('Wikimedia login has expired.');
        }

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserInfo(): array
    {
        return $this->authorizedRequest('GET', self::PROFILE_URL);
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
            throw new OAuthAuthorizationRequired('Not authorized with Wikimedia OAuth 2.0.');
        }

        return $token;
    }

    /**
     * @param array<string, scalar> $data
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    private function authorizedRequest(string $method, string $url, array $data = [], array $headers = []): array
    {
        $requestHeaders = [
            'Authorization: Bearer ' . $this->accessToken(),
            'User-Agent: New-Name/0.1 (https://new-name.toolforge.org/)',
            ...$headers,
        ];

        try {
            return $this->requestJson($method, $url, $data, $requestHeaders);
        } catch (OAuthAuthorizationRequired) {
            if ($this->envAccessToken() !== '') {
                throw new OAuthAuthorizationRequired('The configured Wikimedia access token is no longer valid.');
            }
            $this->refreshAccessToken();
        }

        $requestHeaders[0] = 'Authorization: Bearer ' . $this->accessToken();

        try {
            return $this->requestJson($method, $url, $data, $requestHeaders);
        } catch (OAuthAuthorizationRequired $e) {
            $this->logout();
            throw $e;
        }
    }

    private function refreshAccessToken(): void
    {
        $session = $this->requestStack->getSession();
        $refreshToken = (string) $session->get('oauth2_refresh_token', '');
        if ($refreshToken === '') {
            $this->logout();
            throw new OAuthAuthorizationRequired('Wikimedia login has expired.');
        }

        try {
            $token = $this->requestJson('POST', self::ACCESS_TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $this->consumerKey,
                'client_secret' => $this->consumerSecret,
            ]);
        } catch (\Throwable $e) {
            $this->logout();
            throw new OAuthAuthorizationRequired('Wikimedia login has expired.', 0, $e);
        }

        if (!isset($token['access_token']) || !is_string($token['access_token'])) {
            $this->logout();
            throw new OAuthAuthorizationRequired('Wikimedia did not renew the login.');
        }

        $this->storeTokenResponse($token);
    }

    /**
     * @param array<string, mixed> $token
     */
    private function storeTokenResponse(array $token): void
    {
        $session = $this->requestStack->getSession();
        $session->set('oauth2_access_token', $token['access_token']);
        if (isset($token['refresh_token']) && is_string($token['refresh_token']) && $token['refresh_token'] !== '') {
            $session->set('oauth2_refresh_token', $token['refresh_token']);
        }
        $expiresIn = isset($token['expires_in']) && is_numeric($token['expires_in'])
            ? max(0, (int) $token['expires_in'])
            : 0;
        if ($expiresIn > 0) {
            $session->set('oauth2_expires_at', time() + $expiresIn);
        } else {
            $session->remove('oauth2_expires_at');
        }
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
        if ($status === 401) {
            throw new OAuthAuthorizationRequired('Wikimedia login has expired.');
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Wikimedia returned a non-JSON response with HTTP ' . $status . '.');
        }
        if (isset($decoded['error'])) {
            $code = '';
            if (is_array($decoded['error'])) {
                $code = is_string($decoded['error']['code'] ?? null) ? $decoded['error']['code'] : 'unknown error';
                $info = is_string($decoded['error']['info'] ?? null) ? $decoded['error']['info'] : $code;
                $message = $code . ': ' . $info;
            } else {
                $message = (string) $decoded['error'];
            }
            if (
                in_array($code, ['invalid_token', 'mwoauth-invalid-authorization'], true)
                || in_array(strtolower($message), ['invalid_token', 'invalid_grant'], true)
                || str_contains(strtolower($message), 'invalid access token')
            ) {
                throw new OAuthAuthorizationRequired('Wikimedia login has expired.');
            }
            throw new RuntimeException('Wikimedia returned an error: ' . $message);
        }

        return $decoded;
    }
}
