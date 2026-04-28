<?php

namespace Mihairu\GDZParser\Helper;

final class Text
{
	/**
	 * Очистить текст от лишних символов
	 * @param string $text исходный текст
	 * @return string
	 */
	public static function CleanupText(string $text): string
	{
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace('/\x{00A0}/u', ' ', $text);
		$text = preg_replace('/\s+/u', ' ', $text);

		return trim($text);
	}

	/**
	 * Сгенерировать ЧПУ транслит текста
	 * @param string $value Исходный текст
	 * @return string
	 */
	public static function TranslitRef(string $value): string
	{
		$converter = array(
			'а' => 'a',
			'б' => 'b',
			'в' => 'v',
			'г' => 'g',
			'д' => 'd',
			'е' => 'e',
			'ё' => 'e',
			'ж' => 'zh',
			'з' => 'z',
			'и' => 'i',
			'й' => 'y',
			'к' => 'k',
			'л' => 'l',
			'м' => 'm',
			'н' => 'n',
			'о' => 'o',
			'п' => 'p',
			'р' => 'r',
			'с' => 's',
			'т' => 't',
			'у' => 'u',
			'ф' => 'f',
			'х' => 'h',
			'ц' => 'c',
			'ч' => 'ch',
			'ш' => 'sh',
			'щ' => 'sch',
			'ь' => '',
			'ы' => 'y',
			'ъ' => '',
			'э' => 'e',
			'ю' => 'yu',
			'я' => 'ya',
		);

		$value = mb_strtolower($value);
		$value = strtr($value, $converter);
		$value = mb_ereg_replace('[^-0-9a-z]', '-', $value);
		$value = mb_ereg_replace('[-]+', '-', $value);
		$value = trim($value, '-');

		return $value;
	}

	/**
	 * Сгенерировать название папки на основе ссылки
	 * @param string $url
	 * @return string
	 */
	public static function GenerateFolderNameFromURL(string $url): string
	{
		$url = trim($url);

		if ($url === '') {
			return 'default';
		}

		$parts = parse_url($url);

		$host = $parts['host'] ?? '';
		$path = $parts['path'] ?? '';

		// Если URL без схемы, parse_url может положить всё в path
		if ($host === '' && $path !== '') {
			$prepared = parse_url('http://' . ltrim($url, '/'));
			$host = $prepared['host'] ?? '';
			$path = $prepared['path'] ?? '';
		}

		$path = trim($path, '/');

		if ($path !== '') {
			$segments = explode('/', $path);
			$lastIndex = count($segments) - 1;

			// Удаляем расширение у последнего сегмента
			$segments[$lastIndex] = pathinfo($segments[$lastIndex], PATHINFO_FILENAME);

			// Убираем пустые сегменты после обработки
			$segments = array_filter($segments, static fn($segment) => $segment !== '');

			$path = implode('/', $segments);
		}

		$result = trim($host . ($path !== '' ? '/' . $path : ''), '/');

		// Заменяем все неподходящие символы на "_"
		$result = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $result);

		// Убираем повторяющиеся "_"
		$result = preg_replace('/_+/', '_', $result);

		$result = trim($result, '._-');
		$result = mb_substr($result, 0, 100);

		return $result !== '' ? $result : 'default';
	}

	/**
	 * Сделать относительную ссылку абсолютной
	 * @param string $domain Исходный домен (без протокола и слэша в конце)
	 * @param string $href Относительная ссылка
	 * @return string
	 */
	public static function MakeAbsoluteURL(string $domain, string $href): string
	{
		$domain = trim($domain);
		$href = trim($href);

		if ($href === '') {
			return '';
		}

		// Уже абсолютная ссылка
		if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $href)) {
			return $href;
		}

		// Protocol-relative ссылка
		if (strpos($href, '//') === 0) {
			return 'https:' . $href;
		}

		return 'https://' . rtrim($domain, '/') . '/' . ltrim($href, '/');
	}
}
