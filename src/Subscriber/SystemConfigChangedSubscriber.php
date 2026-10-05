<?php declare(strict_types=1);

namespace Mxcoan\Subscriber;

use Mxcoan\Service\ConnectService;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Runs the connect handshake after the plugin's admin config is saved
 * (post-write, exactly like the Craft/PrestaShop handshake handlers).
 * All filtering/throttling/guarding lives in ConnectService.
 */
class SystemConfigChangedSubscriber implements EventSubscriberInterface
{
    private ConnectService $connectService;

    public function __construct(ConnectService $connectService)
    {
        $this->connectService = $connectService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SystemConfigChangedEvent::class => 'onConfigChanged',
        ];
    }

    public function onConfigChanged(SystemConfigChangedEvent $event): void
    {
        $this->connectService->onConfigChanged($event);
    }
}