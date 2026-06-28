<?php
/**
 * Standalone image hosting page.
 *
 * Copy this file to any PHP project and edit the config below.
 * Requirements: PHP 8.0+, file uploads enabled. GitHub storage requires curl.
 */

declare(strict_types=1);

session_start();
date_default_timezone_set('Asia/Shanghai');

$TCU_CONFIG = [
    // Change this before public deployment. You can set admin_password_hash
    // to password_hash('your-password', PASSWORD_DEFAULT) and clear admin_password.
    'admin_password' => '1668',
    'admin_password_hash' => '',

    // API uploads require this key via X-Tuchuang-Key header or api_key field.
    'api_key' => 'change-this-api-key',

    // github | local. GitHub mode uploads directly to GitHub without keeping a server copy.
    'storage_driver' => 'github',
    'upload_dir' => __DIR__ . '/uploads',
    'upload_url_base' => '', // Empty means auto-detect /uploads from current URL.
    'max_image_bytes' => 5 * 1024 * 1024,
    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'],

    'github_token' => '',
    'github_repo' => '', // owner/repo
    'github_branch' => 'main',
    'github_path' => 'uploads/{Ym}',
    'github_public_base' => '', // Optional CDN base, e.g. https://cdn.jsdelivr.net/gh/owner/repo@main
    'public_access_mode' => 'github', // github | jsdelivr | raw
    'github_auto_create_repo' => true,
    'github_repo_visibility' => 'public', // public | private. Only used when auto-creating a missing repo.
];
$TCU_CONFIG = tcu_load_saved_config($TCU_CONFIG);

if (isset($_GET['api']) && $_GET['api'] === 'upload') {
    tcu_handle_api_upload($TCU_CONFIG);
}

if (isset($_GET['logout'])) {
    unset($_SESSION['tcu_logged_in']);
    tcu_redirect(tcu_self_url());
}

$message = '';
$results = [];
$uploadConfig = $TCU_CONFIG;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'login') {
        if (tcu_verify_admin_password((string)($_POST['password'] ?? ''), $TCU_CONFIG)) {
            $_SESSION['tcu_logged_in'] = true;
            $_SESSION['tcu_csrf'] = bin2hex(random_bytes(16));
            tcu_redirect(tcu_self_url());
        }
        $message = '密码错误。';
    } elseif (!tcu_is_logged_in()) {
        $message = '请先登录。';
    } elseif (!tcu_verify_csrf((string)($_POST['_csrf'] ?? ''))) {
        $message = '请求已过期，请刷新页面后重试。';
    } elseif ($action === 'upload') {
        if (isset($_POST['github_repo']) || isset($_POST['github_path']) || isset($_POST['github_repo_visibility'])) {
            $uploadConfig = tcu_config_from_post($TCU_CONFIG);
        }
        $results = tcu_store_request_images($uploadConfig);
        $message = tcu_upload_message($results);
    } elseif ($action === 'save_config') {
        $TCU_CONFIG = tcu_config_from_post($TCU_CONFIG);
        $message = tcu_save_config($TCU_CONFIG);
        $uploadConfig = $TCU_CONFIG;
    } elseif ($action === 'test_github') {
        if (isset($_POST['github_repo']) || isset($_POST['github_token']) || isset($_POST['github_repo_visibility'])) {
            $TCU_CONFIG = tcu_config_from_post($TCU_CONFIG);
            $uploadConfig = $TCU_CONFIG;
        }
        $message = tcu_test_github($TCU_CONFIG);
    }
}

tcu_render_page($TCU_CONFIG, $message, $results, $uploadConfig);

function tcu_config_file(): string
{
    return __DIR__ . '/tuchuang.config.php';
}

function tcu_load_saved_config(array $defaults): array
{
    $file = tcu_config_file();
    if (!is_file($file)) {
        return $defaults;
    }
    $saved = require $file;
    if (!is_array($saved)) {
        return $defaults;
    }
    $config = array_replace($defaults, array_intersect_key($saved, $defaults));
    $config['storage_driver'] = 'github';
    return $config;
}

function tcu_config_from_post(array $config): array
{
    $repo = array_key_exists('github_repo', $_POST)
        ? tcu_normalize_github_repo((string)$_POST['github_repo'])
        : (string)($config['github_repo'] ?? '');
    $driver = (string)($_POST['storage_driver'] ?? ($config['storage_driver'] ?? 'github'));
    if (!in_array($driver, ['local', 'github'], true)) {
        $driver = 'github';
    }
    $maxBytes = (int)($config['max_image_bytes'] ?? 5 * 1024 * 1024);
    if (array_key_exists('max_image_mb', $_POST)) {
        $maxMb = (float)$_POST['max_image_mb'];
        $maxBytes = (int)(max(1, min(100, $maxMb)) * 1024 * 1024);
    }
    $token = trim((string)($_POST['github_token'] ?? ''));
    if ($token === '' && !empty($config['github_token'])) {
        $token = (string)$config['github_token'];
    }
    return array_replace($config, [
        'api_key' => array_key_exists('api_key', $_POST) ? trim((string)$_POST['api_key']) : (string)($config['api_key'] ?? ''),
        'storage_driver' => $driver,
        'upload_url_base' => array_key_exists('upload_url_base', $_POST) ? trim((string)$_POST['upload_url_base']) : (string)($config['upload_url_base'] ?? ''),
        'max_image_bytes' => $maxBytes,
        'github_token' => $token,
        'github_repo' => $repo,
        'github_branch' => array_key_exists('github_branch', $_POST) ? (trim((string)$_POST['github_branch']) ?: 'main') : (string)($config['github_branch'] ?? 'main'),
        'github_path' => array_key_exists('github_path', $_POST) ? (trim((string)$_POST['github_path']) ?: 'uploads/{Ym}') : (string)($config['github_path'] ?? 'uploads/{Ym}'),
        'github_public_base' => array_key_exists('github_public_base', $_POST) ? trim((string)$_POST['github_public_base']) : (string)($config['github_public_base'] ?? ''),
        'public_access_mode' => array_key_exists('public_access_mode', $_POST) ? tcu_public_access_mode((string)$_POST['public_access_mode']) : (string)($config['public_access_mode'] ?? 'github'),
        'github_auto_create_repo' => array_key_exists('github_auto_create_repo', $_POST) ? !empty($_POST['github_auto_create_repo']) : (bool)($config['github_auto_create_repo'] ?? true),
        'github_repo_visibility' => array_key_exists('github_repo_visibility', $_POST) ? tcu_github_repo_visibility((string)$_POST['github_repo_visibility']) : tcu_github_repo_visibility((string)($config['github_repo_visibility'] ?? 'public')),
    ]);
}

function tcu_save_config(array $config): string
{
    $repo = tcu_normalize_github_repo((string)($config['github_repo'] ?? ''));
    if ($repo !== '' && !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
        return '仓库格式必须是 owner/repo 或 GitHub 仓库 URL。';
    }
    $config['github_repo'] = $repo;
    $saved = array_intersect_key($config, array_flip([
        'api_key',
        'storage_driver',
        'upload_url_base',
        'max_image_bytes',
        'github_token',
        'github_repo',
        'github_branch',
        'github_path',
        'github_public_base',
        'public_access_mode',
        'github_auto_create_repo',
        'github_repo_visibility',
    ]));
    $php = "<?php\nreturn " . var_export($saved, true) . ";\n";
    if (file_put_contents(tcu_config_file(), $php, LOCK_EX) === false) {
        return '配置保存失败，请检查目录写入权限。';
    }
    return '配置已保存。';
}

function tcu_normalize_github_repo(string $repo): string
{
    $repo = trim($repo);
    if (preg_match('#^https?://github\.com/([^/\s]+)/([^/\s]+?)(?:\.git)?/?$#i', $repo, $m)) {
        return $m[1] . '/' . $m[2];
    }
    return preg_replace('#\.git$#i', '', $repo) ?? $repo;
}

function tcu_github_repo_visibility(string $visibility): string
{
    return $visibility === 'private' ? 'private' : 'public';
}

function tcu_handle_api_upload(array $config): void
{
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => '请求方法不正确。'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!tcu_verify_api_key($config)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'API Key 无效。'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (isset($_POST['github_repo']) || isset($_POST['github_path']) || isset($_POST['public_access_mode']) || isset($_POST['github_repo_visibility'])) {
        $config = tcu_config_from_post($config);
    }
    $results = tcu_store_request_images($config);
    if (!$results) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => '没有收到图片。'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ok = array_values(array_filter($results, static fn(array $item): bool => !empty($item['ok'])));
    $payload = count($results) === 1 ? $results[0] : ['ok' => count($ok) === count($results), 'files' => $results];
    if (empty($payload['ok'])) {
        http_response_code(400);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tcu_store_request_images(array $config): array
{
    $files = tcu_collect_uploads();
    $results = [];
    foreach ($files as $file) {
        $results[] = tcu_store_uploaded_file($file, $config);
    }
    return $results;
}

function tcu_upload_message(array $results): string
{
    if (!$results) {
        return '没有收到图片。';
    }
    $ok = count(array_filter($results, static fn(array $item): bool => !empty($item['ok'])));
    $failed = count($results) - $ok;
    if ($failed === 0) {
        return '上传完成。';
    }
    if ($ok === 0) {
        return '上传失败。';
    }
    return '部分上传成功：成功 ' . $ok . ' 个，失败 ' . $failed . ' 个。';
}

function tcu_collect_uploads(): array
{
    $files = [];
    $postedPaths = $_POST['upload_paths'] ?? [];
    $postedPathIndex = 0;
    foreach (['file', 'files', 'image', 'images'] as $field) {
        if (empty($_FILES[$field])) {
            continue;
        }
        $item = $_FILES[$field];
        if (is_array($item['name'] ?? null)) {
            foreach ($item['name'] as $i => $name) {
                $postedPath = is_array($postedPaths) ? (string)($postedPaths[$postedPathIndex] ?? '') : '';
                $postedPathIndex++;
                $files[] = [
                    'name' => (string)$name,
                    'full_path' => $postedPath !== '' ? $postedPath : (string)($item['full_path'][$i] ?? $name),
                    'type' => (string)($item['type'][$i] ?? ''),
                    'tmp_name' => (string)($item['tmp_name'][$i] ?? ''),
                    'error' => (int)($item['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                    'size' => (int)($item['size'][$i] ?? 0),
                ];
            }
        } else {
            $postedPath = is_array($postedPaths) ? (string)($postedPaths[$postedPathIndex] ?? '') : '';
            $postedPathIndex++;
            $files[] = [
                'name' => (string)($item['name'] ?? ''),
                'full_path' => $postedPath !== '' ? $postedPath : (string)($item['full_path'] ?? ($item['name'] ?? '')),
                'type' => (string)($item['type'] ?? ''),
                'tmp_name' => (string)($item['tmp_name'] ?? ''),
                'error' => (int)($item['error'] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int)($item['size'] ?? 0),
            ];
        }
    }
    return array_values(array_filter($files, static fn(array $file): bool => ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
}

function tcu_store_uploaded_file(array $file, array $config): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        return tcu_error_result((string)($file['name'] ?? ''), '上传文件无效或上传中断。');
    }
    $size = (int)($file['size'] ?? 0);
    $max = max(1, (int)$config['max_image_bytes']);
    if ($size < 1 || $size > $max) {
        return tcu_error_result((string)($file['name'] ?? ''), '只允许 ' . tcu_format_bytes($max) . ' 内的文件。');
    }
    $tmp = (string)$file['tmp_name'];
    $data = @file_get_contents($tmp);
    if ($data === false) {
        return tcu_error_result((string)($file['name'] ?? ''), '读取上传临时文件失败。');
    }
    $name = tcu_safe_upload_path((string)($file['full_path'] ?? $file['name'] ?? ''));
    if ($name === '') {
        $name = bin2hex(random_bytes(8));
    }
    if (($config['storage_driver'] ?? 'github') === 'github') {
        $githubError = '';
        $githubUrl = tcu_upload_file_to_github($data, $name, $config, $githubError, (string)($config['github_path'] ?? 'uploads/{Ym}'), 'Upload file ' . $name);
        if ($githubUrl === '') {
            return tcu_error_result((string)($file['name'] ?? ''), $githubError ?: 'GitHub 上传失败。');
        }
        return [
            'ok' => true,
            'name' => (string)($file['name'] ?? ''),
            'url' => $githubUrl,
            'local_url' => '',
            'markdown' => '![](' . $githubUrl . ')',
            'html' => '<img src="' . tcu_h($githubUrl) . '" alt="">',
        ];
    }
    $localError = '';
    $local = tcu_save_local_bytes($data, $name, $config, date('Ym'), $localError);
    if ($local['url'] === '') {
        return tcu_error_result((string)($file['name'] ?? ''), $localError ?: '本地保存失败。');
    }
    $url = $local['url'];
    return [
        'ok' => true,
        'name' => (string)($file['name'] ?? ''),
        'url' => $url,
        'local_url' => $local['url'],
        'markdown' => '![](' . $url . ')',
        'html' => '<img src="' . tcu_h($url) . '" alt="">',
    ];
}

function tcu_safe_upload_path(string $path): string
{
    $path = trim(str_replace('\\', '/', $path), '/');
    $parts = [];
    foreach (explode('/', $path) as $part) {
        $part = trim($part);
        if ($part === '' || $part === '.' || $part === '..') {
            continue;
        }
        $part = preg_replace('/[^\pL\pN._ -]+/u', '-', $part) ?? '';
        $part = trim($part, " .\t\n\r\0\x0B");
        if ($part !== '') {
            $parts[] = $part;
        }
    }
    return implode('/', $parts);
}

function tcu_error_result(string $name, string $error, string $localUrl = ''): array
{
    return ['ok' => false, 'name' => $name, 'error' => $error, 'local_url' => $localUrl];
}

function tcu_save_local_bytes(string $data, string $name, array $config, string $subdir, string &$error): array
{
    $error = '';
    $root = rtrim((string)$config['upload_dir'], '/\\');
    $subdir = trim(str_replace('\\', '/', $subdir), '/');
    $dir = $root . ($subdir !== '' ? '/' . $subdir : '');
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        $error = '上传目录创建失败：' . $dir;
        return ['path' => '', 'url' => ''];
    }
    if (!is_writable($dir)) {
        $error = '上传目录不可写：' . $dir;
        return ['path' => '', 'url' => ''];
    }
    $path = $dir . '/' . $name;
    if (file_put_contents($path, $data, LOCK_EX) === false) {
        $error = '图片写入失败：' . $dir;
        return ['path' => '', 'url' => ''];
    }
    $base = trim((string)($config['upload_url_base'] ?? ''));
    if ($base === '') {
        $base = rtrim(tcu_script_dir_url(), '/') . '/uploads';
    }
    $url = rtrim($base, '/') . ($subdir !== '' ? '/' . rawurlencode($subdir) : '') . '/' . rawurlencode($name);
    return ['path' => $path, 'url' => $url];
}

function tcu_upload_file_to_github(string $data, string $name, array $config, string &$error, string $pathTemplate = 'uploads/{Ym}', string $message = ''): string
{
    $error = '';
    if (!function_exists('curl_init')) {
        $error = '服务器未启用 curl 扩展，无法上传到 GitHub。';
        return '';
    }
    $token = trim((string)($config['github_token'] ?? ''));
    $repo = trim((string)($config['github_repo'] ?? ''));
    $branch = trim((string)($config['github_branch'] ?? 'main')) ?: 'main';
    if ($token === '' || !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
        $error = 'GitHub 图床未配置完整，请填写 Token 和 owner/repo 仓库。';
        return '';
    }
    $repoError = '';
    if (!tcu_github_ensure_repository_exists($repo, $token, (bool)($config['github_auto_create_repo'] ?? true), tcu_github_repo_visibility((string)($config['github_repo_visibility'] ?? 'public')), $repoError)) {
        $error = 'GitHub 仓库不可用：' . $repoError;
        return '';
    }
    [$owner, $repoName] = explode('/', $repo, 2);
    $dir = tcu_github_upload_dir($pathTemplate);
    $path = trim($dir . '/' . $name, '/');
    $endpoint = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repoName) . '/contents/' . str_replace('%2F', '/', rawurlencode($path));
    $payloadData = [
        'message' => $message !== '' ? $message : 'Upload file ' . $name,
        'content' => base64_encode($data),
        'branch' => $branch,
    ];
    $sha = tcu_github_existing_content_sha($endpoint, $token, $branch);
    if ($sha !== '') {
        $payloadData['sha'] = $sha;
    }
    $status = 0;
    $apiError = '';
    $body = tcu_github_raw_request('PUT', $endpoint, $token, $payloadData, $status, $apiError);
    if ($status < 200 || $status >= 300) {
        $msg = tcu_github_api_message($body) ?: $apiError ?: 'HTTP ' . $status;
        if (stripos($msg, 'Resource not accessible by personal access token') !== false) {
            $msg = 'Token 无法写入这个仓库。Fine-grained token 需要当前仓库 Contents: Read and write；Classic token 需要 repo 权限。';
        }
        $error = 'GitHub 上传失败：' . $msg;
        return '';
    }
    return tcu_github_public_url($path, $repo, $branch, (string)($config['github_public_base'] ?? ''), (string)($config['public_access_mode'] ?? 'raw'));
}

function tcu_github_ensure_repository_exists(string $repo, string $token, bool $autoCreate, string $visibility, string &$error): bool
{
    static $cache = [];
    $visibility = tcu_github_repo_visibility($visibility);
    $cacheKey = $repo . '|' . $visibility;
    if (isset($cache[$cacheKey])) {
        $error = $cache[$cacheKey]['error'];
        return $cache[$cacheKey]['ok'];
    }
    $error = '';
    [$owner, $name] = explode('/', $repo, 2);
    $status = 0;
    $apiError = '';
    $body = tcu_github_raw_request('GET', 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($name), $token, null, $status, $apiError);
    if ($status === 200 && is_array($body)) {
        $cache[$cacheKey] = ['ok' => true, 'error' => ''];
        return true;
    }
    if ($status !== 404 || !$autoCreate) {
        $message = tcu_github_api_message($body) ?: $apiError ?: 'HTTP ' . $status;
        $cache[$cacheKey] = ['ok' => false, 'error' => '仓库检测失败：' . $message];
        $error = $cache[$cacheKey]['error'];
        return false;
    }
    if (!tcu_github_create_repository($owner, $name, $token, $visibility, $error)) {
        $cache[$cacheKey] = ['ok' => false, 'error' => $error];
        return false;
    }
    $cache[$cacheKey] = ['ok' => true, 'error' => ''];
    return true;
}

function tcu_github_create_repository(string $owner, string $name, string $token, string $visibility, string &$error): bool
{
    $error = '';
    $status = 0;
    $apiError = '';
    $user = tcu_github_raw_request('GET', 'https://api.github.com/user', $token, null, $status, $apiError);
    if ($status !== 200 || !is_array($user)) {
        $error = '仓库不存在，且无法读取 Token 用户信息：' . (tcu_github_api_message($user) ?: $apiError ?: 'HTTP ' . $status);
        return false;
    }
    $login = (string)($user['login'] ?? '');
    $url = strcasecmp($login, $owner) === 0 ? 'https://api.github.com/user/repos' : 'https://api.github.com/orgs/' . rawurlencode($owner) . '/repos';
    $payload = ['name' => $name, 'private' => tcu_github_repo_visibility($visibility) === 'private', 'auto_init' => true];
    $created = tcu_github_raw_request('POST', $url, $token, $payload, $status, $apiError);
    if ($status >= 200 && $status < 300 && is_array($created)) {
        return true;
    }
    $message = tcu_github_api_message($created) ?: $apiError ?: 'HTTP ' . $status;
    if (stripos($message, 'Resource not accessible by personal access token') !== false || $status === 403) {
        $message .= '。自动创建仓库需要 Token 有创建仓库权限。';
    }
    $error = '仓库 ' . $owner . '/' . $name . ' 不存在，自动创建失败：' . $message;
    return false;
}

function tcu_github_raw_request(string $method, string $url, string $token, ?array $payload, int &$status, string &$error): array
{
    $status = 0;
    $error = '';
    $headers = [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $token,
        'User-Agent: Standalone-Tuchuang',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    $ch = curl_init($url);
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    if (PHP_VERSION_ID < 80500 && is_resource($ch)) {
        curl_close($ch);
    }
    $decoded = json_decode((string)$body, true);
    return is_array($decoded) ? $decoded : [];
}

function tcu_github_existing_content_sha(string $endpoint, string $token, string $branch): string
{
    $status = 0;
    $error = '';
    $body = tcu_github_raw_request('GET', $endpoint . '?ref=' . rawurlencode($branch), $token, null, $status, $error);
    return $status === 200 && !empty($body['sha']) ? (string)$body['sha'] : '';
}

function tcu_github_api_message($body): string
{
    if (!is_array($body)) {
        return '';
    }
    if (!empty($body['message'])) {
        return (string)$body['message'];
    }
    if (!empty($body['errors']) && is_array($body['errors'])) {
        return json_encode($body['errors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return '';
}

function tcu_github_upload_dir(string $template): string
{
    $template = trim($template) !== '' ? trim($template) : 'uploads/{Ym}';
    $replaced = strtr($template, [
        '{Ym}' => date('Ym'),
        '{Y}' => date('Y'),
        '{m}' => date('m'),
        '{d}' => date('d'),
    ]);
    $parts = array_filter(explode('/', str_replace('\\', '/', $replaced)), static fn(string $part): bool => $part !== '' && $part !== '.' && $part !== '..');
    return implode('/', $parts) ?: 'uploads/' . date('Ym');
}

function tcu_github_public_url(string $path, string $repo, string $branch, string $publicBase, string $mode = 'raw'): string
{
    $path = trim($path, '/');
    $publicBase = trim($publicBase);
    if ($publicBase !== '') {
        return rtrim($publicBase, '/') . '/' . str_replace('%2F', '/', rawurlencode($path));
    }
    return tcu_public_file_url($path, $repo, $branch, tcu_public_access_mode($mode));
}

function tcu_public_access_mode(string $mode): string
{
    return in_array($mode, ['github', 'jsdelivr', 'raw'], true) ? $mode : 'github';
}

function tcu_public_access_options(): array
{
    return [
        'github' => 'GitHub 仓库页面',
        'jsdelivr' => 'jsDelivr CDN',
        'raw' => 'Raw 文件前缀',
    ];
}

function tcu_encode_path(string $path): string
{
    $parts = array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== '');
    return implode('/', array_map('rawurlencode', $parts));
}

function tcu_public_file_url(string $path, string $repo, string $branch, string $mode): string
{
    $repo = trim($repo, '/');
    $branch = rawurlencode($branch);
    $path = tcu_encode_path($path);
    if ($mode === 'github') {
        return 'https://github.com/' . $repo . '/blob/' . $branch . '/' . $path;
    }
    if ($mode === 'jsdelivr') {
        return 'https://cdn.jsdelivr.net/gh/' . $repo . '@' . $branch . '/' . $path;
    }
    return 'https://raw.githubusercontent.com/' . $repo . '/' . $branch . '/' . $path;
}

function tcu_public_directory_url(string $repo, string $branch, string $dir, string $mode): string
{
    $repo = trim($repo, '/');
    $branch = rawurlencode($branch);
    $dir = tcu_encode_path($dir);
    if ($mode === 'github') {
        return 'https://github.com/' . $repo . '/tree/' . $branch . ($dir !== '' ? '/' . $dir : '');
    }
    if ($mode === 'jsdelivr') {
        return 'https://cdn.jsdelivr.net/gh/' . $repo . '@' . $branch . ($dir !== '' ? '/' . $dir : '') . '/';
    }
    return 'https://raw.githubusercontent.com/' . $repo . '/' . $branch . ($dir !== '' ? '/' . $dir : '') . '/';
}

function tcu_test_github(array $config): string
{
    if (($config['storage_driver'] ?? 'github') !== 'github') {
        return '当前是本地存储模式，不需要测试 GitHub。';
    }
    $repo = trim((string)($config['github_repo'] ?? ''));
    $token = trim((string)($config['github_token'] ?? ''));
    if ($token === '' || !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
        return 'GitHub 图床未配置完整，请填写 Token 和 owner/repo 仓库。';
    }
    $error = '';
    if (!tcu_github_ensure_repository_exists($repo, $token, (bool)($config['github_auto_create_repo'] ?? true), tcu_github_repo_visibility((string)($config['github_repo_visibility'] ?? 'public')), $error)) {
        return 'GitHub 配置不可用：' . $error;
    }
    return 'GitHub 配置可用。';
}

function tcu_detect_mime_type(string $file): string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $file);
            if (PHP_VERSION_ID < 80500) {
                finfo_close($finfo);
            }
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
    }
    $head = (string)@file_get_contents($file, false, null, 0, 32);
    if (substr($head, 0, 3) === "\xFF\xD8\xFF") return 'image/jpeg';
    if (substr($head, 0, 8) === "\x89PNG\r\n\x1A\n") return 'image/png';
    if (substr($head, 0, 6) === 'GIF87a' || substr($head, 0, 6) === 'GIF89a') return 'image/gif';
    if (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') return 'image/webp';
    if (substr($head, 4, 4) === 'ftyp' && preg_match('/avif|avis/i', substr($head, 8, 16))) return 'image/avif';
    return '';
}

function tcu_image_mime_exts(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];
}

function tcu_verify_admin_password(string $password, array $config): bool
{
    $hash = trim((string)($config['admin_password_hash'] ?? ''));
    if ($hash !== '') {
        return password_verify($password, $hash);
    }
    return tcu_hash_equals((string)($config['admin_password'] ?? ''), $password);
}

function tcu_verify_api_key(array $config): bool
{
    $expected = (string)($config['api_key'] ?? '');
    if ($expected === '') {
        return false;
    }
    $provided = (string)($_SERVER['HTTP_X_TUCHUANG_KEY'] ?? ($_POST['api_key'] ?? ($_GET['api_key'] ?? '')));
    return tcu_hash_equals($expected, $provided);
}

function tcu_hash_equals(string $expected, string $actual): bool
{
    return $expected !== '' && function_exists('hash_equals') ? hash_equals($expected, $actual) : $expected === $actual;
}

function tcu_is_logged_in(): bool
{
    return !empty($_SESSION['tcu_logged_in']);
}

function tcu_csrf_token(): string
{
    if (empty($_SESSION['tcu_csrf'])) {
        $_SESSION['tcu_csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['tcu_csrf'];
}

function tcu_verify_csrf(string $token): bool
{
    return tcu_hash_equals((string)($_SESSION['tcu_csrf'] ?? ''), $token);
}

function tcu_self_url(): string
{
    return strtok((string)($_SERVER['REQUEST_URI'] ?? 'tuchuang.php'), '?') ?: 'tuchuang.php';
}

function tcu_current_github_dir(array $config): string
{
    return tcu_github_upload_dir((string)($config['github_path'] ?? 'uploads/{Ym}'));
}

function tcu_current_api_endpoint(): string
{
    return rtrim(tcu_script_dir_url(), '/') . tcu_self_url() . '?api=upload';
}

function tcu_script_dir_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '127.0.0.1');
    $script = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/tuchuang.php')));
    $script = $script === '/' || $script === '.' ? '' : $script;
    return $scheme . '://' . $host . $script;
}

function tcu_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function tcu_format_bytes(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return rtrim(rtrim(number_format($bytes / 1024 / 1024, 2), '0'), '.') . 'MB';
    }
    return $bytes . 'B';
}

function tcu_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tcu_render_page(array $config, string $message, array $results, ?array $uploadConfig = null): void
{
    $uploadConfig = $uploadConfig ?? $config;
    $loggedIn = tcu_is_logged_in();
    $status = ($config['storage_driver'] ?? 'github') === 'github'
        ? 'GitHub：' . ((string)($config['github_repo'] ?? '') ?: '未配置仓库')
        : '本地存储';
    $targetRepo = trim((string)($uploadConfig['github_repo'] ?? ''));
    $targetBranch = trim((string)($uploadConfig['github_branch'] ?? 'main')) ?: 'main';
    $targetDir = tcu_current_github_dir($uploadConfig);
    $targetPath = $targetRepo !== '' ? $targetRepo . ' / ' . $targetBranch . ' / ' . $targetDir : '请先配置 GitHub 仓库';
    $targetAccessMode = tcu_public_access_mode((string)($uploadConfig['public_access_mode'] ?? 'github'));
    $targetRepoVisibility = tcu_github_repo_visibility((string)($uploadConfig['github_repo_visibility'] ?? 'public'));
    $targetGithubUrl = $targetRepo !== '' ? tcu_public_directory_url($targetRepo, $targetBranch, $targetDir, 'github') : '';
    $targetAccessUrl = $targetRepo !== '' ? tcu_public_directory_url($targetRepo, $targetBranch, $targetDir, $targetAccessMode) : '';
    $publicAccessOptions = tcu_public_access_options();
    $okCount = count(array_filter($results, static fn(array $item): bool => !empty($item['ok'])));
    $failedCount = count($results) - $okCount;
    $firstError = '';
    foreach ($results as $result) {
        if (empty($result['ok'])) {
            $firstError = (string)($result['error'] ?? '未知错误');
            break;
        }
    }
    $noticeMessage = $results ? '' : $message;
    $apiEndpoint = tcu_current_api_endpoint();
    $apiKey = (string)($config['api_key'] ?? '');
    $apiCurl = "curl -X POST '" . $apiEndpoint . "' \\\n  -H 'X-Tuchuang-Key: " . $apiKey . "' \\\n  -F 'github_repo=" . $targetRepo . "' \\\n  -F 'github_path=" . (string)($uploadConfig['github_path'] ?? 'uploads/{Ym}') . "' \\\n  -F 'public_access_mode=" . $targetAccessMode . "' \\\n  -F 'github_repo_visibility=" . $targetRepoVisibility . "' \\\n  -F 'files[]=@/path/to/file.zip'";
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>独立图床</title>
    <style>
        :root{--bg:#f7f8fb;--surface:#fff;--surface-soft:#f8fafc;--text:#111827;--muted:#667085;--line:#d8dee8;--line-strong:#bac4d3;--primary:#2563eb;--primary-strong:#1d4ed8;--accent:#059669;--danger:#b42318;--warning:#a15c07;--shadow:0 14px 40px rgba(17,24,39,.07)}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:16px/1.6 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;letter-spacing:0}
        main{width:min(1080px,calc(100% - 32px));margin:28px auto 44px}.card{background:var(--surface);border:1px solid var(--line);border-radius:8px;padding:24px;margin-bottom:16px;box-shadow:var(--shadow)}
        h1{font-size:30px;line-height:1.15;margin:0 0 8px}h2{font-size:19px;line-height:1.25;margin:0 0 16px}.muted{color:var(--muted)}label{display:grid;gap:7px;margin:10px 0;color:#344054;font-size:14px;font-weight:600}
        input,button,select,textarea{font:inherit}input[type=text],input[type=password],input[type=file],input[type=number],select,textarea{width:100%;min-height:44px;border:1px solid var(--line);border-radius:8px;padding:10px 12px;background:#fff;color:var(--text);transition:border-color .18s ease,box-shadow .18s ease,background-color .18s ease}
        input:focus,select:focus,textarea:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.15)}button,.btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;border:1px solid var(--primary);border-radius:8px;background:var(--primary);color:#fff;padding:10px 16px;text-decoration:none;cursor:pointer;font-weight:650;transition:background-color .18s ease,border-color .18s ease,box-shadow .18s ease,opacity .18s ease}
        button:hover,.btn:hover{background:var(--primary-strong);border-color:var(--primary-strong)}button:focus-visible,.btn:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(37,99,235,.18)}button:disabled{opacity:.58;cursor:not-allowed}.secondary{background:#fff;color:var(--text);border-color:var(--line-strong)}.secondary:hover{background:var(--surface-soft);border-color:#98a2b3}
        .row{display:flex;flex-wrap:wrap;gap:10px;align-items:center}.notice{border:1px solid #bfd7ff;border-left:4px solid var(--primary);background:#eff6ff;color:#1e3a8a;border-radius:8px;padding:12px 14px;margin:14px 0}.error{color:var(--danger);font-weight:600}
        .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:4px 16px}.grid-compact{grid-template-columns:repeat(3,minmax(0,1fr))}.check{display:flex;gap:8px;align-items:center;margin:12px 0}.check input{width:auto}
        .top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;padding:26px 28px}.top a{color:var(--primary);font-weight:650;text-decoration:none}.top a:hover{text-decoration:underline}.header-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end}.pill{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--line);border-radius:999px;background:var(--surface-soft);padding:7px 11px;color:#344054;font-size:13px;font-weight:650}.pill::before{content:"";width:8px;height:8px;border-radius:999px;background:var(--accent)}
        .section-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:16px}.section-head h2{margin:0}.section-note{margin:4px 0 0;color:var(--muted);font-size:14px}.form-note{border:1px solid #f3d9a4;background:#fff8eb;color:#7a4504;border-radius:8px;padding:10px 12px;margin:12px 0;font-size:14px}
        .target{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:0 0 14px}.target div{border:1px solid var(--line);border-radius:8px;padding:13px 14px;background:var(--surface-soft)}.target span{display:block;color:var(--muted);font-size:12px;font-weight:650;text-transform:uppercase}.target strong{display:block;margin-top:3px;overflow-wrap:anywhere}
        .picker{border:1px dashed var(--line-strong);border-radius:8px;background:linear-gradient(180deg,#fff,#f8fafc);padding:22px;margin:14px 0;text-align:center}.picker.dragover{border-color:var(--primary);background:#eff6ff}.picker input[type=file]{position:absolute;inline-size:1px;block-size:1px;opacity:0;pointer-events:none}.picker-title{font-weight:750;margin:0 0 12px;font-size:17px}.picker-controls{display:flex;flex-wrap:wrap;justify-content:center;gap:10px}.picker-controls select{width:auto;min-width:140px}.picker-status{color:var(--muted);font-size:14px;margin:10px 0 0}
        .progress{display:none;gap:8px;margin-top:14px}.progress[aria-hidden=false]{display:grid}.bar{height:12px;border-radius:999px;background:#e6ebf2;overflow:hidden}.bar span{display:block;width:0;height:100%;border-radius:inherit;background:var(--accent);transition:width .2s ease}.progress-line{display:flex;justify-content:space-between;gap:12px;color:var(--muted);font-size:14px}.progress-line strong{color:var(--text)}
        .result{display:grid;gap:10px;border-top:1px solid var(--line);padding-top:14px;margin-top:14px}.result-link{display:block;border:1px solid var(--line);border-radius:8px;background:var(--surface-soft);padding:13px 14px;overflow-wrap:anywhere;color:var(--primary);font-weight:650;text-decoration:none}.result-link:hover{text-decoration:underline}.result-link span{display:block;color:var(--muted);font-size:13px;margin-bottom:3px;font-weight:650}
        code{background:#eef2f7;border:1px solid #e4e9f1;padding:2px 5px;border-radius:6px;overflow-wrap:anywhere}textarea{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;min-height:92px}
        @media(max-width:780px){main{width:min(100% - 24px,1080px);margin:16px auto 28px}.card{padding:18px}.top{display:block;padding:20px}.header-actions{justify-content:flex-start;margin-top:14px}.grid,.grid-compact,.target{grid-template-columns:1fr}h1{font-size:27px}.section-head{display:block}.picker-controls{display:grid;grid-template-columns:1fr}.picker-controls select{width:100%}}
    </style>
</head>
<body>
<main>
    <section class="card top">
        <div>
            <h1>独立图床</h1>
            <p class="muted">上传本地文件到 GitHub 仓库，保留目录并生成访问入口。</p>
        </div>
        <div class="header-actions">
            <span class="pill"><?= tcu_h($status) ?></span>
            <?php if ($loggedIn): ?><a href="?logout=1">退出</a><?php endif; ?>
        </div>
    </section>

    <?php if ($noticeMessage !== ''): ?><div class="notice"><?= tcu_h($noticeMessage) ?></div><?php endif; ?>

    <?php if (!$loggedIn): ?>
        <section class="card">
            <h2>登录</h2>
            <form method="post">
                <input type="hidden" name="action" value="login">
                <label>管理密码<input type="password" name="password" required autofocus></label>
                <button type="submit">登录</button>
            </form>
            <p class="muted">默认密码在文件顶部配置中修改。公网部署前必须修改默认密码和 API Key。</p>
        </section>
    <?php else: ?>
        <section class="card">
            <div class="section-head">
                <div>
                    <h2>GitHub 配置</h2>
                    <p class="section-note">保存默认仓库和 Token，后续上传可直接使用。</p>
                </div>
            </div>
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= tcu_h(tcu_csrf_token()) ?>">
                <input type="hidden" name="storage_driver" value="github">
                <input type="hidden" name="github_auto_create_repo" value="1">
                <div class="grid grid-compact">
                    <label>默认远程仓库地址<input type="text" name="github_repo" value="<?= tcu_h($config['github_repo'] ?? '') ?>" placeholder="owner/repo 或 https://github.com/owner/repo"></label>
                    <label>GitHub Token<input type="password" name="github_token" value="" autocomplete="off" placeholder="<?= !empty($config['github_token']) ? '已保存，留空不修改' : '填写 token' ?>"></label>
                    <label>默认仓库类型
                        <select name="github_repo_visibility">
                            <option value="public" <?= tcu_github_repo_visibility((string)($config['github_repo_visibility'] ?? 'public')) === 'public' ? 'selected' : '' ?>>公开仓库</option>
                            <option value="private" <?= tcu_github_repo_visibility((string)($config['github_repo_visibility'] ?? 'public')) === 'private' ? 'selected' : '' ?>>私有仓库</option>
                        </select>
                    </label>
                </div>
                <p class="form-note">仓库类型仅在远程仓库不存在并自动创建时生效。Token 不会回显到页面，留空保存不会覆盖已保存 Token。</p>
                <div class="row">
                    <button type="submit" name="action" value="save_config">保存配置</button>
                    <button class="secondary" type="submit" name="action" value="test_github">测试 GitHub 链接</button>
                </div>
            </form>
        </section>

        <section class="card">
            <div class="section-head">
                <div>
                    <h2>上传文件</h2>
                    <p class="section-note">本次上传可以覆盖默认仓库、目录、访问方式和新建仓库类型。</p>
                </div>
            </div>
            <div class="target">
                <div><span>远端仓库</span><strong data-target-repo><?= tcu_h($targetRepo !== '' ? $targetRepo : '未配置') ?></strong></div>
                <div><span>分支</span><strong><?= tcu_h($targetBranch) ?></strong></div>
                <div><span>文件夹</span><strong data-target-dir><?= tcu_h($targetDir) ?></strong></div>
            </div>
            <p class="muted">远端目标：<code data-target-path data-branch="<?= tcu_h($targetBranch) ?>"><?= tcu_h($targetPath) ?></code></p>
            <form method="post" enctype="multipart/form-data" data-upload-form>
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="_csrf" value="<?= tcu_h(tcu_csrf_token()) ?>">
                <div class="grid grid-compact">
                    <label>本次上传仓库<input type="text" name="github_repo" value="<?= tcu_h($targetRepo) ?>" placeholder="owner/repo 或 https://github.com/owner/repo" data-upload-repo></label>
                    <label>本次上传文件夹<input type="text" name="github_path" value="<?= tcu_h($uploadConfig['github_path'] ?? 'uploads/{Ym}') ?>" placeholder="uploads/{Ym}" data-upload-path></label>
                    <label>访问方式
                        <select name="public_access_mode" data-upload-public-mode>
                            <?php foreach ($publicAccessOptions as $mode => $label): ?>
                                <option value="<?= tcu_h($mode) ?>" <?= $targetAccessMode === $mode ? 'selected' : '' ?>><?= tcu_h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>本次新建仓库类型
                        <select name="github_repo_visibility">
                            <option value="public" <?= $targetRepoVisibility === 'public' ? 'selected' : '' ?>>公开仓库</option>
                            <option value="private" <?= $targetRepoVisibility === 'private' ? 'selected' : '' ?>>私有仓库</option>
                        </select>
                    </label>
                </div>
                <p class="form-note">仓库类型仅在远程仓库不存在并自动创建时生效。</p>
                <div class="picker" data-upload-picker>
                    <p class="picker-title">上传文件或文件夹</p>
                    <div class="picker-controls">
                        <select data-picker-mode aria-label="上传类型">
                            <option value="file">文件</option>
                            <option value="folder">文件夹</option>
                        </select>
                        <button type="button" data-picker-button>选择文件</button>
                    </div>
                    <p class="picker-status" data-picker-status>选择上传类型后点击按钮</p>
                    <input type="file" name="files[]" multiple data-file-input>
                    <input type="file" name="files[]" webkitdirectory directory multiple data-folder-input>
                </div>
                <div class="row">
                    <button type="submit" data-upload-button>上传</button>
                </div>
                <div class="progress" aria-hidden="true" aria-live="polite" data-upload-progress>
                    <div class="progress-line">
                        <span data-upload-status>等待上传</span>
                        <strong data-upload-percent>0%</strong>
                    </div>
                    <div class="bar"><span data-upload-bar></span></div>
                </div>
            </form>
        </section>

        <?php if ($results): ?>
            <section class="card">
                <div class="section-head">
                    <div>
                        <h2>上传结果</h2>
                        <p class="section-note">上传完成后使用下面的入口访问远端目录。</p>
                    </div>
                </div>
                <div class="result">
                    <?php if ($okCount > 0 && $targetGithubUrl !== ''): ?><a class="result-link" href="<?= tcu_h($targetGithubUrl) ?>" target="_blank" rel="noopener"><span>GitHub 仓库链接</span><?= tcu_h($targetGithubUrl) ?></a><?php endif; ?>
                    <?php if ($okCount > 0 && $targetAccessUrl !== ''): ?><a class="result-link" href="<?= tcu_h($targetAccessUrl) ?>" target="_blank" rel="noopener"><span>公共访问前缀链接</span><?= tcu_h($targetAccessUrl) ?></a><?php endif; ?>
                    <?php if ($firstError !== ''): ?><p class="error">首个错误：<?= tcu_h($firstError) ?></p><?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="card">
            <div class="section-head">
                <div>
                    <h2>API 使用文档</h2>
                    <p class="section-note">服务器部署后可用同一接口进行程序化上传。</p>
                </div>
            </div>
            <p>接口：<code>POST <?= tcu_h($apiEndpoint) ?></code></p>
            <p class="muted">字段名支持 <code>file</code>、<code>files[]</code>、<code>image</code> 或 <code>images[]</code>。浏览器页面支持选择文件夹并保留相对目录。</p>
            <p class="muted">鉴权使用请求头 <code>X-Tuchuang-Key</code> 或参数 <code>api_key</code>。可选参数：<code>github_repo</code>、<code>github_path</code>、<code>public_access_mode</code>、<code>github_repo_visibility</code>（<code>public</code> 或 <code>private</code>）。</p>
            <label>curl 示例<textarea readonly><?= tcu_h($apiCurl) ?></textarea></label>
        </section>
    <?php endif; ?>
</main>
<script>
(function () {
    function githubDir(template) {
        var now = new Date();
        var year = String(now.getFullYear());
        var month = String(now.getMonth() + 1).padStart(2, '0');
        var day = String(now.getDate()).padStart(2, '0');
        var value = (template || 'uploads/{Ym}')
            .replaceAll('{Ym}', year + month)
            .replaceAll('{Y}', year)
            .replaceAll('{m}', month)
            .replaceAll('{d}', day)
            .replaceAll('\\\\', '/');
        var parts = value.split('/').filter(function (part) {
            return part !== '' && part !== '.' && part !== '..';
        });
        return parts.join('/') || 'uploads/' + year + month;
    }

    function setPickedFiles(form, items) {
        form._tcuPickedFiles = items || [];
        var status = form.querySelector('[data-picker-status]');
        if (!status) return;
        status.textContent = form._tcuPickedFiles.length
            ? '已选择 ' + form._tcuPickedFiles.length + ' 个文件'
            : '选择上传类型后点击按钮';
    }

    function updatePickerButton(form) {
        var mode = form.querySelector('[data-picker-mode]');
        var button = form.querySelector('[data-picker-button]');
        if (!mode || !button) return;
        button.textContent = mode.value === 'folder' ? '选择文件夹' : '选择文件';
    }

    function filesFromEntry(entry, prefix) {
        prefix = prefix || '';
        if (entry.isFile) {
            return new Promise(function (resolve) {
                entry.file(function (file) {
                    resolve([{file: file, path: prefix + file.name}]);
                }, function () {
                    resolve([]);
                });
            });
        }
        if (!entry.isDirectory) return Promise.resolve([]);
        var reader = entry.createReader();
        var dirPath = prefix + entry.name + '/';
        return new Promise(function (resolve) {
            var all = [];
            function readBatch() {
                reader.readEntries(async function (entries) {
                    if (!entries.length) {
                        resolve(all);
                        return;
                    }
                    for (var i = 0; i < entries.length; i++) {
                        all = all.concat(await filesFromEntry(entries[i], dirPath));
                    }
                    readBatch();
                }, function () {
                    resolve(all);
                });
            }
            readBatch();
        });
    }

    async function filesFromDrop(event) {
        var transfer = event.dataTransfer;
        if (!transfer) return [];
        if (transfer.items && transfer.items.length) {
            var items = [];
            for (var i = 0; i < transfer.items.length; i++) {
                var raw = transfer.items[i];
                var entry = raw.webkitGetAsEntry ? raw.webkitGetAsEntry() : null;
                if (entry) {
                    items = items.concat(await filesFromEntry(entry, ''));
                } else {
                    var file = raw.getAsFile ? raw.getAsFile() : null;
                    if (file) items.push({file: file, path: file.name});
                }
            }
            return items;
        }
        return Array.prototype.map.call(transfer.files || [], function (file) {
            return {file: file, path: file.webkitRelativePath || file.name};
        });
    }

    function bindUploadTargets() {
        document.querySelectorAll('[data-upload-form]').forEach(function (form) {
            var repoInput = form.querySelector('[data-upload-repo]');
            var pathInput = form.querySelector('[data-upload-path]');
            var repoTarget = document.querySelector('[data-target-repo]');
            var dirTarget = document.querySelector('[data-target-dir]');
            var pathTarget = document.querySelector('[data-target-path]');
            if (!repoInput || !pathInput || !repoTarget || !dirTarget || !pathTarget) return;
            function renderTarget() {
                var repo = repoInput.value.trim();
                var dir = githubDir(pathInput.value);
                var branch = pathTarget.getAttribute('data-branch') || 'main';
                repoTarget.textContent = repo || '未配置';
                dirTarget.textContent = dir;
                pathTarget.textContent = repo ? repo + ' / ' + branch + ' / ' + dir : '请先配置 GitHub 仓库';
            }
            repoInput.addEventListener('input', renderTarget);
            pathInput.addEventListener('input', renderTarget);
            renderTarget();
        });
    }

    function bindUnifiedPickers() {
        document.querySelectorAll('[data-upload-form]').forEach(function (form) {
            if (form.dataset.pickerBound === '1') return;
            form.dataset.pickerBound = '1';
            var picker = form.querySelector('[data-upload-picker]');
            var button = form.querySelector('[data-picker-button]');
            var mode = form.querySelector('[data-picker-mode]');
            var fileInput = form.querySelector('[data-file-input]');
            var folderInput = form.querySelector('[data-folder-input]');
            if (!picker || !button || !mode || !fileInput || !folderInput) return;

            button.addEventListener('click', function () {
                if (mode.value === 'folder') {
                    folderInput.click();
                    return;
                }
                fileInput.click();
            });

            mode.addEventListener('change', function () {
                setPickedFiles(form, []);
                fileInput.value = '';
                folderInput.value = '';
                updatePickerButton(form);
            });

            fileInput.addEventListener('change', function () {
                setPickedFiles(form, Array.prototype.map.call(fileInput.files || [], function (file) {
                    return {file: file, path: file.name};
                }));
            });

            folderInput.addEventListener('change', function () {
                setPickedFiles(form, Array.prototype.map.call(folderInput.files || [], function (file) {
                    return {file: file, path: file.webkitRelativePath || file.name};
                }));
            });

            updatePickerButton(form);

            picker.addEventListener('dragover', function (event) {
                event.preventDefault();
                picker.classList.add('dragover');
            });
            picker.addEventListener('dragleave', function () {
                picker.classList.remove('dragover');
            });
            picker.addEventListener('drop', async function (event) {
                event.preventDefault();
                picker.classList.remove('dragover');
                setPickedFiles(form, await filesFromDrop(event));
            });
        });
    }

    function bindUploadForms() {
        document.querySelectorAll('[data-upload-form]').forEach(function (form) {
            if (form.dataset.progressBound === '1') return;
            form.dataset.progressBound = '1';
            form.addEventListener('submit', function (event) {
                if (!window.XMLHttpRequest || !window.FormData) return;
                var xhr = new XMLHttpRequest();
                if (!xhr.upload || typeof xhr.upload.onprogress === 'undefined') return;
                event.preventDefault();

                var button = form.querySelector('[data-upload-button]');
                var progress = form.querySelector('[data-upload-progress]');
                var status = form.querySelector('[data-upload-status]');
                var percent = form.querySelector('[data-upload-percent]');
                var bar = form.querySelector('[data-upload-bar]');
                var originalText = button ? button.textContent : '';

                function setProgress(value, text) {
                    var safeValue = Math.max(0, Math.min(100, value));
                    if (progress) progress.setAttribute('aria-hidden', 'false');
                    if (bar) bar.style.width = safeValue + '%';
                    if (percent) percent.textContent = Math.round(safeValue) + '%';
                    if (status) status.textContent = text;
                }

                if (button) {
                    button.disabled = true;
                    button.textContent = '上传中...';
                }
                setProgress(0, '准备上传...');

                xhr.upload.onprogress = function (event) {
                    if (!event.lengthComputable) {
                        setProgress(5, '正在上传...');
                        return;
                    }
                    var value = event.total > 0 ? event.loaded / event.total * 100 : 0;
                    setProgress(value, value >= 100 ? '正在写入 GitHub...' : '正在上传...');
                };
                xhr.onload = function () {
                    var parser = new DOMParser();
                    var doc = parser.parseFromString(xhr.responseText, 'text/html');
                    var nextMain = doc.querySelector('main');
                    var currentMain = document.querySelector('main');
                    if (nextMain && currentMain) {
                        currentMain.replaceWith(nextMain);
                        bindUploadTargets();
                        bindUnifiedPickers();
                        bindUploadForms();
                        return;
                    }
                    setProgress(100, xhr.status >= 200 && xhr.status < 300 ? '上传完成。' : '上传失败，请重试。');
                    if (button) {
                        button.disabled = false;
                        button.textContent = originalText;
                    }
                };
                xhr.onerror = function () {
                    setProgress(0, '网络错误，请重试。');
                    if (button) {
                        button.disabled = false;
                        button.textContent = originalText;
                    }
                };
                var formData = new FormData(form);
                if (form._tcuPickedFiles && form._tcuPickedFiles.length) {
                    formData.delete('files[]');
                    form._tcuPickedFiles.forEach(function (item) {
                        formData.append('files[]', item.file, item.path || item.file.name);
                        formData.append('upload_paths[]', item.path || item.file.name);
                    });
                }
                xhr.open(form.method || 'POST', form.action || window.location.href);
                xhr.send(formData);
            });
        });
    }

    bindUploadTargets();
    bindUnifiedPickers();
    bindUploadForms();
})();
</script>
</body>
</html>
<?php
}
