<?php
class Router
{
    private static $routes = [];
    private static $notFound = NULL;

    //ответ на неизвестный маршрут задаётся снаружи, чтобы он был в том же конверте, что и остальные
    public static function notFound($handler)
    {
        self::$notFound = $handler;
    }

    public static function get($path, $handler)
    {
        self::$routes[] = ['method' => 'GET', 'path' => $path, 'handler' => $handler];
    }

    public static function post($path, $handler)
    {
        self::$routes[] = ['method' => 'POST', 'path' => $path, 'handler' => $handler];
    }

    public static function put($path, $handler)
    {
        self::$routes[] = ['method' => 'PUT', 'path' => $path, 'handler' => $handler];
    }

    public static function delete($path, $handler)
    {
        self::$routes[] = ['method' => 'DELETE', 'path' => $path, 'handler' => $handler];
    }

    public static function dispatch($method, $uri)
    {
        //при заходе через /api/... (RewriteRule в .htaccess) REQUEST_URI остаётся исходным
        //и не содержит api.php - вырезать по нему нечего, и все маршруты отдавали 404.
        //Apache в этом случае кладёт путь после скрипта в PATH_INFO, он и есть маршрут
        $uri = self::pathInfo($uri);
        if (empty($uri) || $uri[0] !== '/')
            $uri = '/'.ltrim($uri, '/');

        foreach (self::$routes as $route)
        {
            if ($route['method'] !== $method)
                continue;

            $pattern = self::pathToRegex($route['path']);
            if (preg_match($pattern, $uri, $matches))
            {
                // Извлекаем именованные параметры
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return call_user_func($route['handler'], $params);
            }
        }

        if (self::$notFound !== NULL)
            return call_user_func(self::$notFound);

        self::json(['error' => 'Маршрут не найден'], 404);
    }

    //путь маршрута: PATH_INFO, иначе остаток REQUEST_URI после api.php или /api
    private static function pathInfo($uri)
    {
        foreach (array('PATH_INFO', 'ORIG_PATH_INFO') as $key)
        {
            if ( ! empty($_SERVER[$key]) && $_SERVER[$key] !== '/')
                return strtok($_SERVER[$key], '?');
        }

        $uri = strtok($uri, '?');
        if (preg_match('#^.*/api(?:\.php)?(/.*)?$#', $uri, $m))
            return isset($m[1]) ? $m[1] : '/';

        return $uri;
    }

    private static function pathToRegex($path)
    {
        $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $path);
        return '#^' . $regex . '$#';
    }

    public static function json($data, $code = 200)
    {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    public static function getInput()
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (is_array($data))
            return $data;

        //тело может приехать и обычной формой - тогда php://input уже разобран в $_POST
        return is_array($_POST) ? $_POST : [];
    }
}
?>
