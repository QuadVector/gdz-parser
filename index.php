<?php

set_time_limit(0);
error_reporting(E_ERROR | E_PARSE);

require_once("vendor/autoload.php");

use QuadVector\GDZParser\GDZParser;
use QuadVector\GDZParser\GDZParserConfig;
use QuadVector\GDZParser\BookParser\ReshakBookParser;
use QuadVector\GDZParser\TaskListParser\ReshakTaskListParser;
use QuadVector\GDZParser\TaskParser\ReshakTaskParser;
use QuadVector\GDZParser\ValueObject\Proxy;
use QuadVector\GDZParser\Helper\Text;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$config = require_once("src/config.php"); // подключение файла конфигурации

// обработка входных параметров
$inputOptions = getopt("", [
    "logs",
    "output:",
    "parser:",
    "attempts:",
    "timeout:",
    "start-urls:",
    "proxy:",
]);

$showLogs = isset($inputOptions["logs"]); // показывать логи

// папка для сохранения результатов
if (isset($inputOptions["output"]) && trim((string) $inputOptions["output"]) !== "") {
    $outputFolder = $inputOptions["output"]; // входная переменная
} elseif (isset($_ENV["OUTPUT_FOLDER"]) && trim((string) $_ENV["OUTPUT_FOLDER"]) !== "") {
    $outputFolder = $_ENV["OUTPUT_FOLDER"]; // переменная окружения
} elseif (isset($config["output_folder"]) && trim((string) $config["output_folder"]) !== "") {
    $outputFolder = $config["output_folder"]; // значение по умолчанию
} else {
    throw new RuntimeException(
        "Output folder isn't defined. Use --output in CLI, OUTPUT_FOLDER in .env or config['output_folder'] in src/config.php."
    );
}

// тип парсера
if (isset($inputOptions["parser"]) && trim((string) $inputOptions["parser"]) !== "") {
    $parser = $inputOptions["parser"]; // входная переменная
} elseif (isset($_ENV["PARSER"]) && trim((string) $_ENV["PARSER"]) !== "") {
    $parser = $_ENV["PARSER"]; // переменная окружения
} elseif (isset($config["parser"]) && trim((string) $config["parser"]) !== "") {
    $parser = $config["parser"]; // значение по умолчанию
} else {
    throw new RuntimeException(
        "Parser isn't defined. Use --parser in CLI, PARSER in .env or config['parser'] in src/config.php."
    );
}

// кол-во попыток
if (isset($inputOptions["attempts"]) && trim((string) $inputOptions["attempts"]) !== "") {
    $attempts = (int) $inputOptions["attempts"]; // входная переменная
} elseif (isset($_ENV["ATTEMPTS"]) && trim((string) $_ENV["ATTEMPTS"]) !== "") {
    $attempts = (int) $_ENV["ATTEMPTS"]; // переменная окружения
} elseif (isset($config["attempts"])) {
    $attempts = (int) $config["attempts"]; // значение по умолчанию
} else {
    $attempts = 5;
}

// таймаут
if (isset($inputOptions["timeout"]) && trim((string) $inputOptions["timeout"]) !== "") {
    $timeout = (int) $inputOptions["timeout"]; // входная переменная
} elseif (isset($_ENV["TIMEOUT"]) && trim((string) $_ENV["TIMEOUT"]) !== "") {
    $timeout = (int) $_ENV["TIMEOUT"]; // переменная окружения
} elseif (isset($config["timeout"])) {
    $timeout = (int) $config["timeout"]; // значение по умолчанию
} else {
    $timeout = 5;
}

// стартовые ссылки для парсинга
$startURLs = [];

if (isset($inputOptions["start-urls"]) && trim((string) $inputOptions["start-urls"]) !== "") {
    $startURLsRaw = $inputOptions["start-urls"]; // входная переменная
    $startURLs = explode(',', (string) $startURLsRaw);
    $startURLs = array_map('trim', $startURLs);
    $startURLs = array_filter($startURLs, function (string $url): bool {
        return $url !== '';
    });
    $startURLs = array_values($startURLs);
} elseif (isset($_ENV["START_URLS"]) && trim((string) $_ENV["START_URLS"]) !== "") {
    $startURLsRaw = $_ENV["START_URLS"]; // переменная окружения
    $startURLs = explode(',', (string) $startURLsRaw);
    $startURLs = array_map('trim', $startURLs);
    $startURLs = array_filter($startURLs, function (string $url): bool {
        return $url !== '';
    });
    $startURLs = array_values($startURLs);
} elseif (isset($config["startURLs"]) && is_array($config["startURLs"])) {
    $startURLs = $config["startURLs"]; // значение по умолчанию
}

// прокси сервера
$proxy = [];

if (Text::cliOptionPassed($argv, "proxy")) {
    $proxyRaw = $inputOptions["proxy"] ?? "";

    if ($proxyRaw !== false && trim((string) $proxyRaw) !== "") {
        $proxy = explode(',', (string) $proxyRaw);
        $proxy = array_map('trim', $proxy);
        $proxy = array_filter($proxy, function (string $url): bool {
            return $url !== '';
        });
        $proxy = array_values($proxy);
    }
} elseif (isset($_ENV["PROXY"]) && trim((string) $_ENV["PROXY"]) !== "") {
    $proxyRaw = $_ENV["PROXY"]; // переменная окружения

    // преобразуем входную строку в массив
    if ($proxyRaw !== false && trim((string) $proxyRaw) !== "") {
        $proxy = explode(";", trim((string) $proxyRaw, ';'));
        $proxy = array_map('trim', $proxy);
        $proxy = array_filter($proxy, function (string $url): bool {
            return $url !== '';
        });
        $proxy = array_values($proxy);
    }
} elseif (isset($config["proxy"]) && is_array($config["proxy"])) {
    $proxy = $config["proxy"]; // значение по умолчанию
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
        throw new RuntimeException(
            "Parser '{$parser}' isn't supported. Available parsers: reshak."
        );
}

/*
    Пример консольной команды:
    php index.php --start-urls="https://reshak.ru/tag/4klass.html" --proxy="96.62.194.189:6391:user:pass,31.98.15.181:5358:user:pass" --attempts=5 --timeout=5 --logs

    Пример команды с параметрами из .env:
    php index.php --logs

    Пример команды с параметрами из config.php:
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
        parseOutputFolder: Text::resolvePath(__DIR__, $outputFolder),
        showLogs: $showLogs
    )
);

$GDZParser->run();
