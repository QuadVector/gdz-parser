<?php

namespace Mihairu\GDZParser\TaskListParser;

use Mihairu\GDZParser\TaskListParser\TaskListParserInterface;
use Mihairu\GDZParser\DTO\TaskListItemDTO;
use Mihairu\GDZParser\Exception\AccessDeniedException;
use Mihairu\GDZParser\Exception\PageNotFoundException;
use Mihairu\GDZParser\Exception\ParseException;
use Mihairu\GDZParser\Helper\CURL;
use Mihairu\GDZParser\Helper\Proxy;
use Mihairu\GDZParser\Helper\Text;

use voku\helper\HtmlDomParser;

class ReshakTaskListParser implements TaskListParserInterface
{
	const DOMAIN = "reshak.ru";

	/**
	 * Сделать ссылку абсолютной
	 * @param string $href Исходная ссылка
	 * 
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
	 * Получить список задач
	 * @param string $url Ссылка на страницу Reshak.ru со списком задач
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

		// обрабатываем HTML код и собираем список задач
		$dom = HtmlDomParser::str_get_html($html);
		if (!$dom) {
			throw new ParseException("Can't parse {$url}.");
		}

		$article = $dom->findOneOrFalse('article.lcol');
		if (!$article) {
			throw new ParseException("Can't find article.lcol on {$url}.");
		}

		$result = [];
		$currentChapter = null;

		foreach ($article->children() as $child) {
			$classAttr = (string)($child->getAttribute('class') ?? '');
			$classList = preg_split('/\s+/', trim($classAttr)) ?: [];

			if (in_array('subtitle', $classList, true)) {
				$currentChapter = Text::CleanupText($child->plaintext);
				continue;
			}

			if (in_array('razdel', $classList, true)) {
				foreach ($child->find('a') as $a) {
					$href = trim((string)$a->getAttribute('href'));
					$title = Text::CleanupText($a->plaintext);

					if ($href === '' || $title === '') {
						continue;
					}

					if (!str_contains($href, '/otvet/reshebniki.php') && !preg_match('#^https?://#i', $href)) {
						continue;
					}

					$result[] = new TaskListItemDTO(
						title: $title,
						chapter: $currentChapter,
						url: $this->MakeAbsoluteURL($href)
					);
				}
			}
		}

		return $result;
	}
}
