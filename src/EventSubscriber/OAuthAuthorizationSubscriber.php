<?php

namespace App\EventSubscriber;

use App\Service\OAuthAuthorizationRequired;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsEventListener(event: 'kernel.exception')]
final class OAuthAuthorizationSubscriber
{
    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof OAuthAuthorizationRequired) {
            return;
        }

        $request = $event->getRequest();
        $returnTo = (string) ($request->headers->get('referer') ?: $this->urlGenerator->generate('app_home'));
        $loginUrl = $this->urlGenerator->generate('oauth_login', ['return' => $returnTo]);

        $event->setResponse(new RedirectResponse($loginUrl));
    }
}
