<?php

namespace Mihairu\GDZParser\DTO;

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
	 * @param string $url Ссылка на задачи книги
	 */
	public function __construct(
		public string $title,
		public string $author,
		public string $grade,
		public string $url,
	) {}
}
