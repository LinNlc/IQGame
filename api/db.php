<?php
// ——— 1) 主连接：容器内地址（给 PHP/同机容器用） ———
$DB_HOST = '1Panel-mysql-9yPI';  // ← 1Panel 显示“PHP 运行环境/容器安装的应用使用此连接地址”
$DB_PORT = 3306;
$DB_NAME = 'banquan';
$DB_USER = 'banquan';
$DB_PASS = 'mysql2025';

// 可选：检查 pdo_mysql 是否可用
if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>'pdo_mysql_not_loaded']);
  exit;
}

function connect($host, $port, $db, $user, $pass) {
  $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
  return new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
}

try {
  // 先用容器内地址
  $pdo = connect($DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS);
} catch (PDOException $e1) {
  // ——— 2) 兜底：尝试外部连接（仅当上面失败时） ———
  try {
    $fallbackHost = '115.190.9.41';
    $pdo = connect($fallbackHost, 3306, $DB_NAME, $DB_USER, $DB_PASS);
  } catch (PDOException $e2) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'ok'=>false,
      'error'=>'db_connect_failed',
      'tried'=>["$DB_HOST:$DB_PORT","$fallbackHost:3306"],
      'message'=>$e2->getMessage()
    ]);
    exit;
  }
}
