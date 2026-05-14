<?php

namespace QuadVector\GDZParser;

use QuadVector\GDZParser\GDZParserConfig;
use QuadVector\GDZParser\BookParser\BookParserContext;
use QuadVector\GDZParser\TaskListParser\TaskListParserContext;
use QuadVector\GDZParser\TaskParser\TaskParserContext;
use QuadVector\GDZParser\DTO\BookDTO;
use QuadVector\GDZParser\DTO\TaskListItemDTO;
use QuadVector\GDZParser\Helper\Proxy;
use QuadVector\GDZParser\Helper\Text;
use QuadVector\GDZParser\Exception\AccessDeniedException;
use QuadVector\GDZParser\Exception\PageNotFoundException;
use QuadVector\GDZParser\Exception\ParseException;
use \Exception;

use League\CLImate\CLImate;

class GDZParser
{
	protected CLImate $cli;
	protected GDZParserConfig $config; // класс с конфигурацией
	protected BookParserContext $bookParserContext;
	protected TaskListParserContext $taskListParserContext;
	protected TaskParserContext $taskParserContext;

	const DIRECTORY_SEPARATOR = '\\';

	/**
	 * Конструктор
	 * @param GDZParserConfig $config
	 */
	public function __construct(GDZParserConfig $config)
	{
		$this->cli = new CLImate();
		$this->config = $config;

		// инициализируем стратегии
		$this->bookParserContext = new BookParserContext($this->config->bookParser);
		$this->taskListParserContext = new TaskListParserContext($this->config->taskListParser);
		$this->taskParserContext = new TaskParserContext($this->config->taskParser);
	}

	/**
	 * Получить рандомный прокси-сервер из конфига
	 * @return Proxy
	 */
	private function getRandomProxy(): Proxy
	{
		return $this->config->proxies[array_rand($this->config->proxies)];
	}

	/**
	 * Запустить парсинг
	 * @throws AccessDeniedException
	 * @throws PageNotFoundException
	 * @throws Exception
	 * @return void
	 */
	public function run(): void
	{
		// счетчики
		$startURLsCount = count($this->config->startURLs);
		$totalBooksCount = 0;
		$successstartURLsCount = 0;
		$startURLsProgress = 0;

		// проверка на наличие входных URL
		if (count($this->config->startURLs) == 0) {
			// [OUTPUT] выводим сообщение об ошибке
			$this->cli->output("<red>Start URLs must not be empty!</red>");
			return;
		}

		// [OUTPUT] создаем прогрессбар
		if (!$this->config->showLogs) {
			$startURLsProgressBar = $this->cli->progress()->total($startURLsCount);
			$startURLsProgressBar->current(0, "Parsing books from start URLs [0 / {$startURLsCount}]");
		}

		// выводим приветствие
		if ($this->config->showLogs) {
			$this->cli->br();
			$this->cli->output('<bold><green>Start parsing...</green></bold>');
			$this->cli->output("<bold><cyan>Start URLs count:</cyan></bold> {$startURLsCount}");
			$this->cli->br();
		}

		// [OUTPUT] создаем папку с выходными данными
		if ($this->config->showLogs) $this->cli->output("Checking output folder...");
		if (!is_dir($this->config->parseOutputFolder)) {
			if ($this->config->showLogs) $this->cli->output("Folder <yellow>{$this->config->parseOutputFolder}</yellow> not found. Creating...");
			mkdir($this->config->parseOutputFolder);
		} else {
			if ($this->config->showLogs) $this->cli->output("Folder <yellow>{$this->config->parseOutputFolder}</yellow> found.");
		}

		// начинаем парсить список учебников с входных URL
		if ($this->config->showLogs) $this->cli->output('<bold><green>Parsing books from start URLs...</green></bold>');
		$booksList = []; // список обрабатываемых книг

		foreach ($this->config->startURLs as $startURL) {
			$currentStartURLProgress = $startURLsProgress + 1;

			$startURLsProgressPercent = $startURLsCount > 0
				? round($currentStartURLProgress / $startURLsCount * 100)
				: 0; // считаем прогресс в процентах для удобства

			// [OUTPUT] Название папки с текущей ссылкой
			$outputStartURLFolderName = Text::GenerateNameFromURL($startURL);
			$outputStartURLFolderPath = $this->config->parseOutputFolder . self::DIRECTORY_SEPARATOR . $outputStartURLFolderName;

			$startURLParsedSuccessfully = false;

			if (is_dir($outputStartURLFolderPath)) {
				// [OUTPUT] Пропускаем и формируем список не из парсера, а из исходных файлов
				if ($this->config->showLogs) $this->cli->output("<dim>[{$currentStartURLProgress} / {$startURLsCount}]</dim> <dim>[{$startURLsProgressPercent}%]</dim> Folder <yellow>{$outputStartURLFolderPath}</yellow> already exists. Skipping...");

				// [OUTPUT] формируем список файлов, где хранится информация о книгах
				$parseFiles = scandir($outputStartURLFolderPath);

				$parseFiles = array_filter($parseFiles, function ($item) {
					return $item != '.' && $item != '..' && pathinfo($item, PATHINFO_EXTENSION) === 'json';
				});

				$parseFiles = array_map(function ($item) use ($outputStartURLFolderPath) {
					return $outputStartURLFolderPath . self::DIRECTORY_SEPARATOR . $item;
				}, $parseFiles);

				// [OUTPUT] восстанавливаем объекты книг
				foreach ($parseFiles as $parseFile) {
					$parsedBooks = json_decode(file_get_contents($parseFile), true);

					if (!is_array($parsedBooks)) {
						unset($parsedBooks);
						continue;
					}

					$totalBooksCount += count($parsedBooks);
					foreach ($parsedBooks as $book) {
						$booksList[] = [
							"outputPath" => $outputStartURLFolderPath,
							"book" => BookDTO::FromArray($book)
						];
					}

					unset(
						$book,
						$parsedBooks
					); // очищаем память
				}

				$startURLParsedSuccessfully = true;

				// очищаем память
				unset(
					$parseFile,
					$parseFiles
				);
			} else {
				for ($attempt = 1; $attempt <= $this->config->attempts; $attempt++) {
					try {
						// парсим книги
						if ($this->config->showLogs) $this->cli->output("<dim>[{$currentStartURLProgress} / {$startURLsCount}]</dim> <dim>[{$startURLsProgressPercent}%]</dim> " . "Parsing books from {$startURL}... (Attempt {$attempt} of {$this->config->attempts})");
						$books = $this->bookParserContext->parse($startURL, $this->getRandomProxy(), $this->config->timeout);

						// обновляем счетчики
						$booksCount = count($books);
						$totalBooksCount += $booksCount;

						// добавляем книги в список
						foreach ($books as $book) {
							$booksList[] = [
								"outputPath" => $outputStartURLFolderPath,
								"book" => $book
							];
						}

						// выводим информацию о найденных книгах
						if ($booksCount == 0) {
							if ($this->config->showLogs) $this->cli->output('<red>No books found.</red>');
						} else {
							if ($this->config->showLogs) $this->cli->output("<bold><green>Found {$booksCount} books.</green></bold>");

							// [OUTPUT] Создаем папку с соответствующей входной ссылкой, куда будет размещены будущие папки и файлы с книгами и задачами
							if ($this->config->showLogs) $this->cli->output("Creating folder <yellow>{$outputStartURLFolderPath}</yellow>...");
							mkdir($outputStartURLFolderPath);

							// [OUTPUT] Сохраняем информацию о книгах в JSON-файл внутрь папки
							if ($this->config->showLogs) $this->cli->output("Saving books info to <yellow>{$outputStartURLFolderPath}\\books.json...</yellow>");
							
							$saveStatus = file_put_contents($outputStartURLFolderPath . '\\books.json', json_encode(
								$books,
								JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
							));

							if(!$saveStatus) {
								if ($this->config->showLogs) $this->cli->output("<red>Failed to save books info to <yellow>{$outputStartURLFolderPath}\\books.json...</yellow></red>");
							}
						}

						$startURLParsedSuccessfully = true;

						unset(
							$book,
							$books
						);

						break;
					} catch (AccessDeniedException $ex) {
						if ($this->config->showLogs) $this->cli->red()->out($ex->getMessage());
					} catch (PageNotFoundException $ex) {
						if ($this->config->showLogs) $this->cli->red()->out($ex->getMessage());
					} catch (ParseException $ex) {
						if ($this->config->showLogs) $this->cli->red()->out($ex->getMessage());
					} catch (Exception $ex) {
						if ($this->config->showLogs) $this->cli->red()->out($ex->getMessage());
					}
				}
			}

			if ($startURLParsedSuccessfully) {
				$successstartURLsCount++;
			}

			// обновляем счетчик прогресса
			$startURLsProgress++;

			// [OUTPUT] обновляем прогрессбар
			if (!$this->config->showLogs) $startURLsProgressBar->current($startURLsProgress, "Parsing books from start URLs [{$startURLsProgress} / {$startURLsCount}]");

			unset(
				$startURL,
				$outputStartURLFolderName,
				$outputStartURLFolderPath
			); // очищаем память после обработки текущего URL
		}

		// завершаем парсинг книг
		if ($this->config->showLogs) {
			$this->cli->br();
			$this->cli->output('<bold><green>Finished parsing books.</green></bold>');
			$this->cli->output("<bold><cyan>Total books count:</cyan></bold> {$totalBooksCount}");
			$this->cli->output("<bold><green>Success parsed start URLs count:</green></bold> {$successstartURLsCount}");
			$this->cli->br();
		}

		// начинаем парсинг списков задач
		if ($this->config->showLogs) $this->cli->output('<bold><green>Parsing task items lists...</green></bold>');
		$tasksItemsList = [];

		// счетчики
		$taskListProgress = 0;
		$totalTasksCount = 0;
		$successTasksListCount = 0;

		// [OUTPUT] создаем прогрессбар
		if (!$this->config->showLogs) {
			$taskListProgressBar = $this->cli->progress()->total($totalBooksCount);
			$taskListProgressBar->current(0, "Parsing task items lists [0 / {$totalBooksCount}]");
		}

		foreach ($booksList as $bookItem) {
			$currentTaskListProgress = $taskListProgress + 1;

			$tasksItemsProgressPercent = $totalBooksCount > 0
				? round($currentTaskListProgress / $totalBooksCount * 100)
				: 0; // считаем прогресс в процентах для удобства

			// [OUTPUT] Название папки с задачами по конкретной книге
			$bookFolderName = Text::TranslitRef($bookItem["book"]->title) . "_" . Text::TranslitRef($bookItem["book"]->author) . "_" . Text::GenerateNameFromURL($bookItem["book"]->url);
			$bookFolderPath = $bookItem["outputPath"] . self::DIRECTORY_SEPARATOR . $bookFolderName; // путь к папке с задачами

			$taskListParsedSuccessfully = false;

			// [OUTPUT] Проверяем папку на существование
			if (is_dir($bookFolderPath) && file_exists($bookFolderPath . '\\taskList.json')) {
				if ($this->config->showLogs) $this->cli->output("<dim>[{$currentTaskListProgress} / {$totalBooksCount}]</dim> <dim>[{$tasksItemsProgressPercent}%]</dim> Task list for book <yellow>{$bookItem['book']->title}</yellow> already parsed. Skipping...");

				// [OUTPUT] восстанавливаем список задач
				$storedTasks = json_decode(file_get_contents($bookFolderPath . '\\taskList.json'), true);

				if (is_array($storedTasks)) {
					$totalTasksCount += count($storedTasks);
					foreach ($storedTasks as $taskItem) {
						$tasksItemsList[] = [
							"outputPath" => $bookFolderPath,
							"tasksList" => TaskListItemDTO::FromArray($taskItem)
						];
					}

					$taskListParsedSuccessfully = true;

					unset($taskItem); // очищаем память после foreach
				}

				unset($storedTasks); // очищаем память
			} else {
				// Парсим список задач
				for ($attempt = 1; $attempt <= $this->config->attempts; $attempt++) {
					try {
						if ($this->config->showLogs) $this->cli->output("<dim>[{$currentTaskListProgress} / {$totalBooksCount}]</dim> <dim>[{$tasksItemsProgressPercent}%]</dim> " . "Parsing task list for book <yellow>{$bookItem['book']->title}</yellow> (<bold>URL:</bold> <yellow>{$bookItem['book']->url})</yellow>... (Attempt <yellow>{$attempt}</yellow> of <yellow>{$this->config->attempts}</yellow>)");
						$tasksItems = $this->taskListParserContext->parse($bookItem["book"]->url, $this->getRandomProxy(), $this->config->timeout);

						// обновляем счетчики
						$tasksItemsCount = count($tasksItems);
						$totalTasksCount += $tasksItemsCount;

						// добавляем список задач в общий список
						foreach ($tasksItems as $task) {
							$tasksItemsList[] = [
								"outputPath" => $bookFolderPath,
								"tasksList" => $task
							];
						}

						// выводим информацию о найденных списках задач
						if ($tasksItemsCount == 0) {
							if ($this->config->showLogs) $this->cli->output('<red>No task items list found.</red>');
						} else {
							if ($this->config->showLogs) $this->cli->output("<bold><green>Found {$tasksItemsCount} task items lists.</green></bold>");

							// [OUTPUT] Создаем папку
							if (!is_dir($bookFolderPath)) {
								if ($this->config->showLogs) $this->cli->output("Folder <yellow>{$bookFolderPath}</yellow> not found. Creating...");
								mkdir($bookFolderPath);
							}

							// [OUTPUT] Сохраняем информацию о списке задач в JSON-файл внутрь папки книги
							if ($this->config->showLogs) $this->cli->output("Saving task items lists to <yellow>{$bookFolderPath}\\taskList.json...</yellow>");

							$saveStatus = file_put_contents($bookFolderPath . '\\taskList.json', json_encode(
								$tasksItems,
								JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
							));

							if(!$saveStatus) {
								if ($this->config->showLogs) $this->cli->output("<red>Failed to save task items lists to <yellow>{$bookFolderPath}\\taskList.json!</red>");
							}
						}

						$taskListParsedSuccessfully = true;

						unset(
							$task,
							$tasksItems
						); // очищаем память

						break;
					} catch (AccessDeniedException $ex) {
						if ($this->config->showLogs) $this->cli->red()->out($ex->getMessage());
					} catch (PageNotFoundException $ex) {
						if ($this->config->showLogs) $this->cli->red()->out($ex->getMessage());
					} catch (ParseException $ex) {
						if ($this->config->showLogs) $this->cli->red()->out($ex->getMessage());
					} catch (Exception $ex) {
						if ($this->config->showLogs) $this->cli->red()->out($ex->getMessage());
					}
				}
			}

			if ($taskListParsedSuccessfully) {
				$successTasksListCount++;
			}

			// обновляем счетчик прогресса
			$taskListProgress++;

			// [OUTPUT] обновляем прогрессбар
			if (!$this->config->showLogs) $taskListProgressBar->current($taskListProgress, "Parsing task items lists [{$taskListProgress} / {$totalBooksCount}]");

			unset(
				$bookItem,
				$bookFolderName,
				$bookFolderPath
			); // очищаем память после обработки текущей книги
		}

		// завершаем парсинг списка задач
		if ($this->config->showLogs) {
			$this->cli->br();
			$this->cli->output('<bold><green>Finished parsing task lists.</green></bold>');
			$this->cli->output("<bold><cyan>Total tasks count:</cyan></bold> {$totalTasksCount}");
			$this->cli->output("<bold><green>Success parsed task lists count:</green></bold> {$successTasksListCount}");
			$this->cli->br();
		}

		// начинаем парсить каждую задачу
		if ($this->config->showLogs) $this->cli->output("<bold><green>Start parsing tasks...</green></bold>");
		// счетчики
		$successParsedTasksCount = 0;
		$tasksProgress = 0;

		// [OUTPUT] создаем прогрессбар
		if (!$this->config->showLogs) {
			$tasksProgressBar = $this->cli->progress()->total($totalTasksCount);
			$tasksProgressBar->current(0, "Parsing tasks [0 / {$totalTasksCount}]");
		}

		foreach ($tasksItemsList as $tasksItemsListItem) {
			$currentTasksProgress = $tasksProgress + 1;

			$tasksProgressPercent = $totalTasksCount > 0
				? round($currentTasksProgress / $totalTasksCount * 100)
				: 0; // считаем прогресс в процентах для удобства

			// [OUTPUT] Название файла с задачей
			$outputTaskFileName = "";
			if(is_string($tasksItemsListItem["tasksList"]->chapter)) $outputTaskFileName .= Text::TranslitRef($tasksItemsListItem["tasksList"]->chapter);

			if(is_string($tasksItemsListItem["tasksList"]->url)) $outputTaskFileName .= "_" . Text::GenerateNameFromURL($tasksItemsListItem["tasksList"]->url);

			if(is_string($tasksItemsListItem["tasksList"]->title)) $outputTaskFileName .= "_" . Text::TranslitRef($tasksItemsListItem["tasksList"]->title);

			$outputTaskFileName = trim($outputTaskFileName, "_");
			$outputTaskFileName .= ".json";

			//гарантируем безопасную длину названия файла
			$outputTaskFileName = Text::makeSafeJSONFileName($outputTaskFileName);

			// директория, где будет находиться задача, совпадает с директорией списка задач, т.к. это конечный элемент
			$outputStartURLFolderPath = $tasksItemsListItem["outputPath"]; 
			$outputTaskFullFileName = $outputStartURLFolderPath . self::DIRECTORY_SEPARATOR . $outputTaskFileName;

			$taskParsedSuccessfully = false;

			if (file_exists($outputTaskFullFileName)) {
				// [OUTPUT] Пропускаем, т.к. файл уже существует, и его парсить не требуется
				if ($this->config->showLogs) $this->cli->output("<dim>[{$currentTasksProgress} / {$totalTasksCount}]</dim> <dim>[{$tasksProgressPercent}%]</dim> File <yellow>{$outputTaskFullFileName}</yellow> already exists. Skipping...");

				$taskParsedSuccessfully = true;
			} else {
				for ($attempt = 1; $attempt <= $this->config->attempts; $attempt++) {
					try {
						// парсим задачу
						if ($this->config->showLogs) $this->cli->output("<dim>[{$currentTasksProgress} / {$totalTasksCount}]</dim> <dim>[{$tasksProgressPercent}%]</dim> " . "Parsing task from {$tasksItemsListItem['tasksList']->url}... (Attempt {$attempt} of {$this->config->attempts})");
						$taskInfo = $this->taskParserContext->parse($tasksItemsListItem["tasksList"]->url, $this->getRandomProxy(), $this->config->timeout);

						// обновляем счетчики
						$taskParsedSuccessfully = true;

						if ($this->config->showLogs) $this->cli->output("<bold><green>Parsed successfully.</green></bold>");

						// [OUTPUT] Сохраняем информацию о задаче в JSON-файл
						if ($this->config->showLogs) $this->cli->output("Saving data to <yellow>{$outputTaskFullFileName}</yellow>...");

						$saveStatus = file_put_contents($outputTaskFullFileName, json_encode(
							$taskInfo,
							JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
						));

						if(!$saveStatus) {
							if ($this->config->showLogs) $this->cli->red()->out("Failed to save data to <yellow>{$outputTaskFullFileName}</yellow>");
						}

						unset($taskInfo); // очищаем память после foreach

						break;
					} catch (AccessDeniedException $ex) {
						if ($this->config->showLogs) $this->cli->red()->out($ex->getMessage());
					} catch (PageNotFoundException $ex) {
						if ($this->config->showLogs) $this->cli->red()->out($ex->getMessage());
					} catch (ParseException $ex) {
						if ($this->config->showLogs) $this->cli->red()->out($ex->getMessage());
					} catch (Exception $ex) {
						if ($this->config->showLogs) $this->cli->red()->out($ex->getMessage());
					}
				}
			}

			if ($taskParsedSuccessfully) {
				$successParsedTasksCount++;
			}

			// обновляем счетчик прогресса
			$tasksProgress++;

			// [OUTPUT] обновляем прогрессбар
			if (!$this->config->showLogs) $tasksProgressBar->current($tasksProgress, "Parsing tasks [{$tasksProgress} / {$totalTasksCount}]");

			unset(
				$outputTaskFileName,
				$outputStartURLFolderPath,
				$outputTaskFullFileName
			);
		}

		// завершаем парсинг книг
		if ($this->config->showLogs) {
			$this->cli->br();
			$this->cli->output('<bold><green>Finished parsing tasks.</green></bold>');
			$this->cli->output("<bold><green>Success parsed tasks count:</green></bold> {$successParsedTasksCount}");
			$this->cli->br();
		}
	}
}
