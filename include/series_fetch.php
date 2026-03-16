<?php
// AJAX-эндпоинт: получение списка сериалов с трекера
$dir = dirname(__FILE__).'/../';
include_once $dir.'config.php';

header('Content-Type: application/json; charset=utf-8');

if ( ! Sys::checkAuth())
{
    echo json_encode(['error' => true, 'msg' => 'Не авторизован']);
    exit;
}

if (session_id() !== '')
    session_write_close();

$tracker = $_GET['tracker'] ?? '';
$limit = (int)($_GET['limit'] ?? 50);
if ($limit < 1 || $limit > 200) $limit = 50;

// Маппинг трекер → класс
$trackerMap = [
    'baibako.tv'        => 'baibako',
    'hamsterstudio.org' => 'hamsterstudio',
    'lostfilm.tv'       => 'lostfilm',
    'lostfilm-mirror'   => 'lostfilmmirror',
    'newstudio.tv'      => 'newstudio',
];

if ( ! isset($trackerMap[$tracker]))
{
    echo json_encode(['error' => true, 'msg' => 'Трекер не поддерживает список сериалов']);
    exit;
}

$className = $trackerMap[$tracker];
$engineFile = $dir.'trackers/'.$tracker.'.engine.php';
if ( ! file_exists($engineFile))
{
    echo json_encode(['error' => true, 'msg' => 'Модуль трекера не найден']);
    exit;
}

include_once $engineFile;

if ( ! method_exists($className, 'getSeriesList'))
{
    echo json_encode(['error' => true, 'msg' => 'Трекер не поддерживает список сериалов']);
    exit;
}

try {
    $series = call_user_func([$className, 'getSeriesList'], $limit);
} catch (Exception $e) {
    echo json_encode(['error' => true, 'msg' => 'Ошибка при получении списка: '.$e->getMessage()]);
    exit;
}

// Убираем уже добавленные сериалы для этого трекера
$existing = [];
$torrents = Database::getTorrentsList('name');
if (is_array($torrents))
{
    foreach ($torrents as $t)
    {
        if ($t['tracker'] === $tracker && empty($t['torrent_id']))
            $existing[strtolower($t['name'])] = true;
    }
}

$filtered = [];
foreach ($series as $item)
{
    // Поддержка и старого формата (строка) и нового ({name, episode})
    $name = is_array($item) ? $item['name'] : $item;
    $episode = is_array($item) ? ($item['episode'] ?? null) : null;
    if ( ! isset($existing[strtolower($name)]))
        $filtered[] = ['name' => $name, 'episode' => $episode];
}

usort($filtered, function($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});

echo json_encode(['error' => false, 'series' => $filtered], JSON_UNESCAPED_UNICODE);
