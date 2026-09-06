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
		public ?string $group_id = null,
		public ?int $order_number_in_group = null,
		public ?string $book_id = null,
		public ?string $parse_url = null
	) {
	}

	public static function fromArray(array $data): self
	{
		$orderNumber =
			$data['order_number_in_group']
			?? $data['group_order_number']
			?? null;

		return new self(
			title: trim((string)($data['title'] ?? '')),
			url: trim((string)($data['url'] ?? '')),
			chapter: self::normalizeNullableString($data['chapter'] ?? null),
			group_id: self::normalizeNullableString(
				$data['group_id']
				?? $data['group_name']
				?? null
			),
			order_number_in_group: $orderNumber !== null
				? (int)$orderNumber
				: null,
			book_id: self::normalizeNullableString($data['book_id'] ?? null),
			parse_url: self::normalizeNullableString($data['parse_url'] ?? null)
		);
	}

	private static function normalizeNullableString(mixed $value): ?string
	{
		$value = trim((string)($value ?? ''));

		return $value !== '' ? $value : null;
	}
}
