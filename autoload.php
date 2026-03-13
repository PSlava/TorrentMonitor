<?php
/**
 * Автозагрузчик классов TorrentMonitor
 */

spl_autoload_register(function ($className) {
    // Маппинг класс → файл для особых случаев
    static $classMap = [
        'Sys' => 'System.class.php',
        'TransmissionRPCException' => 'TransmissionRPC.class.php',
    ];

    $dir = dirname(__FILE__) . '/class/';

    if (isset($classMap[$className])) {
        $file = $dir . $classMap[$className];
    } else {
        $file = $dir . $className . '.class.php';
    }

    if (file_exists($file)) {
        include_once $file;
    }
});
