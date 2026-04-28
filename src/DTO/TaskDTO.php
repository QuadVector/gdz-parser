<?php

namespace Mihairu\GDZParser\DTO;

use Mihairu\GDZParser\ValueObject\Base64Image;

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
	 * @param Base64Image[] $images Массив закодированных в base64 изображений
	 */
	public function __construct(
		public string $title,
		public string $url,
		public string $content,
		public array $images
	) {}
}