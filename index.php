<?php

set_time_limit(0);
require_once("vendor/autoload.php");

use QuadVector\GDZParser\GDZParser;
use QuadVector\GDZParser\GDZParserConfig;
use QuadVector\GDZParser\BookParser\ReshakBookParser;
use QuadVector\GDZParser\TaskListParser\ReshakTaskListParser;
use QuadVector\GDZParser\TaskParser\ReshakTaskParser;
use QuadVector\GDZParser\Helper\Proxy;

$GDZParser = new GDZParser(
    new GDZParserConfig(
        BookParser: new ReshakBookParser(),
        TaskListParser: new ReshakTaskListParser(),
        TaskParser: new ReshakTaskParser(),
        StartURLs: [
            "https://reshak.ru/tag/3klass.html",
            "https://reshak.ru/tag/4klass.html",
            "https://reshak.ru/tag/5klass.html",
            "https://reshak.ru/tag/6klass.html",
            "https://reshak.ru/tag/7klass.html",
            "https://reshak.ru/tag/8klass.html",
            "https://reshak.ru/tag/9klass.html",
            "https://reshak.ru/tag/10klass.html",
            "https://reshak.ru/tag/11klass.html",
        ],
        Proxies: array_map(function (string $item) {
            return Proxy::fromString($item);
        }, [
            "45.56.137.220:9285:mkubsocc:zt8bk98vbqn9",
        ]),
        Attempts: 5,
        Timeout: 5,
        ParseOutputFolder: __DIR__ . "\\output"
    )
);

$GDZParser->run();
