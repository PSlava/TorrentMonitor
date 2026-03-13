<?php
// JSON-эндпоинт: прогресс и лог работы движка
$dir = dirname(__FILE__).'/../';
include_once $dir.'config.php';

header('Content-Type: application/json; charset=utf-8');

if ( ! Sys::checkAuth())
{
    echo json_encode(['error' => true]);
    exit;
}

// Закрываем сессию чтобы не блокировать другие запросы
if (session_id() !== '')
    session_write_close();

$progressFile = $dir.'data/engine_progress.json';
$logFile = $dir.'data/engine_log.txt';
$pidFile = $dir.'data/engine.pid';

$progress = null;
if (file_exists($progressFile))
    $progress = json_decode(file_get_contents($progressFile), true);

$log = '';
if (file_exists($logFile))
{
    $size = filesize($logFile);
    if ($size > 65536)
    {
        // Читаем последние 64 КБ
        $fh = fopen($logFile, 'r');
        fseek($fh, -65536, SEEK_END);
        fgets($fh); // пропускаем неполную строку
        $log = '... (лог обрезан) ...' . "\n" . fread($fh, 65536);
        fclose($fh);
    }
    else
        $log = file_get_contents($logFile);
}

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
            $running = ! empty(trim($out));
        }
    }
    if ( ! $running)
    {
        @unlink($pidFile);
        @unlink($progressFile);
    }
}

echo json_encode([
    'running'  => $running ? true : false,
    'progress' => $progress,
    'log'      => $log
], JSON_UNESCAPED_UNICODE);
?>
