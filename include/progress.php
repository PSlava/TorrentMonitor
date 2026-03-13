<?php
// Прогресс загрузок — JSON для health dashboard (session-авторизация)
$dir = dirname(__FILE__)."/../";
include_once $dir."config.php";
include_once $dir."class/Database.class.php";
include_once $dir."class/System.class.php";
include_once $dir."class/HealthCheck.class.php";
include_once $dir."class/Transmission.class.php";
include_once $dir."class/TransmissionRPC.class.php";
include_once $dir."class/qBittorrent.class.php";
include_once $dir."class/Deluge.class.php";
include_once $dir."class/TorrServer.class.php";
include_once $dir."class/SynologyDS.class.php";

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
