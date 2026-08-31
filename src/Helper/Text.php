<?php

namespace QuadVector\GDZParser\Helper;

final class Text
{
	/**
	 * Очистить текст от лишних символов.
	 */
	public static function cleanupText(
		string $text
	): string {
		if ($text === '') {
			return '';
		}

		/*
         * На всякий случай удаляем UTF-8 BOM,
         * если он попал непосредственно в текст.
         */
		$text = preg_replace(
			'/^\xEF\xBB\xBF/',
			'',
			$text
		);

		$text = html_entity_decode(
			$text,
			ENT_QUOTES | ENT_HTML5,
			'UTF-8'
		);

		/*
         * NBSP -> обычный пробел.
         */
		$text = preg_replace(
			'/\x{00A0}/u',
			' ',
			$text
		);

		/*
         * Узкий неразрывный пробел.
         */
		$text = preg_replace(
			'/\x{202F}/u',
			' ',
			$text
		);

		/*
         * Переносы, табы и повторные пробелы
         * превращаем в один пробел.
         */
		$text = preg_replace(
			'/\s+/u',
			' ',
			$text
		);

		return trim(
			(string)$text
		);
	}

	/**
	 * Сгенерировать ЧПУ-транслит текста.
	 */
	public static function translitRef(
		string $value
	): string {
		$value = self::cleanupText(
			$value
		);

		if ($value === '') {
			return 'default';
		}

		$converter = [
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
		];

		$value = mb_strtolower(
			$value,
			'UTF-8'
		);

		$value = strtr(
			$value,
			$converter
		);

		/*
         * Все неподходящие символы заменяем "-".
         */
		$value = preg_replace(
			'/[^a-z0-9]+/u',
			'-',
			$value
		);

		/*
         * Убираем повторные "-".
         */
		$value = preg_replace(
			'/-+/',
			'-',
			(string)$value
		);

		$value = trim(
			(string)$value,
			'-'
		);

		return $value !== ''
			? $value
			: 'default';
	}

	/**
	 * Сгенерировать название на основе ссылки.
	 */
	public static function generateNamefromURL(
		string $url
	): string {
		$url = trim(
			$url
		);

		if ($url === '') {
			return 'default';
		}

		$parts = parse_url(
			$url
		);

		if (!is_array($parts)) {
			return 'default';
		}

		$host =
			$parts['host'] ?? '';

		$path =
			$parts['path'] ?? '';

		$query =
			$parts['query'] ?? '';

		/*
         * Если URL без схемы, parse_url()
         * может положить всё в path.
         */
		if (
			$host === ''
			&& $path !== ''
		) {
			$prepared = parse_url(
				'http://'
					. ltrim($url, '/')
			);

			if (is_array($prepared)) {
				$host =
					$prepared['host'] ?? '';

				$path =
					$prepared['path'] ?? '';

				$query =
					$prepared['query'] ?? '';
			}
		}

		$path = trim(
			$path,
			'/'
		);

		if ($path !== '') {
			$segments = explode(
				'/',
				$path
			);

			$lastIndex =
				count($segments) - 1;

			if (
				$lastIndex >= 0
				&& isset($segments[$lastIndex])
			) {
				$segments[$lastIndex] =
					pathinfo(
						$segments[$lastIndex],
						PATHINFO_FILENAME
					);
			}

			$segments = array_filter(
				$segments,
				static fn($segment): bool =>
				$segment !== ''
			);

			$path = implode(
				'/',
				$segments
			);
		}

		$result = trim(
			$host
				. (
					$path !== ''
					? '/' . $path
					: ''
				),
			'/'
		);

		/*
         * Добавляем GET-параметры.
         */
		if ($query !== '') {
			$query = urldecode(
				$query
			);

			$query = str_replace(
				[
					'&',
					'=',
				],
				[
					'_',
					'-',
				],
				$query
			);

			$result .=
				'_'
				. $query;
		}

		/*
         * Всё неподходящее заменяем "_".
         */
		$result = preg_replace(
			'/[^a-zA-Z0-9._-]+/',
			'_',
			$result
		);

		$result = preg_replace(
			'/_+/',
			'_',
			(string)$result
		);

		$result = trim(
			(string)$result,
			'._-'
		);

		/*
         * Не позволяем этому идентификатору
         * разрастаться бесконечно.
         */
		$result = mb_substr(
			$result,
			0,
			100,
			'UTF-8'
		);

		return $result !== ''
			? $result
			: 'default';
	}

	/**
	 * Сделать относительную ссылку абсолютной.
	 */
	public static function makeAbsoluteURL(
		string $domain,
		string $href
	): string {
		$domain = trim(
			$domain
		);

		$href = trim(
			$href
		);

		if ($href === '') {
			return '';
		}

		/*
         * Уже абсолютная ссылка.
         */
		if (
			preg_match(
				'#^[a-z][a-z0-9+.-]*://#i',
				$href
			)
		) {
			return $href;
		}

		/*
         * Protocol-relative:
         *
         * //example.com/image.jpg
         */
		if (
			str_starts_with(
				$href,
				'//'
			)
		) {
			return 'https:'
				. $href;
		}

		return 'https://'
			. rtrim(
				$domain,
				'/'
			)
			. '/'
			. ltrim(
				$href,
				'/'
			);
	}

	/**
	 * Преобразовать имя JSON-файла так,
	 * чтобы оно не превышало максимальную длину.
	 */
	public static function makeSafeJSONFileName(
		string $name
	): string {
		$extension = '.json';

		$maxLength = 255;

		/*
         * Для имени файла правильнее считать байты,
         * потому что файловые системы обычно ограничивают
         * именно длину компонента пути в байтах.
         */
		if (strlen($name) <= $maxLength) {
			return $name;
		}

		/*
         * Если передали имя с .json —
         * временно убираем расширение.
         */
		if (
			str_ends_with(
				strtolower($name),
				$extension
			)
		) {
			$baseName = substr(
				$name,
				0,
				-strlen($extension)
			);
		} else {
			$baseName = $name;
		}

		$hash = md5(
			$name
		);

		/*
         * Оставляем место под:
         *
         * "_" + md5 + ".json"
         */
		$suffix =
			'_'
			. $hash
			. $extension;

		$availableLength =
			$maxLength
			- strlen($suffix);

		$baseName = substr(
			$baseName,
			0,
			$availableLength
		);

		return $baseName
			. $suffix;
	}

	/**
	 * Был ли передан входной параметр в CLI.
	 */
	public static function cliOptionPassed(
		array $argv,
		string $optionName
	): bool {
		$option =
			'--'
			. $optionName;

		foreach ($argv as $arg) {
			if ($arg === $option) {
				return true;
			}

			if (
				str_starts_with(
					$arg,
					$option . '='
				)
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Преобразует путь в абсолютный.
	 */
	public static function resolvePath(
		string $dir,
		string $path
	): string {
		$path = trim(
			$path
		);

		/*
         * Unix:
         *
         * /var/www/output
         */
		if (
			str_starts_with(
				$path,
				'/'
			)
		) {
			return $path;
		}

		/*
         * Windows:
         *
         * C:\output
         * C:/output
         */
		if (
			preg_match(
				'/^[A-Za-z]:[\/\\\\]/',
				$path
			) === 1
		) {
			return $path;
		}

		return rtrim(
			$dir,
			'/\\'
		)
			. DIRECTORY_SEPARATOR
			. ltrim(
				$path,
				'/\\'
			);
	}
}
