<?php

namespace QuadVector\GDZParser\Helper;

final class Text
{
    /**
     * Очистить текст от лишних символов.
     */
    public static function cleanupText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $text = preg_replace(
            '/^\xEF\xBB\xBF/',
            '',
            $text
        );

        $text = html_entity_decode(
            $text,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $text = preg_replace(
            '/\x{00A0}/u',
            ' ',
            $text
        );

        $text = preg_replace(
            '/\x{202F}/u',
            ' ',
            $text
        );

        $text = preg_replace(
            '/\s+/u',
            ' ',
            $text
        );

        return trim((string)$text);
    }

    /**
     * Сгенерировать стабильный ID книги по URL.
     *
     * Например:
     *
     * https://reshak.ru/reshebniki/istoria/11/zagladin/index.html
     *
     * и:
     *
     * https://reshak.ru/reshebniki/istoria/11/zagladin/index.php
     *
     * будут относиться к одной книге.
     */
    public static function generateBookId(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new \InvalidArgumentException(
                'Book URL must not be empty.'
            );
        }

        /*
         * Если схема отсутствует, временно добавляем ее,
         * чтобы parse_url() корректно разобрал адрес.
         */
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        $parts = parse_url($url);

        if (!is_array($parts)) {
            throw new \InvalidArgumentException(
                "Invalid book URL: {$url}"
            );
        }

        $host = strtolower(
            trim((string)($parts['host'] ?? ''))
        );

        $path = (string)($parts['path'] ?? '');

        /*
         * Убираем повторные "/".
         */
        $path = preg_replace(
            '#/+#',
            '/',
            $path
        );

        /*
         * URL книги может заканчиваться:
         *
         * /index.html
         * /index.htm
         * /index.php
         *
         * Это одна и та же логическая книга.
         */
        $path = preg_replace(
            '#/index\.(?:php|html?|htm)$#i',
            '',
            $path
        );

        $path = '/' . trim(
            (string)$path,
            '/'
        );

        if ($path === '/') {
            $path = '';
        }

        /*
         * Query обычно у книг отсутствует,
         * но если он есть, учитываем его.
         */
        $query = '';

        if (
            isset($parts['query'])
            && trim((string)$parts['query']) !== ''
        ) {
            parse_str(
                (string)$parts['query'],
                $queryParams
            );

            ksort($queryParams);

            $query = http_build_query(
                $queryParams,
                '',
                '&',
                PHP_QUERY_RFC3986
            );
        }

        $canonical =
            $host
            . $path
            . ($query !== '' ? '?' . $query : '');

        /*
         * 20 hex-символов = 80 бит.
         *
         * Для нашего количества книг этого более чем достаточно,
         * при этом идентификатор остается коротким.
         */
        return substr(
            hash('sha256', $canonical),
            0,
            20
        );
    }

    /**
     * Сгенерировать ЧПУ-транслит текста.
     */
    public static function translitRef(string $value): string
    {
        $value = self::cleanupText($value);

        if ($value === '') {
            return 'default';
        }

        $converter = [
            'а' => 'a',
            'б' => 'b',
            'в' => 'v',
            'г' => 'g',
            'д' => 'd',
            'е' => 'e',
            'ё' => 'e',
            'ж' => 'zh',
            'з' => 'z',
            'и' => 'i',
            'й' => 'y',
            'к' => 'k',
            'л' => 'l',
            'м' => 'm',
            'н' => 'n',
            'о' => 'o',
            'п' => 'p',
            'р' => 'r',
            'с' => 's',
            'т' => 't',
            'у' => 'u',
            'ф' => 'f',
            'х' => 'h',
            'ц' => 'c',
            'ч' => 'ch',
            'ш' => 'sh',
            'щ' => 'sch',
            'ь' => '',
            'ы' => 'y',
            'ъ' => '',
            'э' => 'e',
            'ю' => 'yu',
            'я' => 'ya',
        ];

        $value = mb_strtolower(
            $value,
            'UTF-8'
        );

        $value = strtr(
            $value,
            $converter
        );

        $value = preg_replace(
            '/[^a-z0-9]+/u',
            '-',
            $value
        );

        $value = preg_replace(
            '/-+/',
            '-',
            (string)$value
        );

        $value = trim(
            (string)$value,
            '-'
        );

        return $value !== ''
            ? $value
            : 'default';
    }

    /**
     * Сгенерировать название на основе URL.
     */
    public static function generateNamefromURL(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return 'default';
        }

        $parts = parse_url($url);

        if (!is_array($parts)) {
            return 'default';
        }

        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? '';

        if ($host === '' && $path !== '') {
            $prepared = parse_url(
                'http://' . ltrim($url, '/')
            );

            if (is_array($prepared)) {
                $host = $prepared['host'] ?? '';
                $path = $prepared['path'] ?? '';
                $query = $prepared['query'] ?? '';
            }
        }

        $path = trim($path, '/');

        if ($path !== '') {
            $segments = explode(
                '/',
                $path
            );

            $lastIndex =
                count($segments) - 1;

            if (
                $lastIndex >= 0
                && isset($segments[$lastIndex])
            ) {
                $segments[$lastIndex] = pathinfo(
                    $segments[$lastIndex],
                    PATHINFO_FILENAME
                );
            }

            $segments = array_filter(
                $segments,
                static fn($segment): bool =>
                    $segment !== ''
            );

            $path = implode(
                '/',
                $segments
            );
        }

        $result = trim(
            $host
            . (
                $path !== ''
                    ? '/' . $path
                    : ''
            ),
            '/'
        );

        if ($query !== '') {
            $query = urldecode($query);

            $query = str_replace(
                ['&', '='],
                ['_', '-'],
                $query
            );

            $result .= '_' . $query;
        }

        $result = preg_replace(
            '/[^a-zA-Z0-9._-]+/',
            '_',
            $result
        );

        $result = preg_replace(
            '/_+/',
            '_',
            (string)$result
        );

        $result = trim(
            (string)$result,
            '._-'
        );

        $result = mb_substr(
            $result,
            0,
            100,
            'UTF-8'
        );

        return $result !== ''
            ? $result
            : 'default';
    }

    /**
     * Сделать относительную ссылку абсолютной.
     */
    public static function makeAbsoluteURL(
        string $domain,
        string $href
    ): string {
        $domain = trim($domain);
        $href = trim($href);

        if ($href === '') {
            return '';
        }

        if (
            preg_match(
                '#^[a-z][a-z0-9+.-]*://#i',
                $href
            )
        ) {
            return $href;
        }

        if (
            str_starts_with(
                $href,
                '//'
            )
        ) {
            return 'https:' . $href;
        }

        return 'https://'
            . rtrim($domain, '/')
            . '/'
            . ltrim($href, '/');
    }

    /**
     * Сделать безопасное имя JSON-файла.
     */
    public static function makeSafeJSONFileName(
        string $name
    ): string {
        $extension = '.json';
        $maxLength = 255;

        if (strlen($name) <= $maxLength) {
            return $name;
        }

        if (
            str_ends_with(
                strtolower($name),
                $extension
            )
        ) {
            $baseName = substr(
                $name,
                0,
                -strlen($extension)
            );
        } else {
            $baseName = $name;
        }

        $hash = md5($name);

        $suffix =
            '_'
            . $hash
            . $extension;

        $availableLength =
            $maxLength
            - strlen($suffix);

        $baseName = substr(
            $baseName,
            0,
            $availableLength
        );

        return $baseName . $suffix;
    }

    /**
     * Был ли передан параметр в CLI.
     */
    public static function cliOptionPassed(
        array $argv,
        string $optionName
    ): bool {
        $option = '--' . $optionName;

        foreach ($argv as $arg) {
            if ($arg === $option) {
                return true;
            }

            if (
                str_starts_with(
                    $arg,
                    $option . '='
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Преобразовать путь в абсолютный.
     */
    public static function resolvePath(
        string $dir,
        string $path
    ): string {
        $path = trim($path);

        if (
            str_starts_with(
                $path,
                '/'
            )
        ) {
            return $path;
        }

        if (
            preg_match(
                '/^[A-Za-z]:[\/\\\\]/',
                $path
            ) === 1
        ) {
            return $path;
        }

        return rtrim(
            $dir,
            '/\\'
        )
            . DIRECTORY_SEPARATOR
            . ltrim(
                $path,
                '/\\'
            );
    }
}