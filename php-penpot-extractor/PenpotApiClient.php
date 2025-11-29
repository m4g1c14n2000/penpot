<?php
/**
 * Penpot API Client
 * 
 * Alternative approach: Use Penpot's built-in API instead of direct database access.
 * This works with ALL Penpot versions regardless of blob encoding.
 * 
 * Requires: The Penpot management API to be enabled and accessible.
 * 
 * @package PenpotExtractor
 * @author Penpot Contributors
 * @license MPL-2.0
 */

class PenpotApiClient
{
    private string $baseUrl;
    private string $apiKey;

    /**
     * Constructor
     * 
     * @param string $baseUrl Penpot base URL (e.g., "https://penpot.example.com")
     * @param string $apiKey Management API key (configured in Penpot settings)
     */
    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }

    /**
     * Get file data as JSON
     * 
     * Uses the Penpot management API endpoint that handles all blob formats.
     * 
     * @param string $fileId File UUID
     * @param array $options Additional options:
     *                       - include_file_info: bool (default: true)
     *                       - format: 'json'|'transit' (default: 'json')
     * @return array Decoded file data
     * @throws Exception If API call fails
     */
    public function getFileData(string $fileId, array $options = []): array
    {
        $payload = [
            'file-id' => $fileId,
            'include-file-info' => $options['include_file_info'] ?? true,
            'format' => $options['format'] ?? 'json',
        ];

        $response = $this->apiRequest('POST', '/api/management/get-file-json', $payload);
        
        // The API returns JSON string, decode it
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to decode API response: ' . json_last_error_msg());
        }
        return $decoded ?? [];
    }

    /**
     * Get customer/profile info (if authenticated)
     * 
     * @param string $profileId Profile UUID
     * @return array Profile data
     */
    public function getCustomer(string $profileId): array
    {
        $response = $this->apiRequest('POST', '/api/management/get-customer', [
            'id' => $profileId,
        ]);
        
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to decode API response: ' . json_last_error_msg());
        }
        return $decoded ?? [];
    }

    /**
     * Make an API request to Penpot management API
     * 
     * @param string $method HTTP method
     * @param string $endpoint API endpoint path
     * @param array $data Request payload
     * @return string Response body
     * @throws Exception If request fails
     */
    private function apiRequest(string $method, string $endpoint, array $data = []): string
    {
        $url = $this->baseUrl . $endpoint;
        
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Token ' . $this->apiKey,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 120, // 2 minute timeout for large files
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("cURL error: $error");
        }

        if ($httpCode >= 400) {
            $errorBody = json_decode($response, true);
            $message = $errorBody['message'] ?? $errorBody['hint'] ?? 'API request failed';
            throw new Exception("API error ($httpCode): $message");
        }

        return $response;
    }

    /**
     * Build full URL for a media asset
     * 
     * @param string $mediaId Storage object UUID
     * @return string Full URL to access the asset
     */
    public function getAssetUrl(string $mediaId): string
    {
        return $this->baseUrl . '/assets/by-id/' . $mediaId;
    }
}


/**
 * Example usage wrapper that combines API client with media URL generation
 */
class PenpotExtractorViaApi
{
    private PenpotApiClient $client;

    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->client = new PenpotApiClient($baseUrl, $apiKey);
    }

    /**
     * Get complete file data with resolved media URLs
     * 
     * @param string $fileId File UUID
     * @return array File data with media URLs
     */
    public function extractFile(string $fileId): array
    {
        $data = $this->client->getFileData($fileId);
        
        // Process media references to add full URLs
        if (isset($data['data']['media'])) {
            foreach ($data['data']['media'] as $mediaId => &$mediaObj) {
                if (isset($mediaObj['id'])) {
                    $mediaObj['url'] = $this->client->getAssetUrl($mediaObj['id']);
                }
            }
        }

        return $data;
    }
}
