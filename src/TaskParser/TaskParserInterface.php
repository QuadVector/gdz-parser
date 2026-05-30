<?php

namespace QuadVector\GDZParser\TaskParser;

use QuadVector\GDZParser\DTO\TaskDTO;
use QuadVector\GDZParser\ValueObject\Proxy;

interface TaskParserInterface
{
	/**
	 * Выполнить парсинг конкретной задачи
	 * @param string $url Ссылка на страницу с задачей
	 * @param ?Proxy $proxy Прокси
	 * @param ?int $timeout Таймаут на выполнение одного CURL-запроса
	 * @return TaskDTO
	 */
	public function parse(string $url, ?Proxy $proxy = null, ?int $timeout = null): TaskDTO;
}
