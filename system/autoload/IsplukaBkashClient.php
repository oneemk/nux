<?php
/**
 * Minimal bKash HTTP client for Ispluka.
 *
 * The client is intentionally configuration-driven: base URL and credentials
 * are supplied at runtime and are never stored in source control. It does not
 * write Ispluka or legacy billing data.
 */
class IsplukaBkashClient
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        foreach (['base_url', 'app_key', 'app_secret', 'username', 'password'] as $key) {
            if (!isset($config[$key]) || trim((string) $config[$key]) === '') {
                throw new InvalidArgumentException('Missing bKash configuration: ' . $key);
            }
        }

        $this->config['base_url'] = rtrim(trim((string) $config['base_url']), '/');
        $this->config['timeout'] = isset($config['timeout']) ? max(1, (int) $config['timeout']) : 20;
        $this->config['verify_tls'] = !isset($config['verify_tls']) || (bool) $config['verify_tls'];
    }

    public function grantToken()
    {
        return $this->request('POST', '/tokenized/checkout/token/grant', [
            'username' => (string) $this->config['username'],
            'password' => (string) $this->config['password'],
        ], [
            'Authorization' => 'Basic ' . base64_encode(
                (string) $this->config['username'] . ':' . (string) $this->config['password']
            ),
            'X-App-Key' => (string) $this->config['app_key'],
        ]);
    }

    public function createPayment($token, array $payload)
    {
        return $this->request('POST', '/tokenized/checkout/create', $payload, [
            'Authorization' => 'Bearer ' . trim((string) $token),
            'X-App-Key' => (string) $this->config['app_key'],
        ]);
    }

    public function executePayment($token, $paymentId)
    {
        $paymentId = trim((string) $paymentId);
        if ($paymentId === '') {
            throw new InvalidArgumentException('bKash paymentID is required.');
        }

        return $this->request('POST', '/tokenized/checkout/execute', [
            'paymentID' => $paymentId,
        ], [
            'Authorization' => 'Bearer ' . trim((string) $token),
            'X-App-Key' => (string) $this->config['app_key'],
        ]);
    }

    public function queryPayment($token, $paymentId)
    {
        $paymentId = trim((string) $paymentId);
        if ($paymentId === '') {
            throw new InvalidArgumentException('bKash paymentID is required.');
        }

        return $this->request('POST', '/tokenized/checkout/payment/status', [
            'paymentID' => $paymentId,
        ], [
            'Authorization' => 'Bearer ' . trim((string) $token),
            'X-App-Key' => (string) $this->config['app_key'],
        ]);
    }

    private function request($method, $path, array $body, array $headers)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required for bKash integration.');
        }

        $url = $this->config['base_url'] . '/' . ltrim($path, '/');
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode bKash request.');
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialize bKash HTTP client.');
        }

        $headerLines = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->config['timeout']),
            CURLOPT_TIMEOUT => $this->config['timeout'],
            CURLOPT_SSL_VERIFYPEER => $this->config['verify_tls'],
            CURLOPT_SSL_VERIFYHOST => $this->config['verify_tls'] ? 2 : 0,
        ]);

        $response = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException('bKash HTTP request failed: ' . ($curlError ?: 'unknown error'));
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('bKash returned a non-JSON response (HTTP ' . $httpCode . ').');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = isset($decoded['statusMessage']) ? (string) $decoded['statusMessage'] : 'bKash API request failed.';
            throw new RuntimeException($message . ' (HTTP ' . $httpCode . ')');
        }

        return $decoded;
    }
}
