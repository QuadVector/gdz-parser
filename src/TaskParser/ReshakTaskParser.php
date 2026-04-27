<?php

namespace Mihairu\GDZParser\TaskListParser;

use Mihairu\GDZParser\TaskListParser\TaskParserInterface;
use Mihairu\GDZParser\DTO\TaskDTO;
use Mihairu\GDZParser\Exception\AccessDeniedException;
use Mihairu\GDZParser\Exception\PageNotFoundException;
use Mihairu\GDZParser\Exception\ParseException;
use Mihairu\GDZParser\Helper\CURL;
use Mihairu\GDZParser\Helper\Proxy;
use Mihairu\GDZParser\Helper\Text;

use voku\helper\HtmlDomParser;

class ReshakTaskListParser implements TaskParserInterface
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
		if (
			!str_contains($url, self::DOMAIN)
			&& !str_starts_with($url, 'http://')
			&& !str_starts_with($url, 'https://')
		) {
			$url = 'https://' . self::DOMAIN . '/' . ltrim($url, '/');
		}

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

		$resultTitle = "";
		$resultURL = "";
		$resultContent = "";
		$resultBase64Images = [];

		return new TaskDTO(
			title: $resultTitle,
			url: $resultURL,
			content: $resultContent,
			base64Images: $resultBase64Images
		);
	}
}
