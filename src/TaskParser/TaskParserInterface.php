<?php

namespace Mihairu\GDZParser\TaskParser;

use Mihairu\GDZParser\DTO\TaskDTO;
use Mihairu\GDZParser\Helper\Proxy;

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
