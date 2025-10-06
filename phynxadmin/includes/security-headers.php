<?php
// Production security headers for HTTPS
function setSecurityHeaders() {
    // Only set Secure flag if using HTTPS
    $isHTTPS = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    
    if ($isHTTPS) {
        // Strict CSP for production
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com data:; img-src 'self' data:; connect-src 'self'; upgrade-insecure-requests;");
        
        // HSTS for HTTPS
        header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
        
        // Secure cookies
        ini_set('session.cookie_secure', '1');
    } else {
        // Development CSP (less strict)
        header("Content-Security-Policy: default-src 'self' 'unsafe-inline' 'unsafe-eval' https: http: data:;");
        
        // Allow insecure cookies for development
        ini_set('session.cookie_secure', '0');
    }
    
    // Common security headers
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: SAMEORIGIN");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    
    // Cross-site access
    header("Access-Control-Allow-Credentials: true");
    $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_HOST'] ?? '*';
    header("Access-Control-Allow-Origin: " . $origin);
}

// Call this function before any output
// setSecurityHeaders();
?>