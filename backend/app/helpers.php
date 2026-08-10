<?php

if (! function_exists('base64url_encode')) {
    /**
     * Base64 URL-safe encode (tanpa padding)
     */
    function base64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (! function_exists('base64url_decode')) {
    /**
     * Base64 URL-safe decode
     */
    function base64url_decode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
