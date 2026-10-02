<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$storage = __DIR__ . '/storage';
$report = [
    'phpVersion' => PHP_VERSION,
    'curlExtension' => extension_loaded('curl'),
    'zipArchive' => class_exists('ZipArchive'),
    'uploadMaxFilesize' => ini_get('upload_max_filesize'),
    'postMaxSize' => ini_get('post_max_size'),
    'maxExecutionTime' => ini_get('max_execution_time'),
    'storageWritable' => is_dir($storage) && is_writable($storage),
    'https' => (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https',
];

if (extension_loaded('curl')) {
    $curl = curl_init('https://api.github.com/rate_limit');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'User-Agent: MrAiPrime-Host-Check'],
    ]);
    curl_exec($curl);
    $report['githubApiHttpStatus'] = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $report['githubApiReachable'] = $report['githubApiHttpStatus'] === 200;
    curl_close($curl);
} else {
    $report['githubApiReachable'] = false;
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);