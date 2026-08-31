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
     * Конструктор.
     */
    public function __construct(GDZParserConfig $config)
    {
        $this->cli = new CLImate();
        $this->config = $config;

        // Инициализируем стратегии.
        if (!is_null($this->config->bookParser)) {
            $this->bookParserContext = new BookParserContext(
                $this->config->bookParser
            );
        }

        if (!is_null($this->config->taskListParser)) {
            $this->taskListParserContext = new TaskListParserContext(
                $this->config->taskListParser
            );
        }

        if (!is_null($this->config->taskParser)) {
            $this->taskParserContext = new TaskParserContext(
                $this->config->taskParser
            );
        }
    }

    /**
     * Получить текущий режим парсинга.
     */
    private function getMode(): string
    {
        $mode = strtolower(
            trim(
                (string)($this->config->mode ?? 'all')
            )
        );

        return $mode !== ''
            ? $mode
            : 'all';
    }

    /**
     * Нужно ли парсить книги.
     */
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

    /**
     * Нужно ли парсить списки задач.
     */
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

    /**
     * Нужно ли открывать и парсить содержимое каждой задачи.
     */
    private function shouldParseTaskContents(): bool
    {
        return $this->getMode() === 'all';
    }

    /**
     * Получить рандомный прокси-сервер из конфига.
     */
    private function getRandomProxy(): ?Proxy
    {
        if (count($this->config->proxy) === 0) {
            return null;
        }

        return $this->config->proxy[
            array_rand($this->config->proxy)
        ];
    }

    /**
     * Проверить, содержит ли сохраненный taskList.json
     * новые поля group_name и group_order_number.
     *
     * Это позволяет автоматически перепарсить старый кеш.
     */
    private function isStoredTaskListCompatible(array $storedTasks): bool
    {
        foreach ($storedTasks as $storedTask) {
            if (!is_array($storedTask)) {
                return false;
            }

            if (!array_key_exists('group_name', $storedTask)) {
                return false;
            }

            if (!array_key_exists('group_order_number', $storedTask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Добавить/обновить group_name и group_order_number
     * в уже сохраненном JSON конкретной задачи.
     *
     * Возвращает true, если файл был обновлен.
     */
    private function updateStoredTaskGroupMetadata(
        string $filePath,
        TaskListItemDTO $taskListItem
    ): bool {
        if (!is_file($filePath)) {
            return false;
        }

        $json = file_get_contents($filePath);

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

        $currentGroupName =
            $storedTask['group_name'] ?? null;

        $currentGroupOrderNumber =
            $storedTask['group_order_number'] ?? null;

        $expectedGroupName =
            $taskListItem->group_name;

        $expectedGroupOrderNumber =
            $taskListItem->group_order_number;

        /*
         * Если данные уже совпадают —
         * ничего обновлять не нужно.
         */
        if (
            array_key_exists('group_name', $storedTask)
            && array_key_exists('group_order_number', $storedTask)
            && $currentGroupName === $expectedGroupName
            && $currentGroupOrderNumber === $expectedGroupOrderNumber
        ) {
            unset($storedTask);

            return false;
        }

        $storedTask['group_name'] =
            $expectedGroupName;

        $storedTask['group_order_number'] =
            $expectedGroupOrderNumber;

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

    /**
     * Запустить парсинг.
     *
     * @throws AccessDeniedException
     * @throws PageNotFoundException
     * @throws Exception
     */
    public function run(): void
    {
        $mode = $this->getMode();

        // ============================================================
        // Стартовая информация
        // ============================================================

        $startURLsCount =
            count($this->config->startURLs);

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

            $this->cli->table(
                $this->config->proxy
            )->br();
        }

        // ============================================================
        // Проверки
        // ============================================================

        if (
            !in_array(
                $mode,
                [
                    'books',
                    'tasks',
                    'all',
                ],
                true
            )
        ) {
            $this->cli->error(
                "Unsupported mode '{$mode}'. "
                . "Available modes books, tasks, all."
            );

            return;
        }

        /*
         * Проверяем только те контексты, которые реально
         * нужны выбранному режиму.
         */
        if (
            $this->shouldParseBooks()
            && is_null($this->bookParserContext)
        ) {
            $this->cli->error(
                "Book parser must be specified for mode '{$mode}'!"
            );

            return;
        }

        if (
            $this->shouldParseTaskLists()
            && is_null($this->taskListParserContext)
        ) {
            $this->cli->error(
                "Task list parser must be specified for mode '{$mode}'!"
            );

            return;
        }

        if (
            $this->shouldParseTaskContents()
            && is_null($this->taskParserContext)
        ) {
            $this->cli->error(
                "Task parser must be specified for mode '{$mode}'!"
            );

            return;
        }

        if ($startURLsCount === 0) {
            $this->cli->error(
                "Start URLs must not be empty!"
            );

            return;
        }

        if (empty($this->config->parseOutputFolder)) {
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
        // Создаем выходную директорию
        // ============================================================

        if ($this->config->showLogs) {
            $this->cli->output(
                "Checking output folder..."
            );
        }

        if (!is_dir($this->config->parseOutputFolder)) {
            if ($this->config->showLogs) {
                $this->cli->output(
                    "Folder <yellow>{$this->config->parseOutputFolder}</yellow> "
                    . "not found. Creating..."
                );
            }

            if (
                !mkdir(
                    $this->config->parseOutputFolder,
                    0777,
                    true
                )
                && !is_dir($this->config->parseOutputFolder)
            ) {
                $this->cli->error(
                    "Failed to create folder: "
                    . $this->config->parseOutputFolder
                );

                return;
            }
        } else {
            if ($this->config->showLogs) {
                $this->cli->output(
                    "Folder <yellow>{$this->config->parseOutputFolder}</yellow> found."
                );
            }
        }
		
        // ============================================================
        // ПАРСИНГ КНИГ
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
                    ->total($startURLsCount);

            $startURLsProgressBar->current(
                0,
                "<bold>[0 / {$startURLsCount}]</bold> "
                . "Parsing books from start URLs"
            );
        }

        if ($this->config->showLogs) {
            $this->cli->br();

            $this->cli->output(
                '<bold><green>Parsing books from start URLs...</green></bold>'
            );
        }

        foreach (
            $this->config->startURLs
            as $startURL
        ) {
            $currentStartURLProgress =
                $startURLsProgress + 1;

            $startURLsProgressPercent =
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

            $startURLParsedSuccessfully = false;

            /*
             * Если директория уже существует —
             * восстанавливаем книги из JSON.
             */
            if (is_dir($outputStartURLFolderPath)) {
                if ($this->config->showLogs) {
                    $this->cli->output(
                        "<dim>[{$currentStartURLProgress} / {$startURLsCount}]</dim> "
                        . "<dim>[{$startURLsProgressPercent}%]</dim> "
                        . "Folder <yellow>{$outputStartURLFolderPath}</yellow> "
                        . "already exists. Loading stored books..."
                    );
                }

                $skippedStartURLsCount++;

                $parseFiles = scandir(
                    $outputStartURLFolderPath
                );

                if (!is_array($parseFiles)) {
                    $parseFiles = [];
                }

                $parseFiles = array_filter(
                    $parseFiles,
                    static function ($item) {
                        return $item !== '.'
                            && $item !== '..'
                            && pathinfo(
                                $item,
                                PATHINFO_EXTENSION
                            ) === 'json';
                    }
                );

                $parseFiles = array_map(
                    static function ($item) use (
                        $outputStartURLFolderPath
                    ) {
                        return $outputStartURLFolderPath
                            . DIRECTORY_SEPARATOR
                            . $item;
                    },
                    $parseFiles
                );

                foreach ($parseFiles as $parseFile) {
                    $parsedBooks = json_decode(
                        file_get_contents($parseFile),
                        true
                    );

                    if (!is_array($parsedBooks)) {
                        unset($parsedBooks);

                        continue;
                    }

                    /*
                     * Нам нужны только JSON-массивы,
                     * содержащие данные книг.
                     *
                     * Например taskList.json на данном уровне
                     * в нормальной структуре отсутствует,
                     * но дополнительная проверка не повредит.
                     */
                    foreach ($parsedBooks as $book) {
                        if (
                            !is_array($book)
                            || !isset($book['url'])
                            || !isset($book['title'])
                        ) {
                            continue;
                        }

                        try {
                            $bookDTO =
                                BookDTO::fromArray(
                                    $book
                                );
                        } catch (Exception $ex) {
                            continue;
                        }

                        $booksList[] = [
                            'outputPath' =>
                                $outputStartURLFolderPath,

                            'book' =>
                                $bookDTO,
                        ];

                        $totalBooksCount++;

                        unset($bookDTO);
                    }

                    unset(
                        $book,
                        $parsedBooks
                    );
                }

                unset(
                    $parseFile,
                    $parseFiles
                );
            } else {
                for (
                    $attempt = 1;
                    $attempt <= $this->config->attempts;
                    $attempt++
                ) {
                    try {
                        if ($this->config->showLogs) {
                            $this->cli->output(
                                "<dim>[{$currentStartURLProgress} / {$startURLsCount}]</dim> "
                                . "<dim>[{$startURLsProgressPercent}%]</dim> "
                                . "Parsing books from {$startURL}... "
                                . "(Attempt {$attempt} of {$this->config->attempts})"
                            );
                        }

                        $books =
                            $this->bookParserContext->parse(
                                $startURL,
                                $this->getRandomProxy(),
                                $this->config->timeout
                            );

                        $booksCount =
                            count($books);

                        $totalBooksCount +=
                            $booksCount;

                        foreach ($books as $book) {
                            $booksList[] = [
                                'outputPath' =>
                                    $outputStartURLFolderPath,

                                'book' =>
                                    $book,
                            ];
                        }

                        if ($booksCount === 0) {
                            if ($this->config->showLogs) {
                                $this->cli->output(
                                    '<red>No books found.</red>'
                                );
                            }
                        } else {
                            if ($this->config->showLogs) {
                                $this->cli->output(
                                    "<bold><green>Found {$booksCount} books.</green></bold>"
                                );

                                $this->cli->output(
                                    "Creating folder "
                                    . "<yellow>{$outputStartURLFolderPath}</yellow>..."
                                );
                            }

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

                            $booksFilePath =
                                $outputStartURLFolderPath
                                . DIRECTORY_SEPARATOR
                                . 'books.json';

                            if ($this->config->showLogs) {
                                $this->cli->output(
                                    "Saving books info to "
                                    . "<yellow>{$booksFilePath}</yellow>..."
                                );
                            }

                            $saveStatus =
                                file_put_contents(
                                    $booksFilePath,
                                    json_encode(
                                        $books,
                                        JSON_UNESCAPED_UNICODE
                                        | JSON_UNESCAPED_SLASHES
                                    )
                                );

                            if (
                                $saveStatus === false
                                && $this->config->showLogs
                            ) {
                                $this->cli->output(
                                    "<red>Failed to save books info to "
                                    . "<yellow>{$booksFilePath}</yellow></red>"
                                );
                            }
                        }

                        $startURLParsedSuccessfully = true;

                        unset(
                            $book,
                            $books
                        );

                        break;
                    } catch (AccessDeniedException $ex) {
                        if ($this->config->showLogs) {
                            $this->cli
                                ->red()
                                ->out(
                                    $ex->getMessage()
                                );
                        }
                    } catch (PageNotFoundException $ex) {
                        if ($this->config->showLogs) {
                            $this->cli
                                ->red()
                                ->out(
                                    $ex->getMessage()
                                );
                        }
                    } catch (ParseException $ex) {
                        if ($this->config->showLogs) {
                            $this->cli
                                ->red()
                                ->out(
                                    $ex->getMessage()
                                );
                        }
                    } catch (Exception $ex) {
                        if ($this->config->showLogs) {
                            $this->cli
                                ->red()
                                ->out(
                                    $ex->getMessage()
                                );
                        }
                    }
                }
            }

            if ($startURLParsedSuccessfully) {
                $successStartURLsCount++;
            }

            $startURLsProgress++;

            if (!$this->config->showLogs) {
                $startURLsProgressBar->current(
                    $startURLsProgress,
                    "<bold>[{$startURLsProgress} / {$startURLsCount}]</bold> "
                    . "Parsing books from start URLs"
                );
            }

            unset(
                $startURL,
                $outputStartURLFolderName,
                $outputStartURLFolderPath
            );
        }

        // ============================================================
        // Завершение стадии книг
        // ============================================================

        if ($this->config->showLogs) {
            $this->cli->br();

            $this->cli->output(
                '<bold><green>Finished parsing books.</green></bold>'
            );

            $this->cli->output(
                "<bold><cyan>Total books count:</cyan></bold> "
                . $totalBooksCount
            );

            $this->cli->output(
                "<bold><green>Success parsed start URLs count:</green></bold> "
                . $successStartURLsCount
            );

            $this->cli->output(
                "<bold><yellow>Skipped/cached start URLs count:</yellow></bold> "
                . $skippedStartURLsCount
            );

            $this->cli->br();
        }

        // ============================================================
        // MODE = BOOKS
        // ============================================================

        if ($mode === 'books') {
            $this->cli->br();

            $this->cli->output(
                "<bold><green>Finished parsing at books level.</green></bold>"
            );

            $this->cli->output(
                "<bold><cyan>Total books count:</cyan></bold> "
                . $totalBooksCount
            );

            $this->cli->output(
                "<dim>Task lists and task contents were not requested.</dim>"
            );

            $this->cli->br();

            return;
        }

        // ============================================================
        // ПАРСИНГ СПИСКОВ ЗАДАЧ
        // ============================================================

        if ($this->config->showLogs) {
            $this->cli->output(
                '<bold><green>Parsing task items lists...</green></bold>'
            );
        }

        $tasksItemsList = [];

        $taskListProgress = 0;

        $totalTasksCount = 0;

        $successTasksListCount = 0;

        $skippedTasksListCount = 0;

        $reparsedOldTaskListsCount = 0;

        if (
            !$this->config->showLogs
            && $totalBooksCount > 0
        ) {
            $taskListProgressBar =
                $this->cli
                    ->progress()
                    ->total($totalBooksCount);

            $taskListProgressBar->current(
                0,
                "<bold>[0 / {$totalBooksCount}]</bold> "
                . "Parsing task items lists"
            );
        }

        foreach ($booksList as $bookItem) {
            $currentTaskListProgress =
                $taskListProgress + 1;

            $tasksItemsProgressPercent =
                $totalBooksCount > 0
                    ? round(
                        $currentTaskListProgress
                        / $totalBooksCount
                        * 100
                    )
                    : 0;

            $bookFolderName =
                Text::translitRef(
                    $bookItem['book']->title
                )
                . '_'
                . Text::translitRef(
                    $bookItem['book']->author
                )
                . '_'
                . Text::generateNamefromURL(
                    $bookItem['book']->url
                );

            $bookFolderPath =
                $bookItem['outputPath']
                . DIRECTORY_SEPARATOR
                . $bookFolderName;

            $taskListParsedSuccessfully = false;

            $taskListFilePath =
                $bookFolderPath
                . DIRECTORY_SEPARATOR
                . 'taskList.json';

            /*
             * Проверяем существующий кеш.
             */
            $useStoredTaskList = false;

            $storedTasks = null;

            if (
                is_dir($bookFolderPath)
                && file_exists($taskListFilePath)
            ) {
                $storedTasks = json_decode(
                    file_get_contents(
                        $taskListFilePath
                    ),
                    true
                );

                if (
                    is_array($storedTasks)
                    && $this->isStoredTaskListCompatible(
                        $storedTasks
                    )
                ) {
                    $useStoredTaskList = true;
                } else {
                    /*
                     * Старый taskList.json, созданный до появления
                     * group_name/group_order_number.
                     *
                     * Его нужно перепарсить.
                     */
                    $reparsedOldTaskListsCount++;

                    if ($this->config->showLogs) {
                        $this->cli->output(
                            "<dim>[{$currentTaskListProgress} / {$totalBooksCount}]</dim> "
                            . "<dim>[{$tasksItemsProgressPercent}%]</dim> "
                            . "Stored task list for book "
                            . "<yellow>{$bookItem['book']->title}</yellow> "
                            . "is outdated and does not contain group metadata. "
                            . "Reparsing..."
                        );
                    }
                }
            }

            // ========================================================
            // Используем сохраненный taskList.json
            // ========================================================

            if ($useStoredTaskList) {
                if ($this->config->showLogs) {
                    $this->cli->output(
                        "<dim>[{$currentTaskListProgress} / {$totalBooksCount}]</dim> "
                        . "<dim>[{$tasksItemsProgressPercent}%]</dim> "
                        . "Task list for book "
                        . "<yellow>{$bookItem['book']->title}</yellow> "
                        . "already parsed. Loading..."
                    );
                }

                $skippedTasksListCount++;

                $totalTasksCount +=
                    count($storedTasks);

                foreach (
                    $storedTasks
                    as $taskItem
                ) {
                    try {
                        $taskListItemDTO =
                            TaskListItemDTO::fromArray(
                                $taskItem
                            );
                    } catch (Exception $ex) {
                        continue;
                    }

                    $tasksItemsList[] = [
                        'outputPath' =>
                            $bookFolderPath,

                        'tasksList' =>
                            $taskListItemDTO,
                    ];

                    unset($taskListItemDTO);
                }
            }

            // ========================================================
            // Парсим taskList заново
            // ========================================================

            else {
                for (
                    $attempt = 1;
                    $attempt <= $this->config->attempts;
                    $attempt++
                ) {
                    try {
                        if ($this->config->showLogs) {
                            $this->cli->output(
                                "<dim>[{$currentTaskListProgress} / {$totalBooksCount}]</dim> "
                                . "<dim>[{$tasksItemsProgressPercent}%]</dim> "
                                . "Parsing task list for book "
                                . "<yellow>{$bookItem['book']->title}</yellow> "
                                . "(<bold>URL:</bold> "
                                . "<yellow>{$bookItem['book']->url}</yellow>)... "
                                . "(Attempt <yellow>{$attempt}</yellow> "
                                . "of <yellow>{$this->config->attempts}</yellow>)"
                            );
                        }

                        $tasksItems =
                            $this->taskListParserContext->parse(
                                $bookItem['book']->url,
                                $this->getRandomProxy(),
                                $this->config->timeout
                            );

                        $tasksItemsCount =
                            count($tasksItems);

                        $totalTasksCount +=
                            $tasksItemsCount;

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

                        if ($tasksItemsCount === 0) {
                            if ($this->config->showLogs) {
                                $this->cli->output(
                                    '<red>No task items list found.</red>'
                                );
                            }
                        } else {
                            if ($this->config->showLogs) {
                                $this->cli->output(
                                    "<bold><green>Found {$tasksItemsCount} task items.</green></bold>"
                                );
                            }

                            if (!is_dir($bookFolderPath)) {
                                if ($this->config->showLogs) {
                                    $this->cli->output(
                                        "Folder "
                                        . "<yellow>{$bookFolderPath}</yellow> "
                                        . "not found. Creating..."
                                    );
                                }

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

                            if ($this->config->showLogs) {
                                $this->cli->output(
                                    "Saving task items lists to "
                                    . "<yellow>{$taskListFilePath}</yellow>..."
                                );
                            }

                            $saveStatus =
                                file_put_contents(
                                    $taskListFilePath,
                                    json_encode(
                                        $tasksItems,
                                        JSON_UNESCAPED_UNICODE
                                        | JSON_UNESCAPED_SLASHES
                                    )
                                );

                            if (
                                $saveStatus === false
                                && $this->config->showLogs
                            ) {
                                $this->cli->output(
                                    "<red>Failed to save task items lists to "
                                    . "<yellow>{$taskListFilePath}</yellow></red>"
                                );
                            }
                        }

                        $taskListParsedSuccessfully = true;

                        unset(
                            $task,
                            $tasksItems
                        );

                        break;
                    } catch (AccessDeniedException $ex) {
                        if ($this->config->showLogs) {
                            $this->cli
                                ->red()
                                ->out(
                                    $ex->getMessage()
                                );
                        }
                    } catch (PageNotFoundException $ex) {
                        if ($this->config->showLogs) {
                            $this->cli
                                ->red()
                                ->out(
                                    $ex->getMessage()
                                );
                        }
                    } catch (ParseException $ex) {
                        if ($this->config->showLogs) {
                            $this->cli
                                ->red()
                                ->out(
                                    $ex->getMessage()
                                );
                        }
                    } catch (Exception $ex) {
                        if ($this->config->showLogs) {
                            $this->cli
                                ->red()
                                ->out(
                                    $ex->getMessage()
                                );
                        }
                    }
                }
            }

            if ($taskListParsedSuccessfully) {
                $successTasksListCount++;
            }

            $taskListProgress++;

            if (
                !$this->config->showLogs
                && isset($taskListProgressBar)
            ) {
                $taskListProgressBar->current(
                    $taskListProgress,
                    "<bold>[{$taskListProgress} / {$totalBooksCount}]</bold> "
                    . "Parsing task items lists"
                );
            }

            unset(
                $storedTasks,
                $useStoredTaskList,
                $bookItem,
                $bookFolderName,
                $bookFolderPath,
                $taskListFilePath
            );
        }

        // ============================================================
        // Завершение стадии taskList
        // ============================================================

        if ($this->config->showLogs) {
            $this->cli->br();

            $this->cli->output(
                '<bold><green>Finished parsing task lists.</green></bold>'
            );

            $this->cli->output(
                "<bold><cyan>Total tasks count:</cyan></bold> "
                . $totalTasksCount
            );

            $this->cli->output(
                "<bold><green>Success parsed task lists count:</green></bold> "
                . $successTasksListCount
            );

            $this->cli->output(
                "<bold><yellow>Skipped/cached task lists count:</yellow></bold> "
                . $skippedTasksListCount
            );

            if ($reparsedOldTaskListsCount > 0) {
                $this->cli->output(
                    "<bold><yellow>Reparsed outdated task lists count:</yellow></bold> "
                    . $reparsedOldTaskListsCount
                );
            }

            $this->cli->br();
        }

        // ============================================================
        // MODE = TASKS
        // ============================================================

        if ($mode === 'tasks') {
            $this->cli->br();

            $this->cli->output(
                "<bold><green>Finished parsing at tasks level.</green></bold>"
            );

            $this->cli->output(
                "<bold><cyan>Total task items count:</cyan></bold> "
                . $totalTasksCount
            );

            $this->cli->output(
                "<dim>Individual task pages were not requested.</dim>"
            );

            $this->cli->br();

            return;
        }

        // ============================================================
        // MODE = ALL
        // ============================================================

        if ($totalTasksCount === 0) {
            $this->cli->error(
                "No tasks found!"
            );

            return;
        }

        if ($this->config->showLogs) {
            $this->cli->output(
                "<bold><green>Start parsing tasks...</green></bold>"
            );
        }

        $successParsedTasksCount = 0;

        $skippedParsedTasksCount = 0;

        $updatedTaskMetadataCount = 0;

        $tasksProgress = 0;

        if (!$this->config->showLogs) {
            $tasksProgressBar =
                $this->cli
                    ->progress()
                    ->total($totalTasksCount);

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
            $currentTasksProgress =
                $tasksProgress + 1;

            $tasksProgressPercent =
                $totalTasksCount > 0
                    ? round(
                        $currentTasksProgress
                        / $totalTasksCount
                        * 100
                    )
                    : 0;

            /** @var TaskListItemDTO $taskListItem */
            $taskListItem =
                $tasksItemsListItem['tasksList'];

            // ========================================================
            // Формируем имя JSON-файла задачи
            // ========================================================

            $outputTaskFileName = '';

            if (is_string($taskListItem->chapter)) {
                $outputTaskFileName .=
                    Text::translitRef(
                        $taskListItem->chapter
                    );
            }

            if (is_string($taskListItem->url)) {
                $outputTaskFileName .=
                    '_'
                    . Text::generateNamefromURL(
                        $taskListItem->url
                    );
            }

            if (is_string($taskListItem->title)) {
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
                );

            $outputTaskFileName .=
                '.json';

            $outputTaskFileName =
                Text::makeSafeJSONFileName(
                    $outputTaskFileName
                );

            $outputStartURLFolderPath =
                $tasksItemsListItem['outputPath'];

            $outputTaskFullFileName =
                $outputStartURLFolderPath
                . DIRECTORY_SEPARATOR
                . $outputTaskFileName;

            // ========================================================
            // Файл уже существует
            // ========================================================

            if (
                file_exists(
                    $outputTaskFullFileName
                )
            ) {
                /*
                 * Важное изменение:
                 *
                 * Даже если задача уже была скачана старой
                 * версией парсера, дописываем новые group_*,
                 * не скачивая страницу повторно.
                 */
                $metadataUpdated =
                    $this->updateStoredTaskGroupMetadata(
                        $outputTaskFullFileName,
                        $taskListItem
                    );

                if ($metadataUpdated) {
                    $updatedTaskMetadataCount++;

                    if ($this->config->showLogs) {
                        $this->cli->output(
                            "<dim>[{$currentTasksProgress} / {$totalTasksCount}]</dim> "
                            . "<dim>[{$tasksProgressPercent}%]</dim> "
                            . "File "
                            . "<yellow>{$outputTaskFullFileName}</yellow> "
                            . "already exists. Group metadata updated."
                        );
                    }
                } else {
                    if ($this->config->showLogs) {
                        $this->cli->output(
                            "<dim>[{$currentTasksProgress} / {$totalTasksCount}]</dim> "
                            . "<dim>[{$tasksProgressPercent}%]</dim> "
                            . "File "
                            . "<yellow>{$outputTaskFullFileName}</yellow> "
                            . "already exists. Skipping..."
                        );
                    }
                }

                $skippedParsedTasksCount++;
            }

            // ========================================================
            // Парсим содержимое задачи
            // ========================================================

            else {
                for (
                    $attempt = 1;
                    $attempt <= $this->config->attempts;
                    $attempt++
                ) {
                    try {
                        if ($this->config->showLogs) {
                            $this->cli->output(
                                "<dim>[{$currentTasksProgress} / {$totalTasksCount}]</dim> "
                                . "<dim>[{$tasksProgressPercent}%]</dim> "
                                . "Parsing task from "
                                . "{$taskListItem->url}... "
                                . "(Attempt {$attempt} "
                                . "of {$this->config->attempts})"
                            );
                        }

                        $taskInfo =
                            $this->taskParserContext->parse(
                                $taskListItem->url,
                                $this->getRandomProxy(),
                                $this->config->timeout
                            );

                        // ============================================
                        // Переносим данные группы
                        // ============================================

                        /*
                         * Эти данные невозможно узнать со страницы
                         * самой задачи.
                         *
                         * Они были определены на странице книги,
                         * поэтому переносим их из TaskListItemDTO.
                         */
                        $taskInfo->group_name =
                            $taskListItem->group_name;

                        $taskInfo->group_order_number =
                            $taskListItem
                                ->group_order_number;

                        if ($this->config->showLogs) {
                            $this->cli->output(
                                "<bold><green>Parsed successfully.</green></bold>"
                            );
                        }

                        if ($this->config->showLogs) {
                            $this->cli->output(
                                "Saving data to "
                                . "<yellow>{$outputTaskFullFileName}</yellow>..."
                            );
                        }

                        $saveStatus =
                            file_put_contents(
                                $outputTaskFullFileName,
                                json_encode(
                                    $taskInfo,
                                    JSON_UNESCAPED_UNICODE
                                    | JSON_UNESCAPED_SLASHES
                                )
                            );

                        if ($saveStatus === false) {
                            if ($this->config->showLogs) {
                                $this->cli
                                    ->red()
                                    ->out(
                                        "Failed to save data to "
                                        . "<yellow>{$outputTaskFullFileName}</yellow>"
                                    );
                            }
                        } else {
                            $successParsedTasksCount++;
                        }

                        unset($taskInfo);

                        break;
                    } catch (AccessDeniedException $ex) {
                        if ($this->config->showLogs) {
                            $this->cli
                                ->red()
                                ->out(
                                    $ex->getMessage()
                                );
                        }
                    } catch (PageNotFoundException $ex) {
                        if ($this->config->showLogs) {
                            $this->cli
                                ->red()
                                ->out(
                                    $ex->getMessage()
                                );
                        }
                    } catch (ParseException $ex) {
                        if ($this->config->showLogs) {
                            $this->cli
                                ->red()
                                ->out(
                                    $ex->getMessage()
                                );
                        }
                    } catch (Exception $ex) {
                        if ($this->config->showLogs) {
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
                $outputStartURLFolderPath,
                $outputTaskFullFileName
            );
        }

        // ============================================================
        // Полное завершение
        // ============================================================

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

        if ($updatedTaskMetadataCount > 0) {
            $this->cli->output(
                "<bold><cyan>Updated group metadata in existing tasks:</cyan></bold> "
                . $updatedTaskMetadataCount
            );
        }

        $this->cli->br();
    }
}