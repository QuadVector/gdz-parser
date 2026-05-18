<?php

set_time_limit(0);
error_reporting(E_ERROR | E_PARSE);

require_once("vendor/autoload.php");

use QuadVector\GDZParser\GDZParser;
use QuadVector\GDZParser\GDZParserConfig;
use QuadVector\GDZParser\BookParser\ReshakBookParser;
use QuadVector\GDZParser\TaskListParser\ReshakTaskListParser;
use QuadVector\GDZParser\TaskParser\ReshakTaskParser;
use QuadVector\GDZParser\Helper\Proxy;

$options = getopt("", [
    "logs",
    "output:",
    "start-urls:",
    "proxy:",
    "attempts:",
    "timeout:"
]);

$showLogs = isset($options["logs"]);
$outputFolder = isset($options["output"]) ? $options["output"] : __DIR__ . "\\output";
$attempts = isset($options["attempts"]) ? (int)$options["attempts"] : 5;
$timeout = isset($options["timeout"]) ? (int)$options["timeout"] : 5;

// стартовые ссылки для парсинга
$startURLs = [];
if (isset($options["start-urls"])) {
    $startURLsRaw = $options["start-urls"];
    $startURLs = explode(',', $startURLsRaw);
    $startURLs = array_map('trim', $startURLs);
    $startURLs = array_filter($startURLs, function (string $url): bool {
        return $url !== '';
    });
    $startURLs = array_values($startURLs);
}

// прокси сервера (через запятую, в формате ip:port:login:password)
$proxy = [];
if (isset($options["proxy"])) {
    $proxyRaw = $options["proxy"];
    $proxy = explode(',', $proxyRaw);
    $proxy = array_map('trim', $proxy);
    $proxy = array_filter($proxy, function (string $url): bool {
        return $url !== '';
    });
    $proxy = array_values($proxy);
}

/*
    Пример консольной команды:
    php index.php --start-urls="https://reshak.ru/tag/4klass.html" --proxy="96.62.194.189:6391:mkubsocc:zt8bk98vbqn9,31.98.15.181:5358:mkubsocc:zt8bk98vbqn9" --attempts=5 --timeout=5
*/

$GDZParser = new GDZParser(
    new GDZParserConfig(
        bookParser: new ReshakBookParser(),
        taskListParser: new ReshakTaskListParser(),
        taskParser: new ReshakTaskParser(),
        startURLs: $startURLs,
        proxy: array_map(function (string $item) {
            return Proxy::fromString($item);
        }, $proxy),
        attempts: $attempts,
        timeout: $timeout,
        parseOutputFolder: $outputFolder,
        showLogs: $showLogs
    )
);

$GDZParser->run();
