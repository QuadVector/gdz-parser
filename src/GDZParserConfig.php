<?php

namespace Mihairu\GDZParser;

use Mihairu\GDZParser\BookParser\BookParserInterface;
use Mihairu\GDZParser\TaskListParser\TaskListParserInterface;
use Mihairu\GDZParser\Helper\Proxy;
use InvalidArgumentException;

final class GDZParserConfig
{
	/**
	 * @param BookParserInterface $BookParser Контекст парсера книг
	 * @param Proxy[] $Proxies Список прокси-серверов
	 * @param string[] $StartURLs Начальные URL, где находятся книги
	 * @param int $Attempts Количество попыток парсинга
	 * @param int $Timeout Таймаут на выполнение одного CURL-запроса
	 */
	public function __construct(
		public readonly BookParserInterface $BookParser,
		public readonly TaskListParserInterface $TaskListParser,
		public readonly array $Proxies = [],
		public readonly array $StartURLs = [],
		public readonly int $Attempts = 5,
		public readonly int $Timeout = 5
	) {
		foreach ($this->Proxies as $proxy) {
			if (!$proxy instanceof Proxy) {
				throw new InvalidArgumentException('Все элементы Proxies должны быть экземплярами Proxy');
			}
		}
	}
}
