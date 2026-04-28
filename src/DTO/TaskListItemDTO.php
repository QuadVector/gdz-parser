<?php

namespace Mihairu\GDZParser\DTO;

/**
 * DTO-класс с информацией о книге
 */
final class TaskListItemDTO
{
	/**
	 * Конструктор
	 * @param string $title Название или номер задачи
	 * @param string $chapter Глава (если есть)
	 * @param string $url Ссылка на задачу
	 */
	public function __construct(
		public string $title,
		public ?string $chapter = null,
		public string $url
	) {}

	/**
	 * Создать объект из ассоциативного массива
	 * @param array $data
	 * @return TaskListItemDTO
	 */
	public static function FromArray(array $data): self
	{
		return new self(
			$data['title'],
			$data['chapter'],
			$data['url']
		);
	}
}
