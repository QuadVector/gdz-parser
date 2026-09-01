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
                    empty($bookData['title'])
                    || empty($bookData['url'])
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

        /*
         * Заодно перезаписываем старый books.json
         * в новом формате с book_id.
         */
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
        foreach ($storedTasks as $storedTask) {
            if (!is_array($storedTask)) {
                return false;
            }

            if (
                !array_key_exists(
                    'group_id',
                    $storedTask
                )
            ) {
                return false;
            }

            if (
                !array_key_exists(
                    'order_number_in_group',
                    $storedTask
                )
                && !array_key_exists(
                    'group_order_number',
                    $storedTask
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Дописать book_id и привести старое
     * group_order_number к order_number_in_group.
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

            if (
                !array_key_exists(
                    'order_number_in_group',
                    $storedTask
                )
                && array_key_exists(
                    'group_order_number',
                    $storedTask
                )
            ) {
                $storedTask['order_number_in_group'] =
                    $storedTask['group_order_number'];

                unset(
                    $storedTask['group_order_number']
                );
            }
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
    private function updateStoredTaskMetadata(
        string $filePath,
        TaskListItemDTO $taskListItem
    ): bool {
        if (!is_file($filePath)) {
            return false;
        }

        $json =
            file_get_contents(
                $filePath
            );

        if (
            $json === false
            || trim($json) === ''
        ) {
            return false;
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
            return false;
        }

        if (!is_array($storedTask)) {
            return false;
        }

        $currentOrder =
            $storedTask['order_number_in_group']
            ?? $storedTask['group_order_number']
            ?? null;

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
            );

        if ($alreadyCorrect) {
            return false;
        }

        $storedTask['book_id'] =
            $taskListItem->book_id;

        $storedTask['group_id'] =
            $taskListItem->group_id;

        $storedTask['order_number_in_group'] =
            $taskListItem->order_number_in_group;

        unset(
            $storedTask['group_order_number']
        );

        try {
            $this->saveJson(
                $filePath,
                $storedTask
            );
        } catch (Exception) {
            return false;
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
                        'url' =>
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
                    "<red>- {$failed['url']}</red>"
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
                                $book->url,
                                $this->getNextProxy(),
                                $this->config->timeout
                            );

                        $tasksItemsCount =
                            count(
                                $tasksItems
                            );

                        if ($tasksItemsCount === 0) {
                            throw new ParseException(
                                "No tasks found for book {$book->url}."
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
                    $taskListItem->url
                )
            ) {
                $outputTaskFileName .=
                    '_'
                    . Text::generateNamefromURL(
                        $taskListItem->url
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

            if (is_file($outputTaskFullFileName)) {
                if (
                    $this
                    ->updateStoredTaskMetadata(
                        $outputTaskFullFileName,
                        $taskListItem
                    )
                ) {
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
                                    . "<yellow>{$taskListItem->url}</yellow> "
                                    . "(attempt {$attempt} "
                                    . "of {$this->config->attempts})"
                            );
                        }

                        $taskInfo =
                            $this->taskParserContext
                            ->parse(
                                $taskListItem->url,
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
                                . $taskListItem->url
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
                $outputTaskFullFileName
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
