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
 * the Shopify pixel payload: { event_name, d, total, currency, items[, discount_code] }.
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
 * - 15s timeout
 */
class OrderPaidSubscriber implements EventSubscriberInterface
{
    private const API_TIMEOUT = 15;

    private SystemConfigService $systemConfig;
    private EntityRepository $orderRepository;

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
        $apiBase = $this->systemConfig->getString(ConnectService::CONFIG_PREFIX . 'apiBase');
        $domain = $this->systemConfig->getString(ConnectService::CONFIG_PREFIX . 'connectedDomain');
        $enabled = $this->systemConfig->getBool(ConnectService::CONFIG_PREFIX . 'trackingEnabled');
        $connected = $this->systemConfig->getBool(ConnectService::CONFIG_PREFIX . 'connected');

        // Double gate + domain: nothing sends until the shop is connected and
        // tracking is on (same semantics as the PrestaShop module).
        if ($apiKey === '' || $apiBase === '' || $domain === '' || !$enabled || !$connected) {
            return;
        }

        $order = $event->getOrder();
        if (!$this->markOrderSent($order, $event->getContext())) {
            return; // already reported (e.g. paid → cancelled → paid again)
        }

        $items = [];
        $discountCode = '';
        foreach ($order->getLineItems() ?? [] as $lineItem) {
            $type = $lineItem->getType();
            if ($type === 'promotion') {
                if ($discountCode === '') {
                    $discountCode = (string) ($lineItem->getPayload()['code'] ?? $lineItem->getLabel());
                }
                continue;
            }
            if ($type === 'credit') {
                continue;
            }
            $unitPrice = $lineItem->getUnitPrice();
            if ($lineItem->getPrice() !== null) {
                $unitPrice = $lineItem->getPrice()->getUnitPrice();
            }
            $items[] = [
                'title' => (string) $lineItem->getLabel(),
                'price' => (float) $unitPrice,
                'quantity' => (int) $lineItem->getQuantity(),
            ];
        }

        $payload = [
            // Browser ingest shape (n/u/d/p): the /api/event schema is the
            // boundary for BOTH auth paths. Commerce fields live in p —
            // normalizeCommerceProps (pipeline.ts) converts total + per-item
            // prices to EUR at ingest from p.currency.
            'n' => 'order_completed',
            'u' => 'https://' . $domain . '/checkout/finish/' . $order->getDeepLinkCode(),
            'd' => $domain,
            'p' => array_filter([
                'total' => (float) $order->getAmountTotal(),
                'currency' => $order->getCurrency()?->getIsoCode() ?? 'EUR',
                'order_id' => $order->getOrderNumber(),
                'items' => self::itemsProp(json_encode($items)),
                'discount_code' => $discountCode,
            ], static fn ($v) => $v !== '' && $v !== null),
        ];

        try {
            HttpClient::create(['timeout' => self::API_TIMEOUT])->request(
                'POST',
                $apiBase . '/api/event',
                [
                    'headers' => ['Authorization' => 'Bearer ' . $apiKey],
                    'json' => $payload,
                ]
            )->getStatusCode();
        } catch (\Throwable $e) {
            // Best-effort: never block the order flow. This order is marked
            // sent to avoid double counting; the next order reports normally.
        }
    }

    /** Prop values are capped at 2000 chars (trackSchema). A truncated JSON
     * is invalid, so on overflow retry with the first item only, else drop. */
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

    /**
     * Exactly-once guard via the order's customFields (mirrors the PrestaShop
     * module's _mxcoan_order_completed flag). Returns true when this call is
     * the first one (and marks the order); false when it was already sent.
     */
    private function markOrderSent(OrderEntity $order, Context $context): bool
    {
        $customFields = $order->getCustomFields() ?? [];
        if (!empty($customFields['_mxcoan_order_completed'])) {
            return false;
        }

        $this->orderRepository->upsert([[
            'id' => $order->getId(),
            'customFields' => array_merge($customFields, ['_mxcoan_order_completed' => true]),
        ]], $context);

        return true;
    }
}