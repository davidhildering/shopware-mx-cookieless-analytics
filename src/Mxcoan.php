<?php declare(strict_types=1);

namespace Mxcoan;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Symfony\Component\HttpClient\HttpClient;

class Mxcoan extends Plugin
{
    public const CONFIG_PREFIX = 'Mxcoan.config.';

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        // Best-effort: tell the API the site was uninstalled so ingestion
        // stops (same semantics as the WordPress/PrestaShop plugins). The
        // key may already be revoked — failures are ignored on purpose.
        //
        // Live-learned Shopware contracts in the uninstall process (do not
        // regress):
        // 1) The plugin's OWN services are NOT in the container: Shopware
        //    deactivates the plugin (which rebuilds the container) BEFORE
        //    calling uninstall(). Resolving Mxcoan\Service\ConnectService
        //    here throws ServiceNotFoundException and breaks the whole
        //    uninstall. Core services remain available.
        // 2) SystemConfigService is EMPTY during uninstall — every
        //    getString() returns '' (observed live, even for core keys like
        //    core.store.apiPublicKey), while a raw SQL query of
        //    system_config sees the rows fine. Read the values via the DBAL
        //    Connection directly.
        // 3) Symfony HttpClient requests are LAZY: without forcing a response
        //    the CLI process can exit before the request is sent. Call
        //    ->getStatusCode() to force completion.
        try {
            $container = $this->container;
            if ($container === null || !$container->has(Connection::class)) {
                return;
            }

            $conn = $container->get(Connection::class);
            if (!$conn instanceof Connection) {
                return;
            }

            $apiKey = self::readConfigValue($conn, 'apiKey');
            $apiBase = self::readConfigValue($conn, 'apiBase');
            if ($apiKey === '' || $apiBase === '') {
                return;
            }

            HttpClient::create(['timeout' => 3])->request(
                'POST',
                $apiBase . '/api/integrations/shopware/uninstall',
                ['headers' => ['Authorization' => 'Bearer ' . $apiKey]]
            )->getStatusCode();
        } catch (\Throwable $e) {
            // Intentionally ignored — uninstall must always succeed locally.
        }
    }

    /** Read a plugin config value straight from system_config (see the
     * uninstall() comment: SystemConfigService is empty in that context).
     * Values are stored as {"_value": x} JSON envelopes. */
    private static function readConfigValue(Connection $conn, string $field): string
    {
        $row = $conn->fetchOne(
            'SELECT configuration_value FROM system_config '
            . "WHERE configuration_key = ? AND sales_channel_id IS NULL",
            [self::CONFIG_PREFIX . $field]
        );
        if ($row === false) {
            return '';
        }
        $decoded = json_decode((string) $row, true);
        if (is_array($decoded) && array_key_exists('_value', $decoded)) {
            return (string) $decoded['_value'];
        }
        return (string) $row;
    }
}