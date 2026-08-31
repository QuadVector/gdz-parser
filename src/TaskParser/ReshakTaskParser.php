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

	/**
	 * Сделать ссылку абсолютной.
	 */
	private function makeAbsoluteURL(
		string $href
	): string {
		$href = trim($href);

		if ($href === '') {
			return '';
		}

		if (
			preg_match(
				'#^https?://#i',
				$href
			)
		) {
			return $href;
		}

		return 'https://'
			. self::DOMAIN
			. '/'
			. ltrim($href, '/');
	}

	/**
	 * Получить информацию о задаче.
	 */
	public function parse(
		string $url = '',
		?Proxy $proxy = null,
		?int $timeout = null
	): TaskDTO {
		$url = Text::makeAbsoluteURL(
			self::DOMAIN,
			$url
		);

		$resultTitle = '';
		$resultContent = '';
		$resultImages = [];

		// =====================================================
		// Ссылка непосредственно на картинку
		// =====================================================

		if (CURL::isURLImage($url)) {
			/*
             * При parse_images=false ничего не скачиваем.
             */
			if ($this->parseImages) {
				try {
					$imageObject =
						Base64Image::fromURL(
							$url,
							$proxy,
							$timeout
						);

					$resultImages[] =
						$imageObject->getBase64();

					unset($imageObject);
				} catch (Exception $e) {
					error_log(
						$e->getMessage()
					);
				}
			}

			return new TaskDTO(
				title: $resultTitle,
				url: $url,
				content: $resultContent,
				images: $resultImages
			);
		}

		// =====================================================
		// Обычная HTML-страница задачи
		// =====================================================

		$html = CURL::fileGetContents(
			$url,
			$proxy,
			$timeout
		);

		if (!$html) {
			throw new PageNotFoundException(
				"Can't open {$url}."
			);
		}

		if ($html === 'Access Denied') {
			throw new AccessDeniedException(
				"Access denied for {$url}"
			);
		}

		$dom = HtmlDomParser::str_get_html(
			$html
		);

		unset($html);

		if (!$dom) {
			throw new ParseException(
				"Can't parse {$url}."
			);
		}

		$article = $dom->findOneOrFalse(
			'article.lcol'
		);

		if (!$article) {
			unset($dom);

			throw new ParseException(
				"Can't find article.lcol on {$url}."
			);
		}

		// =====================================================
		// Заголовок
		// =====================================================

		$resultTitleNode =
			$article->findOneOrFalse(
				'.titleh1'
			);

		if ($resultTitleNode) {
			$resultTitle =
				Text::cleanupText(
					strip_tags(
						$resultTitleNode->innerText()
					)
				);

			unset($resultTitleNode);
		}

		// =====================================================
		// Текст решения
		// =====================================================

		$resultContentNode =
			$article->findOneOrFalse(
				'.text_zad'
			);

		if ($resultContentNode) {
			$resultContent =
				Text::cleanupText(
					strip_tags(
						$resultContentNode->innerText()
					)
				);

			unset($resultContentNode);
		}

		// =====================================================
		// Изображения
		// =====================================================

		if ($this->parseImages) {
			$resultImagesNodes =
				$article->findMultiOrFalse(
					"div[class*='pic_otvet'] img"
				);

			if ($resultImagesNodes) {
				foreach (
					$resultImagesNodes as $image
				) {
					$imageURL = trim(
						(string)$image->getAttribute(
							'src'
						)
					);

					if ($imageURL === '') {
						$imageURL = trim(
							(string)$image->getAttribute(
								'data-src'
							)
						);
					}

					if ($imageURL === '') {
						continue;
					}

					$imageURL =
						$this->makeAbsoluteURL(
							$imageURL
						);

					if ($imageURL === '') {
						continue;
					}

					try {
						$imageObject =
							Base64Image::fromURL(
								$imageURL,
								$proxy,
								$timeout
							);

						$resultImages[] =
							$imageObject->getBase64();

						unset($imageObject);
					} catch (Exception $e) {
						error_log(
							$e->getMessage()
						);
					}

					unset($imageURL);
				}
			}

			unset($resultImagesNodes);
		}

		unset(
			$article,
			$dom
		);

		if (function_exists('gc_collect_cycles')) {
			gc_collect_cycles();
		}

		return new TaskDTO(
			title: $resultTitle,
			url: $url,
			content: $resultContent,
			images: $resultImages
		);
	}
}
