<?php

set_time_limit(0);
error_reporting(E_ERROR | E_PARSE);

require_once("vendor/autoload.php");
require_once("config.php");

use QuadVector\GDZParser\GDZParser;
use QuadVector\GDZParser\GDZParserConfig;
use QuadVector\GDZParser\BookParser\ReshakBookParser;
use QuadVector\GDZParser\TaskListParser\ReshakTaskListParser;
use QuadVector\GDZParser\TaskParser\ReshakTaskParser;
use QuadVector\GDZParser\ValueObject\Proxy;

// входные параметры CLI
// данные параметры имеют больший приоритет, чем параметры, указанные в config.php
$inputOptions = getopt("", [
    "logs",
    "output:",
    "parser:",
    "attempts:",
    "timeout:",
    "start-urls:",
    "proxy:",
]);

/**
 * Введен ли входной параметр в консоли
 * @param array $argv Массив с входными параметрами CLI
 * @param string $optionName Название параметра
 * @return bool
 */
function cliOptionPassed(array $argv, string $optionName): bool
{
    $option = '--' . $optionName;

    foreach ($argv as $arg) {
        if ($arg === $option) {
            return true;
        }

        if (str_starts_with($arg, $option . '=')) {
            return true;
        }
    }

    return false;
}

$showLogs = isset($inputOptions["logs"]); // показывать логи

// папка для сохранения результатов
if (isset($inputOptions["output"])) {
    $outputFolder = $inputOptions["output"];
} elseif (isset($config["output"])) {
    $outputFolder = $config["output"];
} else {
    $outputFolder = null;
}

// кол-во попыток
if (isset($inputOptions["attempts"])) {
    $attempts = (int)$inputOptions["attempts"];
} elseif (isset($config["attempts"])) {
    $attempts = $config["attempts"];
} else {
    $attempts = 5;
}

// таймаут
if (isset($inputOptions["timeout"])) {
    $timeout = (int)$inputOptions["timeout"];
} elseif (isset($config["timeout"])) {
    $timeout = $config["timeout"];
} else {
    $timeout = 5;
}

if (isset($inputOptions["parser"])) {
    $parser = $inputOptions["parser"];
} elseif (isset($config["parser"])) {
    $parser = $config["parser"];
} else {
    $parser = null;
}

// стартовые ссылки для парсинга
if (isset($inputOptions["start-urls"])) {
    $startURLsRaw = $inputOptions["start-urls"];
    $startURLs = explode(',', $startURLsRaw);
    $startURLs = array_map('trim', $startURLs);
    $startURLs = array_filter($startURLs, function (string $url): bool {
        return $url !== '';
    });
    $startURLs = array_values($startURLs);
} elseif (isset($config["startURLs"]) && is_array($config["startURLs"])) {
    $startURLs = $config["startURLs"];
} else {
    $startURLs = [];
}

// прокси сервера (через запятую, в формате ip:port:login:password)
if (cliOptionPassed($argv, "proxy")) {
    $proxyRaw = $inputOptions["proxy"] ?? "";

    if ($proxyRaw === false || trim((string)$proxyRaw) === "") {
        $proxy = [];
    } else {
        $proxy = explode(',', (string)$proxyRaw);
        $proxy = array_map('trim', $proxy);
        $proxy = array_filter($proxy, function (string $url): bool {
            return $url !== '';
        });
        $proxy = array_values($proxy);
    }
} elseif (isset($config["proxy"]) && is_array($config["proxy"])) {
    $proxy = $config["proxy"];
} else {
    $proxy = [];
}

$proxy = array_map(function (string $item) {
    return Proxy::fromString($item);
}, $proxy);

// контексты парсера
switch ($parser) {
    case "reshak":
        $bookParser = new ReshakBookParser();
        $taskListParser = new ReshakTaskListParser();
        $taskParser = new ReshakTaskParser();
        break;
    default:
        $bookParser = null;
        $taskListParser = null;
        $taskParser = null;
        break;
}

/*
    Пример консольной команды с параметрами:
    php index.php --start-urls="https://reshak.ru/tag/4klass.html" --proxy="96.62.194.189:6391:mkubsocc:zt8bk98vbqn9,31.98.15.181:5358:mkubsocc:zt8bk98vbqn9" --attempts=5 --timeout=5 --logs

    Пример консольной команды с параметрами из config.php:
    php index.php
*/

$GDZParser = new GDZParser(
    new GDZParserConfig(
        bookParser: $bookParser,
        taskListParser: $taskListParser,
        taskParser: $taskParser,
        startURLs: $startURLs,
        proxy: $proxy,
        attempts: $attempts,
        timeout: $timeout,
        parseOutputFolder: $outputFolder,
        showLogs: $showLogs
    )
);

$GDZParser->run();
