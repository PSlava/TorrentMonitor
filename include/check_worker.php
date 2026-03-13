<?php
// Фоновый воркер тестирования системы — запускается через CLI
// Записывает результаты в data/check_results.json пошагово
set_time_limit(0);

$dir = dirname(__FILE__).'/../';
include_once $dir.'config.php';

$resultsFile = $dir.'data/check_results.json';

function writeResults($file, $data)
{
    $data['updated_at'] = date('Y-m-d H:i:s');
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

$results = [];
$errors = 0;

// === Основные настройки ===

// Интернет
writeResults($resultsFile, ['status' => 'running', 'phase' => 'Проверка интернета', 'results' => $results, 'errors' => $errors]);
if (Sys::checkInternet())
    $results[] = ['ok' => true, 'group' => 'system', 'text' => 'Подключение к интернету установлено.'];
else
{
    $results[] = ['ok' => false, 'group' => 'system', 'text' => 'Отсутствует подключение к интернету.'];
    $errors++;
    writeResults($resultsFile, ['status' => 'done', 'results' => $results, 'errors' => $errors]);
    exit;
}
writeResults($resultsFile, ['status' => 'running', 'phase' => 'Основные настройки', 'results' => $results, 'errors' => $errors]);

// Конфиг
if (Sys::checkConfigExist())
    $results[] = ['ok' => true, 'group' => 'system', 'text' => 'Конфигурационный файл существует.'];
else
{
    $results[] = ['ok' => false, 'group' => 'system', 'text' => 'Для корректной работы необходимо внести изменения в конфигурационный файл.'];
    $errors++;
    writeResults($resultsFile, ['status' => 'done', 'results' => $results, 'errors' => $errors]);
    exit;
}

// cURL
if (Sys::checkCurl())
    $results[] = ['ok' => true, 'group' => 'system', 'text' => 'Расширение cURL установлено.'];
else
{
    $results[] = ['ok' => false, 'group' => 'system', 'text' => 'Для работы системы необходимо включить расширение cURL.'];
    $errors++;
    writeResults($resultsFile, ['status' => 'done', 'results' => $results, 'errors' => $errors]);
    exit;
}

// Запись в директорию торрентов
$torrentPath = $dir.'torrents/';
if (Sys::checkWriteToPath($torrentPath))
    $results[] = ['ok' => true, 'group' => 'system', 'text' => 'Запись в директорию для torrent-файлов '.$torrentPath.' разрешена.'];
else
{
    $results[] = ['ok' => false, 'group' => 'system', 'text' => 'Запись в директорию для torrent-файлов '.$torrentPath.' запрещена.'];
    $errors++;
}

// Запись в системную директорию
if (Sys::checkWriteToPath($dir))
    $results[] = ['ok' => true, 'group' => 'system', 'text' => 'Запись в системную директорию '.$dir.' разрешена.'];
else
{
    $results[] = ['ok' => false, 'group' => 'system', 'text' => 'Запись в системную директорию '.$dir.' запрещена.'];
    $errors++;
}

writeResults($resultsFile, ['status' => 'running', 'phase' => 'Проверка трекеров', 'results' => $results, 'errors' => $errors]);

// === Трекеры ===

$trackers = Database::getTrackersList();
$trackersWithSearch = ['nnmclub.to', 'pornolab.net', 'rutracker.org', 'tapochek.net', 'tfile.cc'];
$trackersNoCredentials = ['lostfilm-mirror', 'rutor.org', 'tfile.cc'];

// Маппинг трекер → URL для проверки доступности
$trackerUrls = [
    'baibako.tv_forum' => 'http://baibako.tv/',
    'lostfilm.tv'      => 'https://www.lostfilm.tv/',
    'lostfilm-mirror'  => 'https://rss.bzda.ru/rss.xml',
    'nnmclub.to'       => 'https://nnmclub.to/forum/index.php',
    'rutor.org'        => 'http://rutor.info/',
    'rutracker.org'    => 'http://rutracker.org/forum/index.php',
];

$total = count($trackers);
for ($i = 0; $i < $total; $i++)
{
    $tracker = $trackers[$i];
    writeResults($resultsFile, [
        'status'  => 'running',
        'phase'   => 'Проверка трекера: '.$tracker,
        'current' => $i + 1,
        'total'   => $total,
        'results' => $results,
        'errors'  => $errors
    ]);

    // Движок
    if (file_exists($dir.'trackers/'.$tracker.'.engine.php'))
        $results[] = ['ok' => true, 'group' => $tracker, 'text' => 'Основной файл для работы с трекером '.$tracker.' найден.'];
    else
    {
        $results[] = ['ok' => false, 'group' => $tracker, 'text' => 'Основной файл для работы с трекером '.$tracker.' не найден.'];
        $errors++;
    }

    // Search
    if (in_array($tracker, $trackersWithSearch))
    {
        if (file_exists($dir.'trackers/'.$tracker.'.search.php'))
            $results[] = ['ok' => true, 'group' => $tracker, 'text' => 'Дополнительный файл для работы с трекером '.$tracker.' найден.'];
        else
        {
            $results[] = ['ok' => false, 'group' => $tracker, 'text' => 'Дополнительный файл для работы с трекером '.$tracker.' не найден.'];
            $errors++;
        }
    }

    // Учётные данные
    if (in_array($tracker, $trackersNoCredentials))
        $results[] = ['ok' => true, 'group' => $tracker, 'text' => 'Учётные данные для работы с трекером '.$tracker.' не требуются.'];
    elseif (Database::checkTrackersCredentialsExist($tracker))
        $results[] = ['ok' => true, 'group' => $tracker, 'text' => 'Учётные данные для работы с трекером '.$tracker.' найдены.'];
    else
    {
        $results[] = ['ok' => false, 'group' => $tracker, 'text' => 'Учётные данные для работы с трекером '.$tracker.' не найдены.'];
        $errors++;
    }

    // Доступность (через getUrlContent — прокси подхватывается автоматически)
    $page = isset($trackerUrls[$tracker]) ? $trackerUrls[$tracker] : 'http://'.$tracker;
    if (Sys::checkavAilability($page))
        $results[] = ['ok' => true, 'group' => $tracker, 'text' => 'Трекер '.$tracker.' доступен.'];
    else
    {
        $results[] = ['ok' => false, 'group' => $tracker, 'text' => 'Трекер '.$tracker.' не доступен.'];
        $errors++;
    }
}

writeResults($resultsFile, ['status' => 'done', 'results' => $results, 'errors' => $errors]);
