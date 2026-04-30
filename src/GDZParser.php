<?php

namespace Mihairu\GDZParser;

use Mihairu\GDZParser\GDZParserConfig;
use Mihairu\GDZParser\BookParser\BookParserContext;
use Mihairu\GDZParser\TaskListParser\TaskListParserContext;
use Mihairu\GDZParser\TaskParser\TaskParserContext;
use Mihairu\GDZParser\DTO\BookDTO;
use Mihairu\GDZParser\DTO\TaskListItemDTO;
use Mihairu\GDZParser\Helper\Proxy;
use Mihairu\GDZParser\Helper\Text;
use Mihairu\GDZParser\Exception\AccessDeniedException;
use Mihairu\GDZParser\Exception\PageNotFoundException;
use Mihairu\GDZParser\Exception\ParseException;

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
		$failedStartURLsCount = 0;
		$startURLsProgress = 0;

		// выводим приветствие
		$this->cli->green()->bold()->out('Start parsing...');
		$this->cli->cyan()->out("Start URLs count: " . $startURLsCount);

		// [OUTPUT] создаем папку с выходными данными
		$this->cli->output("Checking output folder...");
		if (!is_dir($this->Config->ParseOutputFolder)) {
			$this->cli->output("Folder {$this->Config->ParseOutputFolder} not found. Creating...");
			mkdir($this->Config->ParseOutputFolder);
		} else {
			$this->cli->output("Folder {$this->Config->ParseOutputFolder} found.");
		}

		// начинаем парсить список учебников с входных URL
		$this->cli->out('Parsing books from start URLs...');
		$booksList = []; // список обрабатываемых книг

		foreach ($this->Config->StartURLs as $startURL) {
			$startURLsProgressPercent = round($startURLsProgress / $startURLsCount * 100); // считаем прогресс в процентах для удобства

			// [OUTPUT] Название папки с текущей ссылкой
			$outputStartURLFolderName = Text::GenerateFolderNameFromURL($startURL);
			$outputStartURLFolderPath = $this->Config->ParseOutputFolder . self::DIRECTORY_SEPARATOR . $outputStartURLFolderName;

			if (is_dir($outputStartURLFolderPath)) {
				// [OUTPUT] Пропускаем и формируем список не из парсера, а из исходных файлов
				$this->cli->output("[{$startURLsProgressPercent}%] Folder {$outputStartURLFolderPath} already exists. Skipping...");

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

					unset($book); // очищаем память после foreach
					unset($parsedBooks); // очищаем память
				}

				// обновляем счетчик прогресса
				$startURLsProgress++;

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

						$this->cli->out("[{$startURLsProgressPercent}%] " . "Parsing books from {$startURL}... (Attempt {$attempt} of {$this->Config->Attempts})");
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
							$this->cli->output("Creating folder {$outputStartURLFolderPath}...");
							mkdir($outputStartURLFolderPath);

							// [OUTPUT] Сохраняем информацию о книгах в JSON-файл внутрь папки
							$this->cli->output("Saving books info to {$outputStartURLFolderPath}\\books.json...");
							file_put_contents($outputStartURLFolderPath . '\\books.json', json_encode(
								// убираем лишние данные, сохраняя только список книг текущего URL
								array_map(function ($book) {
									return $book;
								}, $books),
								JSON_UNESCAPED_UNICODE
							));
						}

						unset($book); // очищаем память после foreach
						unset($books); // очищаем память

						break;
					} catch (AccessDeniedException $ex) {
						$failedStartURLsCount++;
						$this->cli->red()->out($ex->getMessage());
					} catch (PageNotFoundException $ex) {
						$failedStartURLsCount++;
						$this->cli->red()->out($ex->getMessage());
					} catch (ParseException $ex) {
						$failedStartURLsCount++;
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
		$this->cli->green()->bold()->out('Finished parsing books.');
		$this->cli->cyan()->out("Total books count: {$totalBooksCount}");

		// начинаем парсинг списков задач
		$this->cli->green()->bold()->out('Parsing task items lists...');
		$tasksItemsList = [];

		// счетчики
		$taskListProgress = 0;
		$totalTasksListCount = 0;
		$successTasksListCount = 0;
		$failedTasksListCount = 0;

		foreach ($booksList as $bookItem) {
			// [OUTPUT] Название папки с задачами по конкретной книге
			$bookFolderName = Text::TranslitRef($bookItem["book"]->title . "_" . $bookItem["book"]->author);
			$bookFolderPath = $bookItem["outputPath"] . self::DIRECTORY_SEPARATOR . $bookFolderName; // путь к папке с задачами

			// [OUTPUT] Проверяем папку на существование
			if (is_dir($bookFolderPath) && file_exists($bookFolderPath . '\\taskList.json')) {
				$tasksItemsProgressPercent = $totalBooksCount > 0
					? round($taskListProgress / $totalBooksCount * 100)
					: 0;
					
				$this->cli->output("[{$tasksItemsProgressPercent}%] Task list for book \"{$bookItem["book"]->title}\" already parsed. Skipping...");

				// [OUTPUT] восстанавливаем список задач
				$storedTasks = json_decode(file_get_contents($bookFolderPath . '\\taskList.json'), true);

				if (is_array($storedTasks)) {
					$totalTasksListCount += count($storedTasks);
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

				unset($storedTasks); // очищаем память
			} else {
				// Парсим список задач
				for ($attempt = 1; $attempt <= $this->Config->Attempts; $attempt++) {
					try {
						$tasksItemsProgressPercent = $totalBooksCount > 0
							? round($taskListProgress / $totalBooksCount * 100)
							: 0; // считаем прогресс в процентах для удобства

						$this->cli->out("[{$tasksItemsProgressPercent}%] " . "Parsing task list for book \"{$bookItem["book"]->title}\" (URL: {$bookItem["book"]->url})... (Attempt {$attempt} of {$this->Config->Attempts})");
						$tasksItems = $this->TaskListParserContext->parse($bookItem["book"]->url, $this->getRandomProxy(), $this->Config->Timeout);

						// обновляем счетчики
						$tasksItemsCount = count($tasksItems);
						$totalTasksListCount += $tasksItemsCount;
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
								$this->cli->output("Folder {$bookFolderPath} not found. Creating...");
								mkdir($bookFolderPath);
							}

							// [OUTPUT] Сохраняем информацию о списке задач в JSON-файл внутрь папки книги
							$this->cli->output("Saving task items lists to {$bookFolderPath}\\taskList.json...");
							file_put_contents($bookFolderPath . '\\taskList.json', json_encode(
								// сохраняем только список задач текущей книги
								array_map(function ($taskItem) {
									return $taskItem;
								}, $tasksItems),
								JSON_UNESCAPED_UNICODE
							));
						}

						unset($task); // очищаем память после foreach
						unset($tasksItems); // очищаем память

						break;
					} catch (AccessDeniedException $ex) {
						$failedTasksListCount++;
						$this->cli->red()->out($ex->getMessage());
					} catch (PageNotFoundException $ex) {
						$failedTasksListCount++;
						$this->cli->red()->out($ex->getMessage());
					} catch (ParseException $ex) {
						$failedTasksListCount++;
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

		// очищаем память после этапа парсинга списков задач
		unset($booksList);
	}
}
