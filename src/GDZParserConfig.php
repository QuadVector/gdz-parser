<?php

namespace QuadVector\GDZParser;

use QuadVector\GDZParser\BookParser\BookParserInterface;
use QuadVector\GDZParser\TaskListParser\TaskListParserInterface;
use QuadVector\GDZParser\TaskParser\TaskParserInterface;
use QuadVector\GDZParser\Helper\Proxy;
use InvalidArgumentException;

final class GDZParserConfig
{
	/**
	 * @param BookParserInterface $bookParser Контекст парсера книг
	 * @param TaskListParserInterface $taskListParser Контекст парсера списка заданий
	 * @param TaskParserInterface $taskParser Контекст парсера заданий
	 * @param Proxy[] $proxies Список прокси-серверов
	 * @param string[] $startURLs Начальные URL, где находятся книги
	 * @param int $attempts Количество попыток парсинга
	 * @param int $timeout Таймаут на выполнение одного CURL-запроса
	 * @param string $parseOutputFolder Папка, в которую сохранять результаты парсинга
	 * @param bool $showLogs Выводить в консоли дополнительную информацию
	 * @throws InvalidArgumentException
	 */
	public function __construct(
		public readonly BookParserInterface $bookParser,
		public readonly TaskListParserInterface $taskListParser,
		public readonly TaskParserInterface $taskParser,
		public readonly array $proxies = [],
		public readonly array $startURLs = [],
		public readonly int $attempts = 5,
		public readonly int $timeout = 5,
		public readonly string $parseOutputFolder,
		public readonly bool $showLogs = false
	) {
		foreach ($this->proxies as $proxy) {
			if (!$proxy instanceof Proxy) {
				throw new InvalidArgumentException('All proxies must be instances of Proxy class.');
			}

			if(is_null($parseOutputFolder)) {
				throw new InvalidArgumentException('Need to set output folder.');
			}
		}
	}
}
