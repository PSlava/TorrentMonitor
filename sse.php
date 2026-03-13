<?php
// SSE (Server-Sent Events) — поток событий для реал-тайм обновлений
ignore_user_abort(true);

$dir = dirname(__FILE__).'/';
include_once $dir.'config.php';
include_once $dir.'class/Database.class.php';
include_once $dir.'class/System.class.php';
include_once $dir.'class/EventBus.class.php';

// Проверка авторизации (только cookie/session)
if ( ! Sys::checkAuth())
{
    http_response_code(401);
    exit('Unauthorized');
}

// Закрываем сессию — SSE не должен блокировать другие запросы
if (session_id() !== '')
    session_write_close();

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Отключаем буферизацию
if (function_exists('apache_setenv'))
    @apache_setenv('no-gzip', '1');
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');

while (ob_get_level() > 0)
    ob_end_flush();

$lastId = isset($_GET['lastEventId']) ? (int)$_GET['lastEventId'] : 0;
if (isset($_SERVER['HTTP_LAST_EVENT_ID']))
    $lastId = (int)$_SERVER['HTTP_LAST_EVENT_ID'];

if ($lastId == 0)
    $lastId = EventBus::getLastId();

$maxTime = 60; // 1 минута, потом клиент переподключится (экономим воркеры Apache)
$startTime = time();

// Отправляем начальное событие
echo "retry: 5000\n";
echo "data: {\"type\":\"connected\",\"lastId\":{$lastId}}\n\n";
flush();

// Файл-маркер последнего события (обновляется в EventBus::emit)
$lastIdFile = $dir.'data/last_event_id';

while (time() - $startTime < $maxTime)
{
    if (connection_aborted())
        break;

    // Быстрая проверка файла-маркера — если ID не изменился, пропускаем DB-запрос
    $fileId = 0;
    if (file_exists($lastIdFile))
        $fileId = (int)trim(file_get_contents($lastIdFile));

    if ($fileId > $lastId)
    {
        $events = EventBus::getSince($lastId);
        foreach ($events as $event)
        {
            $lastId = $event['id'];
            $json = json_encode($event, JSON_UNESCAPED_UNICODE);
            $type = str_replace(["\r", "\n"], '', $event['type']);
            echo "id: {$event['id']}\n";
            echo "event: {$type}\n";
            echo "data: {$json}\n\n";
        }
        flush();
    }

    sleep(5);
}
?>
