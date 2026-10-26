<?php

$publicPath = realpath(__DIR__.'/../../public');
$requestPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$filePath = realpath($publicPath.$requestPath);

if ($requestPath !== '/' && $filePath && str_starts_with($filePath, $publicPath.DIRECTORY_SEPARATOR) && is_file($filePath)) {
    return false;
}

require $publicPath.'/index.php';
