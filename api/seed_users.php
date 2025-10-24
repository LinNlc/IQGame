<?php
require __DIR__ . '/db.php'; // 直接用你现有的 db.php
$users = [
  ['lihaolin','banquan123'],
  ['xuxiaoqing','banquan123'],
  ['wangwei','banquan123'],
  ['dengchengxi','banquan123'],
  // 按需添加...
];
$ins = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?,?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)');
foreach ($users as $u) {
  $ins->execute([$u[0], password_hash($u[1], PASSWORD_DEFAULT)]);
}
echo "ok\n";




