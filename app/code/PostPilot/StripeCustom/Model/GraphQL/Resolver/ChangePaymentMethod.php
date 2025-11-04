<?php
declare(strict_types=1);

namespace PostPilot\StripeCustom\Model\GraphQL\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Customer\Model\Session as CustomerSession;
use StripeIntegration\Payments\Model\StripeCustomer;
use StripeIntegration\Payments\Model\Config;
use StripeIntegration\Payments\Model\Stripe\SubscriptionFactory;
use Psr\Log\LoggerInterface;

class ChangePaymentMethod implements ResolverInterface
{
    private CustomerSession $customerSession;
    private StripeCustomer $stripeCustomer;
    private Config $stripeConfig;
    private SubscriptionFactory $stripeSubscriptionFactory;
    private LoggerInterface $logger;

    public function __construct(
        CustomerSession $customerSession,
        StripeCustomer $stripeCustomer,
        Config $stripeConfig,
        SubscriptionFactory $stripeSubscriptionFactory,
        LoggerInterface $logger
    ) {
        $this->customerSession = $customerSession;
        $this->stripeCustomer = $stripeCustomer;
        $this->stripeConfig = $stripeConfig;
        $this->stripeSubscriptionFactory = $stripeSubscriptionFactory;
        $this->logger = $logger;
    }

    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        if (!$this->customerSession->isLoggedIn()) {
            throw new \GraphQL\Error\Error((string) __('Customer not logged in'));
        }

        $input = $args['input'];
        $subscriptionId = $input['subscription_id'];
        $paymentMethodId = $input['payment_method_id'];

        try {
            // Load customer model
            $customerId = $this->customerSession->getCustomerId();
            $this->stripeCustomer->load($customerId, 'customer_id');

            if (!$this->stripeCustomer->getStripeId()) {
                throw new \Exception('Customer does not have a Stripe account');
            }

            // Verify customer owns this subscription
            if (!$this->stripeCustomer->ownsSubscriptionId($subscriptionId)) {
                throw new \Exception('Customer does not own this subscription');
            }

            // Initialize Stripe
            if (!$this->stripeConfig->initStripe()) {
                throw new \Exception('Failed to initialize Stripe');
            }

            // Get subscription model
            $stripeSubscriptionModel = $this->stripeSubscriptionFactory->create()->fromSubscriptionId($subscriptionId);

            // Attach payment method to customer (if not already attached)
            try {
                $this->stripeCustomer->attachPaymentMethod($paymentMethodId);
            } catch (\Exception $e) {
                // Payment method might already be attached, continue
                $this->logger->info("Payment method attachment: " . $e->getMessage());
            }

            // Update subscription with new payment method
            $stripeSubscriptionModel->update(['default_payment_method' => $paymentMethodId]);

            // Get updated subscription to return (expand payment method)
            $stripeClient = $this->stripeConfig->getStripeClient();
            $subscription = $stripeClient->subscriptions->retrieve($subscriptionId, [
                'expand' => ['default_payment_method']
            ]);

            // Format and return subscription data
            return $this->formatSubscription($subscription);

        } catch (\Exception $e) {
            throw new \GraphQL\Error\Error((string) __('Error changing payment method: %1', $e->getMessage()));
        }
    }

    private function formatSubscription($subscription): array
    {
        $defaultPaymentMethod = null;
        if (isset($subscription->default_payment_method)) {
            $defaultPaymentMethod = $this->formatPaymentMethod($subscription->default_payment_method);
        } elseif (isset($subscription->default_source)) {
            // Legacy support for card sources
            $defaultPaymentMethod = [
                'id' => $subscription->default_source,
                'type' => 'card',
            ];
        }

        // Get billing period from subscription items
        $billingPeriod = 'monthly';
        if (!empty($subscription->items->data)) {
            $item = $subscription->items->data[0];
            if (isset($item->price->recurring->interval)) {
                $billingPeriod = $item->price->recurring->interval === 'year' ? 'yearly' : 'monthly';
            }
        }

        return [
            'id' => $subscription->id,
            'status' => $subscription->status,
            'plan_name' => !empty($subscription->items->data) ? $subscription->items->data[0]->price->product->name ?? 'N/A' : 'N/A',
            'current_period_start' => date('Y-m-d H:i:s', $subscription->current_period_start),
            'current_period_end' => date('Y-m-d H:i:s', $subscription->current_period_end),
            'amount' => !empty($subscription->items->data) ? ($subscription->items->data[0]->price->unit_amount / 100) : 0,
            'currency' => $subscription->currency ?? 'brl',
            'billing_period' => $billingPeriod,
            'cancel_at_period_end' => $subscription->cancel_at_period_end ?? false,
            'payment_method' => $defaultPaymentMethod,
        ];
    }

    private function formatPaymentMethod($paymentMethod): ?array
    {
        if (!$paymentMethod) {
            return null;
        }

        // If it's an object (expanded), use it directly
        if (is_object($paymentMethod)) {
            return $this->formatPaymentMethodObject($paymentMethod);
        }

        // If it's just an ID string, try to retrieve it
        if (is_string($paymentMethod)) {
            try {
                $stripeClient = $this->stripeConfig->getStripeClient();
                $paymentMethodObj = $stripeClient->paymentMethods->retrieve($paymentMethod);
                return $this->formatPaymentMethodObject($paymentMethodObj);
            } catch (\Exception $e) {
                $this->logger->error("Error retrieving payment method {$paymentMethod}: " . $e->getMessage());
                return [
                    'id' => $paymentMethod,
                    'type' => 'unknown',
                ];
            }
        }

        return null;
    }

    private function formatPaymentMethodObject($paymentMethod): array
    {
        $formatted = [
            'id' => $paymentMethod->id,
            'type' => $paymentMethod->type,
        ];

        if ($paymentMethod->type === 'card' && isset($paymentMethod->card)) {
            $formatted['card'] = [
                'brand' => $paymentMethod->card->brand ?? null,
                'last4' => $paymentMethod->card->last4 ?? null,
                'exp_month' => $paymentMethod->card->exp_month ?? null,
                'exp_year' => $paymentMethod->card->exp_year ?? null,
            ];
        }

        return $formatted;
    }
}

