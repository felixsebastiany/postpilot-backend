# Configuração CORS para PostPilot Frontend

## Problema
O frontend React não consegue se comunicar com o GraphQL do Magento devido a políticas CORS (Cross-Origin Resource Sharing).

## Soluções Disponíveis

### Opção 1: Configuração Nginx (Recomendada)

Adicione ao seu arquivo de configuração nginx:

```nginx
# CORS Configuration for PostPilot Frontend
location ~* ^/graphql$ {
    # CORS Headers
    add_header 'Access-Control-Allow-Origin' 'http://localhost:3000' always;
    add_header 'Access-Control-Allow-Methods' 'GET, POST, OPTIONS, PUT, DELETE' always;
    add_header 'Access-Control-Allow-Headers' 'DNT,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type,Range,Authorization' always;
    add_header 'Access-Control-Expose-Headers' 'Content-Length,Content-Range' always;
    add_header 'Access-Control-Allow-Credentials' 'true' always;
    
    # Handle preflight requests
    if ($request_method = 'OPTIONS') {
        add_header 'Access-Control-Allow-Origin' 'http://localhost:3000' always;
        add_header 'Access-Control-Allow-Methods' 'GET, POST, OPTIONS, PUT, DELETE' always;
        add_header 'Access-Control-Allow-Headers' 'DNT,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type,Range,Authorization' always;
        add_header 'Access-Control-Max-Age' 1728000;
        add_header 'Content-Type' 'text/plain; charset=utf-8';
        add_header 'Content-Length' 0;
        return 204;
    }
    
    # Continue with normal processing
    try_files $uri $uri/ /index.php?$args;
}
```

### Opção 2: Configuração Apache (.htaccess)

Adicione ao seu arquivo .htaccess:

```apache
# Enable CORS for GraphQL endpoint
<IfModule mod_headers.c>
    # Handle preflight requests
    RewriteEngine On
    RewriteCond %{REQUEST_METHOD} OPTIONS
    RewriteRule ^(.*)$ $1 [R=200,L]
    
    # CORS Headers for GraphQL
    <LocationMatch "^/graphql">
        Header always set Access-Control-Allow-Origin "http://localhost:3000"
        Header always set Access-Control-Allow-Methods "GET, POST, OPTIONS, PUT, DELETE"
        Header always set Access-Control-Allow-Headers "DNT,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type,Range,Authorization"
        Header always set Access-Control-Expose-Headers "Content-Length,Content-Range"
        Header always set Access-Control-Allow-Credentials "true"
        Header always set Access-Control-Max-Age "1728000"
    </LocationMatch>
</IfModule>
```

### Opção 3: Módulo Magento (Automático)

O módulo PostPilot_Cors foi criado para configurar CORS automaticamente.

**Para ativar:**

1. Os arquivos já foram criados em `app/code/PostPilot/Cors/`
2. Execute os comandos:
   ```bash
   cd /home/felix.sebastiany/Documentos/mageos/src
   bin/magento module:enable PostPilot_Cors
   bin/magento setup:upgrade
   bin/magento cache:flush
   ```

### Opção 4: Configuração PHP Manual

Adicione ao início do seu `pub/index.php`:

```php
// CORS Configuration for PostPilot Frontend
if (strpos($_SERVER['REQUEST_URI'], '/graphql') !== false) {
    $allowed_origins = [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'https://localhost:3000',
        'https://127.0.0.1:3000',
        'http://192.168.100.114:3000',
        'https://192.168.100.114:3000'
    ];
    
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if (in_array($origin, $allowed_origins)) {
        header("Access-Control-Allow-Origin: $origin");
    }
    
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
    header("Access-Control-Allow-Headers: DNT,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type,Range,Authorization");
    header("Access-Control-Expose-Headers: Content-Length,Content-Range");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Max-Age: 1728000");
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}
```

## Testando a Configuração

Após configurar CORS, teste com:

```bash
curl -X POST https://postpilot.local/graphql \
  -H "Content-Type: application/json" \
  -H "Origin: http://localhost:3000" \
  -d '{"query":"query { __schema { types { name } } }"}' \
  -k -v
```

Você deve ver os headers CORS na resposta.

## Troubleshooting

1. **Verifique os logs do navegador** (F12 > Console)
2. **Verifique se o servidor web está configurado** corretamente
3. **Teste com curl** para verificar se CORS está funcionando
4. **Limpe o cache** do Magento após mudanças

## URLs Permitidas

As seguintes origens estão configuradas para acesso:
- http://localhost:3000
- http://127.0.0.1:3000
- https://localhost:3000
- https://127.0.0.1:3000
- http://192.168.100.114:3000
- https://192.168.100.114:3000
