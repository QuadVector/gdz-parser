<?php

namespace Mihairu\GDZParser\BookParser;

use Mihairu\GDZParser\DTO\BookDTO;
use Mihairu\GDZParser\BookParser\BookParserInterface;

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
	 * @return void
	 */
	public function setParser(BookParserInterface $parser): void
	{
		$this->parser = $parser;
	}

	/**
	 * Спарсить книги
	 * @param string $url
	 * @return BookDTO[]
	 */
	public function parse(string $url): array
	{
		return $this->parser->parse($url);
	}
}
