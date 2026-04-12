<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Env;
use RuntimeException;

final class MetaInstagramClient
{
    public function __construct(
        private readonly string $graphHost = '',
        private readonly string $apiVersion = '',
        private readonly string $accessToken = '',
        private readonly string $instagramAccountId = ''
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            graphHost: Env::get('META_GRAPH_HOST', 'https://graph.instagram.com'),
            apiVersion: Env::get('META_API_VERSION', 'v25.0'),
            accessToken: Env::get('META_ACCESS_TOKEN'),
            instagramAccountId: Env::get('META_IG_BUSINESS_ACCOUNT_ID')
        );
    }

    public function configurationErrors(): array
    {
        $errors = [];

        if ($this->accessToken === '') {
            $errors[] = 'META_ACCESS_TOKEN is missing.';
        }

        if ($this->instagramAccountId === '') {
            $errors[] = 'META_IG_BUSINESS_ACCOUNT_ID is missing.';
        }

        return $errors;
    }

    public function fetchMediaDetails(string $mediaId): array
    {
        $this->ensureAccessToken();

        $mediaId = trim($mediaId);

        if ($mediaId === '') {
            throw new RuntimeException('Media ID is missing from the comment webhook payload.');
        }

        return $this->request(
            'GET',
            rawurlencode($mediaId),
            ['fields' => 'id,caption']
        );
    }

    public function sendPrivateReply(string $commentId, string $messageText): array
    {
        $this->ensureAccessToken();
        $this->ensureInstagramAccountId();

        $commentId = trim($commentId);

        if ($commentId === '') {
            throw new RuntimeException('Comment ID is missing for the private reply request.');
        }

        $messageText = trim($messageText);

        if ($messageText === '') {
            throw new RuntimeException('The private reply body is empty.');
        }

        if (strlen($messageText) > 1000) {
            throw new RuntimeException('The private reply body exceeds Meta\'s 1000 byte text limit.');
        }

        $payload = [
            'recipient' => [
                'comment_id' => $commentId,
            ],
            'message' => [
                'text' => $messageText,
            ],
        ];

        return $this->request(
            'POST',
            rawurlencode($this->instagramAccountId) . '/messages',
            [],
            $payload
        );
    }

    private function ensureAccessToken(): void
    {
        if ($this->accessToken === '') {
            throw new RuntimeException('META_ACCESS_TOKEN is missing.');
        }
    }

    private function ensureInstagramAccountId(): void
    {
        if ($this->instagramAccountId === '') {
            throw new RuntimeException('META_IG_BUSINESS_ACCOUNT_ID is missing.');
        }
    }

    private function request(string $method, string $path, array $query = [], ?array $payload = null): array
    {
        $url = sprintf(
            '%s/%s/%s',
            rtrim($this->graphHost, '/'),
            trim($this->apiVersion, '/'),
            ltrim($path, '/')
        );

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('Could not initialise cURL for Meta API request.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 20,
        ];

        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        curl_setopt_array($handle, $options);

        $rawResponse = curl_exec($handle);
        $curlError = curl_error($handle);
        $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($rawResponse === false) {
            throw new RuntimeException('Meta API request failed before a response was returned. ' . $curlError);
        }

        $decoded = json_decode($rawResponse, true);

        if (!is_array($decoded)) {
            $decoded = ['raw_body' => $rawResponse];
        }

        $decoded['http_status'] = $httpCode;

        if ($httpCode < 200 || $httpCode >= 300 || isset($decoded['error'])) {
            throw new RuntimeException(json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return $decoded;
    }
}
