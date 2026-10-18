<?php

$upstream = curl_init('http://localhost'.($_SERVER['REQUEST_URI'] ?? '/'));
$headers = ['Host: dropbell.in', 'X-Forwarded-Host: dropbell.in', 'X-Forwarded-Proto: https'];
foreach (getallheaders() as $name => $value) {
    if (! in_array(strtolower($name), ['host', 'connection', 'content-length', 'accept-encoding', 'transfer-encoding', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-for'], true)) {
        $headers[] = $name.': '.$value;
    }
}
curl_setopt_array($upstream, [
    CURLOPT_UNIX_SOCKET_PATH => dirname(__DIR__).'/storefront.sock',
    CURLOPT_CUSTOMREQUEST => $_SERVER['REQUEST_METHOD'],
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_HEADERFUNCTION => static function ($handle, string $line): int {
        $separator = strpos($line, ':');
        if ($separator !== false) {
            $name = strtolower(substr($line, 0, $separator));
            if (! in_array($name, ['connection', 'transfer-encoding', 'content-length', 'keep-alive'], true)) {
                header(trim($line), false);
            }
        }
        return strlen($line);
    },
]);
if (! in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    curl_setopt($upstream, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
}
$body = curl_exec($upstream);
if ($body === false) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Dropbell is temporarily unavailable. Please try again shortly.';
} else {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (! str_starts_with($requestPath, '/_next/static/') && ! str_starts_with($requestPath, '/assets/')) {
        header('Cache-Control: private, no-store, max-age=0');
        header_remove('ETag');
    }
    http_response_code(curl_getinfo($upstream, CURLINFO_RESPONSE_CODE));
    echo $body;
}
