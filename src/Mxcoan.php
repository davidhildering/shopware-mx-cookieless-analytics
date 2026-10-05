<?php declare(strict_types=1);

namespace Mxcoan;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class Mxcoan extends Plugin
{
    /** Cache-bust version appended to the tracker.js URL. Bump at release. */
    public const TRACKER_CACHE_VERSION = '1.0.0';

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        // Best-effort: tell the API the site was uninstalled so ingestion
        // stops (same semantics as the WordPress/PrestaShop plugins). The
        // key may already be revoked — failures are ignored on purpose.
        $container = $this->container;
        if ($container === null) {
            return;
        }

        $service = $container->get(\Mxcoan\Service\ConnectService::class);
        if ($service instanceof \Mxcoan\Service\ConnectService) {
            $service->notifyUninstall();
        }
    }
}