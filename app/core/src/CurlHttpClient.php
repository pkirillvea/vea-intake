<?php

declare(strict_types=1);

namespace VeaIntake\Core;

/** HTTPS-only curl client. Copied from vea-join; request() replaces post(). */
final class CurlHttpClient implements HttpClient
{
    public function request(string $method, string $url, array $headers, #[\SensitiveParameter] ?string $body, int $timeoutSeconds): HttpResponse
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException('unsupported HTTP method');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return new HttpResponse(0, '');
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $status = $response === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return new HttpResponse($status, $response === false ? '' : (string) $response);
    }
}
