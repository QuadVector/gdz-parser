<?php

namespace QuadVector\GDZParser;

use Exception;
use League\CLImate\CLImate;
use QuadVector\GDZParser\BookParser\BookParserContext;
use QuadVector\GDZParser\DTO\BookDTO;
use QuadVector\GDZParser\DTO\TaskListItemDTO;
use QuadVector\GDZParser\Exception\AccessDeniedException;
use QuadVector\GDZParser\Exception\PageNotFoundException;
use QuadVector\GDZParser\Exception\ParseException;
use QuadVector\GDZParser\TaskListParser\TaskListParserContext;
use QuadVector\GDZParser\TaskParser\TaskParserContext;
use QuadVector\GDZParser\ValueObject\Proxy;
use QuadVector\GDZParser\Helper\Text;

class GDZParser
{
    protected CLImate $cli;

    protected GDZParserConfig $config;

    protected ?BookParserContext $bookParserContext = null;

    protected ?TaskListParserContext $taskListParserContext = null;

    protected ?TaskParserContext $taskParserContext = null;

    public function __construct(
        GDZParserConfig $config
    ) {
        $this->cli = new CLImate();
        $this->config = $config;

        if (!is_null($this->config->bookParser)) {
            $this->bookParserContext =
                new BookParserContext(
                    $this->config->bookParser
                );
        }

        if (!is_null($this->config->taskListParser)) {
            $this->taskListParserContext =
                new TaskListParserContext(
                    $this->config->taskListParser
                );
        }

        if (!is_null($this->config->taskParser)) {
            $this->taskParserContext =
                new TaskParserContext(
                    $this->config->taskParser
                );
        }
    }

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

    private function getRandomProxy(): ?Proxy
    {
        if (
            count(
                $this->config->proxy
            ) === 0
        ) {
            return null;
        }

        return $this->config->proxy[array_rand(
                $this->config->proxy
            )];
    }

    /**
     * Проверить, содержит ли старый taskList
     * данные о группах.
     *
     * book_id при этом можно восстановить без перепарсинга,
     * потому что текущая книга нам уже известна.
     */
    private function isStoredTaskListCompatible(
        array $storedTasks
    ): bool {
        foreach ($storedTasks as $storedTask) {
            if (!is_array($storedTask)) {
                return false;
            }

            if (
                !array_key_exists(
                    'group_name',
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
     * Дописать связь с книгой в сохраненный taskList.
     *
     * Также преобразует старое group_order_number
     * в order_number_in_group.
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

    /**
     * Обновить связь и группу в уже существующем JSON задачи,
     * не загружая страницу задачи повторно.
     */
    private function updateStoredTaskMetadata(
        string $filePath,
        TaskListItemDTO $taskListItem
    ): bool {
        if (!is_file($filePath)) {
            return false;
        }

        $json = file_get_contents(
            $filePath
        );

        if ($json === false) {
            return false;
        }

        $storedTask = json_decode(
            $json,
            true
        );

        unset($json);

        if (!is_array($storedTask)) {
            return false;
        }

        $expectedBookId =
            $taskListItem->book_id;

        $expectedGroupName =
            $taskListItem->group_name;

        $expectedOrderNumber =
            $taskListItem
            ->order_number_in_group;

        $currentOrderNumber =
            $storedTask['order_number_in_group']
            ?? $storedTask['group_order_number']
            ?? null;

        $alreadyCorrect =
            ($storedTask['book_id'] ?? null)
            === $expectedBookId

            && ($storedTask['group_name'] ?? null)
            === $expectedGroupName

            && $currentOrderNumber
            === $expectedOrderNumber

            && array_key_exists(
                'order_number_in_group',
                $storedTask
            );

        if ($alreadyCorrect) {
            unset($storedTask);

            return false;
        }

        $storedTask['book_id'] =
            $expectedBookId;

        $storedTask['group_name'] =
            $expectedGroupName;

        $storedTask['order_number_in_group'] =
            $expectedOrderNumber;

        /*
         * Старое поле больше не нужно.
         */
        unset(
            $storedTask['group_order_number']
        );

        $saveStatus = file_put_contents(
            $filePath,
            json_encode(
                $storedTask,
                JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
            )
        );

        unset($storedTask);

        return $saveStatus !== false;
    }

    public function run(): void
    {
        $mode = $this->getMode();

        $startURLsCount =
            count(
                $this->config->startURLs
            );

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
            "<bold>Output folder:</bold>\t {$this->config->parseOutputFolder}"
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

        // ============================================================
        // Проверки
        // ============================================================

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
            && is_null(
                $this->bookParserContext
            )
        ) {
            $this->cli->error(
                "Book parser must be specified!"
            );

            return;
        }

        if (
            $this->shouldParseTaskLists()
            && is_null(
                $this->taskListParserContext
            )
        ) {
            $this->cli->error(
                "Task list parser must be specified!"
            );

            return;
        }

        if (
            $this->shouldParseTaskContents()
            && is_null(
                $this->taskParserContext
            )
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
            empty($this->config
                ->parseOutputFolder)
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

        // ============================================================
        // OUTPUT
        // ============================================================

        if (
            !is_dir(
                $this->config
                    ->parseOutputFolder
            )
        ) {
            if (
                !mkdir(
                    $this->config->parseOutputFolder,
                    0777,
                    true
                )
                && !is_dir(
                    $this->config
                        ->parseOutputFolder
                )
            ) {
                $this->cli->error(
                    "Failed to create folder: "
                        . $this->config
                        ->parseOutputFolder
                );

                return;
            }
        }

        // ============================================================
        // SUBJECTS
        // ============================================================

        if ($mode === 'subjects') {
            $this->cli->br();

            $this->cli->output(
                "<bold><green>Finished parsing at subjects level.</green></bold>"
            );

            $this->cli->br();

            return;
        }

        // ============================================================
        // BOOKS
        // ============================================================

        $totalBooksCount = 0;
        $successStartURLsCount = 0;
        $skippedStartURLsCount = 0;
        $startURLsProgress = 0;

        $booksList = [];

        if (!$this->config->showLogs) {
            $startURLsProgressBar =
                $this->cli
                ->progress()
                ->total(
                    $startURLsCount
                );

            $startURLsProgressBar->current(
                0,
                "<bold>[0 / {$startURLsCount}]</bold> Parsing books"
            );
        }

        foreach (
            $this->config->startURLs
            as $startURL
        ) {
            $currentStartURLProgress =
                $startURLsProgress + 1;

            $outputStartURLFolderName =
                Text::generateNamefromURL(
                    $startURL
                );

            $outputStartURLFolderPath =
                $this->config
                ->parseOutputFolder
                . DIRECTORY_SEPARATOR
                . $outputStartURLFolderName;

            $booksFilePath =
                $outputStartURLFolderPath
                . DIRECTORY_SEPARATOR
                . 'books.json';

            $startURLParsedSuccessfully =
                false;

            /*
             * Используем кеш только при наличии books.json,
             * а не просто существующей папки.
             */
            if (
                is_file(
                    $booksFilePath
                )
            ) {
                $parsedBooks =
                    json_decode(
                        file_get_contents(
                            $booksFilePath
                        ),
                        true
                    );

                if (is_array($parsedBooks)) {
                    foreach (
                        $parsedBooks
                        as $book
                    ) {
                        if (!is_array($book)) {
                            continue;
                        }

                        try {
                            $bookDTO =
                                BookDTO::fromArray(
                                    $book
                                );
                        } catch (Exception) {
                            continue;
                        }

                        $booksList[] = [
                            'outputPath' =>
                            $outputStartURLFolderPath,

                            'book' =>
                            $bookDTO,
                        ];

                        $totalBooksCount++;
                    }

                    /*
                     * Если books.json был старым и без book_id,
                     * сохраняем его уже в новом формате.
                     */
                    $normalizedBooks = array_map(
                        static fn(array $item) =>
                        BookDTO::fromArray($item),
                        array_filter(
                            $parsedBooks,
                            'is_array'
                        )
                    );

                    file_put_contents(
                        $booksFilePath,
                        json_encode(
                            array_values(
                                $normalizedBooks
                            ),
                            JSON_UNESCAPED_UNICODE
                                | JSON_UNESCAPED_SLASHES
                        )
                    );

                    unset(
                        $normalizedBooks
                    );
                }

                $skippedStartURLsCount++;

                unset($parsedBooks);
            } else {
                for (
                    $attempt = 1;
                    $attempt <= $this->config->attempts;
                    $attempt++
                ) {
                    try {
                        $books =
                            $this->bookParserContext
                            ->parse(
                                $startURL,
                                $this->getRandomProxy(),
                                $this->config->timeout
                            );

                        $booksCount =
                            count($books);

                        $totalBooksCount +=
                            $booksCount;

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

                        if ($booksCount > 0) {
                            if (
                                !is_dir(
                                    $outputStartURLFolderPath
                                )
                            ) {
                                if (
                                    !mkdir(
                                        $outputStartURLFolderPath,
                                        0777,
                                        true
                                    )
                                    && !is_dir(
                                        $outputStartURLFolderPath
                                    )
                                ) {
                                    $this->cli->error(
                                        "Failed to create folder: "
                                            . $outputStartURLFolderPath
                                    );

                                    return;
                                }
                            }

                            file_put_contents(
                                $booksFilePath,
                                json_encode(
                                    $books,
                                    JSON_UNESCAPED_UNICODE
                                        | JSON_UNESCAPED_SLASHES
                                )
                            );
                        }

                        $startURLParsedSuccessfully =
                            true;

                        unset($books);

                        break;
                    } catch (
                        AccessDeniedException
                        | PageNotFoundException
                        | ParseException
                        | Exception $ex
                    ) {
                        if (
                            $this->config
                            ->showLogs
                        ) {
                            $this->cli
                                ->red()
                                ->out(
                                    $ex->getMessage()
                                );
                        }
                    }
                }
            }

            if (
                $startURLParsedSuccessfully
            ) {
                $successStartURLsCount++;
            }

            $startURLsProgress++;

            if (
                !$this->config
                    ->showLogs
            ) {
                $startURLsProgressBar->current(
                    $startURLsProgress,
                    "<bold>[{$startURLsProgress} / {$startURLsCount}]</bold> Parsing books"
                );
            }

            unset(
                $booksFilePath,
                $outputStartURLFolderName,
                $outputStartURLFolderPath
            );
        }

        if ($mode === 'books') {
            $this->cli->br();

            $this->cli->output(
                "<bold><green>Finished parsing books.</green></bold>"
            );

            $this->cli->output(
                "Books count: {$totalBooksCount}"
            );

            return;
        }

        // ============================================================
        // TASK LISTS
        // ============================================================

        $tasksItemsList = [];

        $taskListProgress = 0;
        $totalTasksCount = 0;
        $successTasksListCount = 0;
        $skippedTasksListCount = 0;

        if (
            !$this->config
                ->showLogs
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
                "<bold>[0 / {$totalBooksCount}]</bold> Parsing task lists"
            );
        }

        foreach (
            $booksList
            as $bookItem
        ) {
            /** @var BookDTO $book */
            $book =
                $bookItem['book'];

            /*
             * НОВАЯ структура:
             *
             * startURL/books/{book_id}/
             */
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

            $storedTasks = null;

            $useStoredTaskList = false;

            if (
                is_file(
                    $taskListFilePath
                )
            ) {
                $storedTasks =
                    json_decode(
                        file_get_contents(
                            $taskListFilePath
                        ),
                        true
                    );

                if (
                    is_array($storedTasks)
                    && $this
                    ->isStoredTaskListCompatible(
                        $storedTasks
                    )
                ) {
                    /*
                     * book_id не требует повторного
                     * HTTP-парсинга.
                     */
                    $storedTasks =
                        $this
                        ->normalizeStoredTaskList(
                            $storedTasks,
                            $book->book_id
                        );

                    file_put_contents(
                        $taskListFilePath,
                        json_encode(
                            $storedTasks,
                            JSON_UNESCAPED_UNICODE
                                | JSON_UNESCAPED_SLASHES
                        )
                    );

                    $useStoredTaskList =
                        true;
                }
            }

            // ========================================================
            // Cached
            // ========================================================

            if ($useStoredTaskList) {
                $totalTasksCount +=
                    count(
                        $storedTasks
                    );

                foreach (
                    $storedTasks
                    as $taskItem
                ) {
                    $taskDTO =
                        TaskListItemDTO
                        ::fromArray(
                            $taskItem
                        );

                    /*
                     * Дополнительная гарантия:
                     * родитель всегда текущая книга.
                     */
                    $taskDTO->book_id =
                        $book->book_id;

                    $tasksItemsList[] = [
                        'outputPath' =>
                        $bookFolderPath,

                        'tasksList' =>
                        $taskDTO,
                    ];
                }

                $skippedTasksListCount++;
            }

            // ========================================================
            // Parse
            // ========================================================

            else {
                for (
                    $attempt = 1;
                    $attempt <= $this->config->attempts;
                    $attempt++
                ) {
                    try {
                        $tasksItems =
                            $this
                            ->taskListParserContext
                            ->parse(
                                $book->url,
                                $this->getRandomProxy(),
                                $this->config->timeout
                            );

                        /*
                         * КЛЮЧЕВОЙ МОМЕНТ.
                         *
                         * ReshakTaskListParser не должен сам
                         * угадывать книгу.
                         *
                         * GDZParser уже знает родителя,
                         * поэтому здесь и задаем book_id.
                         */
                        foreach (
                            $tasksItems
                            as $task
                        ) {
                            $task->book_id =
                                $book->book_id;

                            $tasksItemsList[] = [
                                'outputPath' =>
                                $bookFolderPath,

                                'tasksList' =>
                                $task,
                            ];
                        }

                        $tasksItemsCount =
                            count($tasksItems);

                        $totalTasksCount +=
                            $tasksItemsCount;

                        if ($tasksItemsCount > 0) {
                            if (
                                !is_dir(
                                    $bookFolderPath
                                )
                            ) {
                                if (
                                    !mkdir(
                                        $bookFolderPath,
                                        0777,
                                        true
                                    )
                                    && !is_dir(
                                        $bookFolderPath
                                    )
                                ) {
                                    $this->cli->error(
                                        "Failed to create folder: "
                                            . $bookFolderPath
                                    );

                                    return;
                                }
                            }

                            /*
                             * Здесь задачи уже содержат book_id.
                             */
                            file_put_contents(
                                $taskListFilePath,
                                json_encode(
                                    $tasksItems,
                                    JSON_UNESCAPED_UNICODE
                                        | JSON_UNESCAPED_SLASHES
                                )
                            );
                        }

                        $taskListParsedSuccessfully =
                            true;

                        unset($tasksItems);

                        break;
                    } catch (
                        AccessDeniedException
                        | PageNotFoundException
                        | ParseException
                        | Exception $ex
                    ) {
                        if (
                            $this->config
                            ->showLogs
                        ) {
                            $this->cli
                                ->red()
                                ->out(
                                    $ex->getMessage()
                                );
                        }
                    }
                }
            }

            if (
                $taskListParsedSuccessfully
            ) {
                $successTasksListCount++;
            }

            $taskListProgress++;

            if (
                !$this->config
                    ->showLogs
                && isset(
                    $taskListProgressBar
                )
            ) {
                $taskListProgressBar->current(
                    $taskListProgress,
                    "<bold>[{$taskListProgress} / {$totalBooksCount}]</bold> Parsing task lists"
                );
            }

            unset(
                $storedTasks,
                $book,
                $bookFolderPath,
                $taskListFilePath
            );
        }

        if ($mode === 'tasks') {
            $this->cli->br();

            $this->cli->output(
                "<bold><green>Finished parsing task lists.</green></bold>"
            );

            $this->cli->output(
                "Tasks count: {$totalTasksCount}"
            );

            return;
        }

        // ============================================================
        // FULL TASKS
        // ============================================================

        if ($totalTasksCount === 0) {
            $this->cli->error(
                'No tasks found!'
            );

            return;
        }

        $successParsedTasksCount = 0;
        $skippedParsedTasksCount = 0;
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
                "<bold>[0 / {$totalTasksCount}]</bold> Parsing tasks"
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

            // ========================================================
            // Already exists
            // ========================================================

            if (
                is_file(
                    $outputTaskFullFileName
                )
            ) {
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

            // ========================================================
            // Parse content
            // ========================================================

            else {
                for (
                    $attempt = 1;
                    $attempt <= $this->config->attempts;
                    $attempt++
                ) {
                    try {
                        $taskInfo =
                            $this
                            ->taskParserContext
                            ->parse(
                                $taskListItem->url,
                                $this->getRandomProxy(),
                                $this->config->timeout
                            );

                        /*
                         * Связь со структурой книги.
                         */
                        $taskInfo->book_id =
                            $taskListItem
                            ->book_id;

                        $taskInfo->group_name =
                            $taskListItem
                            ->group_name;

                        $taskInfo->order_number_in_group =
                            $taskListItem
                            ->order_number_in_group;

                        $saveStatus =
                            file_put_contents(
                                $outputTaskFullFileName,
                                json_encode(
                                    $taskInfo,
                                    JSON_UNESCAPED_UNICODE
                                        | JSON_UNESCAPED_SLASHES
                                )
                            );

                        if (
                            $saveStatus
                            !== false
                        ) {
                            $successParsedTasksCount++;
                        }

                        unset($taskInfo);

                        break;
                    } catch (
                        AccessDeniedException
                        | PageNotFoundException
                        | ParseException
                        | Exception $ex
                    ) {
                        if (
                            $this->config
                            ->showLogs
                        ) {
                            $this->cli
                                ->red()
                                ->out(
                                    $ex->getMessage()
                                );
                        }
                    }
                }
            }

            $tasksProgress++;

            if (
                !$this->config
                    ->showLogs
            ) {
                $tasksProgressBar->current(
                    $tasksProgress,
                    "<bold>[{$tasksProgress} / {$totalTasksCount}]</bold> Parsing tasks"
                );
            }

            unset(
                $taskListItem,
                $outputTaskFileName,
                $outputTaskFullFileName
            );
        }

        $this->cli->br();

        $this->cli->output(
            '<bold><green>Finished parsing tasks.</green></bold>'
        );

        $this->cli->output(
            "<bold><green>Success parsed tasks count:</green></bold> "
                . $successParsedTasksCount
        );

        $this->cli->output(
            "<bold><yellow>Skipped parsed tasks count:</yellow></bold> "
                . $skippedParsedTasksCount
        );

        if (
            $updatedTaskMetadataCount > 0
        ) {
            $this->cli->output(
                "<bold><cyan>Updated metadata count:</cyan></bold> "
                    . $updatedTaskMetadataCount
            );
        }

        $this->cli->br();
    }
}
