<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');              // 同源可留着，跨源更安全
header('Access-Control-Allow-Headers: content-type');

require __DIR__.'/db.php';

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);

if (!$in) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'invalid json']); exit;
}

$token   = substr($in['token']   ?? '', 0, 64);
$name    = substr($in['name']    ?? '', 0, 64);
$type    = substr($in['type']    ?? '', 0, 16); // start/node/ending/choice
$label   = substr($in['label']   ?? '', 0,128);
$passage = substr($in['passage'] ?? '', 0,128);

if (!$token || !$type || !$label) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'missing field']); exit;
}

$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

$pdo->beginTransaction();
$pdo->prepare("INSERT INTO player (token,name) VALUES (?,?)
               ON DUPLICATE KEY UPDATE name=VALUES(name), last_seen=NOW()")
    ->execute([$token, $name ?: null]);

$pdo->prepare("INSERT INTO event (player_token,name,event_type,label,passage,ua,ip)
               VALUES (?,?,?,?,?,?,?)")
    ->execute([$token,$name ?: null,$type,$label,$passage ?: null,$ua,$ip]);

$pdo->commit();
echo json_encode(['ok'=>true]);




