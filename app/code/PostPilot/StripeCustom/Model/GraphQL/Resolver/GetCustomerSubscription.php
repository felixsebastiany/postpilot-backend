<?php
declare(strict_types=1);

namespace PostPilot\StripeCustom\Model\GraphQL\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Customer\Model\Session as CustomerSession;
use StripeIntegration\Payments\Model\StripeCustomer;
use StripeIntegration\Payments\Model\Config;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class GetCustomerSubscription implements ResolverInterface
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
            $customerId = $this->customerSession->getCustomerId();
            
            if (!$customerId) {
                return null; // No customer ID in session
            }
            
            $stripeCustomerId = $this->stripeCustomer->getStripeId($customerId);
            
            // Se não encontrou no banco, tentar buscar no Stripe pelo email do customer do Magento
            if (!$stripeCustomerId) {
                $this->logger->info("GetCustomerSubscription: No Stripe customer ID found in database for Magento customer ID: {$customerId}. Trying to find in Stripe by email.");
                
                // Buscar customer no Stripe pelo email
                if (!$this->stripeConfig->initStripe()) {
                    return null;
                }
                
                $stripeClient = $this->stripeConfig->getStripeClient();
                if (!$stripeClient) {
                    return null;
                }
                
                // Obter email do customer do Magento
                try {
                    $customerObj = $this->customerSession->getCustomer();
                    $customerEmail = $customerObj->getEmail();
                    
                    if ($customerEmail) {
                        // Buscar TODOS os customers no Stripe pelo email (pode haver múltiplos)
                        $customers = $stripeClient->customers->search([
                            'query' => "email:'{$customerEmail}'",
                            'limit' => 100, // Buscar até 100 customers com esse email
                        ]);
                        
                        if (!empty($customers->data)) {
                            $this->logger->info("GetCustomerSubscription: Found " . count($customers->data) . " Stripe customer(s) for email: {$customerEmail}");
                            
                            // Para cada customer encontrado, verificar se tem assinaturas
                            // Priorizar aquele que tem assinatura com magento_customer_id correto
                            $bestCustomerId = null;
                            $bestSubscription = null;
                            
                            foreach ($customers->data as $foundCustomer) {
                                $testCustomerId = $foundCustomer->id;
                                
                                // Buscar assinaturas para este customer
                                try {
                                    $testSubscriptions = $stripeClient->subscriptions->all([
                                        'customer' => $testCustomerId,
                                        'limit' => 100,
                                    ]);
                                    
                                    if (!empty($testSubscriptions->data)) {
                                        // Verificar se alguma assinatura tem magento_customer_id correto
                                        foreach ($testSubscriptions->data as $testSub) {
                                            $metadata = $testSub->metadata ?? null;
                                            $subMagentoCustomerId = null;
                                            
                                            if ($metadata) {
                                                if (is_object($metadata) && isset($metadata->magento_customer_id)) {
                                                    $subMagentoCustomerId = (string)$metadata->magento_customer_id;
                                                } elseif (is_array($metadata) && isset($metadata['magento_customer_id'])) {
                                                    $subMagentoCustomerId = (string)$metadata['magento_customer_id'];
                                                }
                                            }
                                            
                                            // Se encontrou uma assinatura com magento_customer_id correto, usar este customer
                                            if ($subMagentoCustomerId === (string)$customerId) {
                                                $this->logger->info("GetCustomerSubscription: Found customer with matching subscription: {$testCustomerId}, subscription: {$testSub->id}");
                                                $bestCustomerId = $testCustomerId;
                                                $bestSubscription = $testSub;
                                                break 2; // Sair de ambos os loops
                                            }
                                            
                                            // Se ainda não encontrou o melhor, usar o primeiro com assinatura ativa
                                            if (!$bestCustomerId && in_array($testSub->status, ['active', 'trialing', 'past_due'])) {
                                                $bestCustomerId = $testCustomerId;
                                                $bestSubscription = $testSub;
                                                $this->logger->info("GetCustomerSubscription: Using customer with active subscription: {$testCustomerId}, subscription: {$testSub->id}");
                                            }
                                        }
                                    }
                                } catch (\Exception $e) {
                                    // Continuar com próximo customer se houver erro
                                    $this->logger->warning("GetCustomerSubscription: Error checking subscriptions for customer {$testCustomerId}: " . $e->getMessage());
                                }
                            }
                            
                            if ($bestCustomerId) {
                                $stripeCustomerId = $bestCustomerId;
                                $this->logger->info("GetCustomerSubscription: Selected Stripe customer: {$stripeCustomerId} for email: {$customerEmail}");
                                
                                // Tentar salvar no banco para futuras buscas
                                try {
                                    $this->stripeCustomer->createStripeCustomer($customerId);
                                } catch (\Exception $e) {
                                    // Se falhar ao salvar, continuar mesmo assim
                                    $this->logger->warning("GetCustomerSubscription: Could not save Stripe customer ID to database: " . $e->getMessage());
                                }
                                
                                // Se já encontramos a assinatura correta durante a busca, retornar diretamente
                                if ($bestSubscription) {
                                    $this->logger->info("GetCustomerSubscription: Returning subscription found during customer search: {$bestSubscription->id}");
                                    return $this->formatCustomerSubscription($bestSubscription);
                                }
                            } else {
                                $this->logger->info("GetCustomerSubscription: Found customers with email {$customerEmail} but none have subscriptions");
                                return null;
                            }
                        } else {
                            $this->logger->info("GetCustomerSubscription: No Stripe customer found for email: {$customerEmail}");
                            return null;
                        }
                    } else {
                        $this->logger->info("GetCustomerSubscription: Customer email not available");
                        return null;
                    }
                } catch (\Exception $e) {
                    $this->logger->error("GetCustomerSubscription: Error searching for Stripe customer: " . $e->getMessage());
                    return null;
                }
            } else {
                $this->logger->info("GetCustomerSubscription: Found Stripe customer ID in database: {$stripeCustomerId} for Magento customer ID: {$customerId}");
            }

            // Garantir que temos o Stripe client inicializado
            if (!$this->stripeConfig->initStripe()) {
                return null; // Cannot initialize Stripe
            }

            // Get Stripe client instance
            $stripeClient = $this->stripeConfig->getStripeClient();
            if (!$stripeClient) {
                return null; // Cannot get Stripe client
            }
            
            // Se ainda não temos o stripeCustomerId, retornar null (já tentamos buscar pelo email)
            if (!$stripeCustomerId) {
                return null;
            }
            
            // Debug: Verificar se o customer existe no Stripe
            try {
                $stripeCustomerObj = $stripeClient->customers->retrieve($stripeCustomerId);
                // Customer existe, continuar
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Customer não existe no Stripe
                return null;
            }

            // Buscar assinaturas - usar busca mais abrangente
            try {
                // Primeiro, buscar todas as assinaturas do customer
                // Tentar sem filtro de status primeiro para ver todas
                $allSubscriptions = $stripeClient->subscriptions->all([
                    'customer' => $stripeCustomerId,
                    'limit' => 100,
                ]);

                // Se não encontrar nenhuma, retornar null
                if (empty($allSubscriptions->data)) {
                    $this->logger->info("GetCustomerSubscription: No subscriptions found for Stripe customer ID: {$stripeCustomerId}");
                    return null;
                }

                // Debug: Ver quantas assinaturas foram encontradas
                $subscriptionCount = count($allSubscriptions->data);
                $this->logger->info("GetCustomerSubscription: Found {$subscriptionCount} subscription(s) for Stripe customer ID: {$stripeCustomerId}");

                // Debug: verificar metadados das assinaturas para encontrar a correta
                $validStatuses = ['active', 'trialing', 'past_due', 'incomplete', 'incomplete_expired'];
                $validSubscription = null;
                
                // Método auxiliar para obter magento_customer_id dos metadados
                $getMagentoCustomerId = function($subscription) use ($customerId) {
                    $metadata = $subscription->metadata ?? null;
                    if (!$metadata) {
                        return null;
                    }
                    
                    // Tentar como objeto primeiro
                    if (is_object($metadata)) {
                        // Pode ser um objeto com propriedades ou um ArrayObject
                        if (isset($metadata->magento_customer_id)) {
                            return (string)$metadata->magento_customer_id;
                        }
                        // Tentar acessar como array também (caso seja ArrayObject)
                        if (is_array($metadata) || $metadata instanceof \ArrayAccess) {
                            if (isset($metadata['magento_customer_id'])) {
                                return (string)$metadata['magento_customer_id'];
                            }
                        }
                    }
                    // Tentar como array
                    if (is_array($metadata) && isset($metadata['magento_customer_id'])) {
                        return (string)$metadata['magento_customer_id'];
                    }
                    
                    return null;
                };
                
                // Estratégia 1: Encontrar assinatura com status válido E magento_customer_id correto
                foreach ($allSubscriptions->data as $subscription) {
                    $subscriptionMagentoCustomerId = $getMagentoCustomerId($subscription);
                    $this->logger->info("GetCustomerSubscription: Checking subscription {$subscription->id}, status: {$subscription->status}, magento_customer_id in metadata: " . ($subscriptionMagentoCustomerId ?: 'not found'));
                    
                    if (in_array($subscription->status, $validStatuses)) {
                        // Se tem o magento_customer_id correto nos metadados, priorizar esta
                        if ($subscriptionMagentoCustomerId && $subscriptionMagentoCustomerId === (string)$customerId) {
                            $this->logger->info("GetCustomerSubscription: Found matching subscription with magento_customer_id: {$subscription->id}");
                            $validSubscription = $subscription;
                            break;
                        }
                        // Se não encontrou uma com metadados corretos, usar a primeira válida (fallback)
                        if (!$validSubscription) {
                            $this->logger->info("GetCustomerSubscription: Using first valid subscription as fallback: {$subscription->id}");
                            $validSubscription = $subscription;
                        }
                    }
                }

                // Estratégia 2: Se ainda não encontrou, buscar qualquer assinatura com magento_customer_id correto
                // mesmo que o status não seja ideal
                if (!$validSubscription) {
                    foreach ($allSubscriptions->data as $subscription) {
                        $subscriptionMagentoCustomerId = $getMagentoCustomerId($subscription);
                        
                        if ($subscriptionMagentoCustomerId && $subscriptionMagentoCustomerId === (string)$customerId) {
                            $validSubscription = $subscription;
                            break;
                        }
                    }
                }
                
                // Estratégia 3: Se ainda não encontrou, usar qualquer assinatura com status válido
                // (mesmo sem metadados corretos - pode ser de outra sessão/versão)
                if (!$validSubscription) {
                    foreach ($allSubscriptions->data as $subscription) {
                        if (in_array($subscription->status, $validStatuses)) {
                            $validSubscription = $subscription;
                            break;
                        }
                    }
                }
                
                // Estratégia 4: Usar qualquer assinatura do customer (último recurso)
                if (!$validSubscription && !empty($allSubscriptions->data)) {
                    $validSubscription = $allSubscriptions->data[0];
                }

                if (!$validSubscription) {
                    $this->logger->warning("GetCustomerSubscription: No valid subscription found after checking {$subscriptionCount} subscription(s)");
                    return null; // No subscription found at all
                }

                $this->logger->info("GetCustomerSubscription: Returning subscription: {$validSubscription->id} with status: {$validSubscription->status}");
                return $this->formatCustomerSubscription($validSubscription);
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Se houver erro na busca, logar e retornar null
                $this->logger->error("GetCustomerSubscription: Stripe API error: " . $e->getMessage());
                return null;
            } catch (\Exception $e) {
                // Qualquer outro erro, logar e retornar null
                $this->logger->error("GetCustomerSubscription: General error: " . $e->getMessage());
                return null;
            }

        } catch (\Exception $e) {
            throw new \GraphQL\Error\Error((string) __('Error fetching customer subscription: %1', $e->getMessage()));
        }
    }

    private function formatCustomerSubscription($subscription): array
    {
        $price = $subscription->items->data[0]->price ?? null;
        
        // Obter payment method (pode ser um ID ou objeto expandido)
        $paymentMethod = null;
        if (is_string($subscription->default_payment_method)) {
            // Se for apenas um ID, precisamos buscar o payment method
            try {
                $stripeClient = $this->stripeConfig->getStripeClient();
                $paymentMethod = $stripeClient->paymentMethods->retrieve($subscription->default_payment_method);
            } catch (\Exception $e) {
                // Se falhar, continua sem payment method
            }
        } else {
            // Se for objeto expandido, usar diretamente
            $paymentMethod = $subscription->default_payment_method;
        }

        // Determinar nome do plano
        $planName = 'Unknown Plan';
        if ($price) {
            if (isset($price->nickname) && !empty($price->nickname)) {
                $planName = $price->nickname;
            } elseif (isset($price->product)) {
                if (is_object($price->product) && isset($price->product->name)) {
                    $planName = $price->product->name;
                } elseif (is_string($price->product)) {
                    // Se product for apenas um ID, tentar buscar
                    try {
                        $stripeClient = $this->stripeConfig->getStripeClient();
                        $product = $stripeClient->products->retrieve($price->product);
                        $planName = $product->name;
                    } catch (\Exception $e) {
                        // Se falhar, usa o ID como fallback
                        $planName = $price->product;
                    }
                }
            }
        }

        // Determinar billing period
        $billingPeriod = 'month';
        if ($price && isset($price->recurring)) {
            $interval = $price->recurring->interval ?? 'month';
            $billingPeriod = $interval === 'year' ? 'yearly' : 'monthly';
        }

        return [
            'id' => $subscription->id,
            'status' => $subscription->status,
            'plan_name' => $planName,
            'current_period_start' => date('Y-m-d H:i:s', $subscription->current_period_start),
            'current_period_end' => date('Y-m-d H:i:s', $subscription->current_period_end),
            'amount' => $price ? (float) ($price->unit_amount / 100) : 0.0,
            'currency' => $price ? strtoupper($price->currency) : 'BRL',
            'billing_period' => $billingPeriod,
            'cancel_at_period_end' => $subscription->cancel_at_period_end ?? false,
            'payment_method' => $paymentMethod ? $this->formatPaymentMethod($paymentMethod) : null
        ];
    }

    private function formatPaymentMethod($paymentMethod): ?array
    {
        if (!$paymentMethod) {
            return null;
        }

        // Se for apenas um ID string, retornar apenas o ID
        if (is_string($paymentMethod)) {
            return [
                'id' => $paymentMethod,
                'type' => 'unknown',
            ];
        }

        // Se for objeto, formatar corretamente
        $formatted = [
            'id' => $paymentMethod->id ?? null,
            'type' => $paymentMethod->type ?? 'unknown',
        ];

        // Se for cartão, adicionar informações do cartão
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
