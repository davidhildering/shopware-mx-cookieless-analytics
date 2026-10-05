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
 */
class ConnectController extends StorefrontController
{
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
     * bearer token (the config component sends it).
     */
    #[Route(
        path: '/api/_action/mxcoan/connect/start',
        name: 'api.action.mxcoan.connect.start',
        methods: ['POST'],
        defaults: ['_routeScope' => ['administration']]
    )]
    public function start(Request $request): JsonResponse
    {
        $apiBase = rtrim($this->systemConfig->getString('Mxcoan.config.apiBase'), '/');
        if ($apiBase === '') {
            return new JsonResponse(['error' => 'api_base_missing'], 400);
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
        $this->systemConfig->set('Mxcoan.config.connectState', $state);
        $this->systemConfig->set('Mxcoan.config.connectStateExpires', time() + self::STATE_TTL);

        $url = $apiBase . '/connect/shopware?' . http_build_query([
            'state' => $state,
            'site' => $site,
            'back' => $back,
        ]);

        return new JsonResponse(['url' => $url]);
    }

    /**
     * Step 4 (storefront callback from the dashboard redirect).
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
        $storedState = $this->systemConfig->getString('Mxcoan.config.connectState');
        $expires = $this->systemConfig->getInt('Mxcoan.config.connectStateExpires');
        $this->systemConfig->set('Mxcoan.config.connectState', '');
        $this->systemConfig->set('Mxcoan.config.connectStateExpires', 0);

        if ($code === '' || $storedState === ''
            || !hash_equals($storedState, $state)
            || time() > $expires) {
            return $this->backToAdmin();
        }

        $apiBase = rtrim($this->systemConfig->getString('Mxcoan.config.apiBase'), '/');
        if ($apiBase === '') {
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
        $this->systemConfig->set('Mxcoan.config.apiKey', $apiKey);
        $this->systemConfig->set('Mxcoan.config.trackingEnabled', true);
        $this->systemConfig->set('Mxcoan.config.lastConnectAttempt', 0);

        // Full handshake, unthrottled: challenge → serve → verify.
        $this->connectService->connect(true);

        return $this->backToAdmin();
    }

    /** The administration config page for this plugin. */
    private function backToAdmin(): RedirectResponse
    {
        return new RedirectResponse('/admin#/sw/extension/config/Mxcoan');
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