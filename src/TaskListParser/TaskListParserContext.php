<?php

namespace Mihairu\GDZParser\TaskListParser;

use Mihairu\GDZParser\DTO\TaskListItemDTO;
use Mihairu\GDZParser\TaskListParser\TaskListParserInterface;
use Mihairu\GDZParser\Helper\Proxy;

class TaskListParserContext
{
	private TaskListParserInterface $parser;

	/**
	 * Конструктор
	 * @param TaskListParserInterface $parser Класс, реализующий интерфейс парсера списка задач TaskListParserInterface
	 */
	public function __construct(TaskListParserInterface $parser)
	{
		$this->setParser($parser);
	}

	/**
	 * Установить парсер
	 * @param TaskListParserInterface $parser Класс, реализующий интерфейс парсера списка задач TaskListParserInterface
	 * 
	 * @return void
	 */
	public function setParser(TaskListParserInterface $parser): void
	{
		$this->parser = $parser;
	}

	/**
	 * Выполнить парсинг списка задач
	 * @param string $url Ссылка на страницу с списком задач
	 * @param ?Proxy $proxy Прокси
	 * @param ?int $timeout Таймаут на выполнение одного CURL-запроса
	 * 
	 * @return TaskListItemDTO[]
	 */
	public function parse(string $url, ?Proxy $proxy = null, ?int $timeout = null): array
	{
		return $this->parser->parse($url, $proxy, $timeout);
	}
}
