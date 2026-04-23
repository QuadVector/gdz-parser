<?php

namespace Mihairu\GDZParser\BookParser;

use Mihairu\GDZParser\DTO\BookDTO;
use Mihairu\GDZParser\Helper\Proxy;

interface BookParserInterface
{
	/**
	 * Выполнить парсинг книг
	 * @param string $url Ссылка на страницу с книгами
	 * @param ?Proxy $proxy Прокси
	 * @param ?int $timeout Таймаут на выполнение одного CURL-запроса
	 * 
	 * @return BookDTO[]
	 */
	public function parse(string $url, ?Proxy $proxy = null, ?int $timeout = null): array;
}
