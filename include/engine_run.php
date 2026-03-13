<?php
// Запуск engine.php в фоновом процессе
$dir = dirname(__FILE__).'/../';
include_once $dir.'config.php';

header('Content-Type: application/json; charset=utf-8');

if ( ! Sys::checkAuth())
{
    echo json_encode(['error' => true, 'msg' => 'Не авторизован']);
    exit;
}

// Закрываем сессию чтобы не блокировать другие запросы
if (session_id() !== '')
    session_write_close();

if ( ! is_dir($dir.'data'))
    @mkdir($dir.'data', 0750, true);

$pidFile = $dir.'data/engine.pid';
$logFile = $dir.'data/engine_log.txt';

// Проверяем, не запущен ли уже
if (file_exists($pidFile))
{
    $pid = (int)trim(file_get_contents($pidFile));
    if ($pid > 0 && isProcessRunning($pid))
    {
        echo json_encode(['error' => true, 'msg' => 'Синхронизация уже выполняется']);
        exit;
    }
    @unlink($pidFile);
}

// Очищаем лог и прогресс
file_put_contents($logFile, '', LOCK_EX);
@unlink($dir.'data/engine_progress.json');

// Находим PHP CLI
$phpBin = '';
// PHP_BINARY может быть httpd в mod_php — проверяем
if ( ! empty(PHP_BINARY) && stripos(PHP_BINARY, 'php') !== false && file_exists(PHP_BINARY))
    $phpBin = PHP_BINARY;
if (empty($phpBin))
    $phpBin = trim(shell_exec('which php 2>/dev/null') ?: '');
if (empty($phpBin))
{
    // Типичные пути
    $paths = ['/opt/homebrew/bin/php', '/usr/local/bin/php', '/usr/bin/php'];
    foreach ($paths as $p)
        if (file_exists($p)) { $phpBin = $p; break; }
}
if (empty($phpBin))
{
    echo json_encode(['error' => true, 'msg' => 'PHP CLI не найден']);
    exit;
}
$enginePath = escapeshellarg($dir.'engine.php');
$logPath = escapeshellarg($logFile);
$pidPath = escapeshellarg($pidFile);

// Запуск в фоне: перенаправляем stdin из /dev/null чтобы отвязать от терминала
$cmd = escapeshellarg($phpBin).' '.$enginePath.' < /dev/null > '.$logPath.' 2>&1 & echo $! > '.$pidPath;
exec($cmd);

// Даём процессу стартовать
usleep(500000);

$pid = 0;
if (file_exists($pidFile))
    $pid = (int)trim(file_get_contents($pidFile));

if ($pid > 0 && isProcessRunning($pid))
{
    echo json_encode(['error' => false, 'msg' => 'Синхронизация запущена', 'pid' => $pid]);
}
else
{
    echo json_encode(['error' => true, 'msg' => 'Не удалось запустить синхронизацию']);
}

function isProcessRunning($pid)
{
    $pid = (int)$pid;
    if ($pid <= 0) return false;
    if (function_exists('posix_kill'))
        return posix_kill($pid, 0);
    $out = shell_exec('ps -p '.$pid.' -o pid= 2>/dev/null');
    return ! empty(trim($out ?: ''));
}
?>
