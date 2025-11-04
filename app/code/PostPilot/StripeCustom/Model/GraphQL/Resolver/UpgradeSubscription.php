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
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class UpgradeSubscription implements ResolverInterface
{
    private CustomerSession $customerSession;
    private ProductRepositoryInterface $productRepository;
    private StripeCustomer $stripeCustomer;
    private Config $stripeConfig;
    private StoreManagerInterface $storeManager;
    private LoggerInterface $logger;

    public function __construct(
        CustomerSession $customerSession,
        ProductRepositoryInterface $productRepository,
        StripeCustomer $stripeCustomer,
        Config $stripeConfig,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->customerSession = $customerSession;
        $this->productRepository = $productRepository;
        $this->stripeCustomer = $stripeCustomer;
        $this->stripeConfig = $stripeConfig;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        if (!$this->customerSession->isLoggedIn()) {
            throw new \GraphQL\Error\Error((string) __('Customer not logged in'));
        }

        $input = $args['input'];
        $subscriptionId = $input['subscription_id'];
        $productId = $input['product_id'];
        $billingPeriod = $input['billing_period'];

        try {
            // Get customer ID (ensure it's an integer)
            $customerId = (int) $this->customerSession->getCustomerId();
            
            if (!$customerId) {
                throw new \Exception('Customer ID not found');
            }

            // Get product
            $product = $this->productRepository->getById($productId);

            // Verify product is Pro plan
            if (stripos($product->getName(), 'pro') === false) {
                throw new \Exception('Product must be a Pro plan for upgrade');
            }

            // Initialize Stripe
            if (!$this->stripeConfig->initStripe()) {
                throw new \Exception('Failed to initialize Stripe');
            }

            $stripeClient = $this->stripeConfig->getStripeClient();

            // Check if subscription belongs to customer - get Stripe customer ID first
            $stripeCustomerId = $this->getStripeCustomerId($customerId);
            
            if (!$stripeCustomerId) {
                throw new \Exception('Customer does not have a Stripe account');
            }

            // Verify subscription exists and belongs to customer
            $currentSubscription = $stripeClient->subscriptions->retrieve($subscriptionId);
            
            // Check if subscription belongs to customer
            if (!$stripeCustomerId || $currentSubscription->customer !== $stripeCustomerId) {
                throw new \Exception('Subscription does not belong to customer');
            }

            // Get or create price for Pro plan
            $priceId = $this->getOrCreatePriceId($product, $billingPeriod, $stripeClient);

            // Get base URLs
            $baseUrl = $this->storeManager->getStore()->getBaseUrl();
            $successUrl = rtrim($baseUrl, '/') . '/dashboard?subscription=upgrade_success';
            $cancelUrl = rtrim($baseUrl, '/') . '/dashboard?subscription=upgrade_cancelled';

            // Create checkout session for upgrade
            $checkoutSession = $stripeClient->checkout->sessions->create([
                'mode' => 'subscription',
                'customer' => $stripeCustomerId,
                'payment_method_types' => ['card'],
                'line_items' => [
                    [
                        'price' => $priceId,
                        'quantity' => 1,
                    ],
                ],
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => [
                    'magento_customer_id' => (string)$customerId,
                    'subscription_id' => $subscriptionId,
                    'product_id' => (string)$productId,
                    'upgrade' => 'true',
                    'type' => 'upgrade',
                ],
                'subscription_data' => [
                    'metadata' => [
                        'magento_customer_id' => (string)$customerId,
                        'product_id' => (string)$productId,
                        'upgrade' => 'true',
                        'type' => 'upgrade',
                        'previous_subscription_id' => $subscriptionId,
                    ],
                ],
            ]);

            return [
                'checkout_url' => $checkoutSession->url,
                'session_id' => $checkoutSession->id,
                'price_id' => $priceId,
                'amount' => $product->getPrice(),
                'currency' => 'brl',
                'product_id' => (string)$productId,
                'billing_period' => $billingPeriod,
            ];

        } catch (\Exception $e) {
            $this->logger->error('Error creating upgrade checkout session: ' . $e->getMessage());
            throw new \GraphQL\Error\Error((string) __('Error creating upgrade checkout session: %1', $e->getMessage()));
        }
    }

    private function getStripeCustomerId(int $customerId): ?string
    {
        $this->stripeCustomer->load($customerId, 'customer_id');
        return $this->stripeCustomer->getStripeId();
    }

    private function getOrCreatePriceId($product, string $billingPeriod, $stripeClient): string
    {
        $interval = $billingPeriod === 'yearly' ? 'year' : 'month';
        $amount = (int) ($product->getPrice() * 100);
        
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
            $this->logger->info("Price search failed, creating new: " . $e->getMessage());
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
        
        // Add description to product metadata if available
        if ($product->getShortDescription()) {
            $priceData['product_data']['metadata']['description'] = $product->getShortDescription();
        }
        
        $price = $stripeClient->prices->create($priceData);
        
        return $price->id;
    }
}

