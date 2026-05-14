<?php

namespace QuadVector\GDZParser\Helper;

final class Text
{
	/**
	 * Очистить текст от лишних символов
	 * @param string $text исходный текст
	 * @return string
	 */
	public static function cleanupText(string $text): string
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
	public static function translitRef(string $value): string
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
	 * Сгенерировать название на основе ссылки
	 * @param string $url
	 * @return string
	 */
	public static function generateNamefromURL(string $url): string
	{
		$url = trim($url);

		if ($url === '') {
			return 'default';
		}

		$parts = parse_url($url);

		$host = $parts['host'] ?? '';
		$path = $parts['path'] ?? '';
		$query = $parts['query'] ?? '';

		// Если URL без схемы, parse_url может положить всё в path
		if ($host === '' && $path !== '') {
			$prepared = parse_url('http://' . ltrim($url, '/'));

			$host = $prepared['host'] ?? '';
			$path = $prepared['path'] ?? '';
			$query = $prepared['query'] ?? '';
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

		// Добавляем GET-параметры
		if ($query !== '') {
			$query = urldecode($query);

			// Для читаемости заменяем разделители query-строки
			$query = str_replace(
				['&', '='],
				['_', '-'],
				$query
			);

			$result .= '_' . $query;
		}

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
	public static function makeAbsoluteURL(string $domain, string $href): string
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

	/**
	 * Преобразовать имя json-файла таким образом, чтобы
	 * оно всегда соответствовало максимальной длине (255 символов)
	 * @param string $name Исходное имя файла (с расширением)
	 * @return string
	 */
	public static function makeSafeJSONFileName(string $name): string
	{
		$extensionLength = mb_strlen(".json"); // 5 символов для расширения и точки (.json)
		$maxLength = 255;
		$strLen = mb_strlen($name);

		// если слишком длинный текст, генерируем новое уникальное имя, т.к. оно необходимо
		// для уникальности имени при парсинге
		if ($strLen > $maxLength) {
			$md5Name = md5($name);
			$name = mb_substr($name, 0, $maxLength - $extensionLength - mb_strlen($md5Name) - 1) . "_" . $md5Name . ".json"; // -1 для доп. символа подчеркивания
		}
		
		return $name;
	}
}
