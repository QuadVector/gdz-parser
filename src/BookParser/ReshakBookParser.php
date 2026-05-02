<?php

namespace QuadVector\GDZParser\BookParser;

use QuadVector\GDZParser\BookParser\BookParserInterface;
use QuadVector\GDZParser\DTO\BookDTO;
use QuadVector\GDZParser\Exception\AccessDeniedException;
use QuadVector\GDZParser\Exception\PageNotFoundException;
use QuadVector\GDZParser\Exception\ParseException;
use QuadVector\GDZParser\Helper\CURL;
use QuadVector\GDZParser\Helper\Proxy;
use QuadVector\GDZParser\Helper\Text;
use voku\helper\HtmlDomParser;

class ReshakBookParser implements BookParserInterface
{
	const DOMAIN = 'reshak.ru';

	/**
	 * Получить список книг
	 * @param string $url Ссылка на страницу с книгами
	 * 
	 * @return void
	 */
	public function parse(string $url = '', ?Proxy $proxy = null, ?int $timeout = null): array
	{
		// обработка относительных ссылок
		$url = Text::MakeAbsoluteURL(self::DOMAIN, $url);

		// получаем HTML-код страницы
		$html = CURL::FileGetContents($url, $proxy, $timeout);

		if (!$html) {
			throw new PageNotFoundException("Can't open {$url}.");
		}

		if ($html === 'Access Denied') {
			throw new AccessDeniedException("Access denied for {$url}");
		}

		// обрабатываем HTML код и собираем список книг
		$dom = HtmlDomParser::str_get_html($html);
		unset($html); // чистим память

		if (!$dom) {
			throw new ParseException("Can't parse {$url}.");
		}

		$domBooks = $dom->findMultiOrFalse('.list_gdz .main_gdz-div');
		if (!$domBooks) {
			unset($dom); // чистим память

			throw new ParseException("Can't find books on {$url}");
		}

		$result = [];

		foreach ($domBooks as $bookNode) {
			$linkNode = $bookNode->find('a', 0);
			$titleNode = $bookNode->find('.subjectName', 0);
			$dopTitleNode = $bookNode->find('.dopName', 0);
			$authorNode = $bookNode->find('.author', 0);
			$subjectNode = $bookNode->find('.subject', 0);
			$gradeNode = $bookNode->find('.class-number', 0);

			if (!$linkNode || !$titleNode || !$authorNode || !$gradeNode || !$subjectNode) {
				// чистим память
				unset(
					$linkNode,
					$titleNode,
					$dopTitleNode,
					$authorNode,
					$subjectNode,
					$gradeNode
				);
				continue;
			}

			$title = Text::CleanupText($titleNode->plaintext);

			if ($dopTitleNode) {
				$title .= ' ' . Text::CleanupText($dopTitleNode->plaintext);
			}

			$result[] = new BookDTO(
				title: $title,
				author: Text::CleanupText($authorNode->plaintext),
				grade: Text::CleanupText($gradeNode->plaintext),
				subject: Text::CleanupText($subjectNode->plaintext),
				url: Text::MakeAbsoluteURL(self::DOMAIN, Text::CleanupText($linkNode->href))
			);

			// чистим память
			unset(
				$linkNode,
				$titleNode,
				$dopTitleNode,
				$authorNode,
				$subjectNode,
				$gradeNode,
				$title
			);
		}

		// чистим память
		unset($domBooks, $dom);
		if (function_exists('gc_collect_cycles')) {
			gc_collect_cycles();
		}

		return $result;
	}
}
