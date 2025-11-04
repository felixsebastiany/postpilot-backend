<?php
declare(strict_types=1);

namespace PostPilot\StripeCustom\Model\GraphQL\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;

class GetSubscriptionPlans implements ResolverInterface
{
    private ProductRepositoryInterface $productRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private FilterBuilder $filterBuilder;
    private FilterGroupBuilder $filterGroupBuilder;
    private StoreManagerInterface $storeManager;
    private PriceCurrencyInterface $priceCurrency;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        FilterBuilder $filterBuilder,
        FilterGroupBuilder $filterGroupBuilder,
        StoreManagerInterface $storeManager,
        PriceCurrencyInterface $priceCurrency
    ) {
        $this->productRepository = $productRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->filterBuilder = $filterBuilder;
        $this->filterBuilder = $filterBuilder;
        $this->filterGroupBuilder = $filterGroupBuilder;
        $this->storeManager = $storeManager;
        $this->priceCurrency = $priceCurrency;
    }

    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        try {
            $storeId = (int) $context->getExtensionAttributes()->getStore()->getId();
            
            // Filter products by name containing "PostPilot"
            $nameFilter = $this->filterBuilder
                ->setField('name')
                ->setValue('%PostPilot%')
                ->setConditionType('like')
                ->create();

            $statusFilter = $this->filterBuilder
                ->setField('status')
                ->setValue(1)
                ->setConditionType('eq')
                ->create();

            $visibilityFilter = $this->filterBuilder
                ->setField('visibility')
                ->setValue(4) // Catalog, Search
                ->setConditionType('eq')
                ->create();

            $filterGroup1 = $this->filterGroupBuilder->addFilter($nameFilter)->create();
            $filterGroup2 = $this->filterGroupBuilder->addFilter($statusFilter)->create();
            $filterGroup3 = $this->filterGroupBuilder->addFilter($visibilityFilter)->create();

            $searchCriteria = $this->searchCriteriaBuilder
                ->setFilterGroups([$filterGroup1])
                ->setPageSize(10)
                ->setCurrentPage(1)
                ->create();

            $products = $this->productRepository->getList($searchCriteria);
            $subscriptionPlans = [];

            foreach ($products->getItems() as $product) {
                $subscriptionPlans[] = $this->formatSubscriptionPlan($product, $storeId);
            }

            return $subscriptionPlans;
        } catch (\Exception $e) {
            throw new \GraphQL\Error\Error((string) __('Error fetching subscription plans: %1', $e->getMessage()));
        }
    }

    private function formatSubscriptionPlan($product, int $storeId): array
    {
        $price = $product->getPriceInfo()->getPrice('final_price')->getAmount();
        $currency = $this->storeManager->getStore($storeId)->getCurrentCurrencyCode();
        
        // Determine billing period from product name
        $billingPeriod = 'monthly';
        if (stripos($product->getName(), 'annual') !== false || 
            stripos($product->getName(), 'yearly') !== false ||
            stripos($product->getName(), 'anual') !== false) {
            $billingPeriod = 'yearly';
        }

        // Determine plan type
        $planType = 'basic';
        if (stripos($product->getName(), 'pro') !== false) {
            $planType = 'pro';
        }

        // Define features based on plan type
        $features = $this->getPlanFeatures($planType);

        return [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'sku' => $product->getSku(),
            'price' => (float) $price->getValue(),
            'currency' => $currency,
            'billing_period' => $billingPeriod,
            'description' => $product->getShortDescription() ?: $product->getDescription(),
            'features' => $features,
            'is_popular' => $planType === 'pro' && $billingPeriod === 'yearly'
        ];
    }

    private function getPlanFeatures(string $planType): array
    {
        $features = [
            'basic' => [
                'Até 5 perfis conectados',
                '10 posts automáticos por mês',
                'Suporte por email',
                'Templates básicos',
                'Relatórios simples'
            ],
            'pro' => [
                'Perfis ilimitados',
                'Posts automáticos ilimitados',
                'Suporte prioritário',
                'Templates premium',
                'Relatórios avançados',
                'Agendamento inteligente',
                'IA para conteúdo',
                'API access'
            ]
        ];

        return $features[$planType] ?? $features['basic'];
    }
}
