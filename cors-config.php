<?php
/**
 * CORS Configuration for PostPilot Frontend
 * Add this to your Magento configuration
 */

// Enable CORS for GraphQL endpoint
if (isset($_SERVER['HTTP_ORIGIN'])) {
    $allowed_origins = [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'https://localhost:3000',
        'https://127.0.0.1:3000',
        'http://192.168.100.114:3000',
        'https://192.168.100.114:3000'
    ];
    
    $origin = $_SERVER['HTTP_ORIGIN'];
    
    if (in_array($origin, $allowed_origins)) {
        header("Access-Control-Allow-Origin: $origin");
    }
}

// CORS Headers
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: DNT,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type,Range,Authorization");
header("Access-Control-Expose-Headers: Content-Length,Content-Range");
header("Access-Control-Allow-Credentials: true");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}
