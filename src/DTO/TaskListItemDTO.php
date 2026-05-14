<?php

namespace QuadVector\GDZParser\DTO;

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
		public string $url,
		public ?string $chapter = null
	) {}

	/**
	 * Создать объект из ассоциативного массива
	 * @param array $data
	 * @return TaskListItemDTO
	 */
	public static function fromArray(array $data): self
	{
		return new self(
			$data['title'],
			$data['url'],
			$data['chapter']
		);
	}
}
