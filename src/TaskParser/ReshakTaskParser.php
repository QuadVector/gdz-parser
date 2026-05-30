<?php

namespace QuadVector\GDZParser\TaskParser;

use Exception;
use QuadVector\GDZParser\TaskParser\TaskParserInterface;
use QuadVector\GDZParser\DTO\TaskDTO;
use QuadVector\GDZParser\Exception\AccessDeniedException;
use QuadVector\GDZParser\Exception\PageNotFoundException;
use QuadVector\GDZParser\Exception\ParseException;
use QuadVector\GDZParser\Helper\CURL;
use QuadVector\GDZParser\ValueObject\Proxy;
use QuadVector\GDZParser\Helper\Text;
use QuadVector\GDZParser\ValueObject\Base64Image;

use voku\helper\HtmlDomParser;

class ReshakTaskParser implements TaskParserInterface
{
	const DOMAIN = 'reshak.ru';

	/**
	 * Сделать ссылку абсолютной
	 * @param string $href Исходная ссылка
	 * @return string
	 */
	private function makeAbsoluteURL(string $href): string
	{
		$href = trim($href);

		if ($href === '') {
			return '';
		}

		if (preg_match('#^https?://#i', $href)) {
			return $href;
		}

		return 'https://' . self::DOMAIN . '/' . ltrim($href, '/');
	}

	/**
	 * Получить информацию о задаче
	 * @param string $url Ссылка на страницу с конкретной задачей
	 * 
	 * @return TaskDTO
	 */
	public function parse(string $url = '', ?Proxy $proxy = null, ?int $timeout = null): TaskDTO
	{
		// обработка относительных ссылок
		$url = Text::makeAbsoluteURL(self::DOMAIN, $url);

		// если ссылка оказывается картинкой, то тогда просто извлекаем картинку
		if (CURL::isURLImage($url)) {
			$resultTitle = "";
			$resultContent = "";
			$resultImages = [
				Base64Image::fromURL($url, $proxy, $timeout)
			];
		} else {

			// получаем HTML-код страницы
			$html = CURL::fileGetContents($url, $proxy, $timeout);

			if (!$html) {
				throw new PageNotFoundException("Can't open {$url}.");
			}

			if ($html === 'Access Denied') {
				throw new AccessDeniedException("Access denied for {$url}");
			}

			// обрабатываем HTML код
			$dom = HtmlDomParser::str_get_html($html);
			unset($html); // чистим память

			if (!$dom) {
				throw new ParseException("Can't parse {$url}.");
			}

			// получаем контейнер с содержимым задачи
			$article = $dom->findOneOrFalse('article.lcol');
			if (!$article) {
				unset($dom); // чистим память

				throw new ParseException("Can't find article.lcol on {$url}.");
			}

			// получаем заголовок задачи
			$resultTitle = "";

			$resultTitleNode = $article->findOneOrFalse(".titleh1");
			if ($resultTitleNode) {
				$resultTitle = Text::cleanupText(strip_tags($resultTitleNode->innerText()));
				unset($resultTitleNode); // чистим память
			}

			// получаем текст решения задачи
			$resultContent = "";
			$resultContentNode = $article->findOneOrFalse(".text_zad");

			if ($resultContentNode) {
				$resultContent = Text::cleanupText(strip_tags($resultContentNode->innerText()));
				unset($resultContentNode); // чистим память
			}

			// получаем изображения, которые могут содержать решение задачи
			$resultImages = [];
			$resultImagesNodes = $article->findMultiOrFalse("div[class*='pic_otvet'] img");
			if ($resultImagesNodes) {
				foreach ($resultImagesNodes as $image) {
					// получаем ссылку на изображение
					$imageURL = $image->getAttribute("src");
					if (empty($imageURL)) {
						$imageURL = $image->getAttribute("data-src");
					}
					$imageURL = $this->makeAbsoluteURL($imageURL);

					// загружаем к себе изображение в base64 формате
					try {
						$imageObject = Base64Image::fromURL($imageURL, $proxy, $timeout);
						$resultImages[] = $imageObject->getBase64();
					} catch (Exception $e) {
						error_log($e->getMessage());
					}
				}
			}
		}

		return new TaskDTO(
			title: $resultTitle,
			url: $url,
			content: $resultContent,
			images: $resultImages
		);
	}
}
