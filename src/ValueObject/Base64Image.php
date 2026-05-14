<?php

namespace QuadVector\GDZParser\ValueObject;

use QuadVector\GDZParser\Helper\CURL;
use QuadVector\GDZParser\Helper\Proxy;
use QuadVector\GDZParser\Exception\AccessDeniedException;
use QuadVector\GDZParser\Exception\EncodeException;
use QuadVector\GDZParser\Exception\DecodeException;
use \InvalidArgumentException;

class Base64Image
{
	public string $base64;
	private string $mime;

	/**
	 * Конструктор
	 * @param string $base64 Закодированное изображение в base64
	 */
	public function __construct(string $base64)
	{
		// обработка входных данных
		$base64 = trim($base64);

		if ($base64 === '') {
			throw new InvalidArgumentException('Base64 string is empty.');
		}

		$base64 = preg_replace('#^data:image/[a-zA-Z0-9.+-]+;base64,#', '', $base64);

		// декодируем base64
		$binary = base64_decode($base64, true);
		if ($binary === false) {
			throw new DecodeException('Failed to decode base64 data.');
		}

		// проверяем base64 на корректность изображения
		$imageInfo = @getimagesizefromstring($binary);
		if ($imageInfo === false) {
			throw new DecodeException('Decoded data is not a valid image.');
		}

		$this->mime = $imageInfo['mime'] ?? null;
		$allowedMime = [
			'image/png',
			'image/jpeg',
			'image/webp',
			'image/gif',
		];

		if (!in_array($this->mime, $allowedMime, true)) {
			throw new InvalidArgumentException("Unsupported image type: {$this->mime}");
		}

		$this->base64 = $base64;
	}

	/**
	 * Получить изображение в base64 по URL
	 * @param string $url URL изображения
	 * @throws AccessDeniedException
	 * @throws EncodeException
	 * @return Base64Image
	 */
	public static function fromURL(string $url, ?Proxy $proxy = null, ?int $timeout = null): self
	{
		// проверки на корректный URL
		if (!filter_var($url, FILTER_VALIDATE_URL)) {
			throw new InvalidArgumentException("Invalid URL: {$url}");
		}

		$scheme = parse_url($url, PHP_URL_SCHEME);
		if (!in_array($scheme, ['http', 'https'], true)) {
			throw new InvalidArgumentException("Only http/https URLs are allowed: {$url}");
		}

		// получаем содержимое
		$content = CURL::fileGetImage($url, $proxy, $timeout);

		// проверка полученного содержимого на корректность
		if ($content === false) {
			throw new AccessDeniedException("Failed to load content from {$url}");
		}

		if ($content === '') {
			throw new InvalidArgumentException("Empty response from {$url}");
		}

		return new self(
			base64_encode($content)
		);
	}

	/**
	 * Сохранить файл
	 * @param string $path Путь сохранения
	 * @return void
	 */
	public function save(string $path): void
	{
		file_put_contents($path, base64_decode($this->base64));
	}

	/**
	 * Получить Base64 изображение в виде готовой ссылки
	 * @return string
	 */
	public function getBase64URL(): string
	{
		return "data:{$this->mime};base64,{$this->base64}";
	}

	/**
	 * Получить исходный Base64
	 * @return string
	 */
	public function getBase64(): string
	{
		return $this->base64;
	}

	/**
	 * Получить Mime-type текущего изображения
	 * @return string
	 */
	public function getMime(): string
	{
		return $this->mime;
	}
}
