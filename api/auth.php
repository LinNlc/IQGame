<?php
// /api/auth.php - Feishu Bitable backed authentication
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

function jexit(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function log_error(string $message): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $line = sprintf('[%s] %s %s%s', date('c'), $ip, $message, PHP_EOL);
    @file_put_contents(sys_get_temp_dir() . '/auth_php_error.log', $line, FILE_APPEND);
}

if (($_GET['ping'] ?? '') !== '') {
    jexit(200, ['ok' => true, 'ping' => 'pong', 'time' => date('c')]);
}

$input = null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        jexit(400, ['ok' => false, 'error' => 'bad_json', 'hint' => 'body must be application/json']);
    }
} elseif (isset($_GET['username'], $_GET['password'])) {
    $input = ['username' => (string)$_GET['username'], 'password' => (string)$_GET['password']];
} else {
    jexit(405, ['ok' => false, 'error' => 'method_not_allowed', 'hint' => 'POST JSON or GET ping=1']);
}

$username = trim((string)($input['username'] ?? ''));
$password = (string)($input['password'] ?? '');
if ($username === '' || $password === '') {
    jexit(400, ['ok' => false, 'error' => 'missing_params']);
}

if (!function_exists('curl_init')) {
    jexit(500, ['ok' => false, 'error' => 'curl_not_available']);
}

$appId = 'cli_a843a4fed529500c';
$appSecret = (string)(getenv('FEISHU_APP_SECRET') ?: getenv('LARK_APP_SECRET') ?: getenv('APP_SECRET') ?: '');
if ($appSecret === '') {
    jexit(500, ['ok' => false, 'error' => 'missing_feishu_secret']);
}

$appToken = (string)(getenv('FEISHU_APP_TOKEN') ?: 'RGk2bAFnvakyPWs7Uhlc05sbnoe');
$userTable = (string)(getenv('FEISHU_USER_TABLE') ?: 'tbltiM3U0f6O97SP');
$userView = (string)(getenv('FEISHU_USER_VIEW') ?: 'vewQOELBge');

$buildCandidates = static function (?string $env, array $defaults): array {
    $candidates = [];
    if ($env) {
        foreach (preg_split('/[\s,|]+/', $env) as $token) {
            $token = trim($token);
            if ($token !== '') {
                $candidates[$token] = true;
            }
        }
    }
    foreach ($defaults as $item) {
        if ($item !== '' && !isset($candidates[$item])) {
            $candidates[$item] = true;
        }
    }
    return array_keys($candidates);
};

$usernameFields = $buildCandidates(getenv('FEISHU_USERNAME_FIELD') ?: null, ['账号', '用户名', '登录账号', 'username', 'user', 'User']);
$passwordFields = $buildCandidates(getenv('FEISHU_PASSWORD_FIELD') ?: null, ['密码', '口令', 'password', 'Password', 'password_hash']);
$displayFields = $buildCandidates(getenv('FEISHU_DISPLAY_FIELD') ?: null, array_merge(['姓名', '姓名（必填）', 'name', 'Name'], $usernameFields));

$tenantTokenCache = null;

$fetchTenantToken = static function () use ($appId, $appSecret, &$tenantTokenCache): string {
    $now = time();
    if ($tenantTokenCache && ($tenantTokenCache['expire'] ?? 0) > $now) {
        return $tenantTokenCache['token'];
    }
    $url = 'https://open.feishu.cn/open-apis/auth/v3/tenant_access_token/internal';
    $payload = json_encode(['app_id' => $appId, 'app_secret' => $appSecret], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('feishu_token_curl: ' . $err);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp, true);
    if (!is_array($data)) {
        throw new RuntimeException('feishu_token_decode');
    }
    if ($status >= 400 || ($data['code'] ?? 1) !== 0) {
        throw new RuntimeException('feishu_token_error: ' . ($data['msg'] ?? 'unknown'));
    }
    $tenantTokenCache = [
        'token' => (string)$data['tenant_access_token'],
        'expire' => $now + max(60, (int)($data['expire'] ?? 0) - 60),
    ];
    return $tenantTokenCache['token'];
};

$feishuRequest = static function (string $method, string $url, string $tenantToken, ?array $body = null): array {
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $tenantToken];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('feishu_curl: ' . $err);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp, true);
    if (!is_array($data)) {
        throw new RuntimeException('feishu_decode');
    }
    if ($status >= 400 || ($data['code'] ?? 1) !== 0) {
        $code = $data['code'] ?? $status;
        $msg = $data['msg'] ?? 'unknown';
        throw new RuntimeException('feishu_error:' . $code . ':' . $msg);
    }
    return is_array($data['data'] ?? null) ? $data['data'] : [];
};

$normalizeValue = static function ($value) use (&$normalizeValue): ?string {
    if (is_string($value) || is_numeric($value)) {
        $trimmed = trim((string)$value);
        return $trimmed === '' ? null : $trimmed;
    }
    if (is_array($value)) {
        if (array_key_exists('text', $value)) {
            return $normalizeValue($value['text']);
        }
        $parts = [];
        foreach ($value as $item) {
            $part = $normalizeValue($item);
            if ($part !== null && $part !== '') {
                $parts[] = $part;
            }
        }
        if ($parts) {
            return trim(implode('', $parts));
        }
    }
    return null;
};

$extractField = static function (array $fields, array $candidates) use ($normalizeValue): ?string {
    foreach ($candidates as $name) {
        if (array_key_exists($name, $fields)) {
            $val = $normalizeValue($fields[$name]);
            if ($val !== null && $val !== '') {
                return $val;
            }
        }
    }
    return null;
};

$verifyPassword = static function (string $input, string $stored): bool {
    $stored = trim($stored);
    if ($stored === '') {
        return false;
    }
    $info = password_get_info($stored);
    if (($info['algo'] ?? 0) !== 0 || strpos($stored, '$2') === 0) {
        if (@password_verify($input, $stored)) {
            return true;
        }
    }
    return hash_equals($stored, $input);
};

$searchRecord = static function (string $tenantToken, string $userName) use ($appToken, $userTable, $userView, $usernameFields, $feishuRequest, $extractField): ?array {
    $base = "https://open.feishu.cn/open-apis/bitable/v1/apps/{$appToken}/tables/{$userTable}";
    foreach ($usernameFields as $field) {
        $formula = sprintf('CurrentValue.[%s] = "%s"', $field, addcslashes($userName, "\\\""));
        try {
            $data = $feishuRequest('POST', $base . '/records/search', $tenantToken, [
                'view_id' => $userView,
                'page_size' => 1,
                'filter' => ['formula' => $formula],
            ]);
            $items = $data['items'] ?? [];
            if (!empty($items)) {
                return $items[0];
            }
        } catch (Throwable $e) {
            log_error('feishu_search_warn: ' . $e->getMessage());
        }
    }

    $pageToken = '';
    do {
        $query = $base . '/records?view_id=' . rawurlencode($userView) . '&page_size=200';
        if ($pageToken !== '') {
            $query .= '&page_token=' . rawurlencode($pageToken);
        }
        $data = $feishuRequest('GET', $query, $tenantToken);
        foreach ($data['items'] ?? [] as $item) {
            $fields = $item['fields'] ?? [];
            $candidate = $extractField($fields, $usernameFields);
            if ($candidate !== null && $candidate === $userName) {
                return $item;
            }
        }
        $pageToken = (string)($data['page_token'] ?? '');
    } while ($pageToken !== '');

    return null;
};

try {
    $tenantToken = $fetchTenantToken();
    $record = $searchRecord($tenantToken, $username);
    if (!$record) {
        jexit(401, ['ok' => false, 'error' => 'invalid_credentials']);
    }
    $fields = is_array($record['fields'] ?? null) ? $record['fields'] : [];
    $storedPassword = $extractField($fields, $passwordFields);
    if ($storedPassword === null) {
        log_error('password_field_missing for user ' . $username);
        jexit(401, ['ok' => false, 'error' => 'invalid_credentials']);
    }
    if (!$verifyPassword($password, $storedPassword)) {
        jexit(401, ['ok' => false, 'error' => 'invalid_credentials']);
    }

    $displayName = $extractField($fields, $displayFields) ?? $username;
    $recordId = (string)($record['record_id'] ?? $record['id'] ?? '0');

    jexit(200, [
        'ok' => true,
        'user' => [
            'id' => $recordId,
            'username' => $username,
            'displayName' => $displayName,
        ],
    ]);
} catch (Throwable $e) {
    log_error('auth_error: ' . $e->getMessage());
    jexit(500, ['ok' => false, 'error' => 'server_error']);
}
