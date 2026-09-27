<?php

declare(strict_types=1);

namespace Attestwire\Http;

use Attestwire\Exception\AttestwireException;

/**
 * The default `HttpClientInterface`: ext-curl, nothing else.
 *
 * Unlike horstoeko/invoicesuite's own `InvoiceSuiteDocuflairDocumentValidator`
 * (the closest prior art for a validator backed by an HTTP call), this does
 * NOT disable TLS verification — `CURLOPT_SSL_VERIFYPEER` / `_VERIFYHOST` are
 * left at curl's secure defaults (on). That validator's choice to turn them
 * off is not one to copy.
 */
final class CurlHttpClient implements HttpClientInterface
{
    public function send(string $method, string $url, array $headers, string $body, float $timeoutSeconds): HttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new AttestwireException('The curl extension (ext-curl) is required and is not loaded.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new AttestwireException(sprintf('curl_init() failed for %s.', $url));
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = sprintf('%s: %s', $name, $value);
        }

        $options = [
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => (int) max(1, ceil($timeoutSeconds)),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if (strtoupper($method) === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
            if ($body !== '') {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
        }

        curl_setopt_array($handle, $options);

        $responseBody = curl_exec($handle);

        if ($responseBody === false) {
            $error = curl_error($handle);
            curl_close($handle);

            throw new AttestwireException(sprintf('Could not reach %s: %s', $url, $error));
        }

        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        return new HttpResponse($status, (string) $responseBody);
    }
}
