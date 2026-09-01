<?php
//AJAX-эндпоинт: список сериалов с трекера для комбобокса в диалоге добавления
$dir = dirname(__FILE__).'/../';
include_once $dir.'autoload.php';
include_once $dir.'config.php';
include_once $dir.'class/System.class.php';
include_once $dir.'class/Database.class.php';
include_once $dir.'class/Errors.class.php';

header('Content-Type: application/json; charset=utf-8');

//на трекер уходит до трёх последовательных запросов (проверка куки, логин, лента),
//каждый ограничен httpTimeout. Дефолтных 30 секунд на это не хватает, а при выходе
//за лимит PHP отдаёт пустое тело - фронт получает «Ошибка загрузки списка» без причины
set_time_limit(180);

$_seriesDone = FALSE;
register_shutdown_function(function () use (&$_seriesDone) {
    if ($_seriesDone)
        return;
    $err = error_get_last();
    $msg = ($err !== NULL && $err['type'] === E_ERROR)
        ? 'Трекер не ответил вовремя'
        : 'Не удалось получить список';
    echo json_encode(array('error' => TRUE, 'msg' => $msg), JSON_UNESCAPED_UNICODE);
});

if ( ! Sys::checkAuth())
{
    http_response_code(403);
    $_seriesDone = TRUE;
    echo json_encode(array('error' => TRUE, 'msg' => 'Не авторизован'));
    exit;
}
if (session_id() !== '') session_write_close();

//трекеры, у которых есть RSS-лента со списком сериалов
$allowed = array('baibako.tv', 'hamsterstudio.org', 'lostfilm.tv', 'lostfilm-mirror', 'newstudio.tv');

$tracker = isset($_GET['tracker']) ? $_GET['tracker'] : '';
$limit   = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
if ($limit < 1 || $limit > 200)
    $limit = 50;

if ( ! in_array($tracker, $allowed, TRUE))
{
    $_seriesDone = TRUE;
    echo json_encode(array('error' => TRUE, 'msg' => 'Трекер не поддерживает список сериалов'));
    exit;
}

$engineFile = $dir.'trackers/'.$tracker.'.engine.php';
$className  = Sys::resolveTrackerClass($engineFile, $tracker, 'getSeriesList');
if ($className === NULL)
{
    $_seriesDone = TRUE;
    echo json_encode(array('error' => TRUE, 'msg' => 'Модуль трекера не найден'));
    exit;
}

try
{
    $series = call_user_func(array($className, 'getSeriesList'), $limit);
}
catch (Throwable $e)
{
    $_seriesDone = TRUE;
    echo json_encode(array('error' => TRUE, 'msg' => 'Ошибка при получении списка: '.$e->getMessage()));
    exit;
}

if ( ! is_array($series) || empty($series))
{
    $_seriesDone = TRUE;
    echo json_encode(array('error' => TRUE, 'msg' => 'Трекер не вернул список — проверьте учётные данные и доступность трекера'));
    exit;
}

//убираем сериалы, за которыми уже следим на этом трекере
$existing = array();
$torrents = Database::getTorrentsList('name');
if (is_array($torrents))
{
    foreach ($torrents as $t)
    {
        //строки сериалов отличаются от тем отсутствием id темы на трекере
        if ($t['tracker'] === $tracker && empty($t['torrent_id']))
            $existing[mb_strtolower($t['name'], 'UTF-8')] = TRUE;
    }
}

$filtered = array();
foreach ($series as $item)
{
    //поддержка и строкового формата, и array('name' => ..., 'episode' => ...)
    $name    = is_array($item) ? $item['name'] : $item;
    $episode = is_array($item) && isset($item['episode']) ? $item['episode'] : NULL;
    if ( ! isset($existing[mb_strtolower($name, 'UTF-8')]))
        $filtered[] = array('name' => $name, 'episode' => $episode);
}

usort($filtered, function($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});

$_seriesDone = TRUE;
echo json_encode(array('error' => FALSE, 'series' => $filtered), JSON_UNESCAPED_UNICODE);
