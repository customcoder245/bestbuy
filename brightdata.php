<?php
// c:\Xampp\htdocs\api\brightdata.php

class BrightDataAPI {
    private $token;
    private $datasetId;
    private $baseUrl = 'https://api.brightdata.com/dca';

    public function __construct($token, $datasetId) {
        $this->token = $token;
        $this->datasetId = $datasetId;
    }

    private function request($endpoint, $method = 'GET', $data = null) {
        $url = $this->baseUrl . $endpoint;
        $ch = curl_init($url);
        
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json'
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("BrightData cURL Error: " . $error);
        }

        if ($httpCode >= 400) {
            throw new Exception("BrightData API Error ($httpCode): " . $response);
        }

        return json_decode($response, true) ?? $response;
    }

    public function triggerScraper($keywords) {
        $endpoint = '/trigger?dataset_id=' . $this->datasetId . '&include_errors=true';
        $payload = [];
        foreach ($keywords as $keyword) {
            $payload[] = ['url' => 'https://www.bestbuy.com/site/searchpage.jsp?st=' . urlencode($keyword)];
        }
        
        $response = $this->request($endpoint, 'POST', $payload);
        return $response['snapshot_id'] ?? null;
    }

    public function getSnapshotStatus($snapshotId) {
        $endpoint = '/dataset?id=' . $snapshotId;
        return $this->request($endpoint, 'GET');
    }

    public function downloadSnapshotData($snapshotId) {
        $endpoint = '/dataset?id=' . $snapshotId . '&format=json';
        // Download might return raw string instead of typical JSON response if large, handle accordingly
        $data = $this->request($endpoint, 'GET');
        return is_string($data) ? json_decode($data, true) : $data;
    }
}
