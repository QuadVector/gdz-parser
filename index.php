<?php

require_once("vendor/autoload.php");

use Mihairu\GDZParser\GDZParser;
use Mihairu\GDZParser\GDZParserConfig;
use Mihairu\GDZParser\BookParser\ReshakBookParser;
use Mihairu\GDZParser\TaskListParser\ReshakTaskListParser;
use Mihairu\GDZParser\TaskParser\ReshakTaskParser;
use Mihairu\GDZParser\Helper\Proxy;

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
            "46.203.15.11:7012:mkubsocc:zt8bk98vbqn9",
            "45.56.137.220:9285:mkubsocc:zt8bk98vbqn9",
        ]),
        Attempts: 5,
        Timeout: 5,
        ParseOutputFolder: __DIR__ . "/output/"
    )
);

$GDZParser->run();
