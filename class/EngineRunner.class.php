<?php
//Фоновый запуск engine.php и его прогресс для страницы «Запуск».
//Признак «движок работает» - удерживаемая flock-блокировка data/engine.lock: её берёт сам
//engine.php на всё время прогона, поэтому видны и запуски из интерфейса, и запуски по cron,
//а второй экземпляр движка поверх идущего не стартует
class EngineRunner
{
    //прогресс пишется не чаще раза в секунду (интерфейс опрашивает раз в 1.5 секунды)
    const PROGRESS_INTERVAL = 1.0;
    //сколько хвоста лога отдавать в интерфейс
    const LOG_TAIL = 65536;

    private static $lockHandle = NULL;
    private static $lastProgressWrite = 0;

    private static function dataDir()
    {
        $dir = dirname(__FILE__).'/../data/';
        if ( ! is_dir($dir))
            @mkdir($dir, 0750, true);
        return $dir;
    }

    private static function lockFile()     { return self::dataDir().'engine.lock'; }
    private static function progressFile() { return self::dataDir().'engine_progress.json'; }
    private static function logFile()      { return self::dataDir().'engine_log.txt'; }

    //вызывается из engine.php: TRUE - блокировка взята и держится до конца процесса
    public static function acquireLock()
    {
        $file = self::lockFile();
        if ( ! file_exists($file))
        {
            @touch($file);
            @chmod($file, 0666);
        }
        //flock работает и на дескрипторе только для чтения - так блокировку берёт любой пользователь
        $fh = @fopen($file, 'r');
        //файл недоступен совсем - не мешаем движку работать (start() из интерфейса в такой
        //ситуации не запускает движок, см. там)
        if ($fh === false)
            return TRUE;
        //isRunning() берёт блокировку на мгновение для проверки - несколько попыток, чтобы
        //опрос статуса не выдал себя за идущий прогон
        for ($i = 0; $i < 5; $i++)
        {
            if (flock($fh, LOCK_EX | LOCK_NB))
            {
                self::$lockHandle = $fh;
                return TRUE;
            }
            usleep(50000);
        }
        fclose($fh);
        return FALSE;
    }

    public static function isRunning()
    {
        $file = self::lockFile();
        if ( ! file_exists($file))
            return FALSE;
        $fh = @fopen($file, 'r');
        if ($fh === false)
            return FALSE;
        $free = flock($fh, LOCK_EX | LOCK_NB);
        if ($free)
            flock($fh, LOCK_UN);
        fclose($fh);
        return ! $free;
    }

    public static function writeProgress(array $data, $force = false)
    {
        $now = microtime(true);
        if ( ! $force && ($now - self::$lastProgressWrite) < self::PROGRESS_INTERVAL)
            return;
        self::$lastProgressWrite = $now;
        $data['source'] = getenv('TM_RUN_SOURCE') === 'ui' ? 'ui' : 'cron';
        $data['updated_at'] = date('Y-m-d H:i:s');
        Sys::writeSharedFile(self::progressFile(), json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    public static function clearProgress()
    {
        @unlink(self::progressFile());
    }

    //PHP CLI: под mod_php PHP_BINARY указывает на httpd, а у Apache в PATH может не быть homebrew
    public static function phpBinary()
    {
        //путь, заданный вручную, берётся как есть: is_executable() под open_basedir отвечает FALSE
        //для пути вне разрешённых каталогов, а exec() этим ограничением не связан. Неверный путь
        //даст «Не удалось запустить движок»
        $configured = Config::read('php.cli');
        if (is_string($configured) && $configured !== '')
            return $configured;
        if (PHP_BINARY !== '' && preg_match('/php[0-9.]*(-cli)?(\.exe)?$/i', basename(PHP_BINARY)) && is_executable(PHP_BINARY))
            return PHP_BINARY;
        if (function_exists('shell_exec'))
        {
            $which = trim((string) @shell_exec('command -v php 2>/dev/null'));
            if ($which !== '' && is_executable($which))
                return $which;
        }
        foreach (array('/opt/homebrew/bin/php', '/usr/local/bin/php', '/usr/bin/php') as $path)
            if (is_executable($path))
                return $path;
        return '';
    }

    //запуск движка в фоне; возвращает ['ok' => bool, 'msg' => string]
    public static function start()
    {
        if (self::isRunning())
            return array('ok' => FALSE, 'msg' => 'Движок уже выполняется.');
        if ( ! function_exists('exec'))
            return array('ok' => FALSE, 'msg' => 'Функция exec() отключена в настройках PHP.');
        $php = self::phpBinary();
        if ($php === '')
            return array('ok' => FALSE, 'msg' => 'Не найден PHP CLI. Укажите путь в config.php: Config::write(\'php.cli\', \'/usr/bin/php8.3\');');
        //без доступа к блокировке движок работает, но статус его не видит: интерфейс сразу
        //показал бы «Прогон завершён», а кнопка запускала бы второй экземпляр поверх первого
        $lock = self::lockFile();
        if ( ! file_exists($lock))
        {
            @touch($lock);
            @chmod($lock, 0666);
        }
        if ( ! is_readable($lock))
            return array('ok' => FALSE, 'msg' => 'Нет доступа к data/engine.lock.');

        Sys::writeSharedFile(self::logFile(), '', LOCK_EX);
        clearstatcache();
        if ( ! is_writable(self::logFile()) || filesize(self::logFile()) > 0)
            return array('ok' => FALSE, 'msg' => 'Нет прав на запись в data/engine_log.txt.');
        self::clearProgress();

        $engine = dirname(__FILE__).'/../engine.php';
        $cmd = 'TM_RUN_SOURCE=ui '.escapeshellarg($php).' '.escapeshellarg(realpath($engine))
            .' < /dev/null > '.escapeshellarg(self::logFile()).' 2> /dev/null &';
        exec($cmd);

        //ждём, пока движок возьмёт блокировку: до этого статус показывал бы «завершено»
        for ($i = 0; $i < 30; $i++)
        {
            usleep(100000);
            if (self::isRunning())
                return array('ok' => TRUE, 'msg' => 'Движок запущен.');
        }
        //процесс мог успеть отработать целиком (нечего проверять) или упасть на старте -
        //лог перед запуском очищен, так что непустой он только от этого процесса
        clearstatcache();
        $log = (string) @file_get_contents(self::logFile());
        if (trim($log) !== '')
            return array('ok' => TRUE, 'msg' => 'Движок запущен.');
        //stderr фонового процесса уходит в /dev/null, и «command not found» от неверного пути не виден
        $configured = Config::read('php.cli');
        return array('ok' => FALSE, 'msg' => 'Не удалось запустить движок.'
            .(is_string($configured) && $configured !== '' ? ' Проверьте путь php.cli в config.php.' : ''));
    }

    //чтение под LOCK_SH: writeProgress() пишет под LOCK_EX, и без блокировки попадался
    //пустой файл - фаза в интерфейсе мигала «Запуск движка…»
    private static function readShared($file)
    {
        $fh = @fopen($file, 'r');
        if ($fh === false)
            return '';
        flock($fh, LOCK_SH);
        $data = stream_get_contents($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        return (string) $data;
    }

    public static function status()
    {
        $running = self::isRunning();

        $progress = NULL;
        if ($running && file_exists(self::progressFile()))
            $progress = json_decode(self::readShared(self::progressFile()), true);
        elseif ( ! $running)
            //движок упал, не убрав за собой файл прогресса
            self::clearProgress();

        //лог есть только у запуска из интерфейса - cron пишет свой вывод в другое место
        $external = $running && is_array($progress) && isset($progress['source']) && $progress['source'] !== 'ui';
        $log = '';
        if ( ! $external && file_exists(self::logFile()))
        {
            $size = filesize(self::logFile());
            if ($size > self::LOG_TAIL)
            {
                $fh = fopen(self::logFile(), 'r');
                fseek($fh, -self::LOG_TAIL, SEEK_END);
                fgets($fh);
                $log = '... (начало лога обрезано) ...'."\n".fread($fh, self::LOG_TAIL);
                fclose($fh);
            }
            else
                $log = (string) file_get_contents(self::logFile());
            $log = str_replace("\r\n", "\n", $log);
        }

        return array(
            'running'  => $running,
            'external' => $external,
            'progress' => $progress,
            'log'      => $log,
        );
    }
}
?>
