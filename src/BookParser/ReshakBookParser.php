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

		foreach ($domBooks as $bookNode) {
			$linkNode = $bookNode->find('a', 0);
			$titleNode = $bookNode->find('.subjectName', 0);
			$dopTitleNode = $bookNode->find('.dopName', 0);
			$authorNode = $bookNode->find('.author', 0);
			$subjectNode = $bookNode->find('.subject', 0);
			$gradeNode = $bookNode->find('.class-number', 0);

			if (!$linkNode || !$titleNode || !$authorNode || !$gradeNode || !$subjectNode) {
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

			$title = Text::cleanupText($titleNode->plaintext);

			if ($dopTitleNode) {
				$title .= ' ' . Text::cleanupText($dopTitleNode->plaintext);
			}

			$bookUrl = Text::makeAbsoluteURL(
				self::DOMAIN,
				Text::cleanupText($linkNode->href)
			);

			$result[] = new BookDTO(
				title: $title,
				author: Text::cleanupText($authorNode->plaintext),
				grade: Text::cleanupText($gradeNode->plaintext),
				subject: Text::cleanupText($subjectNode->plaintext),
				parse_url: $bookUrl,
				book_id: Text::generateBookId($bookUrl)
			);

			unset(
				$linkNode,
				$titleNode,
				$dopTitleNode,
				$authorNode,
				$subjectNode,
				$gradeNode,
				$title,
				$bookUrl
			);
		}

		unset($domBooks, $dom);

		if (function_exists('gc_collect_cycles')) {
			gc_collect_cycles();
		}

		return $result;
	}
}
