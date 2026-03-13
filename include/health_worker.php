<?php
// Фоновый воркер проверки состояния — запускается через CLI
// Записывает результаты в data/health_results.json пошагово
set_time_limit(0);

$dir = dirname(__FILE__).'/../';
include_once $dir.'config.php';

$resultsFile = $dir.'data/health_results.json';

function writeHealthResults($file, $data)
{
    $data['updated_at'] = date('Y-m-d H:i:s');
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

Migration::run();

$checks = [];

// 1. Интернет
writeHealthResults($resultsFile, ['status' => 'running', 'phase' => 'Проверка интернета', 'checks' => $checks]);
$checks[] = HealthCheck::checkSingle('internet', 'Подключение к интернету', function() {
    return Sys::checkInternet();
});
writeHealthResults($resultsFile, ['status' => 'running', 'phase' => 'Основные проверки', 'checks' => $checks]);

// 2. Конфиг
$checks[] = HealthCheck::checkSingle('config', 'Конфигурационный файл', function() {
    return Sys::checkConfigExist();
});

// 3. cURL
$checks[] = HealthCheck::checkSingle('curl', 'Расширение cURL', function() {
    return Sys::checkCurl();
});

// 4-5. Директории
$torrentPath = $dir.'torrents/';
$checks[] = HealthCheck::checkSingle('write_torrents', 'Запись в '.basename($torrentPath), function() use ($torrentPath) {
    return Sys::checkWriteToPath($torrentPath);
});
$checks[] = HealthCheck::checkSingle('write_system', 'Запись в системную директорию', function() use ($dir) {
    return Sys::checkWriteToPath($dir);
});
writeHealthResults($resultsFile, ['status' => 'running', 'phase' => 'Проверка последнего запуска', 'checks' => $checks]);

// 6. Последний запуск
$checks[] = HealthCheck::checkLastRunPublic();
writeHealthResults($resultsFile, ['status' => 'running', 'phase' => 'Проверка торрент-клиента', 'checks' => $checks]);

// 7. Торрент-клиент
$checks[] = HealthCheck::checkTorrentClientPublic();
writeHealthResults($resultsFile, ['status' => 'running', 'phase' => 'Проверка трекеров', 'checks' => $checks]);

// 8. Куки трекеров
$trackerChecks = HealthCheck::checkTrackerCookiesPublic();
$checks = array_merge($checks, $trackerChecks);

writeHealthResults($resultsFile, ['status' => 'done', 'checks' => $checks]);
