<?php

namespace Mihairu\GDZParser\TaskParser;

use Exception;
use Mihairu\GDZParser\TaskParser\TaskParserInterface;
use Mihairu\GDZParser\DTO\TaskDTO;
use Mihairu\GDZParser\Exception\AccessDeniedException;
use Mihairu\GDZParser\Exception\PageNotFoundException;
use Mihairu\GDZParser\Exception\ParseException;
use Mihairu\GDZParser\Helper\CURL;
use Mihairu\GDZParser\Helper\Proxy;
use Mihairu\GDZParser\Helper\Text;
use Mihairu\GDZParser\ValueObject\Base64Image;

use voku\helper\HtmlDomParser;

class ReshakTaskParser implements TaskParserInterface
{
	const DOMAIN = 'reshak.ru';

	/**
	 * Сделать ссылку абсолютной
	 * @param string $href Исходная ссылка
	 * @return string
	 */
	private function MakeAbsoluteURL(string $href): string
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
		$url = Text::MakeAbsoluteURL(self::DOMAIN, $url);

		// получаем HTML-код страницы
		$html = CURL::FileGetContents($url, $proxy, $timeout);

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
			$resultTitle = Text::CleanupText(strip_tags($resultTitleNode->innerText()));
			unset($resultTitleNode); // чистим память
		}

		// получаем текст решения задачи
		$resultContent = "";
		$resultContentNode = $article->findOneOrFalse(".text_zad");

		if ($resultContentNode) {
			$resultContent = Text::CleanupText(strip_tags($resultContentNode->innerText()));
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
				$imageURL = $this->MakeAbsoluteURL($imageURL);

				// загружаем к себе изображение в base64 формате
				try {
					$imageObject = Base64Image::FromURL($imageURL, $proxy, $timeout);
					$resultImages[] = $imageObject;
				} catch (Exception $e) {
					error_log($e->getMessage());
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
