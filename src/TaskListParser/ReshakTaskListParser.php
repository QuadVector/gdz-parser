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
	 * Получить список задач
	 * @param string $url Ссылка на страницу со списком задач
	 * @return TaskListItemDTO[]
	 */
	public function parse(string $url = '', ?Proxy $proxy = null, ?int $timeout = null): array
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

		// получаем контейнер со списком задач
		$article = $dom->findOneOrFalse('article.lcol');
		if (!$article) {
			unset($dom); // чистим память

			throw new ParseException("Can't find article.lcol on {$url}.");
		}

		$result = [];

		// ===== Вариант 1: старый формат (subtitle + razdel) =====
		$result = $this->parseOldFormat($article);

		// ===== Вариант 2: новый формат (slide-menu-index с sublnk + submenu) =====
		if (empty($result)) {
			$result = $this->parseNewFormat($article);
		}

		// чистим память
		unset($article, $dom);
		if (function_exists('gc_collect_cycles')) {
			gc_collect_cycles();
		}

		return $result;
	}

	/**
	 * Парсинг старого формата: subtitle + razdel
	 * @return TaskListItemDTO[]
	 */
	private function parseOldFormat($article): array
	{
		$result = [];
		$currentChapter = null;

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

					if (!$this->isTaskHref($href)) {
						unset($href, $title); // чистим память
						continue;
					}

					$result[] = new TaskListItemDTO(
						title: $title,
						chapter: $currentChapter,
						url: Text::MakeAbsoluteURL(self::DOMAIN, $href)
					);

					// чистим память
					unset($href, $title);
				}

				unset($links); // чистим память
			}

			unset($classAttr, $classList); // чистим память
		}

		unset($currentChapter); // чистим память

		return $result;
	}


	/**
	 * Парсинг нового формата: #extremum-slide-menu-index с sublnk/submenu и partName/partContent
	 *
	 * Структура:
	 * <ul#slidemenu>
	 *   <li><span class="sublnk">Юнит 1</span></li>        ← глава (chapter)
	 *   <li class="submenu">                                 ← блок задач
	 *     <div class="partName">Step 1:</div>               ← подглава (subchapter)
	 *     <div class="partContent"><a>...</a></div>          ← ссылки на задачи
	 *   </li>
	 *   ...
	 * </ul>
	 *
	 * @return TaskListItemDTO[]
	 */
	private function parseNewFormat($article): array
	{
		$result = [];

		$slideMenu = $article->findOneOrFalse('#slidemenu');
		if (!$slideMenu) {
			// пробуем найти по id контейнера
			$container = $article->findOneOrFalse('#extremum-slide-menu-index');
			if ($container) {
				$slideMenu = $container->findOneOrFalse('ul.reset-index');
			}
			unset($container);
		}

		if (!$slideMenu) {
			return $result;
		}

		$currentChapter = null;

		foreach ($slideMenu->children() as $li) {
			$tag = strtolower((string)$li->tag);
			if ($tag !== 'li') {
				continue;
			}

			$classAttr = (string)($li->getAttribute('class') ?? '');
			$classList = preg_split('/\s+/', trim($classAttr)) ?: [];

			// Элемент с классом "submenu" — блок задач для текущей главы
			if (in_array('submenu', $classList, true)) {
				$this->parseSubmenuBlock($li, $currentChapter, $result);

				unset($classAttr, $classList);
				continue;
			}

			// Элемент без класса "submenu" — ищем span.sublnk (название главы)
			$sublnk = $li->findOneOrFalse('span.sublnk');
			if ($sublnk) {
				$currentChapter = Text::CleanupText($sublnk->plaintext);
				unset($sublnk);
			}

			unset($classAttr, $classList);
		}

		unset($slideMenu, $currentChapter);

		return $result;
	}

	/**
	 * Обработка блока submenu: парсим пары partName/partContent
	 */
	private function parseSubmenuBlock($li, ?string $currentChapter, array &$result): void
	{
		// Внутри submenu ищем div.sublnk1, в котором чередуются partName и блоки ссылок
		$sublnk1 = $li->findOneOrFalse('div.sublnk1');
		if (!$sublnk1) {
			// fallback: ищем блоки напрямую внутри li
			$sublnk1 = $li;
		}

		$currentPartName = null;

		foreach ($sublnk1->children() as $child) {
			$childClassAttr = (string)($child->getAttribute('class') ?? '');
			$childClassList = preg_split('/\s+/', trim($childClassAttr)) ?: [];

			if (in_array('partName', $childClassList, true)) {
				$partText = Text::CleanupText($child->plaintext);
				$currentPartName = rtrim($partText, ':');

				unset($partText, $childClassAttr, $childClassList);
				continue;
			}

			if (
				in_array('partContent', $childClassList, true)
				|| in_array('razdel', $childClassList, true)
			) {
				$this->parseLinksBlock($child, $currentChapter, $currentPartName, $result);
			}

			unset($childClassAttr, $childClassList);
		}

		unset($sublnk1, $currentPartName);
	}

	/**
	 * Парсинг ссылок внутри блока задач
	 */
	private function parseLinksBlock($block, ?string $currentChapter, ?string $currentPartName, array &$result): void
	{
		$links = $block->find('a');

		foreach ($links as $a) {
			$href = trim((string)$a->getAttribute('href'));
			$title = Text::CleanupText($a->plaintext);

			if ($href === '' || $title === '') {
				unset($href, $title);
				continue;
			}

			if (!$this->isTaskHref($href)) {
				unset($href, $title);
				continue;
			}

			$chapter = $this->makeChapterTitle($currentChapter, $currentPartName);

			$result[] = new TaskListItemDTO(
				title: $title,
				chapter: $chapter,
				url: Text::MakeAbsoluteURL(self::DOMAIN, $href)
			);

			unset($href, $title, $chapter);
		}

		unset($links);
	}

	/**
	 * Проверка, что ссылка ведёт на задачу/ответ
	 */
	private function isTaskHref(string $href): bool
	{
		$href = trim($href);

		if ($href === '') {
			return false;
		}

		// Старый/основной формат ответов Reshak
		if (str_starts_with($href, '/otvet/')) {
			return true;
		}

		// Абсолютные ссылки оставляем, как было раньше
		if (preg_match('#^https?://#i', $href)) {
			return true;
		}

		// Прямые ссылки на картинки задач
		if (preg_match('~^/[^?#]+/images/.+\.(?:jpe?g|png|webp|gif)(?:[?#].*)?$~i', $href)) {
			return true;
		}

		return false;
	}

	/**
	 * Формирование названия главы с учётом подраздела
	 */
	private function makeChapterTitle(?string $currentChapter, ?string $currentPartName): ?string
	{
		if ($currentPartName !== null && $currentPartName !== '') {
			return $currentChapter !== null && $currentChapter !== ''
				? $currentChapter . ' — ' . $currentPartName
				: $currentPartName;
		}

		return $currentChapter;
	}
}
