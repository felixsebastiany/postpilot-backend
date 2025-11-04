<?php
declare(strict_types=1);

namespace PostPilot\StripeCustom\Model\GraphQL\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Customer\Model\Session as CustomerSession;
use StripeIntegration\Payments\Model\Config;

class CancelSubscription implements ResolverInterface
{
    private CustomerSession $customerSession;
    private Config $stripeConfig;

    public function __construct(
        CustomerSession $customerSession,
        Config $stripeConfig
    ) {
        $this->customerSession = $customerSession;
        $this->stripeConfig = $stripeConfig;
    }

    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        if (!$this->customerSession->isLoggedIn()) {
            throw new \GraphQL\Error\Error((string) __('Customer not logged in'));
        }

        $input = $args['input'];
        $subscriptionId = $input['subscription_id'];
        $cancelAtPeriodEnd = $input['cancel_at_period_end'] ?? true;

        try {
            // Initialize Stripe
            if (!$this->stripeConfig->initStripe()) {
                throw new \Exception('Erro ao inicializar Stripe. Verifique se as chaves API estão configuradas corretamente.');
            }

            // Get Stripe client instance
            $stripeClient = $this->stripeConfig->getStripeClient();
            if (!$stripeClient) {
                throw new \Exception('Erro ao obter cliente Stripe. Verifique se as chaves API estão configuradas corretamente.');
            }

            if ($cancelAtPeriodEnd) {
                // Cancel at period end
                $stripeClient->subscriptions->update($subscriptionId, [
                    'cancel_at_period_end' => true,
                ]);
            } else {
                // Cancel immediately
                $stripeClient->subscriptions->cancel($subscriptionId);
            }

            return true;

        } catch (\Exception $e) {
            throw new \GraphQL\Error\Error((string) __('Error canceling subscription: %1', $e->getMessage()));
        }
    }
}
