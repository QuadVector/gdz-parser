<?php

namespace QuadVector\GDZParser\BookParser;

use QuadVector\GDZParser\DTO\BookDTO;
use QuadVector\GDZParser\BookParser\BookParserInterface;
use QuadVector\GDZParser\ValueObject\Proxy;

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
