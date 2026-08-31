<?php

namespace QuadVector\GDZParser\DTO;

/**
 * DTO-класс с информацией о задаче в списке задач.
 */
final class TaskListItemDTO
{
	/**
	 * @param string      $title Название или номер задачи
	 * @param string      $url Ссылка на задачу
	 * @param string|null $chapter Глава
	 * @param string|null $group_name Группа задачи
	 * @param int|null    $order_number_in_group Порядковый номер задачи внутри группы
	 */
	public function __construct(
		public string $title,
		public string $url,
		public ?string $chapter = null,
		public ?string $group_name = null,
		public ?int $order_number_in_group = null
	) {}

	/**
	 * Создать объект из ассоциативного массива.
	 */
	public static function fromArray(array $data): self
	{
		return new self(
			title: trim((string)($data['title'] ?? '')),

			url: trim((string)($data['url'] ?? '')),

			chapter: isset($data['chapter'])
				? trim((string)$data['chapter'])
				: null,

			group_name: isset($data['group_name'])
				? trim((string)$data['group_name'])
				: null,

			order_number_in_group: isset($data['order_number_in_group'])
				? (int)$data['order_number_in_group']
				: null
		);
	}
}
