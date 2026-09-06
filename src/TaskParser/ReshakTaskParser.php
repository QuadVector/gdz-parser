<?php

namespace QuadVector\GDZParser\TaskParser;

use Exception;
use QuadVector\GDZParser\DTO\TaskDTO;
use QuadVector\GDZParser\Exception\AccessDeniedException;
use QuadVector\GDZParser\Exception\PageNotFoundException;
use QuadVector\GDZParser\Exception\ParseException;
use QuadVector\GDZParser\Helper\CURL;
use QuadVector\GDZParser\Helper\Text;
use QuadVector\GDZParser\ValueObject\Base64Image;
use QuadVector\GDZParser\ValueObject\Proxy;
use voku\helper\HtmlDomParser;

class ReshakTaskParser implements TaskParserInterface
{
	const DOMAIN = 'reshak.ru';

	public function __construct(
		private bool $parseImages = true
	) {}

	private function makeAbsoluteURL(string $href): string
	{
		$href = trim($href);

		if ($href === '') {
			return '';
		}

		if (preg_match('#^https?://#i', $href)) {
			return $href;
		}

		return 'https://'
			. self::DOMAIN
			. '/'
			. ltrim($href, '/');
	}

	public function parse(
		string $url = '',
		?Proxy $proxy = null,
		?int $timeout = null
	): TaskDTO {
		$url = Text::makeAbsoluteURL(self::DOMAIN, $url);

		$resultTitle = '';
		$resultContent = '';
		$resultImages = [];

		if (CURL::isURLImage($url)) {
			if ($this->parseImages) {
				try {
					$imageObject = Base64Image::fromURL(
						$url,
						$proxy,
						$timeout
					);

					$resultImages[] = $imageObject->getBase64();

					unset($imageObject);
				} catch (Exception $e) {
					/*
					 * У image task нет другого содержимого. Ошибка загрузки
					 * должна попасть во внешний retry, а не закешироваться
					 * как успешная пустая задача.
					 */
					throw new ParseException(
						"Can't load image task {$url}: " . $e->getMessage(),
						0,
						$e
					);
				}
			}

			return new TaskDTO(
				title: $resultTitle,
				url: $url,
				content: $resultContent,
				images: $resultImages,
				parse_url: $url
			);
		}

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

		$article = $dom->findOneOrFalse('article.lcol');

		if (!$article) {
			unset($dom);

			throw new ParseException("Can't find article.lcol on {$url}.");
		}

		$resultTitleNode = $article->findOneOrFalse('.titleh1');

		if ($resultTitleNode) {
			$resultTitle = Text::cleanupText(
				strip_tags($resultTitleNode->innerText())
			);

			unset($resultTitleNode);
		}

		$resultContentNode = $article->findOneOrFalse('.text_zad');

		// получаем и обрабатываем содержимое исходного текста задачи
		if ($resultContentNode) {
			$resultContent = $resultContentNode->innerText();

			// очищаем рекламные описания
			if (preg_match('/решак|reshak/ui', $resultContent)) {
				$resultContent = "";
			} else {
				$resultContent = Text::cleanupText(
					strip_tags($resultContent)
				);
			}

			unset($resultContentNode);
		}

		if ($this->parseImages) {
			$resultImagesNodes = $article->findMultiOrFalse(
				"div[class*='pic_otvet'] img"
			);

			if ($resultImagesNodes) {
				foreach ($resultImagesNodes as $image) {
					$imageURL = trim(
						(string)$image->getAttribute('src')
					);

					if ($imageURL === '') {
						$imageURL = trim(
							(string)$image->getAttribute('data-src')
						);
					}

					if ($imageURL === '') {
						continue;
					}

					$imageURL = $this->makeAbsoluteURL($imageURL);

					if ($imageURL === '') {
						continue;
					}

					try {
						$imageObject = Base64Image::fromURL(
							$imageURL,
							$proxy,
							$timeout
						);

						$resultImages[] = $imageObject->getBase64();

						unset($imageObject);
					} catch (Exception $e) {
						error_log($e->getMessage());
					}

					unset($imageURL);
				}
			}

			unset($resultImagesNodes);
		}

		unset($article, $dom);

		if (function_exists('gc_collect_cycles')) {
			gc_collect_cycles();
		}

		if (
			$resultTitle === ''
			&& $resultContent === ''
			&& $resultImages === []
		) {
			throw new ParseException(
				"Task page contains no parsed content: {$url}."
			);
		}

		return new TaskDTO(
			title: $resultTitle,
			url: $url,
			content: $resultContent,
			images: $resultImages,
			parse_url: $url
		);
	}
}
