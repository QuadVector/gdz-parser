<?php

namespace Mihairu\GDZParser\BookParser;

use Mihairu\GDZParser\DTO\BookDTO;
use Mihairu\GDZParser\BookParser\BookParserInterface;
use Mihairu\GDZParser\Helper\Proxy;

class BookParserContext
{
	private BookParserInterface $parser;

	/**
	 * Конструктор
	 * @param BookParserInterface $parser Класс, реализующий интерфейс парсера книг BookParserInterface
	 */
	public function __construct(BookParserInterface $parser)
	{
		$this->setParser($parser);
	}

	/**
	 * Установить парсер
	 * @param BookParserInterface $parser Класс, реализующий интерфейс парсера книг BookParserInterface
	 * 
	 * @return void
	 */
	public function setParser(BookParserInterface $parser): void
	{
		$this->parser = $parser;
	}

	/**
	 * Выполнить парсинг книг
	 * @param string $url Ссылка на страницу с книгами
	 * @param ?Proxy $proxy Прокси
	 * @param ?int $timeout Таймаут на выполнение одного CURL-запроса
	 * 
	 * @return BookDTO[]
	 */
	public function parse(string $url, ?Proxy $proxy = null, ?int $timeout = null): array
	{
		return $this->parser->parse($url, $proxy, $timeout);
	}
}
