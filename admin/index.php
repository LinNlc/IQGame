<?php
// ===== 简易会话鉴权：登录一次后保持会话，不再依赖 ?p=123456 =====
session_start();
$PASS = '123456';

// 1) 首次访问带 ?p=xxxx 且正确，则写入会话并跳转去掉参数
if (isset($_GET['p']) && $_GET['p'] === $PASS) {
  $_SESSION['admin_ok'] = true;
  header('Location: index.php'); // 清爽URL，后续筛选不再丢鉴权
  exit;
}

// 2) 支持登出
if (isset($_GET['logout'])) {
  $_SESSION['admin_ok'] = false;
  session_destroy();
  header('Location: index.php');
  exit;
}

// 3) 若未登录，显示一个极简登录页（也支持直接用 ?p=123456 进来）
if (empty($_SESSION['admin_ok'])) {
  ?>
  <!doctype html><meta charset="utf-8">
  <title>登录后台</title>
  <style>
    body{font-family:ui-sans-serif,system-ui,Segoe UI,Arial;padding:40px;background:#f6f7f9}
    .box{max-width:360px;margin:auto;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:24px;box-shadow:0 6px 20px rgba(0,0,0,.06)}
    input,button{width:100%;padding:10px 12px;font-size:14px;margin-top:10px}
    button{background:#111827;color:#fff;border:none;border-radius:8px;cursor:pointer}
    .tip{color:#6b7280;font-size:12px;margin-top:8px}
  </style>
  <div class="box">
    <h2>Twine 埋点后台</h2>
    <form method="get" action="index.php">
      <input type="password" name="p" placeholder="请输入访问口令">
      <button type="submit">进入后台</button>
      <div class="tip">也可直接在地址栏加参数：<code>?p=123456</code></div>
    </form>
  </div>
  <?php
  exit;
}

// ===== 通过验证，开始取数 =====
require __DIR__.'/../api/db.php';

// 过滤项：?q=玩家名(或token)  &type=node|ending|start|choice
$q    = isset($_GET['q']) ? trim($_GET['q']) : '';
$type = isset($_GET['type']) ? trim($_GET['type']) : '';

$sql = "SELECT e.id, e.created_at, e.name, e.player_token, e.event_type, e.label, e.passage, e.ip
        FROM event e
        WHERE 1 ";
$arg = [];
if ($q !== '')   { $sql .= " AND (e.name LIKE ? OR e.player_token LIKE ?)"; $arg[]="%$q%"; $arg[]="%$q%"; }
if ($type !== ''){ $sql .= " AND e.event_type = ?"; $arg[]=$type; }
$sql .= " ORDER BY e.id DESC LIMIT 500";

$stm = $pdo->prepare($sql);
$stm->execute($arg);
$rows = $stm->fetchAll();

?>
<!doctype html><meta charset="utf-8">
<title>Twine埋点记录</title>
<style>
  body{font-family:ui-sans-serif,system-ui,Segoe UI,Arial;padding:16px;}
  table{border-collapse:collapse;width:100%;}
  th,td{border:1px solid #e5e7eb;padding:8px;font-size:14px;}
  th{background:#f9fafb;text-align:left;}
  .f{display:flex;gap:8px;margin-bottom:12px;align-items:center;flex-wrap:wrap}
  input,select,button{padding:6px 10px;font-size:14px}
  .muted{color:#6b7280;font-size:12px;margin-left:auto}
  .logout{margin-left:8px;color:#ef4444;text-decoration:none}
</style>

<div class="f">
  <form method="get" action="index.php" class="f">
    <input name="q" value="<?=htmlspecialchars($q, ENT_QUOTES)?>" placeholder="玩家名 或 token">
    <select name="type">
      <option value=""         <?= $type===''?'selected':'' ?>>全部类型</option>
      <option value="node"     <?= $type==='node'?'selected':'' ?>>关键节点</option>
      <option value="ending"   <?= $type==='ending'?'selected':'' ?>>结局</option>
      <option value="start"    <?= $type==='start'?'selected':'' ?>>进入游戏</option>
      <option value="choice"   <?= $type==='choice'?'selected':'' ?>>选项</option>
    </select>
    <button type="submit">筛选</button>
    <a class="logout" href="?logout=1">退出</a>
  </form>
  <div class="muted">最多显示最近 500 条</div>
</div>

<table>
  <tr>
    <th>ID</th><th>时间</th><th>玩家名</th><th>token</th>
    <th>类型</th><th>标签(结局/节点名)</th><th>Passage</th><th>IP</th>
  </tr>
  <?php if (empty($rows)): ?>
  <tr><td colspan="8" class="muted" style="text-align:center;color:#6b7280">暂无数据</td></tr>
  <?php else: foreach ($rows as $r): ?>
  <tr>
    <td><?= (int)$r['id'] ?></td>
    <td><?= htmlspecialchars($r['created_at']) ?></td>
    <td><?= htmlspecialchars($r['name'] ?: '未署名玩家') ?></td>
    <td style="font-family:monospace"><?= htmlspecialchars($r['player_token']) ?></td>
    <td><?= htmlspecialchars($r['event_type']) ?></td>
    <td><?= htmlspecialchars($r['label']) ?></td>
    <td><?= htmlspecialchars($r['passage'] ?: '') ?></td>
    <td><?= htmlspecialchars($r['ip'] ?: '') ?></td>
  </tr>
  <?php endforeach; endif; ?>
</table>