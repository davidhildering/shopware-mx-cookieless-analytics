<?php declare(strict_types=1);

namespace Mxcoan\Controller;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public challenge endpoint for the MetriXs site verification handshake.
 *
 * Contract (mirrors the WordPress/Grav/Craft/PrestaShop plugins):
 *   1. Plugin → API  POST /api/integrations/shopware/challenge (Bearer key)
 *      API stores a fresh random token in sites.verification_token and
 *      returns it.
 *   2. Plugin saves the token in SystemConfig; this controller serves it at
 *      GET https://<domain>/mxcoan/challenge.
 *   3. Plugin → API  POST /api/integrations/shopware/verify (Bearer key)
 *      API fetches this URL and compares (constant-time) with the stored
 *      token. Match → site.verified = true. Only someone who controls the
 *      domain can make this endpoint return the token.
 *
 * The token is cleared by ConnectService once the handshake completes.
 *
 * Freshness is the whole security property of this endpoint: a stored
 * response would let a later handshake read a PREVIOUS token. Shopware only
 * HTTP-caches routes whose `_httpCache` default is true (verified on
 * 6.6.10.6), so this route is never cached — but 6.7 introduced per-area
 * caching policies, so the response also declares itself uncacheable. Cheap
 * insurance, keep it.
 */
class ChallengeController extends StorefrontController
{
    public const CONFIG_PREFIX = 'Mxcoan.config.';

    /** @var array<string,string> */
    private const NO_STORE_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, private, max-age=0',
        'Pragma' => 'no-cache',
    ];

    private SystemConfigService $systemConfig;

    public function __construct(SystemConfigService $systemConfig)
    {
        $this->systemConfig = $systemConfig;
    }

    #[Route(
        path: '/mxcoan/challenge',
        name: 'frontend.mxcoan.challenge',
        methods: ['GET'],
        defaults: ['_routeScope' => ['storefront']]
    )]
    public function challenge(): JsonResponse
    {
        $challenge = $this->systemConfig->getString(self::CONFIG_PREFIX . 'challengeToken');
        if ($challenge === '') {
            return new JsonResponse(['error' => 'no_challenge'], 404, self::NO_STORE_HEADERS);
        }

        return new JsonResponse(['challenge' => $challenge], 200, self::NO_STORE_HEADERS);
    }
}