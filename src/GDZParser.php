<?php

namespace Mihairu\GDZParser;

use Mihairu\GDZParser\GDZParserConfig;
use Mihairu\GDZParser\BookParser\BookParserContext;
use Mihairu\GDZParser\TaskListParser\TaskListParserContext;
use Mihairu\GDZParser\TaskListParser\TaskParserContext;
use Mihairu\GDZParser\Helper\Proxy;
use Mihairu\GDZParser\Exception\AccessDeniedException;
use Mihairu\GDZParser\Exception\PageNotFoundException;

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

		// начинаем парсить список учебников с входных URL
		// $this->cli->out('Parsing books from start URLs...');
		// $startURLsProgress = 0;
		// foreach ($this->Config->StartURLs as $StartURL) {
		// 	for ($attempt = 1; $attempt <= $this->Config->Attempts; $attempt++) {
		// 		try {
		// 			// парсим книги
		// 			$startURLsProgressPercent = round($startURLsProgress / $startURLsCount * 100); // считаем прогресс в процентах для удобства

		// 			$this->cli->out("[{$startURLsProgressPercent}%] " . "Parsing books from {$StartURL}... (Attempt {$attempt} of {$this->Config->Attempts})");
		// 			$books = $this->BookParserContext->parse($StartURL, $this->getRandomProxy(), $this->Config->Timeout);

		// 			// счетчики
		// 			$booksCount = count($books);
		// 			$totalBooksCount += $booksCount;

		// 			// выводим информацию о найденных книгах
		// 			if ($booksCount == 0) {
		// 				$this->cli->red()->out('No books found.');
		// 			} else {
		// 				$this->cli->green()->bold()->out("Found {$booksCount} books.");
		// 			}

		// 			$successStartURLsCount++;
		// 			$startURLsProgress++;

		// 			break;
		// 		} catch (AccessDeniedException $ex) {
		// 			$failedStartURLsCount++;
		// 			$this->cli->red()->out($ex->getMessage());
		// 		} catch (PageNotFoundException $ex) {
		// 			$failedStartURLsCount++;
		// 			$this->cli->red()->out($ex->getMessage());
		// 		} catch (ParseException $ex) {
		// 			$failedStartURLsCount++;
		// 			$this->cli->red()->out($ex->getMessage());
		// 		}
		// 	}
		// }

		// завершаем парсинг книг
		$this->cli->green()->bold()->out('Finished parsing books.');
		$this->cli->cyan()->out("Total books count: {$totalBooksCount}");

		// начинаем парсинг списков задач
		$this->cli->green()->bold()->out('Parsing task lists...');

		var_dump($this->TaskListParserContext->parse('https://reshak.ru/reshebniki/geometriya/10/atanasyan10-11/index.php', $this->getRandomProxy(), $this->Config->Timeout));
	}
}
