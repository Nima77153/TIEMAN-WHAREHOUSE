<?php
function uploadToCloudinary($fileTmpPath, $fileName) {
    $cloudName = $_ENV['CLOUD_NAME'] ?? 'ddhq8vxoo';
    $apiKey    = $_ENV['CLOUDINARY_API_KEY'] ?? '243898541294966';
    $apiSecret = $_ENV['CLOUDINARY_API_SECRET'] ?? 'P1jqAwnZBTIeL-0YH6yiOwohZxM';

    $timestamp = time();
    $publicId  = pathinfo($fileName, PATHINFO_FILENAME);

    // Build signature string
    $paramsToSign = "public_id=" . $publicId . "&timestamp=" . $timestamp;
    $signature    = sha1($paramsToSign . $apiSecret);

    $postData = [
        'file'      => new CURLFile($fileTmpPath),
        'api_key'   => $apiKey,
        'timestamp' => $timestamp,
        'public_id' => $publicId,
        'signature' => $signature
    ];

    $ch = curl_init("https://api.cloudinary.com/v1_1/" . $cloudName . "/image/upload");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);

    if (isset($result['secure_url'])) {
        return $result['secure_url']; // Returns https://res.cloudinary.com/... URL
    }

    return false;
}
?>