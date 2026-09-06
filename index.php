<?php

set_time_limit(0);
error_reporting(E_ERROR | E_PARSE);

require_once __DIR__ . "/vendor/autoload.php";

use QuadVector\GDZParser\GDZParser;
use QuadVector\GDZParser\GDZParserConfig;
use QuadVector\GDZParser\BookParser\ReshakBookParser;
use QuadVector\GDZParser\TaskListParser\ReshakTaskListParser;
use QuadVector\GDZParser\TaskParser\ReshakTaskParser;
use QuadVector\GDZParser\ValueObject\Proxy;
use QuadVector\GDZParser\Helper\Text;

// ============================================================
// ENV
// ============================================================

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// ============================================================
// Вспомогательные функции
// ============================================================

/**
 * Преобразовать значение в bool.
 *
 * Поддерживаются:
 * true / false
 * 1 / 0
 * yes / no
 * on / off
 * y / n
 */
$parseBoolean = static function (
    mixed $value,
    bool $default = false
): bool {
    if ($value === null || $value === '') {
        return $default;
    }

    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value)) {
        return $value !== 0;
    }

    $value = strtolower(
        trim((string)$value)
    );

    return match ($value) {
        '1', 'true', 'yes', 'on', 'y' => true,
        '0', 'false', 'no', 'off', 'n' => false,

        default => throw new InvalidArgumentException(
            "Invalid boolean value '{$value}'. "
                . "Use true/false, 1/0, yes/no or on/off."
        ),
    };
};

/**
 * Получить строковое значение:
 *
 * CLI имеет приоритет над .env.
 */
$getStringOption = static function (
    array $inputOptions,
    string $cliName,
    string $envName,
    ?string $default = null
): ?string {
    if (
        array_key_exists($cliName, $inputOptions)
        && $inputOptions[$cliName] !== false
        && trim((string)$inputOptions[$cliName]) !== ''
    ) {
        return trim(
            (string)$inputOptions[$cliName]
        );
    }

    if (
        array_key_exists($envName, $_ENV)
        && trim((string)$_ENV[$envName]) !== ''
    ) {
        return trim(
            (string)$_ENV[$envName]
        );
    }

    return $default;
};

/**
 * Преобразовать строку со списком в массив.
 *
 * Поддерживаем одновременно:
 *
 * item1,item2,item3
 *
 * и:
 *
 * item1;item2;item3
 */
$parseList = static function (
    ?string $value
): array {
    if (
        $value === null
        || trim($value) === ''
    ) {
        return [];
    }

    $items = preg_split(
        '/[;,]+/',
        $value
    ) ?: [];

    $items = array_map(
        'trim',
        $items
    );

    $items = array_filter(
        $items,
        static fn(string $item): bool =>
        $item !== ''
    );

    return array_values(
        $items
    );
};

// ============================================================
// CLI
// ============================================================

$inputOptions = getopt(
    "",
    [
        "logs",
        "output:",
        "parser:",
        "attempts:",
        "timeout:",
        "start-urls:",
        "proxy:",
        "mode:",
        "parse-images:",
    ]
);


// ============================================================
// LOGS
// ============================================================

/*
 * CLI:
 *
 * --logs
 *
 * ENV:
 *
 * LOGS=true
 */
if (array_key_exists('logs', $inputOptions)) {
    $showLogs = true;
} else {
    $showLogs = $parseBoolean(
        $_ENV['LOGS'] ?? null,
        false
    );
}

// ============================================================
// OUTPUT
// ============================================================

$outputFolder = $getStringOption(
    $inputOptions,
    'output',
    'OUTPUT_FOLDER'
);

if ($outputFolder === null) {
    throw new RuntimeException(
        "Output folder isn't defined. "
            . "Use --output=\"output\" in CLI "
            . "or OUTPUT_FOLDER=output in .env."
    );
}

// ============================================================
// PARSER
// ============================================================

$parser = $getStringOption(
    $inputOptions,
    'parser',
    'PARSER'
);

if ($parser === null) {
    throw new RuntimeException(
        "Parser isn't defined. "
            . "Use --parser=\"reshak\" in CLI "
            . "or PARSER=reshak in .env."
    );
}

$parser = strtolower(
    trim($parser)
);

// ============================================================
// ATTEMPTS
// ============================================================

$attemptsRaw = $getStringOption(
    $inputOptions,
    'attempts',
    'ATTEMPTS',
    '5'
);

$attempts = (int)$attemptsRaw;

if ($attempts < 1) {
    throw new RuntimeException(
        "Attempts must be greater than or equal to 1."
    );
}

// ============================================================
// TIMEOUT
// ============================================================

$timeoutRaw = $getStringOption(
    $inputOptions,
    'timeout',
    'TIMEOUT',
    '5'
);

$timeout = (int)$timeoutRaw;

if ($timeout < 1) {
    throw new RuntimeException(
        "Timeout must be greater than or equal to 1."
    );
}

// ============================================================
// MODE
//
// books    — до книг включительно
// tasks    — до списка задач включительно
// all      — полный парсинг задач
// ============================================================

$mode = $getStringOption(
    $inputOptions,
    'mode',
    'MODE',
    'all'
);

$mode = strtolower(
    trim((string)$mode)
);

$availableModes = [
    'books',
    'tasks',
    'all',
];

if (
    !in_array(
        $mode,
        $availableModes,
        true
    )
) {
    throw new RuntimeException(
        "Mode '{$mode}' isn't supported. "
            . "Available modes: "
            . implode(
                ', ',
                $availableModes
            )
            . "."
    );
}

// ============================================================
// PARSE_IMAGES
//
// true  — скачивать изображения
// false — изображения не загружать
// ============================================================

if (
    array_key_exists(
        'parse-images',
        $inputOptions
    )
) {
    $parseImages = $parseBoolean(
        $inputOptions['parse-images'],
        true
    );
} else {
    $parseImages = $parseBoolean(
        $_ENV['PARSE_IMAGES'] ?? null,
        true
    );
}

// ============================================================
// START_URLS
// ============================================================

$startURLsRaw = $getStringOption(
    $inputOptions,
    'start-urls',
    'START_URLS'
);

$startURLs = $parseList(
    $startURLsRaw
);

if (count($startURLs) === 0) {
    throw new RuntimeException(
        "Start URLs aren't defined. "
            . "Use --start-urls=\"url1,url2\" in CLI "
            . "or START_URLS=url1,url2 in .env."
    );
}

// ============================================================
// PROXY
// ============================================================

$proxy = [];

/*
 * Тут отдельно проверяем факт передачи CLI-параметра.
 *
 * Это позволяет сделать:
 *
 * --proxy=""
 *
 * и тем самым явно отключить прокси,
 * даже если PROXY задан в .env.
 */
if (
    Text::cliOptionPassed(
        $argv,
        'proxy'
    )
) {
    $proxyRaw =
        $inputOptions['proxy'] ?? '';
} else {
    $proxyRaw =
        $_ENV['PROXY'] ?? '';
}

$proxyStrings = $parseList(
    (string)$proxyRaw
);

$proxy = array_map(
    static function (
        string $item
    ): Proxy {
        return Proxy::fromString(
            $item
        );
    },
    $proxyStrings
);

unset(
    $proxyStrings,
    $proxyRaw
);

// ============================================================
// PARSER CONTEXTS
// ============================================================

switch ($parser) {
    case 'reshak':
        $bookParser =
            new ReshakBookParser();

        $taskListParser =
            new ReshakTaskListParser();

        $taskParser =
            new ReshakTaskParser(
                parseImages: $parseImages
            );

        break;

    default:
        throw new RuntimeException(
            "Parser '{$parser}' isn't supported. "
                . "Available parsers: reshak."
        );
}

// ============================================================
// RUN
// ============================================================

$GDZParser = new GDZParser(
    new GDZParserConfig(
        bookParser: $bookParser,
        taskListParser: $taskListParser,
        taskParser: $taskParser,
        startURLs: $startURLs,
        proxy: $proxy,
        attempts: $attempts,
        timeout: $timeout,
        parseOutputFolder: Text::resolvePath(
            __DIR__,
            $outputFolder
        ),
        showLogs: $showLogs,
        mode: $mode,
        parseImages: $parseImages
    )
);

$GDZParser->run();
