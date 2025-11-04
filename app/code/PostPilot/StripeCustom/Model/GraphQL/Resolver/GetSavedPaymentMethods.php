<?php
declare(strict_types=1);

namespace PostPilot\StripeCustom\Model\GraphQL\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Customer\Model\Session as CustomerSession;
use StripeIntegration\Payments\Model\StripeCustomer;
use StripeIntegration\Payments\Model\Config;
use Psr\Log\LoggerInterface;

class GetSavedPaymentMethods implements ResolverInterface
{
    private CustomerSession $customerSession;
    private StripeCustomer $stripeCustomer;
    private Config $stripeConfig;
    private LoggerInterface $logger;

    public function __construct(
        CustomerSession $customerSession,
        StripeCustomer $stripeCustomer,
        Config $stripeConfig,
        LoggerInterface $logger
    ) {
        $this->customerSession = $customerSession;
        $this->stripeCustomer = $stripeCustomer;
        $this->stripeConfig = $stripeConfig;
        $this->logger = $logger;
    }

    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        if (!$this->customerSession->isLoggedIn()) {
            throw new \GraphQL\Error\Error((string) __('Customer not logged in'));
        }

        try {
            // Load customer model
            $customerId = $this->customerSession->getCustomerId();
            $this->stripeCustomer->load($customerId, 'customer_id');

            if (!$this->stripeCustomer->getStripeId()) {
                // No Stripe customer yet, return empty array
                return [];
            }

            // Get saved payment methods
            $paymentMethods = $this->stripeCustomer->getSavedPaymentMethods(['card'], false);

            // Format payment methods for GraphQL response
            $formattedMethods = [];
            
            if (!empty($paymentMethods['card'])) {
                foreach ($paymentMethods['card'] as $method) {
                    $formattedMethods[] = $this->formatPaymentMethod($method);
                }
            }

            return $formattedMethods;

        } catch (\Exception $e) {
            $this->logger->error("Error getting saved payment methods: " . $e->getMessage());
            throw new \GraphQL\Error\Error((string) __('Error getting saved payment methods: %1', $e->getMessage()));
        }
    }

    private function formatPaymentMethod($paymentMethod): array
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

