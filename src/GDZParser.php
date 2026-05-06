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
	protected GDZParserConfig $Config; // класс с конфигурацией
	protected BookParserContext $BookParserContext;
	protected TaskListParserContext $TaskListParserContext;
	protected TaskParserContext $TaskParserContext;

	const DIRECTORY_SEPARATOR = '\\';

	/**
	 * Конструктор
	 * @param GDZParserConfig $Config
	 */
	public function __construct(GDZParserConfig $Config)
	{
		$this->cli = new CLImate();
		$this->Config = $Config;

		// инициализируем стратегии
		$this->BookParserContext = new BookParserContext($this->Config->BookParser);
		$this->TaskListParserContext = new TaskListParserContext($this->Config->TaskListParser);
		$this->TaskParserContext = new TaskParserContext($this->Config->TaskParser);
	}

	/**
	 * Получить рандомный прокси-сервер из конфига
	 * @return Proxy
	 */
	private function getRandomProxy(): Proxy
	{
		return $this->Config->Proxies[array_rand($this->Config->Proxies)];
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
		$startURLsCount = count($this->Config->StartURLs);
		$totalBooksCount = 0;
		$successStartURLsCount = 0;
		$startURLsProgress = 0;

		// проверка на наличие входных URL
		if (count($this->Config->StartURLs) == 0) {
			// [OUTPUT] выводим сообщение об ошибке
			$this->cli->output("<red>Start URLs must not be empty!</red>");
			return;
		}

		// [OUTPUT] создаем прогрессбар
		if (!$this->Config->ShowLogs) {
			$startURLsProgressBar = $this->cli->progress()->total($startURLsCount);
			$startURLsProgressBar->current(0, "Parsing books from start URLs [0 / {$startURLsCount}]");
		}

		// выводим приветствие
		if ($this->Config->ShowLogs) {
			$this->cli->br();
			$this->cli->output('<bold><green>Start parsing...</green></bold>');
			$this->cli->output("<bold><cyan>Start URLs count:</cyan></bold> {$startURLsCount}");
			$this->cli->br();
		}

		// [OUTPUT] создаем папку с выходными данными
		if ($this->Config->ShowLogs) $this->cli->output("Checking output folder...");
		if (!is_dir($this->Config->ParseOutputFolder)) {
			if ($this->Config->ShowLogs) $this->cli->output("Folder <yellow>{$this->Config->ParseOutputFolder}</yellow> not found. Creating...");
			mkdir($this->Config->ParseOutputFolder);
		} else {
			if ($this->Config->ShowLogs) $this->cli->output("Folder <yellow>{$this->Config->ParseOutputFolder}</yellow> found.");
		}

		// начинаем парсить список учебников с входных URL
		if ($this->Config->ShowLogs) $this->cli->output('<bold><green>Parsing books from start URLs...</green></bold>');
		$booksList = []; // список обрабатываемых книг

		foreach ($this->Config->StartURLs as $startURL) {
			$currentStartURLProgress = $startURLsProgress + 1;

			$startURLsProgressPercent = $startURLsCount > 0
				? round($currentStartURLProgress / $startURLsCount * 100)
				: 0; // считаем прогресс в процентах для удобства

			// [OUTPUT] Название папки с текущей ссылкой
			$outputStartURLFolderName = Text::GenerateNameFromURL($startURL);
			$outputStartURLFolderPath = $this->Config->ParseOutputFolder . self::DIRECTORY_SEPARATOR . $outputStartURLFolderName;

			$startURLParsedSuccessfully = false;

			if (is_dir($outputStartURLFolderPath)) {
				// [OUTPUT] Пропускаем и формируем список не из парсера, а из исходных файлов
				if ($this->Config->ShowLogs) $this->cli->output("<dim>[{$currentStartURLProgress} / {$startURLsCount}]</dim> <dim>[{$startURLsProgressPercent}%]</dim> Folder <yellow>{$outputStartURLFolderPath}</yellow> already exists. Skipping...");

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
				for ($attempt = 1; $attempt <= $this->Config->Attempts; $attempt++) {
					try {
						// парсим книги
						if ($this->Config->ShowLogs) $this->cli->output("<dim>[{$currentStartURLProgress} / {$startURLsCount}]</dim> <dim>[{$startURLsProgressPercent}%]</dim> " . "Parsing books from {$startURL}... (Attempt {$attempt} of {$this->Config->Attempts})");
						$books = $this->BookParserContext->parse($startURL, $this->getRandomProxy(), $this->Config->Timeout);

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
							if ($this->Config->ShowLogs) $this->cli->output('<red>No books found.</red>');
						} else {
							if ($this->Config->ShowLogs) $this->cli->output("<bold><green>Found {$booksCount} books.</green></bold>");

							// [OUTPUT] Создаем папку с соответствующей входной ссылкой, куда будет размещены будущие папки и файлы с книгами и задачами
							if ($this->Config->ShowLogs) $this->cli->output("Creating folder <yellow>{$outputStartURLFolderPath}</yellow>...");
							mkdir($outputStartURLFolderPath);

							// [OUTPUT] Сохраняем информацию о книгах в JSON-файл внутрь папки
							if ($this->Config->ShowLogs) $this->cli->output("Saving books info to <yellow>{$outputStartURLFolderPath}\\books.json...</yellow>");
							file_put_contents($outputStartURLFolderPath . '\\books.json', json_encode(
								$books,
								JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
							));
						}

						$startURLParsedSuccessfully = true;

						unset(
							$book,
							$books
						);

						break;
					} catch (AccessDeniedException $ex) {
						if ($this->Config->ShowLogs) $this->cli->red()->out($ex->getMessage());
					} catch (PageNotFoundException $ex) {
						if ($this->Config->ShowLogs) $this->cli->red()->out($ex->getMessage());
					} catch (ParseException $ex) {
						if ($this->Config->ShowLogs) $this->cli->red()->out($ex->getMessage());
					} catch (Exception $ex) {
						if ($this->Config->ShowLogs) $this->cli->red()->out($ex->getMessage());
					}
				}
			}

			if ($startURLParsedSuccessfully) {
				$successStartURLsCount++;
			}

			// обновляем счетчик прогресса
			$startURLsProgress++;

			// [OUTPUT] обновляем прогрессбар
			if (!$this->Config->ShowLogs) $startURLsProgressBar->current($startURLsProgress, "Parsing books from start URLs [{$startURLsProgress} / {$startURLsCount}]");

			unset(
				$startURL,
				$outputStartURLFolderName,
				$outputStartURLFolderPath
			); // очищаем память после обработки текущего URL
		}

		// завершаем парсинг книг
		if ($this->Config->ShowLogs) {
			$this->cli->br();
			$this->cli->output('<bold><green>Finished parsing books.</green></bold>');
			$this->cli->output("<bold><cyan>Total books count:</cyan></bold> {$totalBooksCount}");
			$this->cli->output("<bold><green>Success parsed start URLs count:</green></bold> {$successStartURLsCount}");
			$this->cli->br();
		}

		// начинаем парсинг списков задач
		if ($this->Config->ShowLogs) $this->cli->output('<bold><green>Parsing task items lists...</green></bold>');
		$tasksItemsList = [];

		// счетчики
		$taskListProgress = 0;
		$totalTasksCount = 0;
		$successTasksListCount = 0;

		// [OUTPUT] создаем прогрессбар
		if (!$this->Config->ShowLogs) {
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
				if ($this->Config->ShowLogs) $this->cli->output("<dim>[{$currentTaskListProgress} / {$totalBooksCount}]</dim> <dim>[{$tasksItemsProgressPercent}%]</dim> Task list for book <yellow>{$bookItem['book']->title}</yellow> already parsed. Skipping...");

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
				for ($attempt = 1; $attempt <= $this->Config->Attempts; $attempt++) {
					try {
						if ($this->Config->ShowLogs) $this->cli->output("<dim>[{$currentTaskListProgress} / {$totalBooksCount}]</dim> <dim>[{$tasksItemsProgressPercent}%]</dim> " . "Parsing task list for book <yellow>{$bookItem['book']->title}</yellow> (<bold>URL:</bold> <yellow>{$bookItem['book']->url})</yellow>... (Attempt <yellow>{$attempt}</yellow> of <yellow>{$this->Config->Attempts}</yellow>)");
						$tasksItems = $this->TaskListParserContext->parse($bookItem["book"]->url, $this->getRandomProxy(), $this->Config->Timeout);

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
							if ($this->Config->ShowLogs) $this->cli->output('<red>No task items list found.</red>');
						} else {
							if ($this->Config->ShowLogs) $this->cli->output("<bold><green>Found {$tasksItemsCount} task items lists.</green></bold>");

							// [OUTPUT] Создаем папку
							if (!is_dir($bookFolderPath)) {
								if ($this->Config->ShowLogs) $this->cli->output("Folder <yellow>{$bookFolderPath}</yellow> not found. Creating...");
								mkdir($bookFolderPath);
							}

							// [OUTPUT] Сохраняем информацию о списке задач в JSON-файл внутрь папки книги
							if ($this->Config->ShowLogs) $this->cli->output("Saving task items lists to <yellow>{$bookFolderPath}\\taskList.json...</yellow>");
							file_put_contents($bookFolderPath . '\\taskList.json', json_encode(
								$tasksItems,
								JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
							));
						}

						$taskListParsedSuccessfully = true;

						unset(
							$task,
							$tasksItems
						); // очищаем память

						break;
					} catch (AccessDeniedException $ex) {
						if ($this->Config->ShowLogs) $this->cli->red()->out($ex->getMessage());
					} catch (PageNotFoundException $ex) {
						if ($this->Config->ShowLogs) $this->cli->red()->out($ex->getMessage());
					} catch (ParseException $ex) {
						if ($this->Config->ShowLogs) $this->cli->red()->out($ex->getMessage());
					} catch (Exception $ex) {
						if ($this->Config->ShowLogs) $this->cli->red()->out($ex->getMessage());
					}
				}
			}

			if ($taskListParsedSuccessfully) {
				$successTasksListCount++;
			}

			// обновляем счетчик прогресса
			$taskListProgress++;

			// [OUTPUT] обновляем прогрессбар
			if (!$this->Config->ShowLogs) $taskListProgressBar->current($taskListProgress, "Parsing task items lists [{$taskListProgress} / {$totalBooksCount}]");

			unset(
				$bookItem,
				$bookFolderName,
				$bookFolderPath
			); // очищаем память после обработки текущей книги
		}

		// завершаем парсинг списка задач
		if ($this->Config->ShowLogs) {
			$this->cli->br();
			$this->cli->output('<bold><green>Finished parsing task lists.</green></bold>');
			$this->cli->output("<bold><cyan>Total tasks count:</cyan></bold> {$totalTasksCount}");
			$this->cli->output("<bold><green>Success parsed task lists count:</green></bold> {$successTasksListCount}");
			$this->cli->br();
		}

		// начинаем парсить каждую задачу
		if ($this->Config->ShowLogs) $this->cli->output("<bold><green>Start parsing tasks...</green></bold>");
		// счетчики
		$successParsedTasksCount = 0;
		$tasksProgress = 0;

		// [OUTPUT] создаем прогрессбар
		if (!$this->Config->ShowLogs) {
			$tasksProgressBar = $this->cli->progress()->total($totalTasksCount);
			$tasksProgressBar->current(0, "Parsing tasks [0 / {$totalTasksCount}]");
		}

		foreach ($tasksItemsList as $tasksItemsListItem) {
			$currentTasksProgress = $tasksProgress + 1;

			$tasksProgressPercent = $totalTasksCount > 0
				? round($currentTasksProgress / $totalTasksCount * 100)
				: 0; // считаем прогресс в процентах для удобства

			// [OUTPUT] Название файла с задачей
			if (is_string($tasksItemsListItem["tasksList"]->chapter)) { // глава может отсутствовать
				$outputTaskFileName = Text::TranslitRef($tasksItemsListItem["tasksList"]->chapter) . "_" . Text::GenerateNameFromURL($tasksItemsListItem["tasksList"]->url) . "_" . Text::TranslitRef($tasksItemsListItem["tasksList"]->title); // название файла, который будет сохранен
			} else {
				$outputTaskFileName = Text::GenerateNameFromURL($tasksItemsListItem["tasksList"]->url) . "_" . Text::TranslitRef($tasksItemsListItem["tasksList"]->title); // название файла без главы
			}

			$outputStartURLFolderPath = $tasksItemsListItem["outputPath"]; // директория, где будет находиться задача, совпадает с директорией списка задач, т.к. это конечный элемент
			$outputTaskFullFileName = $outputStartURLFolderPath . self::DIRECTORY_SEPARATOR . $outputTaskFileName . '.json';

			$taskParsedSuccessfully = false;

			if (file_exists($outputTaskFullFileName)) {
				// [OUTPUT] Пропускаем, т.к. файл уже существует, и его парсить не требуется
				if ($this->Config->ShowLogs) $this->cli->output("<dim>[{$currentTasksProgress} / {$totalTasksCount}]</dim> <dim>[{$tasksProgressPercent}%]</dim> File <yellow>{$outputTaskFullFileName}</yellow> already exists. Skipping...");

				$taskParsedSuccessfully = true;
			} else {
				for ($attempt = 1; $attempt <= $this->Config->Attempts; $attempt++) {
					try {
						// парсим задачу
						if ($this->Config->ShowLogs) $this->cli->output("<dim>[{$currentTasksProgress} / {$totalTasksCount}]</dim> <dim>[{$tasksProgressPercent}%]</dim> " . "Parsing task from {$tasksItemsListItem['tasksList']->url}... (Attempt {$attempt} of {$this->Config->Attempts})");
						$taskInfo = $this->TaskParserContext->parse($tasksItemsListItem["tasksList"]->url, $this->getRandomProxy(), $this->Config->Timeout);

						// обновляем счетчики
						$taskParsedSuccessfully = true;

						if ($this->Config->ShowLogs) $this->cli->output("<bold><green>Parsed successfully.</green></bold>");

						// [OUTPUT] Сохраняем информацию о задаче в JSON-файл
						if ($this->Config->ShowLogs) $this->cli->output("Saving data to <yellow>{$outputTaskFullFileName}</yellow>...");

						file_put_contents($outputTaskFullFileName, json_encode(
							$taskInfo,
							JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
						));

						unset($taskInfo); // очищаем память после foreach

						break;
					} catch (AccessDeniedException $ex) {
						if ($this->Config->ShowLogs) $this->cli->red()->out($ex->getMessage());
					} catch (PageNotFoundException $ex) {
						if ($this->Config->ShowLogs) $this->cli->red()->out($ex->getMessage());
					} catch (ParseException $ex) {
						if ($this->Config->ShowLogs) $this->cli->red()->out($ex->getMessage());
					} catch (Exception $ex) {
						if ($this->Config->ShowLogs) $this->cli->red()->out($ex->getMessage());
					}
				}
			}

			if ($taskParsedSuccessfully) {
				$successParsedTasksCount++;
			}

			// обновляем счетчик прогресса
			$tasksProgress++;

			// [OUTPUT] обновляем прогрессбар
			if (!$this->Config->ShowLogs) $tasksProgressBar->current($tasksProgress, "Parsing tasks [{$tasksProgress} / {$totalTasksCount}]");

			unset(
				$outputTaskFileName,
				$outputStartURLFolderPath,
				$outputTaskFullFileName
			);
		}

		// завершаем парсинг книг
		if ($this->Config->ShowLogs) {
			$this->cli->br();
			$this->cli->output('<bold><green>Finished parsing tasks.</green></bold>');
			$this->cli->output("<bold><green>Success parsed tasks count:</green></bold> {$successParsedTasksCount}");
			$this->cli->br();
		}
	}
}
