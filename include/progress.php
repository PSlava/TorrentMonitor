<?php
// Прогресс загрузок — JSON для health dashboard (session-авторизация)
$dir = dirname(__FILE__)."/../";
include_once $dir."config.php";

header('Content-Type: application/json; charset=utf-8');

if ( ! Sys::checkAuth())
{
    echo json_encode(['error' => true, 'data' => []]);
    exit;
}

// Закрываем сессию чтобы не блокировать другие запросы
if (session_id() !== '')
    session_write_close();

$progress = HealthCheck::getTorrentProgress();
echo json_encode(['data' => $progress]);
?>
