<?php

namespace QuadVector\GDZParser\Helper;

use QuadVector\GDZParser\Helper\Proxy;

final class CURL
{
	private static ?\CurlHandle $ch = null;

	/**
	 * Получить через CURL содержимое страницы
	 * @param string $url Ссылка на страницу
	 * @param ?Proxy $proxy прокси-сервер
	 * @param ?int $timeout таймаут
	 * @return bool|string HTML-код страницы
	 */
	public static function FileGetContents(string $url, ?Proxy $proxy = null, ?int $timeout = null): string|false
	{
		if (self::$ch === null) {
			self::$ch = curl_init();
			curl_setopt_array(self::$ch, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_AUTOREFERER    => true,
				CURLOPT_MAXREDIRS      => 5,
				CURLOPT_ENCODING       => '',
				CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
					. 'AppleWebKit/537.36 (KHTML, like Gecko) '
					. 'Chrome/124.0.0.0 Safari/537.36',
				CURLOPT_HTTPHEADER     => [
					'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
					'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
					'Accept-Encoding: gzip, deflate, br',
					'Connection: keep-alive',
					'Upgrade-Insecure-Requests: 1',
				],
				// ↓ ключевое для скорости
				CURLOPT_TCP_KEEPALIVE  => 1,
				CURLOPT_TCP_KEEPIDLE   => 60,
				CURLOPT_TCP_KEEPINTVL  => 30,
			]);
		}

		curl_setopt(self::$ch, CURLOPT_URL, $url);
		curl_setopt(self::$ch, CURLOPT_TIMEOUT, $timeout ?? 30);

		if ($proxy && $proxy->host && $proxy->port) {
			curl_setopt(self::$ch, CURLOPT_PROXY, "{$proxy->host}:{$proxy->port}");
			if ($proxy->login && $proxy->password) {
				curl_setopt(self::$ch, CURLOPT_PROXYUSERPWD, "{$proxy->login}:{$proxy->password}");
			}
		}

		$data = curl_exec(self::$ch); // получаем данные
		$data = mb_convert_encoding($data, 'UTF-8', 'windows-1251'); // Преобразуем в UTF-8

		return $data;
	}

	/**
	 * Получить изображение по URL
	 *
	 * @param string $url
	 * @param ?Proxy $proxy
	 * @param ?int $timeout
	 * @return string|false Бинарные данные изображения или false при ошибке
	 */
	public static function FileGetImage(string $url, ?Proxy $proxy = null, ?int $timeout = null): string|false
	{
		$ch = curl_init($url);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

		if ($timeout !== null) {
			curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		}

		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Accept: image/*,*/*;q=0.8',
		]);

		if ($proxy !== null) {
			if (!empty($proxy->host) && !empty($proxy->port)) {
				curl_setopt($ch, CURLOPT_PROXY, $proxy->host . ':' . $proxy->port);
			}

			if (!empty($proxy->login) && !empty($proxy->password)) {
				curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy->login . ':' . $proxy->password);
			}
		}

		$data = curl_exec($ch);

		if ($data === false) {
			unset($ch);
			return false;
		}

		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		unset($ch);

		if ($httpCode !== 200) {
			return false;
		}

		return $data;
	}
}
