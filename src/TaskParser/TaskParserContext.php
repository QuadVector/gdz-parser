<?php

namespace Mihairu\GDZParser\TaskParser;

use Mihairu\GDZParser\DTO\TaskDTO;
use Mihairu\GDZParser\TaskParser\TaskParserInterface;
use Mihairu\GDZParser\Helper\Proxy;

class TaskParserContext
{
	private TaskParserInterface $parser;

	/**
	 * Конструктор
	 * @param TaskParserInterface $parser Класс, реализующий интерфейс парсера задач TaskParserInterface
	 */
	public function __construct(TaskParserInterface $parser)
	{
		$this->setParser($parser);
	}

	/**
	 * Установить парсер
	 * @param TaskParserInterface $parser Класс, реализующий интерфейс парсера задач TaskParserInterface
	 * @return void
	 */
	public function setParser(TaskParserInterface $parser): void
	{
		$this->parser = $parser;
	}

	/**
	 * Выполнить парсинг задачи
	 * @param string $url Ссылка на страницу с конкретной задачей
	 * @param ?Proxy $proxy Прокси
	 * @param ?int $timeout Таймаут на выполнение одного CURL-запроса
	 * @return TaskDTO
	 */
	public function parse(string $url, ?Proxy $proxy = null, ?int $timeout = null): TaskDTO
	{
		return $this->parser->parse($url, $proxy, $timeout);
	}
}
