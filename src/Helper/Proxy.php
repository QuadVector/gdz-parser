<?php

namespace QuadVector\GDZParser\Helper;

use \InvalidArgumentException;

final class Proxy
{
	/**
	 * Конструктор
	 * @param string $host // IP сервера
	 * @param int $port // порт
	 * @param mixed $login // логин
	 * @param mixed $password // пароль
	 */
	public function __construct(
		public readonly string $host,
		public readonly int $port,
		public readonly ?string $login = null,
		public readonly ?string $password = null,
	) {}

	/**
	 * Сгенерировать объект из строки формата ip:port:login:password
	 * @param string $proxy
	 * @throws InvalidArgumentException
	 * @return Proxy
	 */
	public static function fromString(string $proxy): self
	{
		$parts = explode(':', trim($proxy));

		if (count($parts) < 2) {
			throw new InvalidArgumentException("Uncorrect proxy format: {$proxy}. Must be ip:port:login:password");
		}

		return new self(
			host: $parts[0],
			port: (int) $parts[1],
			login: $parts[2] ?? null,
			password: $parts[3] ?? null,
		);
	}
}
