<?php

namespace Mihairu\GDZParser\TaskListParser;

use Mihairu\GDZParser\DTO\TaskListItemDTO;
use Mihairu\GDZParser\Helper\Proxy;

interface TaskListParserInterface
{
	/**
	 * Выполнить парсинг списка задач
	 * @param string $url Ссылка на страницу со списком задач
	 * @param ?Proxy $proxy Прокси
	 * @param ?int $timeout Таймаут на выполнение одного CURL-запроса
	 * 
	 * @return TaskListItemDTO[]
	 */
	public function parse(string $url, ?Proxy $proxy = null, ?int $timeout = null): array;
}
