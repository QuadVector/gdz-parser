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
    "start-urls:"
]);

$showLogs = isset($options["logs"]);

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

$GDZParser = new GDZParser(
    new GDZParserConfig(
        BookParser: new ReshakBookParser(),
        TaskListParser: new ReshakTaskListParser(),
        TaskParser: new ReshakTaskParser(),
        StartURLs: $startURLs,
        Proxies: array_map(function (string $item) {
            return Proxy::fromString($item);
        }, [
            "45.56.137.220:9285:mkubsocc:zt8bk98vbqn9",
        ]),
        Attempts: 5,
        Timeout: 5,
        ParseOutputFolder: __DIR__ . "\\output",
        ShowLogs: $showLogs
    )
);

$GDZParser->run();
