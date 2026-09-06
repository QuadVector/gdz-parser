<?php

namespace QuadVector\GDZParser\DTO;

use QuadVector\GDZParser\Helper\Text;

/**
 * DTO с информацией о книге.
 */
final class BookDTO
{
	public function __construct(
		public string $title,
		public string $author,
		public string $grade,
		public string $subject,
		public string $url,
		public string $book_id = '',
		public ?string $parse_url = null
	) {
		$this->title = trim($this->title);
		$this->author = trim($this->author);
		$this->grade = trim($this->grade);
		$this->subject = trim($this->subject);
		$this->url = trim($this->url);
		$this->book_id = trim($this->book_id);
		$this->parse_url = self::normalizeNullableString($this->parse_url);

		if ($this->book_id === '' && $this->url !== '') {
			$this->book_id = Text::generateBookId($this->url);
		}
	}

	public static function fromArray(array $data): self
	{
		return new self(
			title: trim((string)($data['title'] ?? '')),
			author: trim((string)($data['author'] ?? '')),
			grade: trim((string)($data['grade'] ?? '')),
			subject: trim((string)($data['subject'] ?? '')),
			url: trim((string)($data['url'] ?? '')),
			book_id: trim((string)($data['book_id'] ?? '')),
			parse_url: self::normalizeNullableString($data['parse_url'] ?? null)
		);
	}

	private static function normalizeNullableString(mixed $value): ?string
	{
		$value = trim((string)($value ?? ''));

		return $value !== '' ? $value : null;
	}
}
