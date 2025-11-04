#!/bin/bash

# Script para corrigir permissões e compilar o módulo PostPilot

echo "Corrigindo permissões..."

# Corrigir permissões dos diretórios
chown -R www-data:www-data generated/ var/
chmod -R 755 generated/ var/

# Habilitar o módulo
echo "Habilitando módulo PostPilot_Subscription..."
php bin/magento module:enable PostPilot_Subscription

# Executar setup:upgrade
echo "Executando setup:upgrade..."
php bin/magento setup:upgrade

# Compilar DI
echo "Compilando DI..."
php bin/magento setup:di:compile

# Deploy de conteúdo estático
echo "Deploy de conteúdo estático..."
php bin/magento setup:static-content:deploy -f

# Limpar cache
echo "Limpando cache..."
php bin/magento cache:flush

echo "Instalação concluída!"
