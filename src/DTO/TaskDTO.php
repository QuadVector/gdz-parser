<?php

namespace QuadVector\GDZParser\DTO;

use QuadVector\GDZParser\ValueObject\Base64Image;

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

	/**
	 * Создать объект из ассоциативного массива
	 * @param array $data
	 * @return TaskDTO
	 */
	public static function FromArray(array $data): self
	{
		return new self(
			$data['title'],
			$data['url'],
			$data['content'],
			$data['images']
		);
	}
}
