<?php

namespace App\Controller;

use App\Service\OAuthAuthorizationRequired;
use App\Service\WikimediaOAuthClient;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OAuthController
{
    #[Route('/oauth/login', name: 'oauth_login', methods: ['GET'])]
    public function login(Request $request, WikimediaOAuthClient $oauthClient): RedirectResponse
    {
        $returnTo = (string) $request->query->get('return', '/');
        $request->getSession()->set('oauth_return_to', $returnTo !== '' ? $returnTo : '/');

        return new RedirectResponse($oauthClient->getAuthorizationUrl());
    }

    #[Route('/oauth/callback', name: 'oauth_callback', methods: ['GET'])]
    public function callback(Request $request, WikimediaOAuthClient $oauthClient): RedirectResponse|Response
    {
        $code = (string) $request->query->get('code', '');
        $state = (string) $request->query->get('state', '');
        if ($code === '' || $state === '') {
            return new Response('Missing OAuth authorization code.', 400);
        }

        $oauthClient->completeAuthorization($code, $state);
        $returnTo = (string) $request->getSession()->get('oauth_return_to', '/');
        $request->getSession()->remove('oauth_return_to');

        return new RedirectResponse($returnTo !== '' ? $returnTo : '/');
    }

    #[Route('/oauth/logout', name: 'oauth_logout', methods: ['GET'])]
    public function logout(Request $request, WikimediaOAuthClient $oauthClient): RedirectResponse
    {
        $oauthClient->logout();

        return new RedirectResponse((string) ($request->headers->get('referer') ?: '/'));
    }

    #[Route('/oauth/status', name: 'oauth_status', methods: ['GET'])]
    public function status(WikimediaOAuthClient $oauthClient): Response
    {
        $authorized = $oauthClient->isAuthorized();
        $tokenOk = false;
        $username = '';
        $groups = [];
        $canEdit = false;
        $canCreate = false;
        $error = '';

        if ($authorized) {
            try {
                $tokenOk = $oauthClient->getCsrfToken() !== '';
                $userInfo = $oauthClient->getUserInfo();
                $username = is_string($userInfo['username'] ?? null) ? $userInfo['username'] : '';
                $groups = is_array($userInfo['groups'] ?? null) ? array_values(array_filter($userInfo['groups'], 'is_string')) : [];
                $rights = is_array($userInfo['rights'] ?? null) ? array_values(array_filter($userInfo['rights'], 'is_string')) : [];
                $canEdit = in_array('edit', $rights, true);
                $canCreate = in_array('createpage', $rights, true) || in_array('createitem', $rights, true);
            } catch (OAuthAuthorizationRequired $e) {
                $authorized = false;
                $error = $e->getMessage();
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $payload = [
            'authorized' => $authorized,
            'tokenCheck' => $tokenOk,
            'username' => $username,
            'groups' => $groups,
            'canEdit' => $canEdit,
            'canCreate' => $canCreate,
            'error' => $error,
        ];

        return new Response(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }
}
