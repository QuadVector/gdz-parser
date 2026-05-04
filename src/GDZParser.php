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
	 * @return void
	 */
	public function run(): void
	{
		// счетчики
		$startURLsCount = count($this->Config->StartURLs);
		$totalBooksCount = 0;
		$successStartURLsCount = 0;
		$startURLsProgress = 0;

		// выводим приветствие
		$this->cli->br();
		$this->cli->out('<bold><green>Start parsing...</green></bold>');
		$this->cli->out("<bold><cyan>Start URLs count:</cyan></bold> {$startURLsCount}");
		$this->cli->br();

		// [OUTPUT] создаем папку с выходными данными
		$this->cli->output("Checking output folder...");
		if (!is_dir($this->Config->ParseOutputFolder)) {
			$this->cli->output("Folder <yellow>{$this->Config->ParseOutputFolder}</yellow> not found. Creating...");
			mkdir($this->Config->ParseOutputFolder);
		} else {
			$this->cli->output("Folder <yellow>{$this->Config->ParseOutputFolder}</yellow> found.");
		}

		// начинаем парсить список учебников с входных URL
		$this->cli->out('<bold><green>Parsing books from start URLs...</green></bold>');
		$booksList = []; // список обрабатываемых книг

		foreach ($this->Config->StartURLs as $startURL) {
			$startURLsProgressPercent = round($startURLsProgress / $startURLsCount * 100); // считаем прогресс в процентах для удобства

			// [OUTPUT] Название папки с текущей ссылкой
			$outputStartURLFolderName = Text::GenerateFolderNameFromURL($startURL);
			$outputStartURLFolderPath = $this->Config->ParseOutputFolder . self::DIRECTORY_SEPARATOR . $outputStartURLFolderName;

			if (is_dir($outputStartURLFolderPath)) {
				// [OUTPUT] Пропускаем и формируем список не из парсера, а из исходных файлов
				$this->cli->output("<dim>[{$startURLsProgress} / {$startURLsCount}]</dim> <dim>[{$startURLsProgressPercent}%]</dim> Folder <yellow>{$outputStartURLFolderPath}</yellow> already exists. Skipping...");

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

				// обновляем счетчик прогресса
				$startURLsProgress++;
				$successStartURLsCount++;

				// очищаем память
				unset(
					$parseFile,
					$parseFiles,
					$outputStartURLFolderName,
					$outputStartURLFolderPath
				);
				continue;
			} else {
				for ($attempt = 1; $attempt <= $this->Config->Attempts; $attempt++) {
					try {
						// парсим книги
						$startURLsProgressPercent = round($startURLsProgress / $startURLsCount * 100); // считаем прогресс в процентах для удобства

						$this->cli->out("<dim>[{$startURLsProgress} / {$startURLsCount}]</dim> <dim>[{$startURLsProgressPercent}%]</dim> " . "Parsing books from {$startURL}... (Attempt {$attempt} of {$this->Config->Attempts})");
						$books = $this->BookParserContext->parse($startURL, $this->getRandomProxy(), $this->Config->Timeout);

						// обновляем счетчики
						$booksCount = count($books);
						$totalBooksCount += $booksCount;
						$successStartURLsCount++;
						$startURLsProgress++;

						// добавляем книги в список
						foreach ($books as $book) {
							$booksList[] = [
								"outputPath" => $outputStartURLFolderPath,
								"book" => $book
							];
						}

						// выводим информацию о найденных книгах
						if ($booksCount == 0) {
							$this->cli->red()->out('No books found.');
						} else {
							$this->cli->green()->bold()->out("Found {$booksCount} books.");

							// [OUTPUT] Создаем папку с соответствующей входной ссылкой, куда будет размещены будущие папки и файлы с книгами и задачами
							$this->cli->output("Creating folder <yellow>{$outputStartURLFolderPath}</yellow>...");
							mkdir($outputStartURLFolderPath);

							// [OUTPUT] Сохраняем информацию о книгах в JSON-файл внутрь папки
							$this->cli->output("Saving books info to <yellow>{$outputStartURLFolderPath}\\books.json...</yellow>");
							file_put_contents($outputStartURLFolderPath . '\\books.json', json_encode(
								$books,
								JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
							));
						}

						unset(
							$book,
							$books
						);

						break;
					} catch (AccessDeniedException $ex) {
						$this->cli->red()->out($ex->getMessage());
					} catch (PageNotFoundException $ex) {
						$this->cli->red()->out($ex->getMessage());
					} catch (ParseException $ex) {
						$this->cli->red()->out($ex->getMessage());
					}
				}
			}

			unset(
				$startURL,
				$outputStartURLFolderName,
				$outputStartURLFolderPath
			); // очищаем память после обработки текущего URL
		}

		// завершаем парсинг книг
		$this->cli->br();
		$this->cli->out('<bold><green>Finished parsing books.</green></bold>');
		$this->cli->out("<bold><cyan>Total books count:</cyan></bold> {$totalBooksCount}");
		$this->cli->out("<bold><green>Success parsed start URLs count:</green></bold> {$successStartURLsCount}");
		$this->cli->br();

		// начинаем парсинг списков задач
		$this->cli->out('<bold><green>Parsing task items lists...</green></bold>');
		$tasksItemsList = [];

		// счетчики
		$taskListProgress = 0;
		$totalTasksCount = 0;
		$successTasksListCount = 0;

		foreach ($booksList as $bookItem) {
			// [OUTPUT] Название папки с задачами по конкретной книге
			$bookFolderName = Text::TranslitRef($bookItem["book"]->title . "_" . $bookItem["book"]->author);
			$bookFolderPath = $bookItem["outputPath"] . self::DIRECTORY_SEPARATOR . $bookFolderName; // путь к папке с задачами

			// [OUTPUT] Проверяем папку на существование
			if (is_dir($bookFolderPath) && file_exists($bookFolderPath . '\\taskList.json')) {
				$tasksItemsProgressPercent = $totalBooksCount > 0
					? round($taskListProgress / $totalBooksCount * 100)
					: 0;

				$this->cli->output("<dim>[{$taskListProgress} / {$totalBooksCount}]</dim> <dim>[{$tasksItemsProgressPercent}%]</dim> Task list for book <yellow>{$bookItem['book']->title}</yellow> already parsed. Skipping...");

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

					unset($taskItem); // очищаем память после foreach
				}

				// обновляем счетчик прогресса
				$taskListProgress++;
				$successTasksListCount++;

				unset($storedTasks); // очищаем память
			} else {
				// Парсим список задач
				for ($attempt = 1; $attempt <= $this->Config->Attempts; $attempt++) {
					try {
						$tasksItemsProgressPercent = $totalBooksCount > 0
							? round($taskListProgress / $totalBooksCount * 100)
							: 0; // считаем прогресс в процентах для удобства

						$this->cli->out("<dim>[{$taskListProgress} / {$totalBooksCount}]</dim> <dim>[{$tasksItemsProgressPercent}%]</dim> " . "Parsing task list for book <yellow>{$bookItem['book']->title}</yellow> (<bold>URL:</bold> <yellow>{$bookItem['book']->url})</yellow>... (Attempt <yellow>{$attempt}</yellow> of <yellow>{$this->Config->Attempts}</yellow>)");
						$tasksItems = $this->TaskListParserContext->parse($bookItem["book"]->url, $this->getRandomProxy(), $this->Config->Timeout);

						// обновляем счетчики
						$tasksItemsCount = count($tasksItems);
						$totalTasksCount += $tasksItemsCount;
						$taskListProgress++;
						$successTasksListCount++;

						// добавляем список задач в общий список
						foreach ($tasksItems as $task) {
							$tasksItemsList[] = [
								"outputPath" => $bookFolderPath,
								"tasksList" => $task
							];
						}

						// выводим информацию о найденных списках задач
						if ($tasksItemsCount == 0) {
							$this->cli->red()->out('No task items list found.');
						} else {
							$this->cli->green()->bold()->out("Found {$tasksItemsCount} task items lists.");

							// [OUTPUT] Создаем папку
							if (!is_dir($bookFolderPath)) {
								$this->cli->output("Folder <yellow>{$bookFolderPath}</yellow> not found. Creating...");
								mkdir($bookFolderPath);
							}

							// [OUTPUT] Сохраняем информацию о списке задач в JSON-файл внутрь папки книги
							$this->cli->output("Saving task items lists to <yellow>{$bookFolderPath}\\taskList.json...</yellow>");
							file_put_contents($bookFolderPath . '\\taskList.json', json_encode(
								$tasksItems,
								JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
							));
						}

						unset(
							$task,
							$tasksItems
						); // очищаем память

						break;
					} catch (AccessDeniedException $ex) {
						$this->cli->red()->out($ex->getMessage());
					} catch (PageNotFoundException $ex) {
						$this->cli->red()->out($ex->getMessage());
					} catch (ParseException $ex) {
						$this->cli->red()->out($ex->getMessage());
					}
				}
			}

			unset(
				$bookItem,
				$bookFolderName,
				$bookFolderPath
			); // очищаем память после обработки текущей книги
		}

		// завершаем парсинг списка задач
		$this->cli->br();
		$this->cli->out('<bold><green>Finished parsing task lists.</green></bold>');
		$this->cli->out("<bold><cyan>Total tasks count:</cyan></bold> {$totalTasksCount}");
		$this->cli->out("<bold><green>Success parsed task lists count:</green></bold> {$successTasksListCount}");
		$this->cli->br();

		// начинаем парсить каждую задачу
		$this->cli->output("<bold><green>Start parsing tasks...</green></bold>");
		// счетчики
		$successParsedTasksCount = 0;
		$tasksProgress = 0;


		foreach ($tasksItemsList as $tasksItemsListItem) {
			$tasksProgressPercent = round($tasksProgress / $totalTasksCount * 100); // считаем прогресс в процентах для удобства

			// [OUTPUT] Название папки с текущей ссылкой
			$outputTaskFolderName = Text::TranslitRef($tasksItemsListItem["tasksList"]->chapter) . "-" . Text::TranslitRef($tasksItemsListItem["tasksList"]->title); // название файла, который будет сохранен
			$outputStartURLFolderPath = $tasksItemsListItem["outputPath"]; // директория, где будет находиться задача, совпадает с директорией списка задач, т.к. это конечный элемент
			$outputTaskFullFileName = $outputStartURLFolderPath . self::DIRECTORY_SEPARATOR . $outputTaskFolderName . '.json';

			if (file_exists($outputTaskFullFileName)) {
				// [OUTPUT] Пропускаем, т.к. файл уже существует, и его парсить не требуется
				$this->cli->output("<dim>[{$tasksProgress} / {$totalTasksCount}]</dim> <dim>[{$tasksProgressPercent}%]</dim> File <yellow>{$outputTaskFullFileName}</yellow> already exists. Skipping...");

				// обновляем счетчик прогресса
				$tasksProgress++;
				$successParsedTasksCount++;
				continue;
			} else {
				for ($attempt = 1; $attempt <= $this->Config->Attempts; $attempt++) {
					try {
						// парсим задачу
						$tasksProgressPercent = round($tasksProgress / $totalTasksCount * 100); // считаем прогресс в процентах для удобства

						$this->cli->out("<dim>[{$tasksProgress} / {$totalTasksCount}]</dim> <dim>[{$tasksProgressPercent}%]</dim> " . "Parsing task from {$tasksItemsListItem["tasksList"]->url}... (Attempt {$attempt} of {$this->Config->Attempts})");
						$taskInfo = $this->TaskParserContext->parse($tasksItemsListItem["tasksList"]->url, $this->getRandomProxy(), $this->Config->Timeout);

						// обновляем счетчики
						$successParsedTasksCount++;
						$tasksProgress++;

						$this->cli->green()->bold()->out("<green>Parsed successfully.</green>");

						// [OUTPUT] Сохраняем информацию о задаче в JSON-файл
						$this->cli->output("Saving data to <yellow>{$outputTaskFullFileName}</yellow>...");

						file_put_contents($outputTaskFullFileName, json_encode(
							$taskInfo,
							JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
						));

						unset($taskInfo); // очищаем память после foreach

						break;
					} catch (AccessDeniedException $ex) {
						$this->cli->red()->out($ex->getMessage());
					} catch (PageNotFoundException $ex) {
						$this->cli->red()->out($ex->getMessage());
					} catch (ParseException $ex) {
						$this->cli->red()->out($ex->getMessage());
					}
				}
			}

			unset(
				$outputTaskFolderName,
				$outputStartURLFolderPath,
				$outputTaskFullFileName
			);
		}

		// завершаем парсинг книг
		$this->cli->br();
		$this->cli->out('<bold><green>Finished parsing tasks.</green></bold>');
		$this->cli->out("<bold><green>Success parsed start URLs count:</green></bold> {$successParsedTasksCount}");
		$this->cli->br();
	}
}
