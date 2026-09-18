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
		public string $parse_url,
		public string $book_id = ''
	) {
		$this->title = trim($this->title);
		$this->author = trim($this->author);
		$this->grade = trim($this->grade);
		$this->subject = trim($this->subject);
		$this->parse_url = trim($this->parse_url);
		$this->book_id = trim($this->book_id);

		if ($this->parse_url === '') {
			throw new \InvalidArgumentException('parse_url is required.');
		}

		if ($this->book_id === '') {
			$this->book_id = Text::generateBookId($this->parse_url);
		}
	}

	public static function fromArray(array $data): self
	{
		return new self(
			title: trim((string)($data['title'] ?? '')),
			author: trim((string)($data['author'] ?? '')),
			grade: trim((string)($data['grade'] ?? '')),
			subject: trim((string)($data['subject'] ?? '')),
			parse_url: trim((string)($data['parse_url'] ?? '')),
			book_id: trim((string)($data['book_id'] ?? ''))
		);
	}
}
