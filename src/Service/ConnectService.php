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

    /**
     * The ONLY config keys that trigger a handshake.
     *
     * SystemConfigChangedEvent fires per key, on EVERY write — including the
     * ones this service makes itself (challengeToken, lastConnectAttempt,
     * connected, connectedDomain) and the ones the one-click callback makes
     * (connectState, connectStateExpires, apiKey, trackingEnabled). Reacting
     * to the whole prefix meant one "Connect" click ran the full handshake
     * three times: six API calls and up to three full cache flushes inside a
     * single request, each verify fetching this very shop while its own
     * request was still in flight. Key changes that are pure bookkeeping must
     * never re-trigger.
     */
    private const HANDSHAKE_KEYS = ['apiKey', 'apiBase'];

    private SystemConfigService $systemConfig;
    private CacheClearer $cacheClearer;
    private HttpClientInterface $client;

    /** Re-entrancy guard: our own config writes re-fire the save event. */
    private static bool $connecting = false;

    /** Set by the one-click callback: it writes several keys at once and runs
     *  exactly one explicit, unthrottled handshake afterwards. */
    private bool $muted = false;

    public function __construct(
        SystemConfigService $systemConfig,
        CacheClearer $cacheClearer
    ) {
        $this->systemConfig = $systemConfig;
        $this->cacheClearer = $cacheClearer;
        $this->client = HttpClient::create(['timeout' => 5]);
    }

    /** Suppress event-driven handshakes for the rest of this request. */
    public function mute(): void
    {
        $this->muted = true;
    }

    /**
     * Validate + normalize the configured API origin (no path, no query, no
     * userinfo — the settings field only accepts an origin). Returns null when
     * it is empty or not a usable http(s) URL.
     *
     * Without this guard a missing scheme turns "/api/..." into a relative URI
     * and the HttpClient throws, which the handshake swallows → a silent,
     * unexplainable "not connected". Kept as a dependency-free static so
     * tests/smoke.php can exercise it without a Shopware container.
     */
    public static function normalizeApiBase(string $raw): ?string
    {
        $apiBase = rtrim(trim($raw), '/');
        if ($apiBase === '' || !preg_match('~^https?://[^\s/?#@]+$~i', $apiBase)) {
            return null;
        }

        return strtolower($apiBase);
    }

    public function onConfigChanged(SystemConfigChangedEvent $event): void
    {
        if ($this->muted) {
            return;
        }

        // SystemConfigChangedEvent fires PER KEY, e.g. "Mxcoan.config.apiKey".
        $key = $event->getKey();
        if (!str_starts_with($key, self::CONFIG_PREFIX)) {
            return;
        }

        if (!in_array(substr($key, strlen(self::CONFIG_PREFIX)), self::HANDSHAKE_KEYS, true)) {
            return;
        }

        $this->connect();
    }

    /**
     * Full handshake. Returns true when the site is verified and the
     * connection state was persisted. $force skips the 5-minute throttle
     * (used by the one-click connect callback, where the click itself is
     * the explicit opt-in and no earlier attempt happened).
     */
    public function connect(bool $force = false): bool
    {
        if (self::$connecting) {
            return false;
        }
        self::$connecting = true;

        try {
            return $this->doConnect($force);
        } finally {
            self::$connecting = false;
        }
    }

    private function doConnect(bool $force = false): bool
    {
        $apiKey = $this->systemConfig->getString(self::CONFIG_PREFIX . 'apiKey');
        $apiBase = self::normalizeApiBase($this->systemConfig->getString(self::CONFIG_PREFIX . 'apiBase')) ?? '';

        if ($apiKey === '' || $apiBase === '') {
            return false;
        }

        // Throttle: one attempt per 5 minutes (config saves fire repeatedly).
        // The one-click callback bypasses it ($force) — the click is the
        // explicit opt-in, same semantics as the WordPress module.
        if (!$force) {
            $lastAttempt = $this->systemConfig->getInt(self::CONFIG_PREFIX . 'lastConnectAttempt');
            if (time() - $lastAttempt < self::CONNECT_THROTTLE_SECONDS) {
                return false;
            }
            $this->systemConfig->set(self::CONFIG_PREFIX . 'lastConnectAttempt', time());
        }

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
        //
        // enableCommerce mirrors the WordPress plugin: a connected Shopware
        // shop always sends server-side order_completed (OrderPaidSubscriber),
        // so the site's commerce flag has to come on with the connection.
        // Without it the dashboard hides the whole Commerce panel and the
        // merchant's revenue silently stays invisible.
        $domain = null;
        try {
            $resp = $this->client->request(
                'POST',
                $apiBase . '/api/integrations/shopware/verify',
                [
                    'headers' => $bearer,
                    'json' => ['enableCommerce' => true],
                ]
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

        // Step 4: persist connection state. Flushing the HTTP cache is only
        // needed when the injected <script> actually changes (head output is
        // baked into cached pages) — re-verifying the same connection must not
        // invalidate a production shop's whole page cache for nothing.
        $wasConnected = $this->systemConfig->getBool(self::CONFIG_PREFIX . 'connected');
        $previousDomain = $this->systemConfig->getString(self::CONFIG_PREFIX . 'connectedDomain');

        $this->systemConfig->set(self::CONFIG_PREFIX . 'connected', true);
        $this->systemConfig->set(self::CONFIG_PREFIX . 'connectedDomain', $domain);

        if (!$wasConnected || $previousDomain !== $domain) {
            $this->flushPageCache();
        }

        return true;
    }

    /**
     * Drop the cached storefront pages so the injected <script> shows up
     * (head output is baked into cached pages).
     *
     * Live-learned the hard way (6.6.10.6): `CacheClearer::clearCache()` does
     * not exist — not in 6.5, not in 6.6. An undefined method there is an
     * Error, thrown right after the connection state was written, so the first
     * successful handshake in a shop blew up the admin config save with a 500
     * while the shop was already marked connected. Real API, checked per
     * version:
     *  - 6.6.4+ : clearHttpCache() — only the storefront page cache
     *  - 6.5.8 / 6.6.0: clear() only (no $clearHttp argument, no
     *    clearHttpCache), which clears the cache adapters
     *
     * call_user_func on an [object, method] callable keeps this compatible
     * without lying to the PHPStan run of the Shopware store review.
     * And it must never break the handshake: a missed flush only means the
     * tracker appears when the pages expire naturally.
     */
    private function flushPageCache(): void
    {
        try {
            $method = method_exists($this->cacheClearer, 'clearHttpCache') ? 'clearHttpCache' : 'clear';
            call_user_func([$this->cacheClearer, $method]);
        } catch (\Throwable $e) {
            // Best-effort: connection state is already correct.
        }
    }
}
