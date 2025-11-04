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
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class CreatePaymentIntent implements ResolverInterface
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
        $orderNumber = $input['order_number'];
        $productId = $input['product_id'];
        $billingPeriod = $input['billing_period'];

        try {
            // Get product
            $product = $this->productRepository->getById($productId);
            
            // Initialize Stripe first (needed for customer search)
            $secretKey = $this->stripeConfig->getSecretKey();
            
            if (empty($secretKey)) {
                throw new \Exception('Stripe Secret Key não configurada. Configure em Admin > Stores > Configuration > Sales > Payment Methods > Stripe');
            }
            
            if (!$this->stripeConfig->initStripe()) {
                throw new \Exception('Erro ao inicializar Stripe. Verifique se as chaves API estão configuradas corretamente.');
            }

            // Get Stripe client instance
            $stripeClient = $this->stripeConfig->getStripeClient();
            if (!$stripeClient) {
                throw new \Exception('Erro ao obter cliente Stripe. Verifique se as chaves API estão configuradas corretamente.');
            }
            
            // Get or create Stripe customer using the module's built-in method
            // This method already handles checking for existing customers and avoiding duplicates
            $customerId = $this->customerSession->getCustomerId();
            
            // Load the model with the customer ID to ensure it's properly set
            $this->stripeCustomer->load($customerId, 'customer_id');
            
            // createStripeCustomerIfNotExists returns the Stripe Customer object
            $stripeCustomerObj = $this->stripeCustomer->createStripeCustomerIfNotExists();
            
            // Get the Stripe customer ID - try from the object first, then from the model
            if ($stripeCustomerObj && isset($stripeCustomerObj->id)) {
                $stripeCustomerId = $stripeCustomerObj->id;
            } else {
                // If object doesn't have ID, try to get from the model
                $stripeCustomerId = $this->stripeCustomer->getStripeId();
            }
            
            if (!$stripeCustomerId) {
                throw new \Exception('Failed to get or create Stripe customer');
            }

            // Create or get price for the product
            $priceId = $this->getOrCreatePriceId($product, $billingPeriod, $stripeClient);

            // Get store base URL for success and cancel URLs
            $baseUrl = $this->getBaseUrl();
            // Remover trailing slash se houver
            $baseUrl = rtrim($baseUrl, '/');
            // Usar o frontend URL (pode ser configurado via admin)
            // Por padrão, vamos usar a URL do Magento e assumir que o frontend está na mesma base
            $successUrl = $baseUrl . '/dashboard?subscription=success';
            $cancelUrl = $baseUrl . '/dashboard?subscription=cancelled';

            // Create Stripe Checkout Session for subscription
            $checkoutSession = $stripeClient->checkout->sessions->create([
                'customer' => $stripeCustomerId,
                'payment_method_types' => ['card'],
                'mode' => 'subscription',
                'line_items' => [
                    [
                        'price' => $priceId,
                        'quantity' => 1,
                    ],
                ],
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => [
                    'Order #' => $orderNumber, // This allows webhook to find and update the order
                    'magento_customer_id' => (string) $customerId,
                    'stripe_customer_id' => $stripeCustomerId,
                    'product_id' => (string) $productId,
                    'billing_period' => $billingPeriod,
                ],
                'subscription_data' => [
                    'metadata' => [
                        'Order #' => $orderNumber, // This allows webhook to find and update the order
                        'magento_customer_id' => (string) $customerId,
                        'product_id' => (string) $productId,
                    ],
                ],
                'allow_promotion_codes' => true,
            ]);

            return [
                'checkout_url' => $checkoutSession->url,
                'session_id' => $checkoutSession->id,
                'price_id' => $priceId,
                'amount' => (float) ($product->getPrice()),
                'currency' => 'brl',
                'product_id' => $productId,
                'billing_period' => $billingPeriod,
            ];

        } catch (\Exception $e) {
            throw new \GraphQL\Error\Error((string) __('Error creating payment intent: %1', $e->getMessage()));
        }
    }

    private function getOrCreatePriceId($product, string $billingPeriod, $stripeClient): string
    {
        $interval = $billingPeriod === 'yearly' ? 'year' : 'month';
        $amount = (int) ($product->getPrice() * 100); // Convert to cents
        
        // Search for existing price with metadata matching this product and billing period
        // This is a workaround since we can't use custom IDs
        $productSku = strtolower(str_replace([' ', '-'], '_', $product->getSku()));
        
        try {
            // List prices and search for one matching our product SKU and billing period in metadata
            // IMPORTANT: Também verificar se a moeda é BRL para evitar reutilizar prices em USD
            $prices = $stripeClient->prices->search([
                'query' => "metadata['product_sku']:'{$productSku}' AND metadata['billing_period']:'{$interval}'",
                'limit' => 10,
            ]);
            
            // Filtrar para encontrar price com BRL e metadados corretos
            foreach ($prices->data as $price) {
                if ($price->currency === 'brl' && 
                    isset($price->metadata->product_sku) && 
                    $price->metadata->product_sku === $productSku &&
                    isset($price->metadata->billing_period) &&
                    $price->metadata->billing_period === $interval) {
                    return $price->id;
                }
            }
        } catch (\Exception $e) {
            // If search fails, continue to create new price
        }
        
        // Price doesn't exist, create it
        // Note: Stripe doesn't allow custom IDs, so we'll use metadata to identify prices
        // Use Brazilian Real (BRL) as currency
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


    private function getBaseUrl(): string
    {
        try {
            $store = $this->storeManager->getStore();
            return $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
        } catch (\Exception $e) {
            // Fallback to a default URL if store manager fails
            // This should be configured via admin or environment variable
            return 'http://localhost';
        }
    }
}

