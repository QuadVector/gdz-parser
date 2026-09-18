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

	/**
	 * Извлечь только полезный текст из блока задачи.
	 *
	 * Скрипты, стили, изображения, noindex-дисклеймеры и пустые служебные
	 * заголовки в content не попадают. Изображения обрабатываются отдельно.
	 */
	private function extractMeaningfulText(object $node): string
	{
		$html = trim((string)$node->innerText());

		if ($html === '') {
			return '';
		}

		$html = preg_replace(
			'~<!--.*?-->~su',
			' ',
			$html
		) ?? $html;

		$html = preg_replace(
			'~<(script|style|noscript|template|iframe)\b[^>]*>.*?</\1\s*>~isu',
			' ',
			$html
		) ?? $html;

		$html = preg_replace(
			'~<noindex\b[^>]*>.*?</noindex\s*>~isu',
			' ',
			$html
		) ?? $html;

		/*
         * «Решение #» и «Ответ #» сами по себе не несут данных. При этом
         * содержательные заголовки остаются в тексте.
         */
		$html = preg_replace_callback(
			'~<h([1-6])\b[^>]*>(.*?)</h\1\s*>~isu',
			static function (array $matches): string {
				$heading = Text::cleanupText(
					html_entity_decode(
						strip_tags($matches[2]),
						ENT_QUOTES | ENT_HTML5,
						'UTF-8'
					)
				);

				if (
					$heading === ''
					|| preg_match(
						'/^(?:решение|ответ)\s*(?:#|№)?\s*$/ui',
						$heading
					) === 1
				) {
					return ' ';
				}

				return ' ' . $matches[0] . ' ';
			},
			$html
		) ?? $html;

		/* Не склеиваем текст соседних div/p/br после strip_tags(). */
		$html = preg_replace(
			'~<br\b[^>]*>|</(?:div|p|li|tr|section|article|h[1-6])\s*>~iu',
			"\n",
			$html
		) ?? $html;

		$text = Text::cleanupText(
			html_entity_decode(
				strip_tags($html),
				ENT_QUOTES | ENT_HTML5,
				'UTF-8'
			)
		);

		if ($text === '') {
			return '';
		}

		if (
			preg_match(
				'/^(?:решение|ответ|показать|скрыть)\s*(?:#|№)?\s*$/ui',
				$text
			) === 1
		) {
			return '';
		}

		$meaningfulCharacters = preg_replace(
			'/[\s\p{P}\p{S}]+/u',
			'',
			$text
		);

		return $meaningfulCharacters !== ''
			? $text
			: '';
	}

	/**
	 * Добавить непустую часть content без точных дублей.
	 */
	private function appendContentPart(array &$parts, string $text): void
	{
		$text = trim($text);

		if ($text === '' || in_array($text, $parts, true)) {
			return;
		}

		$parts[] = $text;
	}

	/**
	 * Собрать текст задачи. Сначала сохраняется привычный текст из
	 * text_zad/txt_otvet, затем при наличии добавляется mainInfo.
	 */
	private function extractContent(object $article): string
	{
		$parts = [];

		foreach (['.text_zad', '.txt_otvet'] as $selector) {
			$nodes = $article->findMultiOrFalse($selector);

			if (!$nodes) {
				continue;
			}

			foreach ($nodes as $node) {
				$text = $this->extractMeaningfulText($node);

				/* Сохраняем прежнюю фильтрацию рекламного текста. */
				if (preg_match('/решак|reshak/ui', $text)) {
					continue;
				}

				$this->appendContentPart($parts, $text);
			}
		}

		$mainInfoNodes = $article->findMultiOrFalse('.mainInfo');

		if ($mainInfoNodes) {
			foreach ($mainInfoNodes as $mainInfoNode) {
				$this->appendContentPart(
					$parts,
					$this->extractMeaningfulText($mainInfoNode)
				);
			}
		}

		return implode('<hr>', $parts);
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
				parse_url: $url,
				content: $resultContent,
				images: $resultImages
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

		$resultContent = $this->extractContent($article);

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
			parse_url: $url,
			content: $resultContent,
			images: $resultImages
		);
	}
}
