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
	 * @param BookParserInterface $BookParser Контекст парсера книг
	 * @param TaskListParserInterface $TaskListParser Контекст парсера списка заданий
	 * @param TaskParserInterface $TaskParser Контекст парсера заданий
	 * @param Proxy[] $Proxies Список прокси-серверов
	 * @param string[] $StartURLs Начальные URL, где находятся книги
	 * @param int $Attempts Количество попыток парсинга
	 * @param int $Timeout Таймаут на выполнение одного CURL-запроса
	 * @param ?string $ParseOutputFolder Папка, в которую сохранять результаты парсинга
	 * @throws InvalidArgumentException
	 */
	public function __construct(
		public readonly BookParserInterface $BookParser,
		public readonly TaskListParserInterface $TaskListParser,
		public readonly TaskParserInterface $TaskParser,
		public readonly array $Proxies = [],
		public readonly array $StartURLs = [],
		public readonly int $Attempts = 5,
		public readonly int $Timeout = 5,
		public readonly ?string $ParseOutputFolder = null
	) {
		foreach ($this->Proxies as $proxy) {
			if (!$proxy instanceof Proxy) {
				throw new InvalidArgumentException('Все элементы Proxies должны быть экземплярами Proxy');
			}
		}
	}
}
