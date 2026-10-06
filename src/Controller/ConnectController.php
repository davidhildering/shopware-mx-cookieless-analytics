<?php declare(strict_types=1);

namespace Mxcoan\Controller;

use Doctrine\DBAL\Connection;
use Mxcoan\Service\ConnectService;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One-click connect (same flow as the WordPress/Grav/Craft/PrestaShop
 * plugins — the dashboard half lives in the MetriXs dashboard under
 * /connect/shopware, see apps/api/src/routes/integrations-connect.ts):
 *
 *   1. Admin clicks "Connect with MetriXs" in the plugin config (admin JS
 *      component) → POST /api/_action/mxcoan/connect/start (admin bearer) →
 *      this controller stores a single-use state and returns the dashboard
 *      URL (apiBase/connect/shopware?state&site&back).
 *   2. Dashboard: inline login/register, site selection, plan if needed.
 *   3. Dashboard redirects to `back` (= the storefront callback below) with
 *      ?code=…&state=….
 *   4. Callback: validate the single-use state, exchange { code, state } for
 *      the site-scoped API key (returned ONCE), store it, turn tracking ON
 *      (the connect click is the explicit opt-in) and run the challenge →
 *      verify handshake, then redirect back into the administration.
 *
 * Security parity with the WP module (do not regress):
 * - state is CSRF token AND code-binding secret; cleared BEFORE any use;
 *   15-minute TTL
 * - the exchange endpoint itself is unauthenticated by design (the plugin
 *   server proves itself with the { code, state } pair); verification of the
 *   exchanged key requires domain control, so a hijacked callback can never
 *   connect a foreign site
 *
 * Route-scope contract (live-verified on 6.6.10.6, do not "simplify"):
 * the start action is called by the administration SPA through the Admin API
 * (`/api/...`). Shopware filters by route scope (a storefront-scoped route
 * under /api is a 404), and BOTH `api` and `administration` resolve for
 * /api/_action/... — `api` is what core uses for its own `_action` routes,
 * `administration` is what the SPA session expects. Declaring both keeps the
 * button working regardless of which area serves the request.
 */
class ConnectController extends StorefrontController
{
    public const CONFIG_PREFIX = 'Mxcoan.config.';
    public const STATE_TTL = 900; // 15 minutes, like the WP module

    private SystemConfigService $systemConfig;
    private ConnectService $connectService;
    private Connection $connection;

    public function __construct(
        SystemConfigService $systemConfig,
        ConnectService $connectService,
        Connection $connection
    ) {
        $this->systemConfig = $systemConfig;
        $this->connectService = $connectService;
        $this->connection = $connection;
    }

    /**
     * Step 1 (admin-initiated): store the single-use state and return the
     * dashboard connect URL. Admin API scope: authenticated by the admin
     * bearer token (the config component sends it) and ACL-guarded with the
     * same privilege core requires to save this very config
     * (`system_config:update`), so a restricted admin role cannot start a
     * connect on its own.
     */
    #[Route(
        path: '/api/_action/mxcoan/connect/start',
        name: 'api.action.mxcoan.connect.start',
        methods: ['POST'],
        defaults: [
            '_routeScope' => ['api', 'administration'],
            '_acl' => ['system_config:update'],
        ]
    )]
    public function start(Request $request): JsonResponse
    {
        $apiBase = $this->apiBase();
        if ($apiBase === null) {
            return new JsonResponse(['error' => 'api_base_invalid'], 400);
        }

        $domainUrl = $this->firstSalesChannelDomainUrl($request);
        if ($domainUrl === null) {
            return new JsonResponse(['error' => 'no_sales_channel_domain'], 400);
        }

        $parsed = parse_url($domainUrl);
        $scheme = ($parsed['scheme'] ?? 'https') === 'http' ? 'http' : 'https';
        $host = $parsed['host'] ?? '';
        if ($host === '') {
            return new JsonResponse(['error' => 'no_sales_channel_domain'], 400);
        }
        // Site param = bare hostname (no port): matches the dashboard's
        // site lookup and its back-host validation (hostname-only compare).
        $site = strtolower($host);

        $back = $scheme . '://' . $host
            . (isset($parsed['port']) ? ':' . $parsed['port'] : '')
            . '/mxcoan/connect';

        $state = bin2hex(random_bytes(16));
        $this->systemConfig->set(self::CONFIG_PREFIX . 'connectState', $state);
        $this->systemConfig->set(self::CONFIG_PREFIX . 'connectStateExpires', time() + self::STATE_TTL);

        $url = $apiBase . '/connect/shopware?' . http_build_query([
            'state' => $state,
            'site' => $site,
            'back' => $back,
        ]);

        return new JsonResponse(['url' => $url]);
    }

    /**
     * Step 4 (storefront callback from the dashboard redirect).
     *
     * Storefront scope on purpose: the callback must be reachable on the
     * shop's own domain (that is what the `back` host check in the dashboard
     * allows), and it has to run before the key is ever used.
     */
    #[Route(
        path: '/mxcoan/connect',
        name: 'frontend.mxcoan.connect',
        methods: ['GET'],
        defaults: ['_routeScope' => ['storefront']]
    )]
    public function callback(Request $request): Response
    {
        $code = (string) preg_replace('/[^0-9a-f]/', '', (string) $request->query->get('code', ''));
        $state = (string) preg_replace('/[^A-Za-z0-9]/', '', (string) $request->query->get('state', ''));

        // Single-use: clear BEFORE any use.
        $storedState = $this->systemConfig->getString(self::CONFIG_PREFIX . 'connectState');
        $expires = $this->systemConfig->getInt(self::CONFIG_PREFIX . 'connectStateExpires');
        $this->systemConfig->set(self::CONFIG_PREFIX . 'connectState', '');
        $this->systemConfig->set(self::CONFIG_PREFIX . 'connectStateExpires', 0);

        if ($code === '' || $storedState === ''
            || !hash_equals($storedState, $state)
            || time() > $expires) {
            return $this->backToAdmin();
        }

        $apiBase = $this->apiBase();
        if ($apiBase === null) {
            return $this->backToAdmin();
        }

        // Exchange { code, state } → the site-scoped API key (returned once).
        try {
            $resp = \Symfony\Component\HttpClient\HttpClient::create(['timeout' => 10])->request(
                'POST',
                $apiBase . '/api/integrations/shopware/exchange',
                ['json' => ['code' => $code, 'state' => $state]]
            );
            $status = $resp->getStatusCode();
            $body = $status === 200 ? $resp->toArray(false) : [];
        } catch (\Throwable $e) {
            return $this->backToAdmin();
        }

        $apiKey = (string) preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($body['apiKey'] ?? ''));
        if ($status !== 200 || $apiKey === '') {
            return $this->backToAdmin();
        }

        // Store the key and turn tracking ON: the connect click is the
        // explicit opt-in (same semantics as the WordPress module).
        //
        // mute(): every set() below dispatches SystemConfigChangedEvent, and
        // ConnectService reacts to config saves. Without the mute the
        // handshake would run once per write (once per key, in the SAME
        // request) — three handshakes and three cache flushes for one click.
        // One explicit, unthrottled handshake is all we need.
        $this->connectService->mute();

        // A fresh key means a possibly different MetriXs site: drop the old
        // verification state instead of letting the tracker keep injecting a
        // data-domain that belongs to the previous connection. The handshake
        // below re-establishes both from the site the key belongs to.
        $this->systemConfig->delete(self::CONFIG_PREFIX . 'connected');
        $this->systemConfig->delete(self::CONFIG_PREFIX . 'connectedDomain');
        $this->systemConfig->set(self::CONFIG_PREFIX . 'apiKey', $apiKey);
        $this->systemConfig->set(self::CONFIG_PREFIX . 'trackingEnabled', true);
        $this->systemConfig->set(self::CONFIG_PREFIX . 'lastConnectAttempt', 0);

        // Full handshake, unthrottled: challenge → serve → verify.
        $this->connectService->connect(true);

        return $this->backToAdmin();
    }

    /** The administration config page for this plugin. */
    private function backToAdmin(): RedirectResponse
    {
        return new RedirectResponse('/admin#/sw/extension/config/Mxcoan');
    }

    /**
     * Validated absolute origin of the MetriXs API (see
     * ConnectService::normalizeApiBase — shared with the handshake so both
     * sides interpret the settings field the same way).
     */
    private function apiBase(): ?string
    {
        return ConnectService::normalizeApiBase($this->systemConfig->getString(self::CONFIG_PREFIX . 'apiBase'));
    }

    /** Best sales channel domain URL: the one matching the admin request's
     *  host (multi-domain shops connect from the domain the admin is on),
     *  falling back to the first parsable URL (skips placeholders like
     *  `default.headless0`). */
    private function firstSalesChannelDomainUrl(Request $request): ?string
    {
        try {
            $rows = $this->connection->fetchFirstColumn(
                'SELECT url FROM sales_channel_domain ORDER BY created_at ASC'
            );
        } catch (\Throwable $e) {
            return null;
        }

        $requestAuthority = $request->getScheme() . '://' . $request->getHost() . ':'
            . $request->getPort();
        $normalize = static function (string $url): ?string {
            $p = parse_url($url);
            if (!is_array($p) || !isset($p['host'])) {
                return null;
            }
            $scheme = ($p['scheme'] ?? 'https') === 'http' ? 'http' : 'https';
            $port = $p['port'] ?? ($scheme === 'http' ? 80 : 443);

            return $scheme . '://' . $p['host'] . ':' . $port;
        };

        $first = null;
        foreach ($rows as $row) {
            if (!is_string($row)) {
                continue;
            }
            $authority = $normalize($row);
            if ($authority === null) {
                continue;
            }
            $first ??= $row;
            if ($authority === $requestAuthority) {
                return $row;
            }
        }

        return $first;
    }
}
