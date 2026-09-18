<?php

namespace QuadVector\GDZParser\DTO;

/**
 * DTO задачи внутри списка задач книги.
 */
final class TaskListItemDTO
{
	public function __construct(
		public string $title,
		public string $parse_url,
		public ?string $chapter = null,
		public ?string $group_id = null,
		public ?int $order_number_in_group = null,
		public ?string $book_id = null
	) {
		$this->parse_url = trim($this->parse_url);

		if ($this->parse_url === '') {
			throw new \InvalidArgumentException('parse_url is required.');
		}
	}

	public static function fromArray(array $data): self
	{
		$orderNumber = $data['order_number_in_group'] ?? null;

		return new self(
			title: trim((string)($data['title'] ?? '')),
			parse_url: trim((string)($data['parse_url'] ?? '')),
			chapter: self::normalizeNullableString($data['chapter'] ?? null),
			group_id: self::normalizeNullableString($data['group_id'] ?? null),
			order_number_in_group: $orderNumber !== null
				? (int)$orderNumber
				: null,
			book_id: self::normalizeNullableString($data['book_id'] ?? null)
		);
	}

	private static function normalizeNullableString(mixed $value): ?string
	{
		$value = trim((string)($value ?? ''));

		return $value !== '' ? $value : null;
	}
}
