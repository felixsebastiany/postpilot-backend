<?php
/**
 * PostPilot CORS Configuration
 * 
 * Instructions:
 * 1. Copy this file to: app/etc/cors-config.php
 * 2. Include it in your index.php or create a plugin
 * 3. Or add the headers directly to your nginx/apache configuration
 */

// Only apply CORS for GraphQL requests
if (strpos($_SERVER['REQUEST_URI'], '/graphql') !== false) {
    
    // Define allowed origins
    $allowed_origins = [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'https://localhost:3000',
        'https://127.0.0.1:3000',
        'http://192.168.100.114:3000',
        'https://192.168.100.114:3000'
    ];
    
    // Get the origin from the request
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    // Check if origin is allowed
    if (in_array($origin, $allowed_origins)) {
        header("Access-Control-Allow-Origin: $origin");
    }
    
    // Set CORS headers
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
    header("Access-Control-Allow-Headers: DNT,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type,Range,Authorization");
    header("Access-Control-Expose-Headers: Content-Length,Content-Range");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Max-Age: 1728000");
    
    // Handle preflight requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}
