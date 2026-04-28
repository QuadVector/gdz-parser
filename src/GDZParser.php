<?php

namespace Mihairu\GDZParser;

use Mihairu\GDZParser\GDZParserConfig;
use Mihairu\GDZParser\BookParser\BookParserContext;
use Mihairu\GDZParser\TaskListParser\TaskListParserContext;
use Mihairu\GDZParser\TaskParser\TaskParserContext;
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

		// выводим приветствие
		$this->cli->green()->bold()->out('Start parsing...');
		$this->cli->cyan()->out("Start URLs count: " . $startURLsCount);

		// [OUTPUT] создаем папку с выходными данными
		$this->cli->output("Checking outpuf folder...");
		if (!is_dir($this->Config->ParseOutputFolder)) {
			$this->cli->output("Folder {$this->Config->ParseOutputFolder} not found. Creating...");
			mkdir($this->Config->ParseOutputFolder);
		} else {
			$this->cli->output("Folder {$this->Config->ParseOutputFolder} found.");
		}

		// начинаем парсить список учебников с входных URL
		$this->cli->out('Parsing books from start URLs...');
		$startURLsProgress = 0;
		$booksList = [];
		foreach ($this->Config->StartURLs as $StartURL) {
			// [OUTPUT] Название папки с текущей ссылкой
			$outputStartURLFolderName = Text::GenerateFolderNameFromURL($StartURL);
			$ouputStartURLFolderPath = $this->Config->ParseOutputFolder . '\\' . $outputStartURLFolderName;
			
			if (is_dir($ouputStartURLFolderPath)) {
				$this->cli->output("Folder {$ouputStartURLFolderPath} already exists. Skipping...");
				continue;
			} else {
				for ($attempt = 1; $attempt <= $this->Config->Attempts; $attempt++) {
					try {
						// парсим книги
						$startURLsProgressPercent = round($startURLsProgress / $startURLsCount * 100); // считаем прогресс в процентах для удобства

						$this->cli->out("[{$startURLsProgressPercent}%] " . "Parsing books from {$StartURL}... (Attempt {$attempt} of {$this->Config->Attempts})");
						$books = $this->BookParserContext->parse($StartURL, $this->getRandomProxy(), $this->Config->Timeout);

						// счетчики
						$booksCount = count($books);
						$totalBooksCount += $booksCount;

						// добавляем книги в список
						$booksList = array_merge($booksList, $books);

						// выводим информацию о найденных книгах
						if ($booksCount == 0) {
							$this->cli->red()->out('No books found.');
						} else {
							$this->cli->green()->bold()->out("Found {$booksCount} books.");

							// [OUTPUT] Создаем папку с соответствующей входной ссылкой, куда будет размещены будущие папки и файлы с книгами и задачами
							$this->cli->output("Creating folder {$ouputStartURLFolderPath}...");
							mkdir($ouputStartURLFolderPath);
						}

						$successStartURLsCount++;
						$startURLsProgress++;

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
		}

		// завершаем парсинг книг
		$this->cli->green()->bold()->out('Finished parsing books.');
		$this->cli->cyan()->out("Total books count: {$totalBooksCount}");

		// начинаем парсинг списков задач
		$this->cli->green()->bold()->out('Parsing task lists...');
	}
}
