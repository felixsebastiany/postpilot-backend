<?php
declare(strict_types=1);

namespace PostPilot\StripeCustom\Model\GraphQL\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Catalog\Api\ProductRepositoryInterface;
use StripeIntegration\Payments\Model\StripeCustomer;
use StripeIntegration\Payments\Model\Config;
use Magento\Framework\Exception\LocalizedException;

class CreateSubscription implements ResolverInterface
{
    private CustomerSession $customerSession;
    private ProductRepositoryInterface $productRepository;
    private StripeCustomer $stripeCustomer;
    private Config $stripeConfig;

    public function __construct(
        CustomerSession $customerSession,
        ProductRepositoryInterface $productRepository,
        StripeCustomer $stripeCustomer,
        Config $stripeConfig
    ) {
        $this->customerSession = $customerSession;
        $this->productRepository = $productRepository;
        $this->stripeCustomer = $stripeCustomer;
        $this->stripeConfig = $stripeConfig;
    }

    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        if (!$this->customerSession->isLoggedIn()) {
            throw new \GraphQL\Error\Error((string) __('Customer not logged in'));
        }

        $input = $args['input'];
        $productId = $input['product_id'];
        $paymentMethodId = $input['payment_method_id'];
        $billingPeriod = $input['billing_period'];
        $setupIntentId = $input['setup_intent_id'] ?? null;

        try {
            // Get product
            $product = $this->productRepository->getById($productId);
            
            // Get or create Stripe customer
            $customerId = $this->customerSession->getCustomerId();
            $stripeCustomerId = $this->stripeCustomer->getStripeId($customerId);
            
            if (!$stripeCustomerId) {
                $stripeCustomerId = $this->stripeCustomer->createStripeCustomer($customerId);
            }

            // Initialize Stripe
            if (!$this->stripeConfig->initStripe()) {
                throw new \Exception('Erro ao inicializar Stripe. Verifique se as chaves API estão configuradas corretamente.');
            }

            // Get Stripe client instance
            $stripeClient = $this->stripeConfig->getStripeClient();
            if (!$stripeClient) {
                throw new \Exception('Erro ao obter cliente Stripe. Verifique se as chaves API estão configuradas corretamente.');
            }

            // Verify and attach payment method to customer
            // When a Setup Intent is confirmed, the payment method should be automatically attached
            // However, we need to verify it's attached to the correct customer
            // If setup_intent_id is provided, we can verify the Setup Intent was created for this customer
            if ($setupIntentId) {
                try {
                    $setupIntent = $stripeClient->setupIntents->retrieve($setupIntentId);
                    
                    // Verify the Setup Intent was created for this customer
                    if ($setupIntent->customer !== $stripeCustomerId) {
                        throw new \Exception(
                            'O Setup Intent foi criado para outro cliente. ' .
                            'Por favor, volte à tela de checkout e complete o processo novamente.'
                        );
                    }
                    
                    // Verify the Setup Intent was confirmed successfully
                    if ($setupIntent->status !== 'succeeded') {
                        throw new \Exception(
                            'O Setup Intent não foi confirmado corretamente. ' .
                            'Por favor, volte à tela de checkout e complete o processo novamente.'
                        );
                    }
                    
                    // Verify the payment method from Setup Intent matches the one provided
                    if ($setupIntent->payment_method !== $paymentMethodId) {
                        throw new \Exception(
                            'O método de pagamento não corresponde ao Setup Intent. ' .
                            'Por favor, volte à tela de checkout e complete o processo novamente.'
                        );
                    }
                    
                    // If Setup Intent verification passes, the payment method should be correctly attached
                    // Continue to verify the payment method is attached
                } catch (\Stripe\Exception\InvalidRequestException $e) {
                    // If Setup Intent doesn't exist or has errors, log but continue with payment method verification
                    // This allows the subscription to proceed if Setup Intent verification fails
                }
            }
            
            try {
                $paymentMethod = $stripeClient->paymentMethods->retrieve($paymentMethodId);
                
                // Check if payment method is attached to a customer
                if ($paymentMethod->customer) {
                    // Payment method is attached to a customer
                    if ($paymentMethod->customer === $stripeCustomerId) {
                        // Already attached to the correct customer, continue
                    } else {
                        // Payment method is attached to a different customer
                        // This shouldn't happen if Setup Intent was created and confirmed correctly
                        // The most likely cause is that:
                        // 1. The Setup Intent was confirmed without the customer being specified
                        // 2. The payment method was created previously for another customer
                        // 3. There's a mismatch between the customer used in Setup Intent and CreateSubscription
                        
                        // Log the issue for debugging
                        // We cannot reuse a payment method attached to another customer
                        // The user needs to complete the checkout again to create a new payment method
                        throw new \Exception(
                            'O método de pagamento está associado a outra conta. ' .
                            'Isso não deveria acontecer. Por favor, volte à tela de checkout e complete o processo novamente. ' .
                            'Se o problema persistir, entre em contato com o suporte.'
                        );
                    }
                } else {
                    // Payment method is not attached to any customer
                    // This should not happen if Setup Intent was created correctly with customer
                    // But we'll attach it here to ensure it's attached
                    try {
                        $stripeClient->paymentMethods->attach($paymentMethodId, [
                            'customer' => $stripeCustomerId,
                        ]);
                    } catch (\Stripe\Exception\InvalidRequestException $attachError) {
                        // Handle different attach error scenarios
                        $errorMessage = $attachError->getMessage();
                        
                        // Check if payment method was previously used without being attached
                        if (strpos($errorMessage, 'previously used') !== false || 
                            strpos($errorMessage, 'detached') !== false) {
                            throw new \Exception(
                                'Este método de pagamento não pode ser reutilizado. ' .
                                'Por favor, volte à tela de checkout e complete o processo novamente.'
                            );
                        }
                        
                        // Check if payment method is already attached (race condition)
                        if (strpos($errorMessage, 'already been attached') !== false) {
                            // Verify it's attached to the correct customer
                            try {
                                $paymentMethod = $stripeClient->paymentMethods->retrieve($paymentMethodId);
                                if ($paymentMethod->customer === $stripeCustomerId) {
                                    // Good, it's now attached to the correct customer
                                } else {
                                    throw new \Exception(
                                        'O método de pagamento está associado a outra conta. ' .
                                        'Por favor, volte à tela de checkout e complete o processo novamente.'
                                    );
                                }
                            } catch (\Exception $verifyError) {
                                throw new \Exception(
                                    'Não foi possível verificar o método de pagamento. ' .
                                    'Por favor, volte à tela de checkout e complete o processo novamente.'
                                );
                            }
                        } else {
                            // Other attach errors
                            throw new \Exception(
                                'Erro ao associar método de pagamento ao cliente: ' . $errorMessage
                            );
                        }
                    }
                }
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Handle Stripe API errors
                $errorMessage = $e->getMessage();
                
                if (strpos($errorMessage, 'previously used') !== false || 
                    strpos($errorMessage, 'detached') !== false) {
                    throw new \Exception(
                        'Este método de pagamento não pode ser reutilizado. ' .
                        'Por favor, volte à tela de checkout e complete o processo novamente.'
                    );
                }
                
                if (strpos($errorMessage, 'attached') !== false) {
                    throw new \Exception(
                        'O método de pagamento já está associado a outra conta. ' .
                        'Por favor, volte à tela de checkout e complete o processo novamente.'
                    );
                }
                
                throw new \Exception('Erro ao verificar método de pagamento: ' . $errorMessage);
            }

            // Create or get price for the product
            $priceId = $this->getOrCreatePriceId($product, $billingPeriod, $stripeClient);

            // Create subscription
            $subscription = $stripeClient->subscriptions->create([
                'customer' => $stripeCustomerId,
                'items' => [
                    [
                        'price' => $priceId,
                    ],
                ],
                'default_payment_method' => $paymentMethodId,
                'expand' => ['latest_invoice.payment_intent'],
            ]);

            // Format response
            return $this->formatCustomerSubscription($subscription);

        } catch (\Exception $e) {
            throw new \GraphQL\Error\Error((string) __('Error creating subscription: %1', $e->getMessage()));
        }
    }

    private function getOrCreatePriceId($product, string $billingPeriod, $stripeClient): string
    {
        $interval = $billingPeriod === 'yearly' ? 'year' : 'month';
        $amount = (int) ($product->getPrice() * 100); // Convert to cents
        
        // Search for existing price with metadata matching this product and billing period
        $productSku = strtolower(str_replace([' ', '-'], '_', $product->getSku()));
        
        try {
            // List prices and search for one matching our product SKU and billing period in metadata
            $prices = $stripeClient->prices->search([
                'query' => "metadata['product_sku']:'{$productSku}' AND metadata['billing_period']:'{$interval}'",
                'limit' => 1,
            ]);
            
            if (count($prices->data) > 0) {
                return $prices->data[0]->id;
            }
        } catch (\Exception $e) {
            // If search fails, continue to create new price
        }
        
        // Price doesn't exist, create it
        $priceData = [
            'unit_amount' => $amount,
            'currency' => 'brl',
            'recurring' => ['interval' => $interval],
            'product_data' => [
                'name' => $product->getName(),
                'metadata' => [
                    'product_sku' => $productSku,
                    'product_id' => (string) $product->getId(),
                ],
            ],
            'metadata' => [
                'product_sku' => $productSku,
                'product_id' => (string) $product->getId(),
                'billing_period' => $interval,
            ],
        ];
        
        // Add description to product metadata if available (not in product_data)
        if ($product->getShortDescription()) {
            $priceData['product_data']['metadata']['description'] = $product->getShortDescription();
        }
        
        $price = $stripeClient->prices->create($priceData);
        
        return $price->id;
    }

    private function formatCustomerSubscription($subscription): array
    {
        $price = $subscription->items->data[0]->price ?? null;
        $paymentMethod = $subscription->default_payment_method ?? null;

        return [
            'id' => $subscription->id,
            'status' => $subscription->status,
            'plan_name' => $price ? $price->nickname ?? $price->product->name : 'Unknown Plan',
            'current_period_start' => date('Y-m-d H:i:s', $subscription->current_period_start),
            'current_period_end' => date('Y-m-d H:i:s', $subscription->current_period_end),
            'amount' => $price ? (float) ($price->unit_amount / 100) : 0.0,
            'currency' => $price ? strtoupper($price->currency) : 'USD',
            'billing_period' => $price ? $price->recurring->interval : 'month',
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'payment_method' => $paymentMethod ? [
                'id' => $paymentMethod->id,
                'type' => $paymentMethod->type,
                'brand' => $paymentMethod->card->brand ?? null,
                'exp_month' => $paymentMethod->card->exp_month ?? null,
                'exp_year' => $paymentMethod->card->exp_year ?? null,
                'label' => $this->formatPaymentMethodLabel($paymentMethod),
                'icon' => $this->getPaymentMethodIcon($paymentMethod)
            ] : null
        ];
    }

    private function formatPaymentMethodLabel($paymentMethod): string
    {
        if ($paymentMethod->type === 'card') {
            $brand = ucfirst($paymentMethod->card->brand);
            return "{$brand} •••• ****";
        }
        return ucfirst($paymentMethod->type);
    }

    private function getPaymentMethodIcon($paymentMethod): string
    {
        if ($paymentMethod->type === 'card') {
            $brand = strtolower($paymentMethod->card->brand);
            return "https://js.stripe.com/v3/fingerprinted/img/{$brand}-f1a4c6408d1c755b3d86c0d316dbf9ab.svg";
        }
        return '';
    }
}
