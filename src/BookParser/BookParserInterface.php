<?php

namespace Mihairu\GDZParser\BookParser;

use Mihairu\GDZParser\DTO\BookDTO;
use Mihairu\GDZParser\Network\Proxy;

interface BookParserInterface
{
	/**
	 * Выполнить парсинг книг
	 * @param string $url Ссылка на страницу с книгами
	 * @param Proxy $proxy Прокси
	 * @return BookDTO[]
	 */
	public function parse(string $url, ?Proxy $proxy = null): array;
}
