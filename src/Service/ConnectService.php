<?php declare(strict_types=1);

namespace Mxcoan\Service;

use Shopware\Core\Framework\Adapter\Cache\CacheClearer;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Auto-connect handshake: on plugin-config save, exchange the stored API key
 * for a challenge token, serve it via the storefront challenge controller,
 * let the API verify it, then persist the connection state.
 *
 * Contract parity with the other plugins (do not regress):
 * - runs ONLY from the config-save handler, never the render path
 * - throttled to one attempt per 5 minutes
 * - ~5s HTTP timeouts on every call
 * - the challenge token is cleared once the handshake completes
 * - the tracker only injects when trackingEnabled AND connected (double gate)
 */
class ConnectService
{
    public const CONFIG_PREFIX = 'Mxcoan.config.';
    private const CONNECT_THROTTLE_SECONDS = 300;

    private SystemConfigService $systemConfig;
    private CacheClearer $cacheClearer;
    private HttpClientInterface $client;

    /** Re-entrancy guard: our own config writes re-fire the save event. */
    private static bool $connecting = false;

    public function __construct(
        SystemConfigService $systemConfig,
        CacheClearer $cacheClearer
    ) {
        $this->systemConfig = $systemConfig;
        $this->cacheClearer = $cacheClearer;
        $this->client = HttpClient::create(['timeout' => 5]);
    }

    public function onConfigChanged(SystemConfigChangedEvent $event): void
    {
        // SystemConfigChangedEvent fires PER KEY (e.g. "MetrixsMxcoan.settings.apiKey").
        if (!str_starts_with($event->getKey(), self::CONFIG_PREFIX)) {
            return;
        }

        $this->connect();
    }

    /**
     * Full handshake. Returns true when the site is verified and the
     * connection state was persisted.
     */
    public function connect(): bool
    {
        if (self::$connecting) {
            return false;
        }
        self::$connecting = true;

        try {
            return $this->doConnect();
        } finally {
            self::$connecting = false;
        }
    }

    private function doConnect(): bool
    {
        $apiKey = $this->systemConfig->getString(self::CONFIG_PREFIX . 'apiKey');
        $apiBase = $this->systemConfig->getString(self::CONFIG_PREFIX . 'apiBase');

        if ($apiKey === '' || $apiBase === '') {
            return false;
        }

        // Throttle: one attempt per 5 minutes (config saves fire repeatedly).
        $lastAttempt = $this->systemConfig->getInt(self::CONFIG_PREFIX . 'lastConnectAttempt');
        if (time() - $lastAttempt < self::CONNECT_THROTTLE_SECONDS) {
            return false;
        }
        $this->systemConfig->set(self::CONFIG_PREFIX . 'lastConnectAttempt', time());

        $bearer = ['Authorization' => 'Bearer ' . $apiKey];

        // Step 1: request a challenge token from the API.
        $challenge = null;
        try {
            $resp = $this->client->request(
                'POST',
                $apiBase . '/api/integrations/shopware/challenge',
                ['headers' => $bearer]
            );
            if ($resp->getStatusCode() === 200) {
                $challenge = $resp->toArray(false)['challenge'] ?? null;
            }
        } catch (\Throwable $e) {
            return false;
        }
        if (!is_string($challenge) || $challenge === '') {
            return false;
        }

        // Step 2: serve the token on the site's public domain.
        $this->systemConfig->set(self::CONFIG_PREFIX . 'challengeToken', $challenge);

        // Step 3: let the API fetch + verify it.
        $domain = null;
        try {
            $resp = $this->client->request(
                'POST',
                $apiBase . '/api/integrations/shopware/verify',
                ['headers' => $bearer]
            );
            if ($resp->getStatusCode() === 200) {
                $body = $resp->toArray(false);
                if (($body['ok'] ?? false) === true) {
                    $domain = $body['site']['domain'] ?? null;
                }
            }
        } catch (\Throwable $e) {
            $this->systemConfig->delete(self::CONFIG_PREFIX . 'challengeToken');
            return false;
        }

        // The token is cleared once the handshake completes — success or not.
        $this->systemConfig->delete(self::CONFIG_PREFIX . 'challengeToken');

        if (!is_string($domain) || $domain === '') {
            return false;
        }

        // Step 4: persist connection state + flush cached pages so the
        // injected <script> appears (head output is baked into page cache).
        $this->systemConfig->set(self::CONFIG_PREFIX . 'connected', true);
        $this->systemConfig->set(self::CONFIG_PREFIX . 'connectedDomain', $domain);
        $this->cacheClearer->clearCache();

        return true;
    }

    /**
     * Best-effort uninstall notice. Failures are ignored: the key may already
     * be revoked, and MetriXs marks the site unverified on its side.
     */
    public function notifyUninstall(): void
    {
        $apiKey = $this->systemConfig->getString(self::CONFIG_PREFIX . 'apiKey');
        $apiBase = $this->systemConfig->getString(self::CONFIG_PREFIX . 'apiBase');

        if ($apiKey === '' || $apiBase === '') {
            return;
        }

        try {
            HttpClient::create(['timeout' => 3])->request(
                'POST',
                $apiBase . '/api/integrations/shopware/uninstall',
                ['headers' => ['Authorization' => 'Bearer ' . $apiKey]]
            );
        } catch (\Throwable $e) {
            // Intentionally ignored.
        }
    }
}