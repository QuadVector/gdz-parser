<?php

namespace QuadVector\GDZParser\BookParser;

use QuadVector\GDZParser\DTO\BookDTO;
use QuadVector\GDZParser\Exception\AccessDeniedException;
use QuadVector\GDZParser\Exception\PageNotFoundException;
use QuadVector\GDZParser\Exception\ParseException;
use QuadVector\GDZParser\Helper\CURL;
use QuadVector\GDZParser\Helper\Text;
use QuadVector\GDZParser\ValueObject\Proxy;
use voku\helper\HtmlDomParser;

class ReshakBookParser implements BookParserInterface
{
	const DOMAIN = 'reshak.ru';

	/**
	 * @return BookDTO[]
	 */
	public function parse(
		string $url = '',
		?Proxy $proxy = null,
		?int $timeout = null
	): array {
		$url = Text::makeAbsoluteURL(self::DOMAIN, $url);
		$html = CURL::fileGetContents($url, $proxy, $timeout);

		if (!$html) {
			throw new PageNotFoundException("Can't open {$url}.");
		}

		if ($html === 'Access Denied') {
			throw new AccessDeniedException("Access denied for {$url}");
		}

		$dom = HtmlDomParser::str_get_html($html);
		unset($html);

		if (!$dom) {
			throw new ParseException("Can't parse {$url}.");
		}

		$domBooks = $dom->findMultiOrFalse('.list_gdz .main_gdz-div');

		if (!$domBooks) {
			unset($dom);

			throw new ParseException("Can't find books on {$url}");
		}

		$result = [];

		// парсим заголовок страницы и определяем класс и предметы
		$pageTitleNode = $dom->find('h1', 0);
		$pageGrade = null;

		if ($pageTitleNode) {
			$pageTitle = Text::cleanupText($pageTitleNode->plaintext);

			if (preg_match('~(\d{1,2})\s*класс~ui', $pageTitle, $matches)) {
				$pageGrade = $matches[1];
			}
		}

		// формируем карту предметов по коду и названию для извлечения информации о предмете книги
		$subjectMap = [];
		$subjectNodes = $dom->findMultiOrFalse('.subject-tabs-item[data-subject]');

		if ($subjectNodes) {
			foreach ($subjectNodes as $subjectNode) {
				$code = trim((string) $subjectNode->getAttribute('data-subject'));
				$name = Text::cleanupText($subjectNode->plaintext);

				if ($code !== '' && $code !== 'all' && $name !== '') {
					$subjectMap[$code] = $name;
				}
			}
		}

		foreach ($domBooks as $bookNode) {
			$linkNode = $bookNode->find('a', 0);
			$titleNode = $bookNode->find('.subjectName', 0);
			$dopTitleNode = $bookNode->find('.dopName', 0);
			$authorNode = $bookNode->find('.author', 0);

			if (!$linkNode || !$titleNode || !$authorNode) {
				continue;
			}

			$title = Text::cleanupText($titleNode->plaintext);

			if ($dopTitleNode) {
				$title .= ' ' . Text::cleanupText($dopTitleNode->plaintext);
			}

			$bookUrl = Text::makeAbsoluteURL(
				self::DOMAIN,
				Text::cleanupText($linkNode->href)
			);

			// определяем класс книги на основе URL
			$grade = $pageGrade;
			$path = parse_url($bookUrl, PHP_URL_PATH);
			if (
				is_string($path)
				&& preg_match(
					'~/reshebniki/[^/]+/(\d{1,2})(?:/|$)~ui',
					$path,
					$matches
				)
			) {
				$grade = $matches[1];
			}

			// определяем предмет книги на основе атрибута data-subject
			$subject = null;

			$subjectCode = trim(
				(string) $bookNode->getAttribute('data-subject')
			);

			if (
				$subjectCode !== ''
				&& isset($subjectMap[$subjectCode])
			) {
				$subject = $subjectMap[$subjectCode];
			}

			$result[] = new BookDTO(
				title: $title,
				author: Text::cleanupText($authorNode->plaintext),
				grade: $grade,
				subject: $subject,
				parse_url: $bookUrl,
				book_id: Text::generateBookId($bookUrl)
			);
		}

		unset($domBooks, $dom);

		if (function_exists('gc_collect_cycles')) {
			gc_collect_cycles();
		}

		return $result;
	}
}
