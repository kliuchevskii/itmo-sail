<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$d = one('SELECT * FROM documents WHERE id=?', [(int) get('id')]);
if (!$d || ((int) $d['user_id'] !== (int) $u['id'] && !is_admin($u))) { http_response_code(404); exit('Документ не найден'); }
$path = __DIR__ . '/storage/docs/' . basename($d['stored_name']);
if (!is_file($path)) { http_response_code(404); exit('Файл отсутствует на сервере'); }
header('Content-Type: ' . $d['mime']);
header('Content-Length: ' . filesize($path));
header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($d['orig_name']));
header('Cache-Control: private, no-store');
readfile($path);
