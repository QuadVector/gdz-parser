<?php

namespace QuadVector\GDZParser\Helper;

use QuadVector\GDZParser\ValueObject\Proxy;

final class CURL
{
	private static ?\CurlHandle $ch = null;

	/**
	 * Получить через CURL содержимое страницы.
	 *
	 * @param string $url Ссылка на страницу
	 * @param Proxy|null $proxy Прокси-сервер
	 * @param int|null $timeout Таймаут
	 *
	 * @return string|false HTML-код страницы
	 */
	public static function fileGetContents(
		string $url,
		?Proxy $proxy = null,
		?int $timeout = null
	): string|false {
		if (self::$ch === null) {
			self::$ch = curl_init();

			curl_setopt_array(
				self::$ch,
				[
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_AUTOREFERER => true,
					CURLOPT_MAXREDIRS => 5,
					CURLOPT_ENCODING => '',
					CURLOPT_USERAGENT =>
					'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
						. 'AppleWebKit/537.36 (KHTML, like Gecko) '
						. 'Chrome/124.0.0.0 Safari/537.36',

					CURLOPT_HTTPHEADER => [
						'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
						'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
						'Cache-Control: no-cache',
						'Pragma: no-cache',
					],

					CURLOPT_TCP_KEEPALIVE => 1,
					CURLOPT_TCP_KEEPIDLE => 60,
					CURLOPT_TCP_KEEPINTVL => 30,
				]
			);
		}

		curl_setopt(
			self::$ch,
			CURLOPT_URL,
			$url
		);

		curl_setopt(
			self::$ch,
			CURLOPT_TIMEOUT,
			$timeout ?? 30
		);

		// ============================================================
		// PROXY
		// ============================================================

		if (
			$proxy !== null
			&& !empty($proxy->ip)
			&& !empty($proxy->port)
		) {
			curl_setopt(
				self::$ch,
				CURLOPT_PROXY,
				"{$proxy->ip}:{$proxy->port}"
			);

			if (
				!empty($proxy->login)
				&& !empty($proxy->password)
			) {
				curl_setopt(
					self::$ch,
					CURLOPT_PROXYUSERPWD,
					"{$proxy->login}:{$proxy->password}"
				);
			} else {
				curl_setopt(
					self::$ch,
					CURLOPT_PROXYUSERPWD,
					''
				);
			}
		} else {
			/*
             * Очень важно при повторном использовании CURL handle:
             * предыдущий запрос мог использовать прокси.
             */
			curl_setopt(
				self::$ch,
				CURLOPT_PROXY,
				''
			);

			curl_setopt(
				self::$ch,
				CURLOPT_PROXYUSERPWD,
				''
			);
		}

		// ============================================================
		// REQUEST
		// ============================================================

		$data = curl_exec(
			self::$ch
		);

		if ($data === false) {
			return false;
		}

		$httpCode = (int)curl_getinfo(
			self::$ch,
			CURLINFO_HTTP_CODE
		);

		if (
			$httpCode >= 400
			|| $httpCode === 0
		) {
			return false;
		}

		// ============================================================
		// ENCODING
		// ============================================================

		/*
         * Раньше здесь было:
         *
         * mb_convert_encoding(
         *     $data,
         *     'UTF-8',
         *     'windows-1251'
         * );
         *
         * Это ломало страницы, которые УЖЕ приходили в UTF-8.
         *
         * Теперь:
         *
         * 1. Если строка уже валидный UTF-8 — ничего не делаем.
         * 2. Если нет — пытаемся определить кодировку.
         * 3. Только тогда преобразуем в UTF-8.
         */
		$data = self::convertToUtf8(
			$data
		);

		return $data;
	}

	/**
	 * Привести строку к UTF-8.
	 */
	private static function convertToUtf8(
		string $data
	): string {
		/*
         * UTF-8 BOM.
         */
		if (str_starts_with($data, "\xEF\xBB\xBF")) {
			$data = substr(
				$data,
				3
			);
		}

		/*
         * Самый важный случай:
         * строка уже нормальная UTF-8.
         */
		if (
			mb_check_encoding(
				$data,
				'UTF-8'
			)
		) {
			return $data;
		}

		/*
         * Если UTF-8 невалиден, пытаемся определить
         * старую русскую кодировку.
         */
		$encoding = mb_detect_encoding(
			$data,
			[
				'Windows-1251',
				'KOI8-R',
				'ISO-8859-5',
			],
			true
		);

		if ($encoding !== false) {
			return mb_convert_encoding(
				$data,
				'UTF-8',
				$encoding
			);
		}

		/*
         * Для Reshak наиболее вероятный fallback —
         * Windows-1251.
         */
		return mb_convert_encoding(
			$data,
			'UTF-8',
			'Windows-1251'
		);
	}

	/**
	 * Получить изображение по URL.
	 *
	 * ВАЖНО:
	 * бинарные данные изображения никогда нельзя
	 * прогонять через mb_convert_encoding().
	 *
	 * @return string|false
	 */
	public static function fileGetImage(
		string $url,
		?Proxy $proxy = null,
		?int $timeout = null
	): string|false {
		$ch = curl_init(
			$url
		);

		if ($ch === false) {
			return false;
		}

		curl_setopt_array(
			$ch,
			[
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS => 5,
				CURLOPT_TIMEOUT => $timeout ?? 30,
				CURLOPT_ENCODING => '',
				CURLOPT_USERAGENT =>
				'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
					. 'AppleWebKit/537.36 (KHTML, like Gecko) '
					. 'Chrome/124.0.0.0 Safari/537.36',

				CURLOPT_HTTPHEADER => [
					'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
					'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
					'Connection: keep-alive',
				],
			]
		);

		// ============================================================
		// PROXY
		// ============================================================

		if (
			$proxy !== null
			&& !empty($proxy->ip)
			&& !empty($proxy->port)
		) {
			curl_setopt(
				$ch,
				CURLOPT_PROXY,
				$proxy->ip
					. ':'
					. $proxy->port
			);

			if (
				!empty($proxy->login)
				&& !empty($proxy->password)
			) {
				curl_setopt(
					$ch,
					CURLOPT_PROXYUSERPWD,
					$proxy->login
						. ':'
						. $proxy->password
				);
			}
		}

		// ============================================================
		// REQUEST
		// ============================================================

		$data = curl_exec(
			$ch
		);

		if ($data === false) {
			curl_close($ch);

			return false;
		}

		$httpCode = (int)curl_getinfo(
			$ch,
			CURLINFO_HTTP_CODE
		);

		if (
			$httpCode >= 400
			|| $httpCode === 0
		) {
			curl_close($ch);

			return false;
		}

		curl_close($ch);

		return $data;
	}

	/**
	 * Проверить по URL, является ли ссылка изображением.
	 */
	public static function isURLImage(
		string $url
	): bool {
		$extensions = [
			'jpg',
			'jpeg',
			'png',
			'gif',
			'webp',
			'bmp',
		];

		$path = parse_url(
			$url,
			PHP_URL_PATH
		);

		if (!is_string($path)) {
			return false;
		}

		$ext = pathinfo(
			$path,
			PATHINFO_EXTENSION
		);

		return in_array(
			strtolower($ext),
			$extensions,
			true
		);
	}
}
