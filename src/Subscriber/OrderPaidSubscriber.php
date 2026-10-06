<?php declare(strict_types=1);

namespace Mxcoan\Subscriber;

use Mxcoan\Service\ConnectService;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Server-side order_completed, exactly once, payment-flow independent.
 * Mirrors the PrestaShop module's hookActionValidateOrder implementation and
 * the Shopify pixel payload: { total, currency, items[, discount_code] } in
 * the browser ingest shape (n/u/d/p).
 *
 * Listens to state_enter.order_transaction.state.paid — fired when a payment
 * transaction enters "paid" (the core OrderStateChangeEventListener
 * re-dispatches it with the full OrderEntity, lineItems + currency pre-loaded),
 * so redirect-based payment methods (iDEAL, Bancontact, Carte Bancaire) are
 * captured too: the event fires when the payment completes, not when the order
 * is placed.
 *
 * Contract parity with the other plugins (do not regress):
 * - double gate: only sends when trackingEnabled AND connected
 * - best-effort, everything wrapped in try/catch: a failure must never break
 *   checkout or the state machine transition
 * - ~5s timeout: this runs inside the payment-return request (and inside the
 *   state-machine transition), so the MetriXs API may never add seconds of
 *   latency to a customer's checkout
 */
class OrderPaidSubscriber implements EventSubscriberInterface
{
    /** Same order of magnitude as the handshake calls: this is synchronous
     *  work on the customer's payment-return path. */
    private const API_TIMEOUT = 5;

    /** customFields flag marking an order as reported (see markOrderSent). */
    private const SENT_FLAG = '_mxcoan_order_completed';

    private SystemConfigService $systemConfig;
    private EntityRepository $orderRepository;

    // Concrete EntityRepository on purpose: `EntityRepositoryInterface` does
    // not exist since Shopware 6.6, and a type the container cannot satisfy is
    // a TypeError thrown INSIDE the state-machine dispatch — that is a broken
    // payment transition, not a swallowed analytics error. Live-verified on
    // 6.6.10.6 (services.yaml passes `@order.repository` explicitly).
    public function __construct(SystemConfigService $systemConfig, EntityRepository $orderRepository)
    {
        $this->systemConfig = $systemConfig;
        $this->orderRepository = $orderRepository;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'state_enter.order_transaction.state.paid' => 'onTransactionPaid',
        ];
    }

    /** No state-machine-event type hint: the event name is dispatched with
     * OrderStateMachineStateChangeEvent, which does NOT extend the generic
     * StateMachineStateChangeEvent — a hint would throw a TypeError before
     * the try/catch ever runs. */
    public function onTransactionPaid(\Symfony\Contracts\EventDispatcher\Event $event): void
    {
        try {
            $this->sendOrderCompleted($event);
        } catch (\Throwable $e) {
            // Never break checkout or the state transition.
        }
    }

    private function sendOrderCompleted(\Symfony\Contracts\EventDispatcher\Event $event): void
    {
        if (!$event instanceof OrderStateMachineStateChangeEvent) {
            return;
        }

        $apiKey = $this->systemConfig->getString(ConnectService::CONFIG_PREFIX . 'apiKey');
        // Same validation as the handshake: an unusable origin must not even
        // be attempted (and must not mark the order as reported).
        $apiBase = ConnectService::normalizeApiBase($this->systemConfig->getString(ConnectService::CONFIG_PREFIX . 'apiBase')) ?? '';
        $domain = $this->systemConfig->getString(ConnectService::CONFIG_PREFIX . 'connectedDomain');
        $enabled = $this->systemConfig->getBool(ConnectService::CONFIG_PREFIX . 'trackingEnabled');
        $connected = $this->systemConfig->getBool(ConnectService::CONFIG_PREFIX . 'connected');

        // Double gate + domain: nothing sends until the shop is connected and
        // tracking is on (same semantics as the PrestaShop module).
        if ($apiKey === '' || $apiBase === '' || $domain === '' || !$enabled || !$connected) {
            return;
        }

        $order = $event->getOrder();
        if ($this->isOrderSent($order)) {
            return; // already reported (e.g. paid → cancelled → paid again)
        }

        $items = [];
        $discountCode = '';
        foreach ($order->getLineItems() ?? [] as $lineItem) {
            $type = $lineItem->getType();
            if ($type === 'promotion') {
                if ($discountCode === '') {
                    $payloadCode = ($lineItem->getPayload() ?? [])['code'] ?? null;
                    $discountCode = (string) ($payloadCode ?? $lineItem->getLabel());
                }
                continue;
            }
            if ($type === 'credit') {
                continue;
            }
            $quantity = (int) $lineItem->getQuantity();
            // CalculatedPrice::getUnitPrice() on a STORED order line item is
            // the price in the order's own tax state — i.e. gross for the
            // normal B2C case, matching getAmountTotal() on the same order.
            // Verified on a live 6.6 order: 2 × unitPrice 19.99, position
            // totalPrice 39.98, amountTotal 39.98, amountNet 33.60, tax 6.38.
            // Do NOT "add the tax back" here; that would double-count VAT.
            $unitPrice = $lineItem->getUnitPrice();
            if ($lineItem->getPrice() !== null) {
                $unitPrice = $lineItem->getPrice()->getUnitPrice();
            }
            $items[] = [
                'title' => (string) $lineItem->getLabel(),
                'price' => (float) $unitPrice,
                'quantity' => $quantity,
            ];
        }

        $payload = [
            // Browser ingest shape (n/u/d/p): the /api/event schema is the
            // boundary for BOTH auth paths. Commerce fields live in p —
            // normalizeCommerceProps (pipeline.ts) converts total + per-item
            // prices to EUR at ingest from p.currency.
            'n' => 'order_completed',
            // Deliberately WITHOUT the order's deepLinkCode: that code is an
            // access credential (Shopware serves /checkout/finish/{deepLinkCode}
            // and /account/order/show/{deepLinkCode} to guests — it is how the
            // order confirmation e-mail lets a guest open their order). The
            // path is stored verbatim in ClickHouse and shown in the dashboard
            // pages report, so embedding it would hand anyone with read access
            // a working link into customer order details, for the whole
            // retention window. The order number is already in p.order_id.
            'u' => 'https://' . $domain . '/checkout/finish',
            'd' => $domain,
            'p' => array_filter([
                'total' => (float) $order->getAmountTotal(),
                'currency' => $order->getCurrency()?->getIsoCode() ?? 'EUR',
                'order_id' => $order->getOrderNumber(),
                'items' => self::itemsProp(json_encode($items)),
                'discount_code' => $discountCode,
            ], static fn ($v) => $v !== '' && $v !== null),
        ];

        // Exactly-once the other way round from "mark, then send": marking
        // before the request means one blipping MetriXs API silently deletes
        // that order's revenue forever (the flag was already written). Send
        // first and mark only on a 2xx, so an order that could not be
        // reported stays eligible for the next "paid" transition. The
        // remaining window (sent OK, flag write failed, shop re-enters paid)
        // is far rarer than an API outage, and over-counts instead of losing
        // revenue.
        if (!$this->send($payload, $apiBase, $apiKey)) {
            return;
        }

        $this->markOrderSent($order, $event->getContext());
    }

    /** POST /api/event; true only on a 2xx response. */
    private function send(array $payload, string $apiBase, string $apiKey): bool
    {
        try {
            $resp = HttpClient::create(['timeout' => self::API_TIMEOUT])->request(
                'POST',
                $apiBase . '/api/event',
                [
                    'headers' => ['Authorization' => 'Bearer ' . $apiKey],
                    'json' => $payload,
                ]
            );
            // Forcing the status also completes the lazy Symfony HttpClient
            // request (see the uninstall lifecycle note in Mxcoan.php).
            return $resp->getStatusCode() < 300;
        } catch (\Throwable $e) {
            // Best-effort: never block the order flow. The order stays
            // unmarked, so a later "paid" transition reports it.
            return false;
        }
    }

    /** Prop values are capped at 2000 chars (trackSchema/MAX_VALUE_LENGTH).
     * A truncated JSON is invalid, so on overflow retry with the first item
     * only, else drop the prop (revenue stays correct, top-products partial). */
    private static function itemsProp(string $json): string
    {
        if (strlen($json) <= 2000) {
            return $json;
        }
        $decoded = json_decode($json, true);
        if (is_array($decoded) && isset($decoded[0])) {
            $single = json_encode([$decoded[0]]);
            if ($single !== false && strlen($single) <= 2000) {
                return $single;
            }
        }
        return '';
    }

    /** Has this order already been reported? Reads the order's customFields —
     *  the same flag the PrestaShop module keeps on its order meta. */
    private function isOrderSent(OrderEntity $order): bool
    {
        $customFields = $order->getCustomFields() ?? [];

        return !empty($customFields[self::SENT_FLAG]);
    }

    /**
     * Exactly-once guard via the order's customFields (mirrors the PrestaShop
     * module's _mxcoan_order_completed flag). Shopware allows writing
     * undeclared custom fields through the DAL (they only need a definition
     * for admin editing/validation), which keeps the plugin install-free.
     * The flag is not shown in the admin and survives an uninstall — see the
     * README note; we deliberately never run a mass UPDATE over `order` just
     * to erase a boolean.
     */
    private function markOrderSent(OrderEntity $order, Context $context): void
    {
        $customFields = $order->getCustomFields() ?? [];
        $customFields[self::SENT_FLAG] = true;

        $this->orderRepository->upsert([[
            'id' => $order->getId(),
            'customFields' => $customFields,
        ]], $context);
    }
}
