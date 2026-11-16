<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

/**
 * Minimal S3-compatible client for Cloudflare R2 (put and delete only), using
 * AWS Signature Version 4. No SDK: signing is ~40 lines of standard HMAC.
 */
final class S3Client
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $bucket,
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $region = 'auto',
    ) {
    }

    public static function forR2(string $accountId, string $bucket, string $accessKey, string $secretKey): self
    {
        return new self("https://$accountId.r2.cloudflarestorage.com", $bucket, $accessKey, $secretKey);
    }

    public function put(string $key, string $body, string $type = 'application/octet-stream'): void
    {
        $this->request('PUT', $key, $body, ['content-type' => $type]);
    }

    public function delete(string $key): void
    {
        $this->request('DELETE', $key, '');
    }

    private function request(string $method, string $key, string $body, array $headers = []): void
    {
        $host = (string) parse_url($this->endpoint, PHP_URL_HOST);
        $path = '/' . rawurlencode($this->bucket) . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
        $payloadHash = hash('sha256', $body);
        $amzDate = gmdate('Ymd\THis\Z');
        $headers += ['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $amzDate];
        $headers['authorization'] = self::authorization($method, $path, '', $headers, $payloadHash, $amzDate, $this->region, 's3', $this->accessKey, $this->secretKey);

        $ch = curl_init($this->endpoint . $path);
        $flat = [];
        foreach ($headers as $k => $v) {
            if ($k !== 'host') {
                $flat[] = "$k: $v";
            }
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $flat,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($res === false || $code < 200 || $code >= 300) {
            $err = $res === false ? curl_error($ch) : 'HTTP ' . $code . ' ' . substr(strip_tags((string) $res), 0, 200);
            throw new \RuntimeException("Backup storage $method failed: $err");
        }
    }

    /**
     * The AWS SigV4 Authorization header. Public so it can be checked against the
     * worked example in AWS's documentation (tests/run.php does this).
     */
    public static function authorization(string $method, string $path, string $query, array $headers, string $payloadHash,
        string $amzDate, string $region, string $service, string $accessKey, string $secretKey): string
    {
        $canon = [];
        foreach ($headers as $k => $v) {
            $canon[strtolower($k)] = trim(preg_replace('/\s+/', ' ', (string) $v));
        }
        unset($canon['authorization']);
        ksort($canon);
        $signedHeaders = implode(';', array_keys($canon));
        $canonicalHeaders = '';
        foreach ($canon as $k => $v) {
            $canonicalHeaders .= "$k:$v\n";
        }
        $canonicalRequest = "$method\n$path\n$query\n$canonicalHeaders\n$signedHeaders\n$payloadHash";
        $date = substr($amzDate, 0, 8);
        $scope = "$date/$region/$service/aws4_request";
        $toSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonicalRequest);
        $k = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
        $k = hash_hmac('sha256', $region, $k, true);
        $k = hash_hmac('sha256', $service, $k, true);
        $k = hash_hmac('sha256', 'aws4_request', $k, true);
        $signature = hash_hmac('sha256', $toSign, $k);
        return "AWS4-HMAC-SHA256 Credential=$accessKey/$scope, SignedHeaders=$signedHeaders, Signature=$signature";
    }
}
