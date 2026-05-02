<?php

namespace QuadVector\GDZParser\DTO;

/**
 * DTO-класс с информацией о книге
 */
final class BookDTO
{
	/**
	 * Конструктор
	 * @param string $title Название книги
	 * @param string $author Автор
	 * @param string $grade Класс
	 * @param string $subject Название предмета
	 * @param string $url Ссылка на задачи книги
	 */
	public function __construct(
		public string $title,
		public string $author,
		public string $grade,
		public string $subject,
		public string $url,
	) {}


	/**
	 * Создать объект из ассоциативного массива
	 * @param array $data
	 * @return BookDTO
	 */
	public static function FromArray(array $data): self
	{
		return new self(
			$data['title'],
			$data['author'],
			$data['grade'],
			$data['subject'],
			$data['url'],
		);
	}
}
