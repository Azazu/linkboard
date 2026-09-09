<?php

declare(strict_types=1);

namespace App\Auth\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * When an existing web session is refused because the account got blocked
 * (UserProvider::refreshUser), the firewall drops the token and redirects
 * to the entry point — but only the login *failure* handler stores the
 * error for the login page. Store it here so /login can say "blocked"
 * (spec user-accounts, "Blocked user tries to log in on the web").
 * Runs before the firewall's ExceptionListener (priority 1).
 */
final class BlockedSessionErrorSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 8]];
    }

    public function onException(ExceptionEvent $event): void
    {
        // With a lazy firewall the refresh happens when the template reads
        // app.user, so the exception may arrive wrapped (e.g. in a Twig
        // RuntimeError); walk the chain like the firewall's listener does.
        $exception = $event->getThrowable();
        while (null !== $exception && !$exception instanceof AccountStatusException) {
            $exception = $exception->getPrevious();
        }
        if (null === $exception) {
            return;
        }
        $request = $event->getRequest();
        if (!$request->hasSession() || str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);
    }
}
