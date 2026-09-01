<?php

namespace QuadVector\GDZParser\DTO;

/**
 * DTO задачи внутри списка задач книги.
 */
final class TaskListItemDTO
{
	public function __construct(
		public string $title,
		public string $url,
		public ?string $chapter = null,
		public ?string $group_name = null,
		public ?int $order_number_in_group = null,
		public ?string $book_id = null
	) {}

	/**
	 * Создать объект из массива.
	 */
	public static function fromArray(array $data): self
	{
		/*
         * Поддерживаем оба варианта названия,
         * если ранее использовался group_order_number.
         */
		$orderNumber =
			$data['order_number_in_group']
			?? $data['group_order_number']
			?? null;

		return new self(
			title: trim(
				(string)($data['title'] ?? '')
			),

			url: trim(
				(string)($data['url'] ?? '')
			),

			chapter: isset($data['chapter'])
				? trim(
					(string)$data['chapter']
				)
				: null,

			group_name: isset($data['group_name'])
				? trim(
					(string)$data['group_name']
				)
				: null,

			order_number_in_group: $orderNumber !== null
				? (int)$orderNumber
				: null,

			book_id: isset($data['book_id'])
				? trim(
					(string)$data['book_id']
				)
				: null
		);
	}
}
