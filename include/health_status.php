<?php
// JSON-эндпоинт: статус проверки состояния
$dir = dirname(__FILE__).'/../';
include_once $dir.'config.php';

header('Content-Type: application/json; charset=utf-8');

if ( ! Sys::checkAuth())
{
    echo json_encode(['error' => true]);
    exit;
}

if (session_id() !== '')
    session_write_close();

$resultsFile = $dir.'data/health_results.json';
$pidFile = $dir.'data/health.pid';

$data = null;
if (file_exists($resultsFile))
    $data = json_decode(file_get_contents($resultsFile), true);

// Проверяем, запущен ли процесс
$running = false;
if (file_exists($pidFile))
{
    $pid = (int)trim(file_get_contents($pidFile));
    if ($pid > 0)
    {
        if (function_exists('posix_kill'))
            $running = posix_kill($pid, 0);
        else
        {
            $out = shell_exec('ps -p '.(int)$pid.' -o pid= 2>/dev/null');
            $running = ! empty(trim($out ?: ''));
        }
    }
    if ( ! $running)
        @unlink($pidFile);
}

echo json_encode([
    'running' => $running,
    'data'    => $data
], JSON_UNESCAPED_UNICODE);
