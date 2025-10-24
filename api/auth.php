<?php
// /api/auth.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');           // 防止 PHP 警告打断 JSON
error_reporting(E_ALL);

require __DIR__ . '/db.php';              // 复用你现有的 db.php

function jexit(int $code, array $data){
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

// 1) 健康检查/快速自测：GET /api/auth.php?ping=1
if (($_GET['ping'] ?? '') !== '') {
  jexit(200, ['ok' => true, 'ping' => 'pong', 'time' => date('c')]);
}

// 2) 解析输入（POST: application/json；也兼容 GET 临时调试）
$in = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $raw = file_get_contents('php://input') ?: '';
  $in = json_decode($raw, true);
  if (!is_array($in)) {
    jexit(400, ['ok'=>false, 'error'=>'bad_json', 'hint'=>'body must be application/json']);
  }
} else {
  // 仅供调试用：GET /api/auth.php?username=...&password=...
  if (isset($_GET['username']) && isset($_GET['password'])) {
    $in = ['username' => (string)$_GET['username'], 'password' => (string)$_GET['password']];
  } else {
    jexit(405, ['ok'=>false, 'error'=>'method_not_allowed', 'hint'=>'POST JSON or GET ping=1']);
  }
}

$user = trim($in['username'] ?? '');
$pass = (string)($in['password'] ?? '');
if ($user === '' || $pass === '') {
  jexit(400, ['ok'=>false, 'error'=>'missing_params']);
}

try {
  $stmt = $pdo->prepare('SELECT id, username, password_hash FROM users WHERE username = ? LIMIT 1');
  $stmt->execute([$user]);
  $row = $stmt->fetch();
  if (!$row || !password_verify($pass, $row['password_hash'])) {
    jexit(401, ['ok'=>false, 'error'=>'invalid_credentials']);
  }

  jexit(200, ['ok'=>true, 'user'=>['id'=>(int)$row['id'], 'username'=>$row['username']]]);
}
catch (Throwable $e) {
  // 可选：把异常写到临时日志，便于排查（会包含时间/IP，但不记录密码）
  $msg = '['.date('c').'] '.$_SERVER['REMOTE_ADDR'].' auth_error '.$e->getMessage()."\n";
  @file_put_contents(sys_get_temp_dir().'/auth_php_error.log', $msg, FILE_APPEND);
  jexit(500, ['ok'=>false, 'error'=>'server_error']);
}
