<?php

namespace QuadVector\GDZParser;

use Exception;
use League\CLImate\CLImate;
use QuadVector\GDZParser\BookParser\BookParserContext;
use QuadVector\GDZParser\DTO\BookDTO;
use QuadVector\GDZParser\DTO\TaskListItemDTO;
use QuadVector\GDZParser\Exception\ParseException;
use QuadVector\GDZParser\Helper\Text;
use QuadVector\GDZParser\TaskListParser\TaskListParserContext;
use QuadVector\GDZParser\TaskParser\TaskParserContext;
use QuadVector\GDZParser\ValueObject\Proxy;

class GDZParser
{
    protected CLImate $cli;

    protected GDZParserConfig $config;

    protected ?BookParserContext $bookParserContext = null;

    protected ?TaskListParserContext $taskListParserContext = null;

    protected ?TaskParserContext $taskParserContext = null;

    /**
     * Текущая позиция в списке прокси.
     *
     * Прокси теперь используются последовательно:
     *
     * 1 -> 2 -> 3 -> 4 -> 1 -> ...
     *
     * Это намного лучше случайного выбора для retry.
     */
    private int $proxyIndex = 0;

    public function __construct(
        GDZParserConfig $config
    ) {
        $this->cli = new CLImate();

        $this->config = $config;

        if ($this->config->bookParser !== null) {
            $this->bookParserContext =
                new BookParserContext(
                    $this->config->bookParser
                );
        }

        if ($this->config->taskListParser !== null) {
            $this->taskListParserContext =
                new TaskListParserContext(
                    $this->config->taskListParser
                );
        }

        if ($this->config->taskParser !== null) {
            $this->taskParserContext =
                new TaskParserContext(
                    $this->config->taskParser
                );
        }
    }

    // ============================================================
    // CONFIG
    // ============================================================

    private function getMode(): string
    {
        $mode = strtolower(
            trim(
                (string)(
                    $this->config->mode
                    ?? 'all'
                )
            )
        );

        return $mode !== ''
            ? $mode
            : 'all';
    }

    private function shouldParseBooks(): bool
    {
        return in_array(
            $this->getMode(),
            [
                'books',
                'tasks',
                'all',
            ],
            true
        );
    }

    private function shouldParseTaskLists(): bool
    {
        return in_array(
            $this->getMode(),
            [
                'tasks',
                'all',
            ],
            true
        );
    }

    private function shouldParseTaskContents(): bool
    {
        return $this->getMode() === 'all';
    }

    // ============================================================
    // PROXY
    // ============================================================

    /**
     * Получить следующий прокси.
     *
     * В отличие от старого getRandomProxy(),
     * один и тот же плохой прокси не может случайно
     * выпасть несколько раз подряд.
     */
    private function getNextProxy(): ?Proxy
    {
        $count =
            count(
                $this->config->proxy
            );

        if ($count === 0) {
            return null;
        }

        $proxy =
            $this->config->proxy[$this->proxyIndex % $count];

        $this->proxyIndex++;

        return $proxy;
    }

    // ============================================================
    // RETRY
    // ============================================================

    /**
     * Подождать перед повторной попыткой.
     *
     * Попытка 1 -> 1 сек.
     * Попытка 2 -> 2 сек.
     * ...
     * Максимум -> 5 сек.
     */
    private function waitBeforeRetry(
        int $attempt
    ): void {
        $delay = min(
            max($attempt, 1),
            5
        );

        if ($this->config->showLogs) {
            $this->cli->output(
                "<dim>Retrying in {$delay} sec...</dim>"
            );
        }

        sleep($delay);
    }

    // ============================================================
    // JSON
    // ============================================================

    /**
     * Безопасно записать JSON.
     *
     * Сначала данные записываются во временный файл,
     * затем временный файл заменяет основной.
     */
    private function saveJson(
        string $filePath,
        mixed $data
    ): void {
        $directory =
            dirname(
                $filePath
            );

        if (!is_dir($directory)) {
            if (
                !mkdir(
                    $directory,
                    0777,
                    true
                )
                && !is_dir($directory)
            ) {
                throw new \RuntimeException(
                    "Failed to create folder: {$directory}"
                );
            }
        }

        $json =
            json_encode(
                $data,
                JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
            );

        $temporaryFile =
            $filePath
            . '.tmp.'
            . getmypid()
            . '.'
            . bin2hex(
                random_bytes(4)
            );

        $saveStatus =
            file_put_contents(
                $temporaryFile,
                $json,
                LOCK_EX
            );

        if ($saveStatus === false) {
            @unlink(
                $temporaryFile
            );

            throw new \RuntimeException(
                "Failed to save temporary file: {$temporaryFile}"
            );
        }

        /*
         * На Linux rename() заменяет существующий файл.
         *
         * На Windows это может не сработать,
         * поэтому предусмотрен fallback.
         */
        if (
            !@rename(
                $temporaryFile,
                $filePath
            )
        ) {
            if (is_file($filePath)) {
                @unlink(
                    $filePath
                );
            }

            if (
                !@rename(
                    $temporaryFile,
                    $filePath
                )
            ) {
                @unlink(
                    $temporaryFile
                );

                throw new \RuntimeException(
                    "Failed to move temporary file to: {$filePath}"
                );
            }
        }
    }

    /**
     * Проверить строгий набор полей JSON без альтернативных схем.
     */
    private function hasExactKeys(
        array $data,
        array $expectedKeys
    ): bool {
        $actualKeys = array_keys($data);

        sort($actualKeys);
        sort($expectedKeys);

        return $actualKeys === $expectedKeys;
    }

    // ============================================================
    // BOOK CACHE
    // ============================================================

    /**
     * Загрузить книги из books.json.
     *
     * Возвращает null, если кеш:
     *
     * - отсутствует;
     * - пустой;
     * - содержит битый JSON;
     * - содержит некорректные записи.
     *
     * В таком случае URL будет запрошен заново.
     *
     * @return BookDTO[]|null
     */
    private function loadStoredBooks(
        string $booksFilePath
    ): ?array {
        if (!is_file($booksFilePath)) {
            return null;
        }

        $content =
            file_get_contents(
                $booksFilePath
            );

        if (
            $content === false
            || trim($content) === ''
        ) {
            return null;
        }

        try {
            $parsedBooks =
                json_decode(
                    $content,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
        } catch (Exception) {
            return null;
        }

        if (
            !is_array($parsedBooks)
            || count($parsedBooks) === 0
        ) {
            /*
             * ВАЖНО:
             *
             * [] больше не считается хорошим кешем.
             *
             * Иначе один временный сбой мог навсегда
             * "закешировать" отсутствие книг.
             */
            return null;
        }

        $books = [];

        try {
            foreach ($parsedBooks as $bookData) {
                if (!is_array($bookData)) {
                    return null;
                }

                if (
                    !$this->hasExactKeys(
                        $bookData,
                        [
                            'title',
                            'author',
                            'grade',
                            'subject',
                            'parse_url',
                            'book_id',
                        ]
                    )
                ) {
                    return null;
                }

                $bookParseUrl = trim(
                    (string)($bookData['parse_url'] ?? '')
                );

                if (
                    empty($bookData['title'])
                    || $bookParseUrl === ''
                ) {
                    return null;
                }

                $books[] =
                    BookDTO::fromArray(
                        $bookData
                    );
            }
        } catch (Exception) {
            return null;
        }

        if (count($books) === 0) {
            return null;
        }

        /* Перезаписываем кеш после нормализации DTO. */
        try {
            $this->saveJson(
                $booksFilePath,
                $books
            );
        } catch (Exception $ex) {
            if ($this->config->showLogs) {
                $this->cli
                    ->red()
                    ->out(
                        "Failed to normalize books cache: "
                            . $ex->getMessage()
                    );
            }
        }

        return $books;
    }

    // ============================================================
    // TASK LIST CACHE
    // ============================================================

    private function isStoredTaskListCompatible(
        array $storedTasks
    ): bool {
        if ($storedTasks === []) {
            return false;
        }

        $expectedOrderByGroup = [];

        foreach ($storedTasks as $storedTask) {
            if (!is_array($storedTask)) {
                return false;
            }

            if (
                !$this->hasExactKeys(
                    $storedTask,
                    [
                        'title',
                        'parse_url',
                        'chapter',
                        'group_id',
                        'order_number_in_group',
                        'book_id',
                    ]
                )
            ) {
                return false;
            }

            $parseUrl = trim(
                (string)($storedTask['parse_url'] ?? '')
            );

            if ($parseUrl === '') {
                return false;
            }

            $groupId = trim(
                (string)(
                    $storedTask['group_id']
                    ?? ''
                )
            );

            if (
                preg_match(
                    '/^razdel_[1-9]\d*$/',
                    $groupId
                ) !== 1
            ) {
                return false;
            }

            $orderNumber =
                $storedTask['order_number_in_group']
                ?? null;

            if (is_int($orderNumber)) {
                $orderNumberAsInt =
                    $orderNumber;
            } elseif (
                is_string($orderNumber)
                && ctype_digit($orderNumber)
            ) {
                $orderNumberAsInt =
                    (int)$orderNumber;
            } else {
                return false;
            }

            $expectedOrderByGroup[$groupId] =
                ($expectedOrderByGroup[$groupId] ?? 0)
                + 1;

            if (
                $orderNumberAsInt
                !== $expectedOrderByGroup[$groupId]
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Последняя страховка после любого парсера списка задач:
     * group_id всегда имеет формат razdel_N, а порядок считается
     * по фактическому порядку задач в полученном массиве.
     *
     * @param TaskListItemDTO[] $tasks
     *
     * @return TaskListItemDTO[]
     */
    private function normalizeParsedTaskListGrouping(
        array $tasks
    ): array {
        $orderByGroup = [];

        foreach ($tasks as $task) {
            if (!$task instanceof TaskListItemDTO) {
                continue;
            }

            $groupId = trim(
                (string)(
                    $task->group_id
                    ?? ''
                )
            );

            if (
                preg_match(
                    '/^razdel_[1-9]\d*$/',
                    $groupId
                ) !== 1
            ) {
                $groupId = 'razdel_1';
            }

            $orderByGroup[$groupId] =
                ($orderByGroup[$groupId] ?? 0)
                + 1;

            $task->group_id =
                $groupId;

            $task->order_number_in_group =
                $orderByGroup[$groupId];
        }

        return $tasks;
    }

    /**
     * Актуализировать book_id сохраненного списка задач.
     */
    private function normalizeStoredTaskList(
        array $storedTasks,
        string $bookId
    ): array {
        foreach (
            $storedTasks
            as &$storedTask
        ) {
            if (!is_array($storedTask)) {
                continue;
            }

            $storedTask['book_id'] =
                $bookId;
        }

        unset($storedTask);

        return $storedTasks;
    }

    // ============================================================
    // STORED FULL TASK
    // ============================================================

    /**
     * Обновить metadata уже сохраненной задачи
     * без повторного скачивания страницы.
     */
    private function normalizeStoredTaskMetadata(
        string $filePath,
        TaskListItemDTO $taskListItem
    ): ?bool {
        if (!is_file($filePath)) {
            return null;
        }

        $json =
            file_get_contents(
                $filePath
            );

        if (
            $json === false
            || trim($json) === ''
        ) {
            return null;
        }

        try {
            $storedTask =
                json_decode(
                    $json,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
        } catch (Exception) {
            return null;
        }

        if (!is_array($storedTask)) {
            return null;
        }

        if (
            !$this->hasExactKeys(
                $storedTask,
                [
                    'title',
                    'parse_url',
                    'content',
                    'images',
                    'group_id',
                    'order_number_in_group',
                    'book_id',
                ]
            )
        ) {
            return null;
        }

        /*
         * Не принимаем за готовый кеш обрезанный/частично записанный JSON.
         * Для image task пустые title/content допустимы, но сами ключи и
         * массив images должны присутствовать.
         */
        foreach (
            [
                'title',
                'parse_url',
                'content',
                'images',
            ]
            as $requiredKey
        ) {
            if (!array_key_exists($requiredKey, $storedTask)) {
                return null;
            }
        }

        if (!is_array($storedTask['images'])) {
            return null;
        }

        $parseUrl = trim(
            (string)$taskListItem->parse_url
        );

        if ($parseUrl === '') {
            return null;
        }

        $storedParseUrl = trim(
            (string)$storedTask['parse_url']
        );

        if ($storedParseUrl === '') {
            return null;
        }

        $currentOrder =
            $storedTask['order_number_in_group']
            ?? null;

        if ($currentOrder !== null) {
            $currentOrder =
                (int)$currentOrder;
        }

        $alreadyCorrect =
            ($storedTask['book_id'] ?? null)
            === $taskListItem->book_id

            && ($storedTask['group_id'] ?? null)
            === $taskListItem->group_id

            && $currentOrder
            === $taskListItem->order_number_in_group

            && array_key_exists(
                'order_number_in_group',
                $storedTask
            )

            && $storedParseUrl
            === $parseUrl;

        if ($alreadyCorrect) {
            return false;
        }

        $storedTask['book_id'] =
            $taskListItem->book_id;

        $storedTask['group_id'] =
            $taskListItem->group_id;

        $storedTask['order_number_in_group'] =
            $taskListItem->order_number_in_group;

        $storedTask['parse_url'] =
            $parseUrl;

        try {
            $this->saveJson(
                $filePath,
                $storedTask
            );
        } catch (Exception) {
            return null;
        }

        return true;
    }

    // ============================================================
    // RUN
    // ============================================================

    public function run(): void
    {
        $mode =
            $this->getMode();

        $startURLsCount =
            count(
                $this->config->startURLs
            );

        // ========================================================
        // START INFO
        // ========================================================

        $this->cli->clear();

        $this->cli->output(
            "<green><bold>Start parsing...</bold></green>"
        );

        $this->cli->output(
            "<bold>URLs count:</bold>\t {$startURLsCount}"
        );

        $this->cli->output(
            "<bold>Mode:</bold>\t {$mode}"
        );

        $this->cli->output(
            "<bold>Attempts:</bold>\t {$this->config->attempts}"
        );

        $this->cli->output(
            "<bold>Timeout:</bold>\t {$this->config->timeout}"
        );

        $this->cli->output(
            "<bold>Parse images:</bold>\t"
                . (
                    ($this->config->parseImages ?? true)
                    ? "<green>Yes</green>"
                    : "<red>No</red>"
                )
        );

        $this->cli->output(
            "<bold>Show logs:</bold>\t"
                . (
                    $this->config->showLogs
                    ? "<green>Yes</green>"
                    : "<red>No</red>"
                )
        );

        $this->cli->output(
            "<bold>Output folder:</bold>\t "
                . $this->config->parseOutputFolder
        )->br();

        if ($this->config->proxy) {
            $this->cli->output(
                "<bold>Proxies:</bold>"
            );

            $this->cli
                ->table(
                    $this->config->proxy
                )
                ->br();
        }

        // ========================================================
        // VALIDATION
        // ========================================================

        if (
            !in_array(
                $mode,
                [
                    'subjects',
                    'books',
                    'tasks',
                    'all',
                ],
                true
            )
        ) {
            $this->cli->error(
                "Unsupported mode '{$mode}'. "
                    . "Available modes: subjects, books, tasks, all."
            );

            return;
        }

        if (
            $this->shouldParseBooks()
            && $this->bookParserContext === null
        ) {
            $this->cli->error(
                "Book parser must be specified!"
            );

            return;
        }

        if (
            $this->shouldParseTaskLists()
            && $this->taskListParserContext === null
        ) {
            $this->cli->error(
                "Task list parser must be specified!"
            );

            return;
        }

        if (
            $this->shouldParseTaskContents()
            && $this->taskParserContext === null
        ) {
            $this->cli->error(
                "Task parser must be specified!"
            );

            return;
        }

        if ($startURLsCount === 0) {
            $this->cli->error(
                "Start URLs must not be empty!"
            );

            return;
        }

        if (
            empty($this->config->parseOutputFolder)
        ) {
            $this->cli->error(
                "Output folder must be specified!"
            );

            return;
        }

        if ($this->config->attempts < 1) {
            $this->cli->error(
                "Attempts must be greater than or equal to 1!"
            );

            return;
        }

        if ($this->config->timeout < 1) {
            $this->cli->error(
                "Timeout must be greater than or equal to 1!"
            );

            return;
        }

        // ========================================================
        // OUTPUT FOLDER
        // ========================================================

        if (
            !is_dir(
                $this->config->parseOutputFolder
            )
        ) {
            if (
                !mkdir(
                    $this->config->parseOutputFolder,
                    0777,
                    true
                )
                && !is_dir(
                    $this->config->parseOutputFolder
                )
            ) {
                $this->cli->error(
                    "Failed to create folder: "
                        . $this->config->parseOutputFolder
                );

                return;
            }
        }

        // ========================================================
        // SUBJECTS
        // ========================================================

        if ($mode === 'subjects') {
            $this->cli->br();

            $this->cli->output(
                "<bold><green>"
                    . "Finished parsing at subjects level."
                    . "</green></bold>"
            );

            $this->cli->br();

            return;
        }

        // ========================================================
        // BOOKS
        // ========================================================

        $totalBooksCount = 0;

        $successStartURLsCount = 0;

        $skippedStartURLsCount = 0;

        $failedStartURLsCount = 0;

        $startURLsProgress = 0;

        $booksList = [];

        $failedStartURLs = [];

        if (!$this->config->showLogs) {
            $startURLsProgressBar =
                $this->cli
                ->progress()
                ->total(
                    $startURLsCount
                );

            $startURLsProgressBar->current(
                0,
                "<bold>[0 / {$startURLsCount}]</bold> "
                    . "Parsing books"
            );
        }

        foreach (
            $this->config->startURLs
            as $startURL
        ) {
            $currentStartURLProgress =
                $startURLsProgress + 1;

            $progressPercent =
                $startURLsCount > 0
                ? round(
                    $currentStartURLProgress
                        / $startURLsCount
                        * 100
                )
                : 0;

            $outputStartURLFolderName =
                Text::generateNamefromURL(
                    $startURL
                );

            $outputStartURLFolderPath =
                $this->config->parseOutputFolder
                . DIRECTORY_SEPARATOR
                . $outputStartURLFolderName;

            $booksFilePath =
                $outputStartURLFolderPath
                . DIRECTORY_SEPARATOR
                . 'books.json';

            $startURLParsedSuccessfully =
                false;

            // ====================================================
            // CACHE
            // ====================================================

            $storedBooks =
                $this->loadStoredBooks(
                    $booksFilePath
                );

            if ($storedBooks !== null) {
                $storedBooksCount =
                    count(
                        $storedBooks
                    );

                foreach (
                    $storedBooks
                    as $book
                ) {
                    $booksList[] = [
                        'outputPath' =>
                        $outputStartURLFolderPath,

                        'book' =>
                        $book,
                    ];
                }

                $totalBooksCount +=
                    $storedBooksCount;

                $skippedStartURLsCount++;

                if ($this->config->showLogs) {
                    $this->cli->output(
                        "<dim>[{$currentStartURLProgress} / {$startURLsCount}]</dim> "
                            . "<dim>[{$progressPercent}%]</dim> "
                            . "Loaded "
                            . "<green>{$storedBooksCount}</green> "
                            . "books from cache for "
                            . "<yellow>{$startURL}</yellow>"
                    );
                }
            }

            // ====================================================
            // HTTP PARSE
            // ====================================================

            else {
                $lastExceptionMessage =
                    null;

                for (
                    $attempt = 1;
                    $attempt <= $this->config->attempts;
                    $attempt++
                ) {
                    try {
                        if ($this->config->showLogs) {
                            $this->cli->output(
                                "<dim>[{$currentStartURLProgress} / {$startURLsCount}]</dim> "
                                    . "<dim>[{$progressPercent}%]</dim> "
                                    . "Parsing books from "
                                    . "<yellow>{$startURL}</yellow> "
                                    . "(attempt {$attempt} "
                                    . "of {$this->config->attempts})"
                            );
                        }

                        /*
                         * Каждый retry получает следующий прокси.
                         */
                        $proxy =
                            $this->getNextProxy();

                        $books =
                            $this->bookParserContext
                            ->parse(
                                $startURL,
                                $proxy,
                                $this->config->timeout
                            );

                        $booksCount =
                            count(
                                $books
                            );

                        /*
                         * Пустой массив не считаем успехом.
                         *
                         * Это одна из важных правок.
                         */
                        if ($booksCount === 0) {
                            throw new ParseException(
                                "No books found on {$startURL}."
                            );
                        }

                        foreach ($books as $book) {
                            if (!$book instanceof BookDTO) {
                                throw new ParseException(
                                    "Book parser returned an invalid item for {$startURL}."
                                );
                            }

                            if (trim($book->parse_url) === '') {
                                throw new ParseException(
                                    "Book parser returned an item without parse_url for {$startURL}."
                                );
                            }
                        }

                        /*
                         * Сначала успешно сохраняем файл.
                         *
                         * Только после этого считаем URL
                         * полностью обработанным.
                         */
                        $this->saveJson(
                            $booksFilePath,
                            $books
                        );

                        foreach (
                            $books
                            as $book
                        ) {
                            $booksList[] = [
                                'outputPath' =>
                                $outputStartURLFolderPath,

                                'book' =>
                                $book,
                            ];
                        }

                        $totalBooksCount +=
                            $booksCount;

                        $startURLParsedSuccessfully =
                            true;

                        $successStartURLsCount++;

                        if ($this->config->showLogs) {
                            $this->cli->output(
                                "<bold><green>"
                                    . "Found {$booksCount} books."
                                    . "</green></bold>"
                            );
                        }

                        unset(
                            $books,
                            $proxy
                        );

                        break;
                    } catch (Exception $ex) {
                        $lastExceptionMessage =
                            $ex->getMessage();

                        if ($this->config->showLogs) {
                            $this->cli
                                ->red()
                                ->out(
                                    "Attempt {$attempt} failed: "
                                        . $lastExceptionMessage
                                );
                        }

                        if (
                            $attempt
                            < $this->config->attempts
                        ) {
                            $this->waitBeforeRetry(
                                $attempt
                            );
                        }
                    }
                }

                // =================================================
                // FAILED COMPLETELY
                // =================================================

                if (!$startURLParsedSuccessfully) {
                    $failedStartURLsCount++;

                    $failedStartURLs[] = [
                        'parse_url' =>
                        $startURL,

                        'error' =>
                        $lastExceptionMessage
                            ?? 'Unknown error',
                    ];

                    /*
                     * Ошибку показываем даже без --logs.
                     *
                     * Иначе невозможно понять,
                     * почему часть классов отсутствует.
                     */
                    $this->cli->error(
                        "FAILED: {$startURL}"
                            . (
                                $lastExceptionMessage !== null
                                ? " — {$lastExceptionMessage}"
                                : ''
                            )
                    );
                }
            }

            unset(
                $storedBooks
            );

            $startURLsProgress++;

            if (!$this->config->showLogs) {
                $startURLsProgressBar->current(
                    $startURLsProgress,
                    "<bold>[{$startURLsProgress} / {$startURLsCount}]</bold> "
                        . "Parsing books"
                );
            }

            unset(
                $outputStartURLFolderName,
                $outputStartURLFolderPath,
                $booksFilePath
            );
        }

        // ========================================================
        // BOOKS SUMMARY
        // ========================================================

        $this->cli->br();

        $this->cli->output(
            "<bold>Books parsing summary:</bold>"
        );

        $this->cli->output(
            "Total start URLs: {$startURLsCount}"
        );

        $this->cli->output(
            "<green>Successfully fetched: "
                . "{$successStartURLsCount}</green>"
        );

        $this->cli->output(
            "<yellow>Loaded from cache: "
                . "{$skippedStartURLsCount}</yellow>"
        );

        $this->cli->output(
            "<cyan>Total books: "
                . "{$totalBooksCount}</cyan>"
        );

        if ($failedStartURLsCount > 0) {
            $this->cli->output(
                "<red>Failed start URLs: "
                    . "{$failedStartURLsCount}</red>"
            );

            foreach (
                $failedStartURLs
                as $failed
            ) {
                $this->cli->output(
                    "<red>- {$failed['parse_url']}</red>"
                );

                $this->cli->output(
                    "  <dim>{$failed['error']}</dim>"
                );
            }
        } else {
            $this->cli->output(
                "<green>Failed start URLs: 0</green>"
            );
        }

        $this->cli->br();

        // ========================================================
        // BOOK MODE DONE
        // ========================================================

        if ($mode === 'books') {
            $this->cli->output(
                "<bold><green>"
                    . "Finished parsing at books level."
                    . "</green></bold>"
            );

            $this->cli->br();

            return;
        }

        // ========================================================
        // TASK LISTS
        // ========================================================

        $tasksItemsList = [];

        $taskListProgress = 0;

        $totalTasksCount = 0;

        $successTasksListCount = 0;

        $skippedTasksListCount = 0;

        $failedTaskListsCount = 0;

        if (
            !$this->config->showLogs
            && $totalBooksCount > 0
        ) {
            $taskListProgressBar =
                $this->cli
                ->progress()
                ->total(
                    $totalBooksCount
                );

            $taskListProgressBar->current(
                0,
                "<bold>[0 / {$totalBooksCount}]</bold> "
                    . "Parsing task lists"
            );
        }

        foreach (
            $booksList
            as $bookItem
        ) {
            /** @var BookDTO $book */
            $book =
                $bookItem['book'];

            $currentTaskListProgress =
                $taskListProgress + 1;

            $bookFolderPath =
                $bookItem['outputPath']
                . DIRECTORY_SEPARATOR
                . 'books'
                . DIRECTORY_SEPARATOR
                . $book->book_id;

            $taskListFilePath =
                $bookFolderPath
                . DIRECTORY_SEPARATOR
                . 'taskList.json';

            $taskListParsedSuccessfully =
                false;

            $storedTasks =
                null;

            $useStoredTaskList =
                false;

            // ====================================================
            // CACHE
            // ====================================================

            if (is_file($taskListFilePath)) {
                try {
                    $content =
                        file_get_contents(
                            $taskListFilePath
                        );

                    if (
                        $content !== false
                        && trim($content) !== ''
                    ) {
                        $storedTasks =
                            json_decode(
                                $content,
                                true,
                                512,
                                JSON_THROW_ON_ERROR
                            );
                    }
                } catch (Exception) {
                    $storedTasks =
                        null;
                }

                if (
                    is_array($storedTasks)
                    && count($storedTasks) > 0
                    && $this
                    ->isStoredTaskListCompatible(
                        $storedTasks
                    )
                ) {
                    $storedTasks =
                        $this->normalizeStoredTaskList(
                            $storedTasks,
                            $book->book_id
                        );

                    try {
                        $this->saveJson(
                            $taskListFilePath,
                            $storedTasks
                        );
                    } catch (Exception $ex) {
                        if ($this->config->showLogs) {
                            $this->cli
                                ->red()
                                ->out(
                                    $ex->getMessage()
                                );
                        }
                    }

                    $useStoredTaskList =
                        true;
                } elseif (
                    $this->config->showLogs
                ) {
                    $this->cli
                        ->yellow()
                        ->out(
                            "Stored task list has invalid or missing "
                                . "grouping and will be reparsed: "
                                . $taskListFilePath
                        );
                }
            }

            if ($useStoredTaskList) {
                foreach (
                    $storedTasks
                    as $taskItem
                ) {
                    $taskDTO =
                        TaskListItemDTO::fromArray(
                            $taskItem
                        );

                    $taskDTO->book_id =
                        $book->book_id;

                    $tasksItemsList[] = [
                        'outputPath' =>
                        $bookFolderPath,

                        'tasksList' =>
                        $taskDTO,
                    ];
                }

                $totalTasksCount +=
                    count(
                        $storedTasks
                    );

                $skippedTasksListCount++;
            }

            // ====================================================
            // PARSE TASK LIST
            // ====================================================

            else {
                $lastExceptionMessage =
                    null;

                for (
                    $attempt = 1;
                    $attempt <= $this->config->attempts;
                    $attempt++
                ) {
                    try {
                        if ($this->config->showLogs) {
                            $this->cli->output(
                                "Parsing task list for "
                                    . "<yellow>{$book->title}</yellow> "
                                    . "(attempt {$attempt} "
                                    . "of {$this->config->attempts})"
                            );
                        }

                        $tasksItems =
                            $this->taskListParserContext
                            ->parse(
                                $book->parse_url,
                                $this->getNextProxy(),
                                $this->config->timeout
                            );

                        $tasksItems =
                            $this
                            ->normalizeParsedTaskListGrouping(
                                $tasksItems
                            );

                        $tasksItemsCount =
                            count(
                                $tasksItems
                            );

                        if ($tasksItemsCount === 0) {
                            throw new ParseException(
                                "No tasks found for book {$book->parse_url}."
                            );
                        }

                        /*
                         * Здесь устанавливаем связь:
                         *
                         * task -> book
                         */
                        foreach (
                            $tasksItems
                            as $task
                        ) {
                            $task->book_id =
                                $book->book_id;
                        }

                        /*
                         * Сначала сохраняем taskList.
                         */
                        $this->saveJson(
                            $taskListFilePath,
                            $tasksItems
                        );

                        /*
                         * Затем добавляем задачи в общий массив.
                         */
                        foreach (
                            $tasksItems
                            as $task
                        ) {
                            $tasksItemsList[] = [
                                'outputPath' =>
                                $bookFolderPath,

                                'tasksList' =>
                                $task,
                            ];
                        }

                        $totalTasksCount +=
                            $tasksItemsCount;

                        $successTasksListCount++;

                        $taskListParsedSuccessfully =
                            true;

                        unset($tasksItems);

                        break;
                    } catch (Exception $ex) {
                        $lastExceptionMessage =
                            $ex->getMessage();

                        if ($this->config->showLogs) {
                            $this->cli
                                ->red()
                                ->out(
                                    "Attempt {$attempt} failed: "
                                        . $lastExceptionMessage
                                );
                        }

                        if (
                            $attempt
                            < $this->config->attempts
                        ) {
                            $this->waitBeforeRetry(
                                $attempt
                            );
                        }
                    }
                }

                if (!$taskListParsedSuccessfully) {
                    $failedTaskListsCount++;

                    $this->cli->error(
                        "FAILED task list: {$book->title}"
                            . (
                                $lastExceptionMessage !== null
                                ? " — {$lastExceptionMessage}"
                                : ''
                            )
                    );
                }
            }

            $taskListProgress++;

            if (
                !$this->config->showLogs
                && isset(
                    $taskListProgressBar
                )
            ) {
                $taskListProgressBar->current(
                    $taskListProgress,
                    "<bold>[{$taskListProgress} / {$totalBooksCount}]</bold> "
                        . "Parsing task lists"
                );
            }

            unset(
                $storedTasks,
                $book,
                $bookFolderPath,
                $taskListFilePath
            );
        }

        // ========================================================
        // TASK LIST SUMMARY
        // ========================================================

        $this->cli->br();

        $this->cli->output(
            "<bold>Task lists summary:</bold>"
        );

        $this->cli->output(
            "<cyan>Total tasks: {$totalTasksCount}</cyan>"
        );

        $this->cli->output(
            "<green>Successfully parsed task lists: "
                . "{$successTasksListCount}</green>"
        );

        $this->cli->output(
            "<yellow>Loaded task lists from cache: "
                . "{$skippedTasksListCount}</yellow>"
        );

        if ($failedTaskListsCount > 0) {
            $this->cli->output(
                "<red>Failed task lists: "
                    . "{$failedTaskListsCount}</red>"
            );
        }

        $this->cli->br();

        // ========================================================
        // TASKS MODE DONE
        // ========================================================

        if ($mode === 'tasks') {
            $this->cli->output(
                "<bold><green>"
                    . "Finished parsing at tasks level."
                    . "</green></bold>"
            );

            $this->cli->br();

            return;
        }

        // ========================================================
        // FULL TASK CONTENT
        // ========================================================

        if ($totalTasksCount === 0) {
            $this->cli->error(
                'No tasks found!'
            );

            return;
        }

        $successParsedTasksCount = 0;

        $skippedParsedTasksCount = 0;

        $failedParsedTasksCount = 0;

        $updatedTaskMetadataCount = 0;

        $tasksProgress = 0;

        if (!$this->config->showLogs) {
            $tasksProgressBar =
                $this->cli
                ->progress()
                ->total(
                    $totalTasksCount
                );

            $tasksProgressBar->current(
                0,
                "<bold>[0 / {$totalTasksCount}]</bold> "
                    . "Parsing tasks"
            );
        }

        foreach (
            $tasksItemsList
            as $tasksItemsListItem
        ) {
            /** @var TaskListItemDTO $taskListItem */
            $taskListItem =
                $tasksItemsListItem['tasksList'];

            $outputTaskFileName =
                '';

            if (
                is_string(
                    $taskListItem->chapter
                )
            ) {
                $outputTaskFileName .=
                    Text::translitRef(
                        $taskListItem->chapter
                    );
            }

            if (
                is_string(
                    $taskListItem->parse_url
                )
            ) {
                $outputTaskFileName .=
                    '_'
                    . Text::generateNamefromURL(
                        $taskListItem->parse_url
                    );
            }

            if (
                is_string(
                    $taskListItem->title
                )
            ) {
                $outputTaskFileName .=
                    '_'
                    . Text::translitRef(
                        $taskListItem->title
                    );
            }

            $outputTaskFileName =
                trim(
                    $outputTaskFileName,
                    '_'
                )
                . '.json';

            $outputTaskFileName =
                Text::makeSafeJSONFileName(
                    $outputTaskFileName
                );

            $outputTaskFullFileName =
                $tasksItemsListItem['outputPath']
                . DIRECTORY_SEPARATOR
                . $outputTaskFileName;

            // ====================================================
            // ALREADY EXISTS
            // ====================================================

            $storedTaskStatus =
                $this->normalizeStoredTaskMetadata(
                    $outputTaskFullFileName,
                    $taskListItem
                );

            if ($storedTaskStatus !== null) {
                if ($storedTaskStatus) {
                    $updatedTaskMetadataCount++;
                }

                $skippedParsedTasksCount++;
            }

            // ====================================================
            // PARSE TASK
            // ====================================================

            else {
                $taskParsedSuccessfully =
                    false;

                $lastExceptionMessage =
                    null;

                for (
                    $attempt = 1;
                    $attempt <= $this->config->attempts;
                    $attempt++
                ) {
                    try {
                        if ($this->config->showLogs) {
                            $this->cli->output(
                                "Parsing task "
                                    . "<yellow>{$taskListItem->parse_url}</yellow> "
                                    . "(attempt {$attempt} "
                                    . "of {$this->config->attempts})"
                            );
                        }

                        $taskInfo =
                            $this->taskParserContext
                            ->parse(
                                $taskListItem->parse_url,
                                $this->getNextProxy(),
                                $this->config->timeout
                            );

                        $taskInfo->book_id =
                            $taskListItem->book_id;

                        $taskInfo->group_id =
                            $taskListItem->group_id;

                        $taskInfo->order_number_in_group =
                            $taskListItem
                            ->order_number_in_group;

                        /*
                         * Для полной задачи источником является её собственная
                         * страница. Для image task это та же исходная ссылка.
                         */
                        $taskInfo->parse_url =
                            $taskListItem->parse_url;

                        $this->saveJson(
                            $outputTaskFullFileName,
                            $taskInfo
                        );

                        $successParsedTasksCount++;

                        $taskParsedSuccessfully =
                            true;

                        unset($taskInfo);

                        break;
                    } catch (Exception $ex) {
                        $lastExceptionMessage =
                            $ex->getMessage();

                        if ($this->config->showLogs) {
                            $this->cli
                                ->red()
                                ->out(
                                    "Attempt {$attempt} failed: "
                                        . $lastExceptionMessage
                                );
                        }

                        if (
                            $attempt
                            < $this->config->attempts
                        ) {
                            $this->waitBeforeRetry(
                                $attempt
                            );
                        }
                    }
                }

                if (!$taskParsedSuccessfully) {
                    $failedParsedTasksCount++;

                    if ($this->config->showLogs) {
                        $this->cli->error(
                            "FAILED task: "
                                . $taskListItem->parse_url
                                . (
                                    $lastExceptionMessage !== null
                                    ? " — {$lastExceptionMessage}"
                                    : ''
                                )
                        );
                    }
                }
            }

            $tasksProgress++;

            if (!$this->config->showLogs) {
                $tasksProgressBar->current(
                    $tasksProgress,
                    "<bold>[{$tasksProgress} / {$totalTasksCount}]</bold> "
                        . "Parsing tasks"
                );
            }

            unset(
                $taskListItem,
                $outputTaskFileName,
                $outputTaskFullFileName,
                $storedTaskStatus
            );
        }

        // ========================================================
        // FINAL SUMMARY
        // ========================================================

        $this->cli->br();

        $this->cli->output(
            '<bold><green>Finished parsing tasks.</green></bold>'
        );

        $this->cli->output(
            "<green>Success parsed tasks: "
                . "{$successParsedTasksCount}</green>"
        );

        $this->cli->output(
            "<yellow>Skipped parsed tasks: "
                . "{$skippedParsedTasksCount}</yellow>"
        );

        if ($updatedTaskMetadataCount > 0) {
            $this->cli->output(
                "<cyan>Updated task metadata: "
                    . "{$updatedTaskMetadataCount}</cyan>"
            );
        }

        if ($failedParsedTasksCount > 0) {
            $this->cli->output(
                "<red>Failed parsed tasks: "
                    . "{$failedParsedTasksCount}</red>"
            );
        }

        $this->cli->br();
    }
}
