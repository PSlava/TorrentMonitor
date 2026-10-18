<?php
////////////////////////////////////
///////////TorrentMonitor///////////
////////////////////////////////////
$dir = dirname(__FILE__).'/';
include_once $dir.'autoload.php';
include_once $dir.'config.php';
include_once $dir.'class/System.class.php';
include_once $dir.'class/Database.class.php';
include_once $dir.'class/Errors.class.php';
include_once $dir.'class/Notification.class.php';
include_once $dir.'class/CurlMultiFetcher.class.php';

//второй экземпляр поверх идущего прогона (cron + «Запуск» в интерфейсе) дважды добавил бы раздачи в клиент
if ( ! EngineRunner::acquireLock())
{
    echo '['.date('Y-m-d H:i:s').'] Движок уже выполняется, повторный запуск пропущен.'.(PHP_SAPI == 'cli' ? "\r\n" : '<br />');
    exit;
}
//сразу отмечаем источник прогона: до первой фазы (миграции, выборка раздач) интерфейс
//показал бы прогон по cron как свой, вместе с логом прошлого запуска из интерфейса
EngineRunner::writeProgress(array('phase' => 'start', 'current' => 0, 'total' => 0, 'name' => ''), true);
//при запуске из интерфейса stderr не пишется в лог (там подробный вывод curl с паролем прокси
//и куками), поэтому ошибки PHP выводим в stdout
if (PHP_SAPI == 'cli' && getenv('TM_RUN_SOURCE') === 'ui')
    ini_set('display_errors', 'stdout');

Migration::run();
Database::beginEngineRun();

header('Content-Type: text/html; charset=utf-8');

set_time_limit(0);
ignore_user_abort(true);

function getTimestamp()
{
    return '['.date_format(date_create(), 'Y-m-d H:i:s').'] ';
}

//тексты предупреждений из Errors::raisedSince() одной строкой для лога
function engineWarningsText(array $warnings, $default)
{
    $texts = array();
    foreach ($warnings as $w)
        $texts[] = strip_tags((string) Errors::getWarning($w['warning']));
    $texts = array_unique(array_filter($texts));
    return empty($texts) ? $default : implode('; ', $texts);
}

$debug = Database::getSetting('debug');
$autoUpdate = Database::getSetting('autoUpdate');

$is_console = PHP_SAPI == 'cli';
if ($is_console)
    $NL = "\r\n";
else
    $NL = "<br />";

$time_start_full = microtime(true);
if (Sys::checkCurl())
{
	$torrentsList = Database::getTorrentsList('name');
    if ($torrentsList != NULL)
        $count = count($torrentsList);
    else
        $count = 0;
    
	echo getTimestamp();
	echo 'Опрос новых раздач на трекерах:'.$NL;
    Sys::lastStart();
    EngineRunner::writeProgress(array('phase' => 'prepare', 'current' => 0, 'total' => $count, 'name' => ''), true);
    $time_start_overall = microtime(true);

    //Проход 0: один раз получаем список учётных данных вместо запроса на каждой итерации
    $allCredentials = Database::getAllCredentials();
    $credentialSet = array();
    foreach ($allCredentials as $cred)
    {
        if ( ! empty($cred['login']) && ! empty($cred['password']))
            $credentialSet[$cred['tracker']] = TRUE;
    }

    $fetcher = new CurlMultiFetcher();
    $pending = array();

    //Проход 1: резолв кук/авторизации (последовательно) + формирование "проверочных" запросов
	for ($i=0; $i<$count; $i++)
	{
		$tracker = $torrentsList[$i]['tracker'];
		if (isset($credentialSet[$tracker]))
		{
			$engineFile = $dir.'trackers/'.$tracker.'.engine.php';
			$functionClass = Sys::resolveTrackerClass($engineFile, $tracker);
			if ($functionClass !== null)
			{
			    try
			    {
				    Database::clearWarnings($tracker);

				    //строка по раздаче пишется в лог при разборе ответа, вместе с итогом: здесь запрос
				    //только ставится в очередь, и «Сериал на трекере X» в логе выглядел бы проверенным
				    EngineRunner::writeProgress(array('phase' => 'prepare', 'current' => $i + 1, 'total' => $count, 'name' => $torrentsList[$i]['name'], 'tracker' => $tracker));
				    if ($torrentsList[$i]['pause'])
				    {
    				    echo getTimestamp();
    				    echo $torrentsList[$i]['name'].' на трекере '.$tracker.': наблюдение приостановлено.'.$NL;
    				    continue;
				    }
				    if ($torrentsList[$i]['type'] == 'RSS' || $torrentsList[$i]['type'] == 'forum')
				    {
				        $time_start = microtime(true);
				        $warningsMark = Errors::raisedCount();
				        $requestParams = call_user_func(array($functionClass, 'getRequestParams'), $torrentsList[$i]);
				        $time_end = microtime(true);
				        $time = $time_end - $time_start;
				        if ($debug)
				        {
    				        echo getTimestamp();
				            echo 'Время выполнения (подготовка запроса): '.$time.$NL;
				        }

				        if ( ! empty($requestParams['url']))
				        {
				            $key = $tracker.'_'.$torrentsList[$i]['id'];
				            $fetcher->add($key, $requestParams['url'], isset($requestParams['options']) ? $requestParams['options'] : array());
				            $pending[$key] = array(
				                'row'   => $torrentsList[$i],
				                'class' => $functionClass,
				            );
				        }
				        else
				        {
				            echo getTimestamp();
				            echo $torrentsList[$i]['name'].' на трекере '.$tracker.': пропущено — '
				                .engineWarningsText(Errors::raisedSince($warningsMark), 'не удалось подготовить запрос (нет авторизации на трекере?)').$NL;
				        }
				    }
				}
				catch (Throwable $e)
				{
				    echo getTimestamp().'Ошибка подготовки запроса ('.$tracker.'): '.$e->getMessage().$NL;
				    Errors::setWarnings($tracker, 'engine_error');
				}
				finally
				{
				    $functionClass = NULL;
				}
			}
			else
				Errors::setWarnings($tracker, 'missing_files');
		}
		else
			Errors::setWarnings($tracker, 'credential_miss');
	}

    //Выполняем все "проверочные" запросы параллельно
    EngineRunner::writeProgress(array('phase' => 'fetch', 'current' => 0, 'total' => count($pending), 'name' => ''), true);
    //одинаковые запросы fetcher склеивает (общий RSS lostfilm на все сериалы) - считаем раздачи, а не запросы
    $fetched = 0;
    try {
        $responses = $fetcher->execute(function($done, $total, $ids, $waiting) use ($pending, &$fetched) {
            if ( ! $waiting)
                $fetched += count($ids);
            $row = isset($pending[$ids[0]]) ? $pending[$ids[0]]['row'] : NULL;
            EngineRunner::writeProgress(array(
                'phase'   => 'fetch',
                'current' => $fetched,
                'total'   => count($pending),
                'name'    => $row ? $row['name'].($waiting ? ' — обход Cloudflare' : '') : '',
                'tracker' => $row ? $row['tracker'] : '',
            ), $waiting || $done == $total);
        });
    } catch (Throwable $e) {
        echo getTimestamp().'Ошибка параллельного запроса: '.$e->getMessage().$NL;
        $responses = array();
    }

    //Проход 2: разбираем ответы и собираем изменения для пакетного обновления
    $pendingUpdates = array();
    $parsed = 0;
    $parseTrackerWarnings = array();
    foreach ($pending as $key => $info)
    {
        $row     = $info['row'];
        $class   = $info['class'];
        $tracker = $row['tracker'];
        EngineRunner::writeProgress(array('phase' => 'parse', 'current' => ++$parsed, 'total' => count($pending), 'name' => $row['name'], 'tracker' => $tracker), $parsed == 1);
        $response = isset($responses[$key]) ? $responses[$key] : array('body' => '', 'http_code' => 0, 'error' => 'no response');

        $time_start = microtime(true);
        $warningsMark = Errors::raisedCount();
        try {
            $result = call_user_func(array($class, 'parse'), $row, $response['body']);
        } catch (Throwable $e) {
            echo getTimestamp().$row['name'].' на трекере '.$tracker.': ошибка разбора — '.$e->getMessage().$NL;
            Errors::setWarnings($tracker, 'engine_error');
            $time_end = microtime(true);
            continue;
        }
        $time_end = microtime(true);
        $time = $time_end - $time_start;

        $warnings = array_filter(Errors::raisedSince($warningsMark), function($w) use ($row) {
            return $w['id'] === NULL || $w['id'] == $row['id'];
        });
        //ошибки уровня трекера движки ставят один раз за прогон (static $warning), и у следующих
        //раздач того же трекера свежих предупреждений нет - напоминаем уже записанное
        $trackerWarnings = array_filter($warnings, function($w) { return $w['id'] === NULL; });
        if ( ! empty($trackerWarnings))
            $parseTrackerWarnings[$tracker] = $trackerWarnings;
        if (isset($result[$row['id']]['timestamp']))
            $outcome = 'найдено обновление, torrent-файл загружен';
        elseif ( ! empty($warnings))
            $outcome = 'ошибка — '.engineWarningsText($warnings, '');
        elseif (isset($parseTrackerWarnings[$tracker]) && ! (isset($result[$row['id']]['error']) && $result[$row['id']]['error'] === 0))
            $outcome = 'ошибка трекера в этом прогоне (уже записана выше) — '.engineWarningsText($parseTrackerWarnings[$tracker], '');
        elseif ($response['body'] === '')
            $outcome = 'нет ответа от трекера'.($response['error'] !== '' ? ' ('.$response['error'].')' : '');
        else
            $outcome = 'обновлений нет';
        echo getTimestamp();
        echo $row['name'].' на трекере '.$tracker.': '.$outcome.$NL;

        if ($debug)
        {
            echo getTimestamp();
            echo 'Время выполнения (разбор ответа, '.$tracker.'): '.$time.$NL;
        }

        if ( ! empty($result))
            $pendingUpdates = $pendingUpdates + $result;
    }

    if ( ! empty($pendingUpdates))
    {
        try {
            Database::batchUpdateTorrents($pendingUpdates);
        } catch (Throwable $e) {
            echo getTimestamp().'Ошибка сохранения обновлений в БД: '.$e->getMessage().$NL;
        }
    }

    $time_end_overall = microtime(true);
    $time = $time_end_overall - $time_start_overall;
    if ($debug)
    {
        echo getTimestamp();
        echo 'Общее время опроса трекеров: '.$time.$NL;
    }
			
	echo getTimestamp();
    echo 'Опрос новых раздач пользователей на трекерах:'.$NL;
	$time_start_overall = microtime(true);
	$usersList = Database::getUserToWatch();
	if ( ! empty($usersList))
	{
    	$count = count($usersList);
    	for ($i=0; $i<$count; $i++)
    	{
    		$tracker = $usersList[$i]['tracker'];
    		if (Database::checkTrackersCredentialsExist($tracker))
    		{
    			$serchFile = $dir.'trackers/'.$tracker.'.search.php';
    			$functionClass = Sys::resolveTrackerClass($serchFile, $tracker, 'mainSearch');
    			if ($functionClass !== null)
    			{
    				try
    				{
    				    Database::clearWarnings($tracker);

    				    echo getTimestamp();
                        echo 'Пользователь '.$usersList[$i]['name'].' на трекере '.$tracker.$NL;
                        EngineRunner::writeProgress(array('phase' => 'users', 'current' => $i + 1, 'total' => $count, 'name' => $usersList[$i]['name'], 'tracker' => $tracker), $i == 0);
                        $time_start = microtime(true);
    				    call_user_func($functionClass .'::mainSearch', $usersList[$i]);
    				    $time_end = microtime(true);
    				    $time = $time_end - $time_start;
    				    if ($debug)
    				    {
        				    echo getTimestamp();
    				        echo 'Время выполнения: '.$time.$NL;
    				    }
    				}
    				catch (Throwable $e)
    				{
    				    echo getTimestamp().'Ошибка mainSearch ('.$tracker.'): '.$e->getMessage().$NL;
    				    Errors::setWarnings($tracker, 'engine_error');
    				}
    
    				$functionClass = NULL;
    			}
    			else
    				Errors::setWarnings($tracker, 'missing_files');
    		}
    		else
    			Errors::setWarnings($tracker, 'credential_miss');
    	}
    }
    $time_end_overall = microtime(true);
    $time = $time_end_overall - $time_start_overall;
    if ($debug)
    {
        echo getTimestamp();
        echo 'Общее время опроса пользователей на трекерах: '.$time.$NL;
    }
    echo getTimestamp();
	echo '=================='.$NL;
	echo getTimestamp();
	echo 'Выполение служебных функций:'.$NL;
	echo getTimestamp();
	echo 'Добавляем темы из Temp.'.$NL;
	EngineRunner::writeProgress(array('phase' => 'service', 'current' => 0, 'total' => 0, 'name' => 'Добавление тем из Temp'), true);
	$time_start = microtime(true);
	$tempList = Database::getAllFromTemp();
	if ( ! empty($tempList))
	{
    	if (count($tempList) > 0)
    	    Sys::AddFromTemp($tempList);
    }
	$time_end = microtime(true);
	$time = $time_end - $time_start;
	if ($debug)
	{
    	echo getTimestamp();
	    echo 'Время выполнения: '.$time.$NL;
    }
    echo getTimestamp();
	echo 'Обновление новостей.'.$NL;
	EngineRunner::writeProgress(array('phase' => 'service', 'current' => 0, 'total' => 0, 'name' => 'Обновление новостей'), true);
	$time_start = microtime(true);
	Sys::getNews();
	$time_end = microtime(true);
	$time = $time_end - $time_start;
	if ($debug)
	{
    	echo getTimestamp();
        echo 'Время выполнения: '.$time.$NL;
    }
    echo getTimestamp();
	echo 'Удаление старых torrent-файлов.'.$NL;
	EngineRunner::writeProgress(array('phase' => 'service', 'current' => 0, 'total' => 0, 'name' => 'Удаление старых torrent-файлов'), true);
	Sys::deleteOldTorrents();
    if ($autoUpdate)
    {
        echo getTimestamp();
        echo 'Установка обновлений.'.$NL;
        include_once $dir.'class/Update.class.php';
        Update::main();
    }
    else
    {
        if (Sys::checkUpdate())
        {
            if ( ! Database::getUpdateNotification())
            {
                $latestVersion = Sys::getLatestVersion();
                $changelog     = Sys::getChangelog($latestVersion);
                $msg = 'Выпущена новая версия ТМ' . ($latestVersion ? ' ' . $latestVersion : '') . ', автоматическое обновление отключено, обновите систему самостоятельно.';
                if ( ! empty($changelog))
                    $msg .= "\n\n" . $changelog;
                $newsId = 'update_' . ($latestVersion ?: time());
                if ( ! Database::checkNewsExist($newsId))
                    Database::insertNews($newsId, $msg);
                Notification::sendNotification('news', date('r'), 0, $msg, 0);
                Database::setUpdateNotification(1);
            }
        }
    }
}	
else
	Errors::setWarnings('system', 'curl');
	
EngineRunner::clearProgress();

$time_end_full = microtime(true);
$time = $time_end_full - $time_start_full;
if ($debug)
{
    echo getTimestamp();
    echo 'Общее время работы скрипта: '.$time.$NL;
}
?>