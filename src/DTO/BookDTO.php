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
		public string $book_id = ''
	) {
		$this->title = trim($this->title);
		$this->author = trim($this->author);
		$this->grade = trim($this->grade);
		$this->subject = trim($this->subject);
		$this->url = trim($this->url);
		$this->book_id = trim($this->book_id);

		/*
         * Если ID явно не передан —
         * генерируем его из URL.
         */
		if (
			$this->book_id === ''
			&& $this->url !== ''
		) {
			$this->book_id =
				Text::generateBookId(
					$this->url
				);
		}
	}

	/**
	 * Создать объект из массива.
	 */
	public static function fromArray(array $data): self
	{
		$url = trim(
			(string)($data['url'] ?? '')
		);

		$bookId = trim(
			(string)($data['book_id'] ?? '')
		);

		/*
         * Поддержка старых books.json.
         */
		if (
			$bookId === ''
			&& $url !== ''
		) {
			$bookId =
				Text::generateBookId(
					$url
				);
		}

		return new self(
			title: trim(
				(string)($data['title'] ?? '')
			),

			author: trim(
				(string)($data['author'] ?? '')
			),

			grade: trim(
				(string)($data['grade'] ?? '')
			),

			subject: trim(
				(string)($data['subject'] ?? '')
			),

			url: $url,

			book_id: $bookId
		);
	}
}
