<?php
////////////////////////////////////
///////////TorrentMonitor///////////
////////////////////////////////////
set_time_limit(0);

$dir = dirname(__FILE__).'/';
include_once $dir.'config.php';
include_once $dir.'class/System.class.php';
include_once $dir.'class/Database.class.php';
include_once $dir.'class/Errors.class.php';
include_once $dir.'class/Notification.class.php';
include_once $dir.'class/EventBus.class.php';
include_once $dir.'class/Webhook.class.php';
include_once $dir.'class/TaskQueue.class.php';
include_once $dir.'class/Migration.class.php';

// Авторизация при HTTP-доступе (CLI — без проверки)
$is_console = PHP_SAPI == 'cli';
if ( ! $is_console && ! Sys::checkAuth())
{
    http_response_code(403);
    exit('Forbidden');
}

Migration::run();

header('Content-Type: text/html; charset=utf-8');

function getTimestamp()
{
    return '['.date_format(date_create(), 'Y-m-d H:i:s').'] ';
}

$debug = Database::getSetting('debug');
$autoUpdate = Database::getSetting('autoUpdate');

if ($is_console)
    $NL = "\r\n";
else
    $NL = "<br />";

// Создаём директорию data при необходимости
if ( ! is_dir($dir.'data'))
    @mkdir($dir.'data', 0750, true);

// Файл прогресса для отображения в UI
$progressFile = $dir.'data/engine_progress.json';
$_lastProgressWrite = 0;
function writeProgress($file, $data, $force = false)
{
    global $_lastProgressWrite;
    $now = microtime(true);
    // Пишем не чаще раза в 2 секунды (поллинг UI — 1.5 сек)
    if ( ! $force && ($now - $_lastProgressWrite) < 2.0)
        return;
    $_lastProgressWrite = $now;
    $data['updated_at'] = date('Y-m-d H:i:s');
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function clearProgress($file)
{
    @unlink($file);
}

$time_start_full = microtime(true);
if (Sys::checkCurl())
{
	EventBus::emit(EventBus::EVENT_ENGINE_START, ['time' => date('c')]);
	writeProgress($progressFile, ['status' => 'running', 'phase' => 'torrents', 'current' => 0, 'total' => 0, 'name' => ''], true);
	$torrentsList = Database::getTorrentsList('name');
    if ($torrentsList != NULL)
        $count = count($torrentsList);
    else
        $count = 0;
    
	Database::clearWarnings('system');
	echo getTimestamp();
	echo 'Опрос новых раздач на трекерах:'.$NL;
    $time_start_overall = microtime(true);
	for ($i=0; $i<$count; $i++)
	{
		$tracker = $torrentsList[$i]['tracker'];
		if (Database::checkTrackersCredentialsExist($tracker))
		{
			$engineFile = $dir.'trackers/'.$tracker.'.engine.php';
			if (file_exists($engineFile))
			{
				
				$functionEngine = include_once $engineFile;
				$class = explode('.', $tracker);
				$class = $class[0];
				$functionClass = str_replace('-', '', $class);
				
				if ($tracker == 'tracker.0day.kiev.ua')
				    $functionClass = 'kiev';
				    
                if ($tracker == 'tv.mekc.info')
				    $functionClass = 'mekc';
				    
				if ($tracker == 'baibako.tv_forum')
				    $functionClass = 'baibako_f';

                writeProgress($progressFile, ['status' => 'running', 'phase' => 'torrents', 'current' => $i + 1, 'total' => $count, 'name' => $torrentsList[$i]['name'], 'tracker' => $tracker]);
                echo getTimestamp();
				echo $torrentsList[$i]['name'].' на трекере '.$tracker.$NL;
				if ($torrentsList[$i]['pause'])
				{
    				echo getTimestamp();
    				echo 'Наблюдение за данной темой приостановлено.'.$NL;
    				continue;
				}
				if ($torrentsList[$i]['type'] == 'RSS' || $torrentsList[$i]['type'] == 'forum')
				{
				    $time_start = microtime(true);
				    $maxRetries = 2;
				    $success = false;
				    for ($retry = 0; $retry <= $maxRetries; $retry++)
				    {
				        try {
				            if ($retry > 0)
				            {
				                echo getTimestamp();
				                echo 'Повторная попытка ('.$retry.'/'.$maxRetries.') для '.$torrentsList[$i]['name'].$NL;
				                sleep(3 * $retry);
				            }
				            call_user_func($functionClass.'::main', $torrentsList[$i]);
				            $success = true;
				            // Сбрасываем флаг ошибки — проверка прошла без исключений
				            Database::setErrorToThreme($torrentsList[$i]['id'], 0);
				            break;
				        } catch (Exception $e) {
				            echo getTimestamp();
				            echo 'Ошибка: '.$e->getMessage().$NL;
				        } catch (Error $e) {
				            echo getTimestamp();
				            echo 'Критическая ошибка: '.$e->getMessage().$NL;
				            break;
				        }
				    }
				    if ( ! $success)
				    {
				        echo getTimestamp();
				        echo 'Не удалось обработать раздачу, пропускаем.'.$NL;
				        Errors::setWarnings($tracker, 'not_available', $torrentsList[$i]['id']);
				    }
				    $time_end = microtime(true);
				    $time = $time_end - $time_start;
				    if ($debug)
				    {
    				    echo getTimestamp();
				        echo 'Время выполнения: '.$time.$NL;
				    }
				}
				$functionClass = NULL;
				$functionEngine = NULL;
			}
			else
				Errors::setWarnings('system', 'missing_files');				
		}
		else
			Errors::setWarnings('system', 'credential_miss');
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
    			if (file_exists($serchFile))
    			{
    				$functionEngine = include_once $serchFile;
    				$class = explode('.', $tracker);
    				$class = $class[0];
    				$class = str_replace('-', '', $class);
    				$functionClass = $class.'Search';
                    writeProgress($progressFile, ['status' => 'running', 'phase' => 'users', 'current' => $i + 1, 'total' => $count, 'name' => $usersList[$i]['name'], 'tracker' => $tracker]);
    				echo getTimestamp();
                    echo 'Пользователь '.$usersList[$i]['name'].' на трекере '.$tracker.$NL;
                    $time_start = microtime(true);
                    $maxRetries = 2;
                    $success = false;
                    for ($retry = 0; $retry <= $maxRetries; $retry++)
                    {
                        try {
                            if ($retry > 0)
                            {
                                echo getTimestamp();
                                echo 'Повторная попытка ('.$retry.'/'.$maxRetries.') для пользователя '.$usersList[$i]['name'].$NL;
                                sleep(3 * $retry);
                            }
                            call_user_func($functionClass.'::mainSearch', $usersList[$i]);
                            $success = true;
                            break;
                        } catch (Exception $e) {
                            echo getTimestamp();
                            echo 'Ошибка: '.$e->getMessage().$NL;
                        } catch (Error $e) {
                            echo getTimestamp();
                            echo 'Критическая ошибка: '.$e->getMessage().$NL;
                            break;
                        }
                    }
                    if ( ! $success)
                    {
                        echo getTimestamp();
                        echo 'Не удалось обработать пользователя, пропускаем.'.$NL;
                        Errors::setWarnings($tracker, 'connect');
                    }
    				$time_end = microtime(true);
    				$time = $time_end - $time_start;
    				if ($debug)
    				{
        				echo getTimestamp();
    				    echo 'Время выполнения: '.$time.$NL;
    				}
    
    				$functionClass = NULL;
    				$functionEngine = NULL;
    			}
    			else
    				Errors::setWarnings('system', 'missing_files');
    		}
    		else
    			Errors::setWarnings('system', 'credential_miss');
    	}
    }
    $time_end_overall = microtime(true);
    $time = $time_end_overall - $time_start_overall;
    if ($debug)
    {
        echo getTimestamp();
        echo 'Общее время опроса пользователей на трекерах: '.$time.$NL;
    }
    writeProgress($progressFile, ['status' => 'running', 'phase' => 'service', 'current' => 0, 'total' => 0, 'name' => 'Служебные функции'], true);
    echo getTimestamp();
	echo '=================='.$NL;
	echo getTimestamp();
	echo 'Выполение служебных функций:'.$NL;
	echo getTimestamp();
	echo 'Добавляем темы из Temp.'.$NL;
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
                $msg = 'Выпущена новая версия ТМ, автоматическое обновление отключено, обновите систему самостоятельно.';
                Notification::sendNotification('news', date('r'), 0, $msg, 0);
                Database::setUpdateNotification(1);
            }
        }
    }
    echo getTimestamp();
	echo 'Обработка очереди задач.'.$NL;
	$processed = TaskQueue::process();
	if ($debug && $processed > 0)
	{
	    echo getTimestamp();
	    echo 'Обработано задач: '.$processed.$NL;
	}
	echo getTimestamp();
	echo 'Очистка старых событий и задач.'.$NL;
	EventBus::cleanup(7);
	TaskQueue::cleanup(7);
	echo getTimestamp();
	echo 'Запись времени последнего запуска ТМ.'.$NL;
	Sys::lastStart();
	EventBus::emit(EventBus::EVENT_ENGINE_DONE, ['time' => date('c'), 'duration' => microtime(true) - $time_start_full]);
	clearProgress($progressFile);
}
else
	Errors::setWarnings('system', 'curl');
	
$time_end_full = microtime(true);
$time = $time_end_full - $time_start_full;
if ($debug)
{
    echo getTimestamp();
    echo 'Общее время работы скрипта: '.$time.$NL;
}
?>