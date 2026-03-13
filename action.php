<?php
$dir = dirname(__FILE__).'/';
include_once $dir.'config.php';

if (isset($_POST['action']))
{
	//Проверяем пароль
	if ($_POST['action'] == 'enter')
	{
		$password = $_POST['password'];
		$hash_pass = Database::getSetting('password');

		if ($hash_pass && (password_verify($password, $hash_pass) || md5($password) === $hash_pass))
		{
			// Если пароль хранится в MD5 — обновляем на bcrypt
			if (md5($password) === $hash_pass)
			{
				$new_hash = password_hash($password, PASSWORD_BCRYPT);
				Database::updateCredentials($new_hash);
				$hash_pass = $new_hash;
			}

			session_start();
			$_SESSION['TM'] = $hash_pass;
			$return['error'] = FALSE;
			if ($_POST['remember'] == 'true')
				setcookie('TM', $hash_pass, [
					'expires' => time()+3600*24*31,
					'path' => '/',
					'httponly' => true,
					'samesite' => 'Lax'
				]);
		}
		else
		{
			$return['error'] = TRUE;
			$return['msg'] = 'Неверный пароль!';
		}
		echo json_encode($return);
	}

    if ( ! Sys::checkAuth())
        exit();

	// CSRF-токен: генерация
	if (session_id() == '')
		session_start();
	if (empty($_SESSION['csrf_token']))
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

	// CSRF-проверка для POST-запросов (кроме login и logout)
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !in_array($_POST['action'], ['enter', 'logout']))
	{
		if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']))
		{
			echo json_encode(array('response' => 'error', 'message' => 'CSRF token invalid'));
			exit;
		}
	}

	// Миграции БД (один вызов для всех action)
	Migration::run();

	//Добавляем тему для мониторинга
	if ($_POST['action'] == 'torrent_add')
	{
		if ($url = parse_url($_POST['url']))
		{
			$tracker = $url['host'];
			$tracker = preg_replace('/www\./', '', $tracker);

			if ($tracker == 'lostfilm.tv' || $tracker == 'lostfilm-mirror' || $tracker == 'newstudio.tv')
			{
                $return['error'] = TRUE;
                $return['msg'] = 'Это не форумный трекер. Добавьте как Сериал по его названию.';
            }
            else
            {
    			if ($tracker == 'tr.anidub.com')
    				$tracker = 'anidub.com';
                elseif ($tracker == 'baibako.tv')
    				$tracker = 'baibako.tv_forum';

    			if ($tracker == 'anidub.com' || $tracker == 'riperam.org')
    			    $threme = $url['path'];
                elseif ($tracker == 'animelayer.ru')
                {
                    $path = str_replace('/torrent', '', $url['path']);
                    preg_match('/\/(\w*)\/?/', $path, $array);
                    $threme = $array[1];
                }
                elseif ($tracker == 'casstudio.tk')
    			{
    				$query = explode('t=', $url['query']);
    				$threme = $query[1];
    			}
    			elseif ($tracker != 'rutor.is')
    			{
    				$query = explode('=', $url['query']);
    				$threme = $query[1];
    			}
    			else
    			{
    				preg_match('/\d{4,8}/', $url['path'], $array);
    				$threme = $array[0];
    			}

    			if (is_array(Database::getCredentials($tracker)))
    			{
    				$engineFile = $dir.'/trackers/'.$tracker.'.engine.php';
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

                        if ( ! empty($threme))
                        {
        					if (call_user_func(array($functionClass, 'checkRule'), $threme))
        					{
        						if (Database::checkThremExist($tracker, $threme))
        						{
        							if ( ! empty($_POST['name']))
        								$name = $_POST['name'];
        							else
        								$name = Sys::getHeader($_POST['url']);

        							$query = Database::setThreme($tracker, $name, $_POST['path'], $threme, Sys::strBoolToInt($_POST['update_header']));
        							if ($query === TRUE)
                                    {
            							$return['error'] = FALSE;
                                        $return['msg'] = 'Тема добавлена для мониторинга.';
                                    }
                                    else
                                    {
                                        $return['error'] = TRUE;
                                        $return['msg'] = 'Произошла ошибка при сохранении в БД.';
                                    }
        						}
        						else
        						{
            						$return['error'] = TRUE;
                                    $return['msg'] = 'Вы уже следите за данной темой на трекере <b>'.htmlspecialchars($tracker, ENT_QUOTES, 'UTF-8').'</b>.';
        						}
        					}
        					else
        					{
        					    $return['error'] = TRUE;
                                $return['msg'] = 'Неверная ссылка.';
        					}
        				}
        				else
    					{
    					    $return['error'] = TRUE;
                            $return['msg'] = 'Неверная ссылка.';
    					}
    				}
    				else
    				{
        				$return['error'] = TRUE;
                        $return['msg'] = 'Отсутствует модуль для трекера - <b>'.htmlspecialchars($tracker, ENT_QUOTES, 'UTF-8').'</b>.';
    				}
    			}
    			else
    			{
        			$return['error'] = TRUE;
                    $return['msg'] = 'Вы не можете следить за этим сериалом на трекере - <b>'.htmlspecialchars($tracker, ENT_QUOTES, 'UTF-8').'</b>, пока не введёте свои учётные данные!';
    			}
            }
		}
		else
		{
    		$return['error'] = TRUE;
            $return['msg'] = 'Не верная ссылка.';
		}
		echo json_encode($return);
	}

	//Добавляем сериал для мониторинга
	if ($_POST['action'] == 'serial_add')
	{

		$tracker = $_POST['tracker'];
		if (is_array(Database::getCredentials($tracker)))
		{
			$engineFile = $dir.'/trackers/'.$tracker.'.engine.php';
			if (file_exists($engineFile))
			{
				$functionEngine = include_once $engineFile;
				$class = explode('.', $tracker);
				$class = $class[0];
				$class = str_replace('-', '', $class);
				if (Database::checkSerialExist($tracker, $_POST['name'], $_POST['hd']))
				{
					$query = Database::setSerial($tracker, $_POST['name'], $_POST['path'], $_POST['hd']);
					if ($query === TRUE)
					{
					    $return['error'] = FALSE;
                        $return['msg'] = 'Сериал добавлен для мониторинга.';
                    }
                    else
                    {
                        $return['error'] = TRUE;
                        $return['msg'] = 'Произошла ошибка при сохранении в БД.';
                    }
				}
				else
				{
					$return['error'] = TRUE;
                    $return['msg'] = 'Вы уже следите за данным сериалом на этом трекере - <b>'.htmlspecialchars($tracker, ENT_QUOTES, 'UTF-8').'</b>.';
				}
			}
			else
			{
				$return['error'] = TRUE;
                $return['msg'] = 'Отсутствует модуль для трекера - <b>'.htmlspecialchars($tracker, ENT_QUOTES, 'UTF-8').'</b>.';
			}
		}
		else
		{
			$return['error'] = TRUE;
            $return['msg'] = 'Вы не можете следить за этим сериалом на трекере - <b>'.htmlspecialchars($tracker, ENT_QUOTES, 'UTF-8').'</b>, пока не введёте свои учётные данные!';
		}

		echo json_encode($return);
	}

	//Обновляем отслеживаемый item
	if ($_POST['action'] == 'update')
	{
	    $tracker = $_POST['tracker'];
	    if ($tracker == 'lostfilm.tv' || $tracker == 'lostfilm-mirror'  || $tracker == 'newstudio.tv' || $tracker == 'baibako.tv')
        {
            $engineFile = $dir.'/trackers/'.$tracker.'.engine.php';
            $functionEngine = include_once $engineFile;
			$class = explode('.', $tracker);
			$class = $class[0];
			$class = str_replace('-', '', $class);
			Database::updateSerial($_POST['id'], $_POST['name'], $_POST['path'], $_POST['hd'], Sys::strBoolToInt($_POST['reset']), $_POST['script'], Sys::strBoolToInt($_POST['pause']));
			$return['error'] = FALSE;
            $return['msg'] = 'Сериал обновлён.';
        }
        else
        {
    		if ($url = parse_url($_POST['url']))
    		{
    			$tracker = $url['host'];
    			$tracker = preg_replace('/www\./', '', $tracker);
    			if ($tracker == 'tr.anidub.com')
    				$tracker = 'anidub.com';
				elseif ($tracker == 'baibako.tv')
    				$tracker = 'baibako.tv_forum';

    			if ($tracker == 'anidub.com' || $tracker == 'riperam.org')
    			    $threme = $url['path'];
                elseif ($tracker == 'animelayer.ru')
                {
                    $path = str_replace('/torrent', '', $url['path']);
                    preg_match('/\/(.*)\/?/', $path, $array);
                    $threme = $array[1];
                }
                elseif ($tracker == 'casstudio.tk')
    			{
    				$query = explode('=', $url['query']);
    				$threme = $query[1];
    			}
    			elseif ($tracker != 'rutor.is')
    			{
    				$query = explode('=', $url['query']);
    				$threme = $query[1];
    			}
    			else
    			{
    				preg_match('/\d{4,8}/', $url['path'], $array);
    				$threme = $array[0];
    			}

    			if (is_array(Database::getCredentials($tracker)))
    			{
    				$engineFile = $dir.'/trackers/'.$tracker.'.engine.php';
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

                        if ( ! empty($threme))
                        {
        					if (call_user_func(array($functionClass, 'checkRule'), $threme))
                			{
                				Database::updateThreme($_POST['id'], $_POST['name'], $_POST['path'], $threme, Sys::strBoolToInt($_POST['update']), Sys::strBoolToInt($_POST['reset']), $_POST['script'], Sys::strBoolToInt($_POST['pause']));
                				$return['error'] = FALSE;
                                $return['msg'] = 'Тема обновлена.';
                            }
                            else
                            {
                				$return['error'] = TRUE;
                                $return['msg'] = 'Не верный ID темы.';
                            }
                        }
                    }
                }
            }
        }
        echo json_encode($return);
	}

	//Добавляем пользователя для мониторинга
	if ($_POST['action'] == 'user_add')
	{
		$tracker = $_POST['tracker'];
		if (is_array(Database::getCredentials($tracker)))
		{
			$engineFile = $dir.'/trackers/'.$tracker.'.search.php';
			if (file_exists($engineFile))
			{
				if (Database::checkUserExist($tracker, $_POST['name']))
				{
					Database::setUser($tracker, $_POST['name']);
					$return['error'] = FALSE;
                    $return['msg'] = 'Пользователь добавлен для мониторинга.';
				}
				else
				{
                    $return['error'] = TRUE;
                    $return['msg'] = 'Вы уже следите за данным пользователем на этом трекере - <b>'.htmlspecialchars($tracker, ENT_QUOTES, 'UTF-8').'</b>.';
				}
			}
			else
			{
    			$return['error'] = TRUE;
                $return['msg'] = 'Отсутствует модуль для трекера - <b>'.htmlspecialchars($tracker, ENT_QUOTES, 'UTF-8').'</b>.';
			}
		}
		else
		{
    		$return['error'] = TRUE;
            $return['msg'] = 'Вы не можете следить за этим пользователем на трекере - <b>'.htmlspecialchars($tracker, ENT_QUOTES, 'UTF-8').'</b>, пока не введёте свои учётные данные!';
		}
		echo json_encode($return);
	}

	//Удаляем пользователя из мониторинга и все его темы
	if ($_POST['action'] == 'delete_user')
	{
    	Database::deletUser($_POST['user_id']);
    	$return['error'] = FALSE;
        $return['msg'] = 'Слежение за пользователем удалено.';
        echo json_encode($return);
	}

	//Удаляем тему из буфера
	if ($_POST['action'] == 'delete_from_buffer')
	{
    	Database::deleteFromBuffer($_POST['id']);
    	$return['error'] = FALSE;
        $return['msg'] = 'Тема удалена из буфера.';
        echo json_encode($return);
	}

	//Очищаем весь список тем
	if ($_POST['action'] == 'thremes_clear')
	{
    	Database::thremesClear($_POST['user_id']);
    	$return['error'] = FALSE;
        $return['msg'] = 'Буфер очищен.';
        echo json_encode($return);
	}

	//Перемещаем тему из буфера в мониторинг постоянный
	if ($_POST['action'] == 'transfer_from_buffer')
	{
    	Database::transferFromBuffer($_POST['id']);
    	$return['error'] = FALSE;
        $return['msg'] = 'Тема перенесена из буфера.';
        echo json_encode($return);
	}

	//Помечаем тему для скачивания
	if ($_POST['action'] == 'threme_add')
	{
		$update = Database::updateThremesToDownload($_POST['id']);
		if ($update)
		{
			$return['error'] = FALSE;
			$return['msg'] = 'Тема помечена для закачки.';
		}
		else
			$return['error'] = TRUE;
		echo json_encode($return);
	}

	//Удаляем мониторинг
	if ($_POST['action'] == 'del')
	{
		Database::deletItem($_POST['id']);
    	$return['error'] = FALSE;
        $return['msg'] = 'Удалено.';
        echo json_encode($return);
	}

	//Обновляем личные данные
	if ($_POST['action'] == 'update_credentials')
	{
    	if ( ! isset($_POST['passkey']))
    	    $_POST['passkey'] = '';
		Database::setCredentials($_POST['id'], $_POST['log'], $_POST['pass'], $_POST['passkey']);
    	$return['error'] = FALSE;
        $return['msg'] = 'Данные для трекера обновлены.';
        echo json_encode($return);
	}

    //Обновляем основные настройки
    if ($_POST['action'] == 'update_basic')
	{
        Database::updateSettings('serverAddress', Sys::checkPath($_POST['serverAddress']));
        Database::updateSettings('send', Sys::strBoolToInt($_POST['send']));
        Database::updateSettings('auth', Sys::strBoolToInt($_POST['auth']));
        Database::updateSettings('rss', Sys::strBoolToInt($_POST['rss']));
        Database::updateSettings('autoUpdate', Sys::strBoolToInt($_POST['autoUpdate']));
        Database::updateSettings('debug', Sys::strBoolToInt($_POST['debug']));

        $return['error'] = FALSE;
        $return['msg'] = 'Основные настройки сохранены.';
        echo json_encode($return);
    }

	//Обновляем расширенные настройки
	if ($_POST['action'] == 'update_extended')
	{
		$config = Config::read('ext_filename');
		$settings = $_POST['settings'];
		$test = @simplexml_load_string($settings, 'SimpleXMLElement', LIBXML_NONET);
		if ($test === false)
		{
			$return['error'] = TRUE;
			$return['msg'] = 'Некорректный XML';
		}
		elseif (file_put_contents($config, $settings))
		{
			$return['error'] = FALSE;
			$return['msg'] = 'Расширенные настройки сохранены.';
		}
		else
		{
			$return['error'] = TRUE;
			$return['msg'] = 'Не удалось сохранить расширенные настройки.';
		}
		echo json_encode($return);
	}

	//Обновляем настройки уведомлений
	if ($_POST['action'] == 'update_services')
	{
        $notifications = Sys::strBoolToInt($_POST['notifySend']) ? Sys::strBoolToInt($_POST['notifySend']) : false;
        if ($notifications) {
            $setNotify = $_POST['notifyService'];
            Database::updateSettings('sendUpdate', 1);
            Database::updateSettings('sendUpdateService', $setNotify['id']);
            Database::updateAddress('notification', $setNotify['id'], $setNotify['address']);
        } else {
            Database::updateSettings('sendUpdate', 0);
        }

        $warnings = Sys::strBoolToInt($_POST['warnSend']) ? Sys::strBoolToInt($_POST['warnSend']) : false;
        if ($warnings) {
            $setWarn = $_POST['warnService'];
            Database::updateSettings('sendWarning', 1);
            Database::updateSettings('sendWarningService', $setWarn['id']);
            Database::updateAddress('warning', $setWarn['id'], $setWarn['address']);
        } else {
            Database::updateSettings('sendWarning', 0);
        }

        if ($notifications or $warnings) {
            Database::updateSettings('send', 1);
        }

        $return['error'] = FALSE;
        $return['msg'] = 'Настройки сервисов сохранены.';
        echo json_encode($return);
    }


	//Меняем пароль
	if ($_POST['action'] == 'update_auth')
	{
		$pass = password_hash($_POST['pass'], PASSWORD_BCRYPT);
		$q = Database::updateCredentials($pass);
		if ($q)
		{
			$return['error'] = FALSE;
            $return['msg'] = 'Новый пароль установлен.';
		}
		else
		{
			$return['error'] = TRUE;
			$return['msg'] = 'Не удалось сменить пароль!';
		}
		echo json_encode($return);
	}

    //Обновляем настройки прокси
    if ($_POST['action'] == 'update_proxy')
    {
        Database::updateSettings('proxy', Sys::strBoolToInt($_POST['proxy']));
		Database::updateSettings('proxyType', $_POST['proxyType']);
		Database::updateSettings('proxyAddress', $_POST['proxyAddress']);

        $return['error'] = FALSE;
        $return['msg'] = 'Настройки прокси сохранены.';
        echo json_encode($return);
    }

    //Обновляем настройки торрент-клиенты
    if ($_POST['action'] == 'update_torrent')
    {
        Database::updateSettings('useTorrent', Sys::strBoolToInt($_POST['useTorrent']));
        Database::updateSettings('torrentClient', $_POST['torrentClient']);
        Database::updateSettings('torrentAddress', $_POST['torrentAddress']);
        Database::updateSettings('torrentLogin', $_POST['torrentLogin']);
        Database::updateSettings('torrentPassword', $_POST['torrentPassword']);
        Database::updateSettings('pathToDownload', Sys::checkPath($_POST['pathToDownload']));
        Database::updateSettings('deleteDistribution', Sys::strBoolToInt($_POST['deleteDistribution']));
        Database::updateSettings('deleteOldFiles', Sys::strBoolToInt($_POST['deleteOldFiles']));

        $return['error'] = FALSE;
        $return['msg'] = 'Настройки торрент-клиента сохранены.';
        echo json_encode($return);
    }

	//Добавляем тему на закачку
	if ($_POST['action'] == 'download_thremes')
	{
		if ( ! empty($_POST['checkbox']))
		{
			$arr = $_POST['checkbox'];
			foreach ($arr as $id => $val)
			{
				Database::updateDownloadThreme($id);
			}
            $return['error'] = FALSE;
            $return['msg'] = count($arr).' тем помечено для закачки.';
            echo json_encode($return);
		}
		Database::updateDownloadThremeNew();
	}

    //Помечаем новость как прочитанную
	if ($_POST['action'] == 'markNews')
	{
		Database::markNews($_POST['id']);
		echo json_encode(['error' => FALSE]);
		exit;
	}

	//Помечаем все новости как прочитанные
	if ($_POST['action'] == 'markAllNews')
	{
		Database::markAllNews();
		echo json_encode(['error' => FALSE]);
		exit;
	}

	//Выполняем обновление системы
	if ($_POST['action'] == 'system_update')
	{
		Update::runUpdate();
		return TRUE;
	}

	//Выход из системы
	if ($_POST['action'] == 'logout')
	{
		session_start();
		$_SESSION = [];
		if (ini_get('session.use_cookies')) {
			$params = session_get_cookie_params();
			setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
		}
		session_destroy();
		setcookie('TM', '', ['expires' => time() - 42000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
		echo json_encode(['error' => FALSE, 'msg' => 'Выход выполнен.']);
		exit;
	}

	//Очистка ошибок потрекерно
	if ($_POST['action'] == 'clear_warnings')
	{
		if (Database::clearWarnings($_POST['tracker'])) {
            $return['error'] = FALSE;
            $return['msg'] = 'Ошибки трекера очищены.';
        } else {
            $return['error'] = TRUE;
            $return['msg'] = 'Не удалось очистить ошибки трекера.';
        }
        echo json_encode($return);
	}

	// === API-токены ===

	if ($_POST['action'] == 'api_token_create')
	{
		$name = ! empty($_POST['name']) ? $_POST['name'] : 'API Token';
		$token = bin2hex(random_bytes(32));
		$now = date('Y-m-d H:i:s');
		$stmt = Database::newStatement("INSERT INTO `api_tokens` (`name`, `token`, `created_at`) VALUES (:name, :token, :date)");
		$stmt->bindParam(':name', $name);
		$stmt->bindParam(':token', $token);
		$stmt->bindParam(':date', $now);
		if ($stmt->execute())
		{
			$return['error'] = FALSE;
			$return['msg'] = 'API-токен создан.';
			$return['token'] = $token;
		}
		else
		{
			$return['error'] = TRUE;
			$return['msg'] = 'Ошибка создания токена.';
		}
		echo json_encode($return);
	}

	if ($_POST['action'] == 'api_token_delete')
	{
		$id = (int)$_POST['id'];
		$stmt = Database::newStatement("DELETE FROM `api_tokens` WHERE `id` = :id");
		$stmt->bindParam(':id', $id, PDO::PARAM_INT);
		if ($stmt->execute())
		{
			$return['error'] = FALSE;
			$return['msg'] = 'Токен удалён.';
		}
		else
		{
			$return['error'] = TRUE;
			$return['msg'] = 'Ошибка удаления токена.';
		}
		echo json_encode($return);
	}

	// === Вебхуки ===

	if ($_POST['action'] == 'webhook_create')
	{
		if ( ! empty($_POST['name']) && ! empty($_POST['url']))
		{
			$url = $_POST['url'];
			if ( ! preg_match('#^https?://#i', $url))
			{
				$return['error'] = TRUE;
				$return['msg'] = 'URL должен начинаться с http:// или https://';
			}
			else
			{
				$events = ! empty($_POST['events']) ? $_POST['events'] : '*';
				Webhook::create($_POST['name'], $url, $events);
				$return['error'] = FALSE;
				$return['msg'] = 'Вебхук создан.';
			}
		}
		else
		{
			$return['error'] = TRUE;
			$return['msg'] = 'Укажите имя и URL вебхука.';
		}
		echo json_encode($return);
	}

	if ($_POST['action'] == 'webhook_delete')
	{
		if (Webhook::delete((int)$_POST['id']))
		{
			$return['error'] = FALSE;
			$return['msg'] = 'Вебхук удалён.';
		}
		else
		{
			$return['error'] = TRUE;
			$return['msg'] = 'Ошибка удаления вебхука.';
		}
		echo json_encode($return);
	}

}

if (isset($_GET['action']))
{
	if ( ! Sys::checkAuth())
		exit();
	Migration::run();

	//Сортировка вывода торрентов
	if ($_GET['action'] == 'order')
	{
		session_start();
        $by  = !empty($_GET['by']) ? $_GET['by'] : 'name';
        $dir = !empty($_GET['dir']) ? $_GET['dir'] : 'asc';

		if (!in_array($by, ['name', 'date'])) {
            $by = 'name';
        }
		if (!in_array($dir, ['asc', 'desc'])) {
            $dir = 'asc';
        }
		setcookie('order', $by, ['expires' => time()+3600*24*365, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
		setcookie('orderDir', $dir, ['expires' => time()+3600*24*365, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
		//header('Location: index.php');
        echo json_encode('ok');
	}

    // Список API-токенов
    if ($_GET['action'] == 'api_tokens_list') {
        $stmt = Database::newStatement("SELECT `id`, `name`, `created_at`, `last_used`, `active` FROM `api_tokens` ORDER BY `id`");
        $tokens = [];
        if ($stmt->execute()) {
            foreach ($stmt as $row)
                $tokens[] = $row;
        }
        echo json_encode(['tokens' => $tokens]);
    }

    // Список вебхуков (без секретов)
    if ($_GET['action'] == 'webhooks_list') {
        $hooks = Webhook::getAllSafe();
        echo json_encode(['hooks' => $hooks]);
    }

    // Single item data
    if ($_GET['action'] == 'item_data') {
        $item = Database::getTorrent($_GET['id'])[0];
        if (!empty($item['torrent_id'])) {
            $item['url'] = Url::href($item['tracker'], $item['torrent_id']);
        }
        $item['reset'] = false;
        echo json_encode($item, JSON_NUMERIC_CHECK);
    }
    
    // Очистка таблицы Temp
    // action.php?action=temp_clear
    if ($_GET['action'] == 'temp_clear') {
        if (Database::clearTemp())
            echo 'Таблица очищена';
    }
}
?>
