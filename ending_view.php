<?php
$path = __DIR__.'/logs.csv';
$rows=[]; $header=[];
if (file_exists($path) && ($fp=fopen($path,'r'))) {
  $header=fgetcsv($fp) ?: [];
  while(($r=fgetcsv($fp))!==false){ $rows[] = array_combine($header,$r); }
  fclose($fp);
}
?><!doctype html><meta charset="utf-8"><title>日志一览</title>
<h2>最新 200 条</h2>
<table border="1" cellspacing="0" cellpadding="6">
<tr><th>时间</th><th>姓名</th><th>节点</th><th>选项</th></tr>
<?php foreach(array_slice(array_reverse($rows),0,200) as $r){
  echo '<tr><td>',htmlspecialchars($r['when']??$r['ts']??''),'</td><td>',
  htmlspecialchars($r['name']??''),'</td><td>',
  htmlspecialchars($r['node']??''),'</td><td>',
  htmlspecialchars($r['choice']??''),'</td></tr>'; } ?>
</table>
