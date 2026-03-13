<?php
// Запуск тестирования в фоновом процессе
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

if ( ! is_dir($dir.'data'))
    @mkdir($dir.'data', 0750, true);

$pidFile = $dir.'data/check.pid';
$resultsFile = $dir.'data/check_results.json';

// Проверяем, не запущен ли уже
if (file_exists($pidFile))
{
    $pid = (int)trim(file_get_contents($pidFile));
    if ($pid > 0 && isProcessRunning($pid))
    {
        echo json_encode(['error' => false, 'msg' => 'Тестирование уже выполняется']);
        exit;
    }
    @unlink($pidFile);
}

// Очищаем предыдущие результаты
@unlink($resultsFile);

// Находим PHP CLI
$phpBin = '';
if ( ! empty(PHP_BINARY) && stripos(PHP_BINARY, 'php') !== false && file_exists(PHP_BINARY))
    $phpBin = PHP_BINARY;
if (empty($phpBin))
    $phpBin = trim(shell_exec('which php 2>/dev/null') ?: '');
if (empty($phpBin))
{
    $paths = ['/opt/homebrew/bin/php', '/usr/local/bin/php', '/usr/bin/php'];
    foreach ($paths as $p)
        if (file_exists($p)) { $phpBin = $p; break; }
}
if (empty($phpBin))
{
    echo json_encode(['error' => true, 'msg' => 'PHP CLI не найден']);
    exit;
}

$workerPath = escapeshellarg(dirname(__FILE__).'/check_worker.php');
$pidPath = escapeshellarg($pidFile);

$cmd = escapeshellarg($phpBin).' '.$workerPath.' < /dev/null > /dev/null 2>&1 & echo $! > '.$pidPath;
exec($cmd);

usleep(300000);

$pid = 0;
if (file_exists($pidFile))
    $pid = (int)trim(file_get_contents($pidFile));

if ($pid > 0 && isProcessRunning($pid))
    echo json_encode(['error' => false, 'msg' => 'Тестирование запущено', 'pid' => $pid]);
else
    echo json_encode(['error' => true, 'msg' => 'Не удалось запустить тестирование']);

function isProcessRunning($pid)
{
    $pid = (int)$pid;
    if ($pid <= 0) return false;
    if (function_exists('posix_kill'))
        return posix_kill($pid, 0);
    $out = shell_exec('ps -p '.$pid.' -o pid= 2>/dev/null');
    return ! empty(trim($out ?: ''));
}
