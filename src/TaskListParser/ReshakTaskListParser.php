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
	 * Получить список задач
	 * @param string $url Ссылка на страницу со списком задач
	 * @return void
	 */
	public function parse(string $url = '', ?Proxy $proxy = null, ?int $timeout = null): array
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

		// получаем контейнер со списком задач
		$article = $dom->findOneOrFalse('article.lcol');
		if (!$article) {
			unset($dom); // чистим память

			throw new ParseException("Can't find article.lcol on {$url}.");
		}

		$result = [];
		$currentChapter = null;

		// последовательно идем по дочерним элементам контейнера
		foreach ($article->children() as $child) {
			$classAttr = (string)($child->getAttribute('class') ?? '');
			$classList = preg_split('/\s+/', trim($classAttr)) ?: [];

			// текущая глава
			if (in_array('subtitle', $classList, true)) {
				$currentChapter = Text::CleanupText($child->plaintext);

				unset($classAttr, $classList); // чистим память
				continue;
			}

			// блок задач
			if (in_array('razdel', $classList, true)) {
				$links = $child->find('a');

				foreach ($links as $a) {
					$href = trim((string)$a->getAttribute('href'));
					$title = Text::CleanupText($a->plaintext);

					// проверка на заполненность данных
					if ($href === '' || $title === '') {
						unset($href, $title); // чистим память
						continue;
					}

					if (
						!str_contains($href, '/otvet/reshebniki.php')
						&& !preg_match('#^https?://#i', $href)
					) {
						unset($href, $title); // чистим память
						continue;
					}

					$result[] = new TaskListItemDTO(
						title: $title,
						chapter: $currentChapter,
						url: $this->MakeAbsoluteURL($href)
					);

					// чистим память
					unset(
						$href,
						$title
					);
				}

				unset($links); // чистим память
			}

			unset($classAttr, $classList); // чистим память
		}

		// чистим память
		unset($article, $currentChapter, $dom);
		if (function_exists('gc_collect_cycles')) {
			gc_collect_cycles();
		}

		return $result;
	}
}
