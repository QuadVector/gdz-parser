<?php

namespace QuadVector\GDZParser\DTO;

/**
 * DTO-класс с информацией о задаче.
 */
final class TaskDTO
{
	/**
	 * @param string      $title Название задачи
	 * @param string      $url Ссылка
	 * @param string      $content Содержимое
	 * @param array       $images Массив изображений в Base64
	 * @param string|null $group_name Группа задачи
	 * @param int|null    $order_number_in_group Позиция внутри группы
	 */
	public function __construct(
		public string $title,
		public string $url,
		public string $content,
		public array $images,
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

			content: trim((string)($data['content'] ?? '')),

			images: is_array($data['images'] ?? null)
				? $data['images']
				: [],

			group_name: isset($data['group_name'])
				? trim((string)$data['group_name'])
				: null,

			order_number_in_group: isset($data['order_number_in_group'])
				? (int)$data['order_number_in_group']
				: null
		);
	}
}
