<?php
//прогресс и лог движка для страницы «Запуск»
$dir = dirname(__FILE__).'/../';
include_once $dir."class/System.class.php";
include_once $dir."class/Database.class.php";

header('Content-Type: application/json; charset=utf-8');

if ( ! Sys::checkAuth())
{
    echo json_encode(array('error' => true, 'msg' => 'Не авторизован'), JSON_UNESCAPED_UNICODE);
    exit;
}
if (session_id() !== '') session_write_close();

echo json_encode(EngineRunner::status(), JSON_UNESCAPED_UNICODE);
?>
