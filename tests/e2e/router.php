<?php

/**
 * php -S router for the e2e tests: serves the built public/ like nginx does for
 * intake.veanea.net (docs/PLAN.md section 4), so the real CSP applies in the browser.
 *
 * - Security headers are read from the live /etc/nginx/snippets/vea-intake-headers.conf,
 *   with img-src set to 'self' (the state after ~/scripts/phase-csp.sh), so the tests
 *   prove the page needs neither data: nor blob:.
 * - /legal → 301 /legal/; /legal/ → index.html; static files under /legal/ only;
 *   ping.php and submit.php do not exist yet (Prompt 4) → 404, like nginx today.
 *   The tests mock them with page.route() where needed.
 */

declare(strict_types=1);

$public = realpath(__DIR__ . '/../../public');
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

$snippet = @file_get_contents('/etc/nginx/snippets/vea-intake-headers.conf') ?: '';
preg_match_all('/^\s*add_header\s+([A-Za-z-]+)\s+"([^"]*)"\s+always;/m', $snippet, $m, PREG_SET_ORDER);
foreach ($m as [, $name, $value]) {
    if (strcasecmp($name, 'Strict-Transport-Security') === 0) {
        continue;   // plain http on localhost
    }
    if (strcasecmp($name, 'Content-Security-Policy') === 0) {
        $value = (string) preg_replace("/img-src [^;]*/", "img-src 'self'", $value);
    }
    header("$name: $value");
}
if ($m === []) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; img-src 'self'; style-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'");
}

if ($path === '/legal') {
    header('Location: /legal/', true, 301);
    return true;
}
if (str_starts_with($path, '/legal/')) {
    header('Cache-Control: no-store');
    $file = $path === '/legal/' ? "$public/legal/index.html" : realpath($public . $path);
    $ok = is_string($file) && str_starts_with($file, "$public/legal/") && is_file($file)
        && !preg_match('~/\.|\.php$~', $path);
    if ($ok) {
        $types = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'js' => 'text/javascript', 'json' => 'application/json', 'png' => 'image/png'];
        header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
        readfile($file);
        return true;
    }
}
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo "<html><body><h1>404 Not Found</h1></body></html>";
return true;
