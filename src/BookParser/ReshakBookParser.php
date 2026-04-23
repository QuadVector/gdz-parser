<?php

namespace Mihairu\GDZParser\BookParser;

use Mihairu\GDZParser\BookParser\BookParserInterface;
use Mihairu\GDZParser\DTO\BookDTO;
use Mihairu\GDZParser\Exception\AccessDeniedException;
use Mihairu\GDZParser\Exception\PageNotFoundException;
use Mihairu\GDZParser\Exception\ParseException;
use Mihairu\GDZParser\Helper\CURL;
use Mihairu\GDZParser\Helper\Proxy;
use voku\helper\HtmlDomParser;

class ReshakBookParser implements BookParserInterface
{
	const DOMAIN = "reshak.ru";

	/**
	 * Получить список книг
	 * @param string $url Ссылка на страницу Reshak.ru с книгами
	 * 
	 * @return void
	 */
	public function parse(string $url = "", ?Proxy $proxy = null, ?int $timeout = null): array
	{
		//обработка относительных ссылок
		if (!str_contains($url, self::DOMAIN) && (!str_contains("http://", self::DOMAIN) || !str_contains("https://", self::DOMAIN))) {
			$url .= "https://" . self::DOMAIN . "/" . $url;
			$url = str_replace(self::DOMAIN . "//", self::DOMAIN . "/", $url); //гарантируем только один слеш после домена
		}

		// получаем HTML-код страницы страницы
		$html = CURL::FileGetContents($url, $proxy, $timeout);
		if (!$html) throw new PageNotFoundException("Can't open {$url}.");
		if ($html === "Access Denied") throw new AccessDeniedException("Access denied for {$url}");

		// обрабатываем HTML код и собираем список книг
		$dom = HtmlDomParser::str_get_html($html);
		if (!$dom) {
			throw new ParseException("Can't parse {$url}.");
		}

		$domBooks = $dom->findMultiOrFalse(".list_gdz .main_gdz-div");
		if (!$domBooks) {
			throw new ParseException("Can't find books on {$url}");
		}

		$result = [];

		// обрабатываем HTML код и собираем список книг
		foreach ($domBooks as $bookNode) {
			$linkNode = $bookNode->find("a", 0);
			$titleNode = $bookNode->find(".subjectName", 0);
			$dopTitleNode = $bookNode->find(".dopName", 0);
			$authorNode = $bookNode->find(".author", 0);
			$subjectNode = $bookNode->find(".subject", 0);
			$gradeNode = $bookNode->find(".class-number", 0);

			// все данные по книге должны быть заполнены
			if (!$linkNode || !$titleNode || !$authorNode || !$gradeNode || !$subjectNode) {
				continue;
			}

			$title = trim($titleNode->plaintext);

			if ($dopTitleNode) {
				$title .= ' ' . trim($dopTitleNode->plaintext);
			}

			$result[] = new BookDTO(
				title: $title,
				author: trim($authorNode->plaintext),
				grade: trim($gradeNode->plaintext),
				subject: trim($subjectNode->plaintext),
				url: trim($linkNode->href)
			);
		}

		return $result;
	}
}
