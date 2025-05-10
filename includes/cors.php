<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

function customapi_add_cors_headers() {
    header("Access-Control-Allow-Origin: https://portal.example.org"); // <-- replace with actual allowed origin if needed
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce");
}

// Handle CORS headers on REST requests
add_action('rest_api_init', function () {
    remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
    add_filter('rest_pre_serve_request', function($value) {
        customapi_add_cors_headers();
        return $value;
    });
});

// Handle preflight OPTIONS request for all routes
add_action('init', function () {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        customapi_add_cors_headers();
        status_header(200);
        exit;
    }
});
