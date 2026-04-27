<?php

namespace Mihairu\GDZParser\DTO;

/**
 * DTO-класс с информацией о задаче
 */
final class TaskDTO
{
	/**
	 * Конструктор
	 * @param string $title Название задачи
	 * @param string $url Ссылка на задачу
	 * @param string $content Содержимое
	 * @param string[] $base64Images Массив закодированных в base64 изображений, которые в дальнейшем могут быть сохранены в файл
	 */
	public function __construct(
		public string $title,
		public string $url,
		public string $content,
		public array $base64Images
	) {}
}