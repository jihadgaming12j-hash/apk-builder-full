<?php
declare(strict_types=1);

/*
 * APK Builder API
 * Required settings can be supplied in storage/config.php or server environment:
 *   GITHUB_TOKEN              Fine-grained token with Actions read/write access
 *   GITHUB_REPOSITORY         owner/repository
 *   APK_BUILDER_PUBLIC_URL    Absolute URL to this file, e.g. https://example.com/build-api.php
 *   APK_BUILDER_SECRET        A random secret of at least 32 characters
 * Optional:
 *   GITHUB_DEFAULT_BRANCH     Branch containing this workflow (default: main)
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

$serverConfig = [];
$privateConfigPath = __DIR__ . '/storage/config.php';
if (is_file($privateConfigPath)) {
    $loadedConfig = require $privateConfigPath;
    if (is_array($loadedConfig)) {
        $serverConfig = $loadedConfig;
    }
}

function configValue(string $key, string $fallback = ''): string
{
    global $serverConfig;
    $fromEnvironment = getenv($key);
    if ($fromEnvironment !== false && trim((string) $fromEnvironment) !== '') {
        return trim((string) $fromEnvironment);
    }
    return trim((string) ($serverConfig[$key] ?? $fallback));
}

$githubToken = configValue('GITHUB_TOKEN');
$githubRepo = configValue('GITHUB_REPOSITORY');
$publicApiUrl = configValue('APK_BUILDER_PUBLIC_URL');
$builderSecret = configValue('APK_BUILDER_SECRET');
$defaultBranch = configValue('GITHUB_DEFAULT_BRANCH', 'main');
$storageRoot = __DIR__ . '/storage';
$jobsRoot = $storageRoot . '/jobs';
$buildsRoot = $storageRoot . '/builds';
$workflowFile = 'build-apk.yml';

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function failRequest(string $message, int $status = 400): void
{
    respond(['ok' => false, 'error' => $message], $status);
}

function safeEquals(string $a, string $b): bool
{
    return strlen($a) === strlen($b) && hash_equals($a, $b);
}

function makeSignature(string $jobId, string $kind, int $expires): string
{
    global $builderSecret;
    return hash_hmac('sha256', $jobId . ':' . $kind . ':' . $expires, $builderSecret);
}

function apiUrl(string $action, array $params = []): string
{
    global $publicApiUrl;
    $query = array_merge(['action' => $action], $params);
    return $publicApiUrl . (str_contains($publicApiUrl, '?') ? '&' : '?') . http_build_query($query);
}

function githubRequest(string $method, string $path, ?array $body = null): array
{
    global $githubToken;
    $url = 'https://api.github.com' . $path;
    $handle = curl_init($url);
    $headers = [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $githubToken,
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: MrAiPrime-APK-Builder',
    ];
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    }
    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);
    if ($raw === false) {
        throw new RuntimeException('GitHub API connection failed: ' . $curlError);
    }
    $decoded = $raw === '' ? [] : json_decode($raw, true);
    if ($status < 200 || $status >= 300) {
        $message = is_array($decoded) ? (string) ($decoded['message'] ?? '') : '';
        throw new RuntimeException($message !== '' ? 'GitHub API: ' . $message : 'GitHub API returned HTTP ' . $status);
    }
    return is_array($decoded) ? $decoded : [];
}

function saveJob(string $jobId, array $job): void
{
    global $jobsRoot;
    $path = $jobsRoot . '/' . $jobId . '.json';
    if (file_put_contents($path, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        throw new RuntimeException('Could not save build state. Check PHP storage folder permissions.');
    }
}

function pruneOldData(): void
{
    global $storageRoot, $jobsRoot, $buildsRoot;
    $stamp = $storageRoot . '/.last-cleanup';
    if (is_file($stamp) && time() - (int) filemtime($stamp) < 3600) {
        return;
    }
    $lock = fopen($storageRoot . '/.cleanup-lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        return;
    }
    foreach (glob($jobsRoot . '/*.json') ?: [] as $jobFile) {
        $job = json_decode((string) @file_get_contents($jobFile), true);
        if (!is_array($job)) {
            @unlink($jobFile);
            continue;
        }
        $created = (int) ($job['created_at'] ?? 0);
        if ($created > 0 && $created < time() - 24 * 3600) {
            foreach (['source_path', 'icon_path'] as $field) {
                if (!empty($job[$field]) && is_file($job[$field])) {
                    @unlink($job[$field]);
                }
            }
        }
        if ($created > 0 && $created < time() - 8 * 24 * 3600) {
            $jobDirectory = $jobsRoot . '/' . (string) ($job['id'] ?? '');
            if (is_dir($jobDirectory)) {
                foreach (glob($jobDirectory . '/*') ?: [] as $path) @unlink($path);
                @rmdir($jobDirectory);
            }
            @unlink($jobFile);
        }
    }
    foreach (glob($buildsRoot . '/*') ?: [] as $path) {
        if (is_file($path) && filemtime($path) < time() - 7 * 24 * 3600) {
            @unlink($path);
        }
    }
    foreach (glob($storageRoot . '/rate-*.json') ?: [] as $path) {
        if (is_file($path) && filemtime($path) < time() - 2 * 24 * 3600) {
            @unlink($path);
        }
    }
    @touch($stamp);
    flock($lock, LOCK_UN);
    fclose($lock);
}

function loadJob(string $jobId): array
{
    global $jobsRoot;
    $path = $jobsRoot . '/' . $jobId . '.json';
    if (!is_file($path)) {
        failRequest('এই build request আর পাওয়া যাচ্ছে না। নতুন করে build দিন।', 404);
    }
    $job = json_decode((string) file_get_contents($path), true);
    if (!is_array($job)) {
        failRequest('Build state পড়া যায়নি।', 500);
    }
    return $job;
}

function requireConfigured(): void
{
    global $githubToken, $githubRepo, $publicApiUrl, $builderSecret, $storageRoot, $jobsRoot, $buildsRoot;
    if ($githubToken === '' || !preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $githubRepo)) {
        failRequest('Backend setup বাকি: GITHUB_TOKEN ও GITHUB_REPOSITORY server environment-এ যোগ করুন।', 503);
    }
    if (!filter_var($publicApiUrl, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($publicApiUrl), 'https://')) {
        failRequest('Backend setup বাকি: HTTPS-সহ সঠিক APK_BUILDER_PUBLIC_URL দিন।', 503);
    }
    if (strlen($builderSecret) < 32) {
        failRequest('Backend setup বাকি: APK_BUILDER_SECRET অন্তত ৩২ অক্ষরের random secret হতে হবে।', 503);
    }
    foreach ([$storageRoot, $jobsRoot, $buildsRoot] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            failRequest('PHP storage folder তৈরি করা যায়নি। Folder permission পরীক্ষা করুন।', 500);
        }
    }
    pruneOldData();
    if (!function_exists('curl_init') || !class_exists('ZipArchive')) {
        failRequest('PHP cURL ও ZipArchive extension চালু করুন।', 503);
    }
}

function checkSameOrigin(): void
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return;
    }
    $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $requestHost = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($originHost === '' || $requestHost === '' || !safeEquals($originHost, $requestHost)) {
        failRequest('Cross-origin request is not allowed.', 403);
    }
}

function checkRateLimit(): void
{
    global $storageRoot;
    $key = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $path = $storageRoot . '/rate-' . $key . '.json';
    $now = time();
    $times = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
    if (!is_array($times)) {
        $times = [];
    }
    $times = array_values(array_filter($times, static fn($time) => is_numeric($time) && (int) $time > $now - 3600));
    if (count($times) >= 5) {
        failRequest('এই নেটওয়ার্ক থেকে এক ঘণ্টায় সর্বোচ্চ ৫টি build request করা যাবে। পরে আবার চেষ্টা করুন।', 429);
    }
    $times[] = $now;
    file_put_contents($path, json_encode($times), LOCK_EX);
}

function uploadedFile(string $field, int $maxBytes, array $extensions): ?array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return null;
    }
    $file = $_FILES[$field];
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        failRequest('আপলোড করা ফাইলটি পড়া যায়নি। আবার চেষ্টা করুন।');
    }
    if ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > $maxBytes) {
        failRequest('আপলোড ফাইলের size সীমার বাইরে।');
    }
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, $extensions, true)) {
        failRequest('এই ধরনের ফাইল গ্রহণ করা হয় না।');
    }
    return ['tmp_name' => (string) $file['tmp_name'], 'name' => (string) $file['name'], 'extension' => $extension, 'size' => (int) $file['size']];
}

function rateLimitedDownload(string $url, string $destination): void
{
    global $githubToken;
    $handle = curl_init($url);
    $file = fopen($destination, 'wb');
    if ($file === false) {
        throw new RuntimeException('Could not create temporary artifact file.');
    }
    curl_setopt_array($handle, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FILE => $file,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $githubToken,
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: MrAiPrime-APK-Builder',
        ],
    ]);
    $ok = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    fclose($file);
    if ($ok === false || $status < 200 || $status >= 300) {
        @unlink($destination);
        throw new RuntimeException('APK artifact download failed: ' . ($error !== '' ? $error : 'HTTP ' . $status));
    }
}

function saveArtifactApk(string $jobId, int $runId): bool
{
    global $githubRepo, $buildsRoot;
    $artifacts = githubRequest('GET', '/repos/' . $githubRepo . '/actions/runs/' . $runId . '/artifacts?per_page=100');
    $expectedName = 'apk-' . $jobId;
    $artifact = null;
    foreach (($artifacts['artifacts'] ?? []) as $candidate) {
        if (($candidate['name'] ?? '') === $expectedName && empty($candidate['expired'])) {
            $artifact = $candidate;
            break;
        }
    }
    if ($artifact === null) {
        return false;
    }
    $zipPath = $buildsRoot . '/' . $jobId . '.artifact.zip';
    rateLimitedDownload((string) $artifact['archive_download_url'], $zipPath);
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        @unlink($zipPath);
        throw new RuntimeException('GitHub returned an invalid APK artifact.');
    }
    $apkIndex = -1;
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = (string) $zip->getNameIndex($index);
        if (preg_match('~(^|/)[^/]+\.apk$~i', $name)) {
            $apkIndex = $index;
            break;
        }
    }
    if ($apkIndex < 0) {
        $zip->close();
        @unlink($zipPath);
        throw new RuntimeException('APK artifact did not contain an APK file.');
    }
    $input = $zip->getStream((string) $zip->getNameIndex($apkIndex));
    $apkPath = $buildsRoot . '/' . $jobId . '.apk';
    $output = fopen($apkPath, 'wb');
    if ($input === false || $output === false) {
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) fclose($output);
        $zip->close();
        @unlink($zipPath);
        throw new RuntimeException('Could not save the finished APK.');
    }
    stream_copy_to_stream($input, $output);
    fclose($input);
    fclose($output);
    $zip->close();
    @unlink($zipPath);
    if (!is_file($apkPath) || filesize($apkPath) < 1024) {
        @unlink($apkPath);
        throw new RuntimeException('The downloaded APK is empty or incomplete.');
    }
    return true;
}

function startBuild(): void
{
    global $githubRepo, $defaultBranch, $workflowFile, $jobsRoot, $storageRoot;
    checkRateLimit();
    $mode = (string) ($_POST['mode'] ?? '');
    $name = trim((string) ($_POST['app_name'] ?? ''));
    $packageName = strtolower(trim((string) ($_POST['package'] ?? '')));
    $orientation = (string) ($_POST['orientation'] ?? 'portrait');
    $refresh = (string) ($_POST['refresh'] ?? '0') === '1';
    if (!in_array($mode, ['url', 'file'], true)) {
        failRequest('Build mode সঠিক নয়।');
    }
    $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : preg_match_all('/./us', $name);
    if ($name === '' || !is_int($nameLength) || $nameLength > 60 || preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $name)) {
        failRequest('অ্যাপের নাম ১–৬০ অক্ষরের মধ্যে দিন।');
    }
    $packageParts = explode('.', $packageName);
    if (strlen($packageName) > 255 || count($packageParts) < 2 || array_filter($packageParts, static fn($part) => strlen($part) > 63) || !preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $packageName)) {
        failRequest('Package name সঠিক নয়। উদাহরণ: com.example.myapp');
    }
    if (!in_array($orientation, ['portrait', 'landscape', 'unspecified'], true)) {
        failRequest('Screen orientation সঠিক নয়।');
    }

    $source = null;
    $websiteUrl = '';
    if ($mode === 'url') {
        $websiteUrl = trim((string) ($_POST['url'] ?? ''));
        $parsed = filter_var($websiteUrl, FILTER_VALIDATE_URL) ? parse_url($websiteUrl) : false;
        if (!is_array($parsed) || !in_array(strtolower((string) ($parsed['scheme'] ?? '')), ['http', 'https'], true) || empty($parsed['host']) || isset($parsed['user']) || isset($parsed['pass'])) {
            failRequest('একটি সঠিক HTTP বা HTTPS website URL দিন।');
        }
    } else {
        $source = uploadedFile('source', 8 * 1024 * 1024, ['zip', 'html', 'htm']);
        if ($source === null) {
            failRequest('HTML অথবা ZIP source file নির্বাচন করুন।');
        }
    }
    $icon = uploadedFile('icon', 1 * 1024 * 1024, ['png', 'jpg', 'jpeg']);

    $jobId = bin2hex(random_bytes(16));
    $jobDir = $jobsRoot . '/' . $jobId;
    if (!mkdir($jobDir, 0700, true)) {
        failRequest('Build storage তৈরি করা যায়নি।', 500);
    }
    $sourcePath = null;
    $iconPath = null;
    if ($source !== null) {
        $sourcePath = $jobDir . '/source.' . $source['extension'];
        if (!move_uploaded_file($source['tmp_name'], $sourcePath)) {
            failRequest('Source file save করা যায়নি।', 500);
        }
    }
    if ($icon !== null) {
        $iconPath = $jobDir . '/icon.' . $icon['extension'];
        if (!move_uploaded_file($icon['tmp_name'], $iconPath)) {
            failRequest('App icon save করা যায়নি।', 500);
        }
    }

    $expires = time() + 6 * 3600;
    $sourceUrl = $mode === 'url'
        ? $websiteUrl
        : apiUrl('asset', ['id' => $jobId, 'type' => 'source', 'exp' => $expires, 'sig' => makeSignature($jobId, 'source', $expires)]);
    $iconUrl = $iconPath !== null
        ? apiUrl('asset', ['id' => $jobId, 'type' => 'icon', 'exp' => $expires, 'sig' => makeSignature($jobId, 'icon', $expires)])
        : '';

    $job = [
        'id' => $jobId,
        'mode' => $mode,
        'app_name' => $name,
        'package' => $packageName,
        'orientation' => $orientation,
        'refresh' => $refresh,
        'created_at' => time(),
        'source_path' => $sourcePath,
        'icon_path' => $iconPath,
        'state' => 'queued',
    ];
    saveJob($jobId, $job);
    try {
        githubRequest('POST', '/repos/' . $githubRepo . '/actions/workflows/' . $workflowFile . '/dispatches', [
            'ref' => $defaultBranch,
            'inputs' => [
                'job_id' => $jobId,
                'mode' => $mode,
                'app_name' => $name,
                'package_name' => $packageName,
                'orientation' => $orientation,
                'refresh' => $refresh ? 'true' : 'false',
                'source_url' => $sourceUrl,
                'icon_url' => $iconUrl,
            ],
        ]);
    } catch (Throwable $error) {
        $job['state'] = 'failed';
        $job['error'] = $error->getMessage();
        foreach ([$sourcePath, $iconPath] as $path) {
            if ($path && is_file($path)) @unlink($path);
        }
        saveJob($jobId, $job);
        failRequest('GitHub Actions-এ build পাঠানো যায়নি। Repository, token permission ও workflow file পরীক্ষা করুন।', 502);
    }
    respond(['ok' => true, 'id' => $jobId, 'state' => 'queued']);
}

function getBuildStatus(string $jobId): void
{
    global $githubRepo, $workflowFile, $builderSecret, $buildsRoot, $jobsRoot;
    $job = loadJob($jobId);
    $expectedTitle = 'APK Build ' . $jobId;
    $runs = githubRequest('GET', '/repos/' . $githubRepo . '/actions/workflows/' . $workflowFile . '/runs?event=workflow_dispatch&per_page=100');
    $run = null;
    foreach (($runs['workflow_runs'] ?? []) as $candidate) {
        if (($candidate['display_title'] ?? '') === $expectedTitle || ($candidate['name'] ?? '') === $expectedTitle) {
            $run = $candidate;
            break;
        }
    }
    if ($run === null) {
        if (time() - (int) ($job['created_at'] ?? time()) > 15 * 60) {
            foreach (['source_path', 'icon_path'] as $field) {
                if (!empty($job[$field]) && is_file($job[$field])) @unlink($job[$field]);
                $job[$field] = null;
            }
            saveJob($jobId, $job);
            respond(['ok' => true, 'state' => 'failed', 'error' => 'GitHub Actions workflow খুঁজে পাওয়া যায়নি। workflow_dispatch চালু আছে কিনা দেখুন।']);
        }
        respond(['ok' => true, 'state' => 'queued', 'steps' => []]);
    }
    $runId = (int) $run['id'];
    $jobs = githubRequest('GET', '/repos/' . $githubRepo . '/actions/runs/' . $runId . '/jobs?per_page=100');
    $steps = [];
    foreach (($jobs['jobs'] ?? []) as $actionJob) {
        foreach (($actionJob['steps'] ?? []) as $step) {
            $steps[] = [
                'name' => (string) ($step['name'] ?? ''),
                'status' => (string) ($step['status'] ?? ''),
                'conclusion' => (string) ($step['conclusion'] ?? ''),
            ];
        }
    }
    $state = ($run['status'] ?? '') === 'completed' ? 'completed' : 'building';
    if ($state === 'completed') {
        if (($run['conclusion'] ?? '') !== 'success') {
            foreach (['source_path', 'icon_path'] as $field) {
                if (!empty($job[$field]) && is_file($job[$field])) @unlink($job[$field]);
                $job[$field] = null;
            }
            saveJob($jobId, $job);
            respond(['ok' => true, 'state' => 'failed', 'steps' => $steps, 'error' => 'GitHub Actions build ব্যর্থ হয়েছে। Repository → Actions-এ build log দেখুন।']);
        }
        $apkPath = $buildsRoot . '/' . $jobId . '.apk';
        if (!is_file($apkPath)) {
            try {
                if (!saveArtifactApk($jobId, $runId)) {
                    respond(['ok' => true, 'state' => 'building', 'steps' => $steps]);
                }
            } catch (Throwable $error) {
                respond(['ok' => false, 'error' => $error->getMessage()], 502);
            }
        }
        $expires = time() + 7 * 24 * 3600;
        $downloadToken = makeSignature($jobId, 'download', $expires);
        $job['state'] = 'done';
        $job['completed_at'] = time();
        foreach (['source_path', 'icon_path'] as $field) {
            if (!empty($job[$field]) && is_file($job[$field])) @unlink($job[$field]);
            $job[$field] = null;
        }
        saveJob($jobId, $job);
        respond([
            'ok' => true,
            'state' => 'done',
            'steps' => $steps,
            'url' => apiUrl('download', ['id' => $jobId, 'exp' => $expires, 'sig' => $downloadToken]),
        ]);
    }
    respond(['ok' => true, 'state' => $state, 'steps' => $steps]);
}

function streamAsset(string $jobId, string $kind, int $expires, string $signature): void
{
    $job = loadJob($jobId);
    if ($expires < time() || $expires > time() + 6 * 3600 + 60 || !safeEquals(makeSignature($jobId, $kind, $expires), $signature)) {
        failRequest('এই build file link-এর মেয়াদ শেষ বা link সঠিক নয়।', 403);
    }
    $path = $kind === 'source' ? (string) ($job['source_path'] ?? '') : (string) ($job['icon_path'] ?? '');
    if ($path === '' || !is_file($path)) {
        failRequest('Build source file পাওয়া যায়নি।', 404);
    }
    $mime = $kind === 'icon'
        ? 'image/' . (str_ends_with(strtolower($path), '.png') ? 'png' : 'jpeg')
        : (str_ends_with(strtolower($path), '.zip') ? 'application/zip' : 'text/html; charset=utf-8');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    readfile($path);
    exit;
}

function downloadApk(string $jobId, int $expires, string $signature): void
{
    global $buildsRoot;
    if ($expires < time() || $expires > time() + 7 * 24 * 3600 + 60 || !safeEquals(makeSignature($jobId, 'download', $expires), $signature)) {
        failRequest('APK download link-এর মেয়াদ শেষ বা link সঠিক নয়। আবার build করুন।', 403);
    }
    $path = $buildsRoot . '/' . $jobId . '.apk';
    if (!is_file($path)) {
        failRequest('APK file পাওয়া যায়নি।', 404);
    }
    header_remove('Content-Type');
    header('Content-Type: application/vnd.android.package-archive');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="app-' . $jobId . '.apk"');
    readfile($path);
    exit;
}

$action = (string) ($_GET['action'] ?? '');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($action === 'asset') {
    $id = (string) ($_GET['id'] ?? '');
    $kind = (string) ($_GET['type'] ?? '');
    $expires = (int) ($_GET['exp'] ?? 0);
    $signature = (string) ($_GET['sig'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $id) || !in_array($kind, ['source', 'icon'], true)) {
        failRequest('Asset request সঠিক নয়।');
    }
    requireConfigured();
    streamAsset($id, $kind, $expires, $signature);
}
if ($action === 'download') {
    $id = (string) ($_GET['id'] ?? '');
    $expires = (int) ($_GET['exp'] ?? 0);
    $signature = (string) ($_GET['sig'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        failRequest('APK download request সঠিক নয়।');
    }
    requireConfigured();
    downloadApk($id, $expires, $signature);
}

requireConfigured();
checkSameOrigin();
if ($action === 'start' && $method === 'POST') {
    startBuild();
}
if ($action === 'status' && $method === 'GET') {
    $id = (string) ($_GET['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        failRequest('Build ID সঠিক নয়.');
    }
    try {
        getBuildStatus($id);
    } catch (Throwable $error) {
        respond(['ok' => false, 'error' => 'GitHub Actions status পাওয়া যায়নি: ' . $error->getMessage()], 502);
    }
}
failRequest('Unknown action or unsupported HTTP method.', 404);