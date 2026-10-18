<?php
//Кэш Cloudflare-допуска (кука cf_clearance + UA решившего браузера) на время прогона.
//Byparr рендерит страницу целиком и ждёт её загрузки: страница раздачи kinozal.me с сотней
//рекламных скриптов решается ~2 минуты, тот же скрипт без параметров - ~10 секунд. Поэтому
//челлендж решается один раз на хост на «лёгком» адресе, а дальше страницы идут обычным cURL
//с выданной кукой - ~1 секунда вместо двух минут на каждую
class CloudflareBypass
{
    private static $clearance = array();
    private static $attempted = array();

    private static function host($url)
    {
        $host = parse_url($url, PHP_URL_HOST);
        return $host ? strtolower($host) : '';
    }

    //['cookie' => 'cf_clearance=...', 'userAgent' => '...'] или NULL
    public static function get($url)
    {
        $host = self::host($url);
        return isset(self::$clearance[$host]) ? self::$clearance[$host] : NULL;
    }

    //допуск не сработал (истёк, сменился IP прокси). Лёгкий обход повторно не запускаем:
    //раз он не помог, дальше по этому хосту работает полный рендер через Byparr
    public static function forget($url)
    {
        unset(self::$clearance[self::host($url)]);
    }

    //куки сессии трекера + cf_clearance. Остальные куки из ответа Byparr не берём:
    //рекламные сети ставят их сотнями, и сервер отвечает 400 на раздутый заголовок
    public static function cookieWith($cookie, $url)
    {
        $cf = self::get($url);
        if ($cf === NULL)
            return $cookie;
        //прежний cf_clearance (rutracker хранит куки своего обхода) убираем - иначе в заголовке два
        //одноимённых значения, и сервер берёт первое, возможно протухшее
        $cookie = preg_replace('/(?:^|;\s*)cf_clearance=[^;]*/', '', (string) $cookie);
        $cookie = trim(rtrim(trim($cookie), ';'), '; ');
        return ($cookie !== '' ? $cookie.'; ' : '').$cf['cookie'];
    }

    //запоминаем допуск из результата Sys::getViaFlareSolverr(); FALSE - cf_clearance в ответе нет
    public static function remember($url, $fsResult)
    {
        if ( ! is_array($fsResult) || empty($fsResult['cookies']))
            return FALSE;
        if ( ! preg_match('/(?:^|;\s*)cf_clearance=([^;]+)/', $fsResult['cookies'], $m))
            return FALSE;
        self::$clearance[self::host($url)] = array(
            'cookie'    => 'cf_clearance='.$m[1],
            'userAgent' => isset($fsResult['userAgent']) ? $fsResult['userAgent'] : '',
        );
        return TRUE;
    }

    //решаем челлендж на том же скрипте без параметров (не больше одной попытки на хост за прогон)
    public static function solve($url, $cookie = '')
    {
        $host = self::host($url);
        if ($host === '' || isset(self::$clearance[$host]))
            return isset(self::$clearance[$host]);
        if (isset(self::$attempted[$host]))
            return FALSE;
        self::$attempted[$host] = TRUE;

        $parts = parse_url($url);
        $light = (isset($parts['scheme']) ? $parts['scheme'] : 'https').'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .(isset($parts['path']) ? $parts['path'] : '/');

        return self::remember($url, Sys::getViaFlareSolverr($light, (string) $cookie));
    }
}
?>
