<?php

namespace Mihairu\GDZParser;

use Mihairu\GDZParser\GDZParserConfig;
use Mihairu\GDZParser\BookParser\BookParserContext;
use Mihairu\GDZParser\Exception\AccessDeniedException;
use Mihairu\GDZParser\Exception\PageNotFoundException;
use Mihairu\GDZParser\Network\Proxy;
use League\CLImate\CLImate;

class GDZParser
{
	protected CLImate $cli;
	protected GDZParserConfig $Config; // класс с конфигурацией

	/**
	 * Конструктор
	 * @param GDZParserConfig $Config
	 */
	public function __construct(GDZParserConfig $Config)
	{
		$this->cli = new CLImate();
		$this->Config = $Config;
	}
	
	/**
	 * Получить рандомный прокси-сервер из конфига
	 * @return Proxy
	 */
	private function getRandomProxy(): Proxy {
		return $this->Config->Proxies[array_rand($this->Config->Proxies)];
	}

	/**
	 * Запустить парсинг
	 * @return void
	 * 
	 * @throws AccessDeniedException
	 * @throws PageNotFoundException
	 */
	public function run(): void
	{
		$this->cli->green()->bold()->out('Start parsing...');

		try {
			//парсим книги
			$books = $this->Config->BookParser->parse("https://reshak.ru/tag/3klass.html", $this->getRandomProxy());

			if(count($books) == 0) {
				$this->cli->red()->out("No books found.");
			} else {
				$this->cli->green()->bold()->out("Found " . count($books) . " books.");
			}
		} catch (AccessDeniedException $ex) {
			$this->cli->red()->out($ex->getMessage());
		} catch (PageNotFoundException $ex) {
			$this->cli->red()->out($ex->getMessage());
		}
	}
}
