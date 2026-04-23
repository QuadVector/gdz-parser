<?php

require_once("vendor/autoload.php");

use Mihairu\GDZParser\GDZParser;
use Mihairu\GDZParser\GDZParserConfig;
use Mihairu\GDZParser\BookParser\ReshakBookParser;
use Mihairu\GDZParser\TaskListParser\ReshakTaskListParser;
use Mihairu\GDZParser\Helper\Proxy;

$GDZParser = new GDZParser(
    new GDZParserConfig(
        BookParser: new ReshakBookParser(),
        TaskListParser: new ReshakTaskListParser(),
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
            "216.170.122.98:6136:mkubsocc:zt8bk98vbqn9",
            "72.1.136.134:7025:mkubsocc:zt8bk98vbqn9",
            "82.21.32.233:7493:mkubsocc:zt8bk98vbqn9",
            "82.21.44.129:7891:mkubsocc:zt8bk98vbqn9",
            "46.203.15.11:7012:mkubsocc:zt8bk98vbqn9",
            "82.21.42.82:7344:mkubsocc:zt8bk98vbqn9",
            "45.56.137.220:9285:mkubsocc:zt8bk98vbqn9",
            "82.21.39.186:7947:mkubsocc:zt8bk98vbqn9",
            "82.29.124.43:7808:mkubsocc:zt8bk98vbqn9",
            "45.248.55.168:6754:mkubsocc:zt8bk98vbqn9",
            "209.166.17.151:6312:mkubsocc:zt8bk98vbqn9",
            "9.142.221.48:5212:mkubsocc:zt8bk98vbqn9",
            "195.40.129.114:6835:mkubsocc:zt8bk98vbqn9",
            "5.59.251.138:6177:mkubsocc:zt8bk98vbqn9",
            "82.24.27.216:8188:mkubsocc:zt8bk98vbqn9",
            "82.24.27.131:8103:mkubsocc:zt8bk98vbqn9",
            "45.58.244.172:6585:mkubsocc:zt8bk98vbqn9",
            "82.21.62.29:7793:mkubsocc:zt8bk98vbqn9",
            "72.46.138.131:6357:mkubsocc:zt8bk98vbqn9",
            "82.21.49.194:7457:mkubsocc:zt8bk98vbqn9"
        ]),
        Attempts: 5,
        Timeout: 5,
        ParseOutputFolder: __DIR__ . "/output/"
    )
);

$GDZParser->run();
