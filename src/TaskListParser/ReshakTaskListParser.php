<?php

namespace QuadVector\GDZParser\TaskListParser;

use QuadVector\GDZParser\TaskListParser\TaskListParserInterface;
use QuadVector\GDZParser\DTO\TaskListItemDTO;
use QuadVector\GDZParser\Exception\AccessDeniedException;
use QuadVector\GDZParser\Exception\PageNotFoundException;
use QuadVector\GDZParser\Exception\ParseException;
use QuadVector\GDZParser\Helper\CURL;
use QuadVector\GDZParser\ValueObject\Proxy;
use QuadVector\GDZParser\Helper\Text;

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
		$url = Text::makeAbsoluteURL(self::DOMAIN, $url);

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
	 * @param object $article Объект Simple PHP DOM
	 * @return TaskListItemDTO[]
	 */
	/**
	 * Парсинг старого формата: subtitle + razdel
	 * @param object $article Объект Simple PHP DOM
	 * @return TaskListItemDTO[]
	 */
	private function parseOldFormat(object $article): array
	{
		$result = [];

		$currentChapter = null;

		// Родительская глава для ситуации:
		// <div class="subtitle">Задачи для подготовки к ЕГЭ.</div>
		// <div class="subtitle">Задание 3.</div>
		$parentChapter = null;

		// Последний "чистый" subtitle без родителя.
		// Нужен, чтобы при двух subtitle подряд сделать первый родителем.
		$lastSubtitleTitle = null;

		// Флаг: предыдущий значимый элемент был subtitle
		$lastElementWasSubtitle = false;

		foreach ($article->children() as $child) {
			$classAttr = (string)($child->getAttribute('class') ?? '');
			$classList = preg_split('/\s+/', trim($classAttr)) ?: [];

			// текущая глава
			if (in_array('subtitle', $classList, true)) {
				$subtitleTitle = Text::cleanupText($child->plaintext);

				if ($subtitleTitle === '') {
					unset($subtitleTitle, $classAttr, $classList);
					continue;
				}

				// Если subtitle идёт сразу после subtitle —
				// предыдущий subtitle становится родителем.
				if ($lastElementWasSubtitle && $lastSubtitleTitle !== null && $lastSubtitleTitle !== '') {
					$parentChapter = $lastSubtitleTitle;
					$currentChapter = $this->makeNestedChapterTitle($parentChapter, $subtitleTitle);
				}
				// Если родитель уже найден ранее — добавляем его к последующим subtitle
				elseif ($parentChapter !== null && $parentChapter !== '') {
					$currentChapter = $this->makeNestedChapterTitle($parentChapter, $subtitleTitle);
				}
				// Обычное поведение, как было раньше
				else {
					$currentChapter = $subtitleTitle;
				}

				$lastSubtitleTitle = $subtitleTitle;
				$lastElementWasSubtitle = true;

				unset($subtitleTitle, $classAttr, $classList); // чистим память
				continue;
			}

			// блок задач
			if (in_array('razdel', $classList, true)) {
				$links = $child->find('a');
				$hasParsedTasks = false;

				foreach ($links as $a) {
					$href = trim((string)$a->getAttribute('href'));
					$title = Text::cleanupText($a->plaintext);

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
						url: Text::makeAbsoluteURL(self::DOMAIN, $href)
					);

					$hasParsedTasks = true;

					// чистим память
					unset($href, $title);
				}

				// Сбрасываем "subtitle подряд" только если в razdel реально были задачи.
				// Это важно, чтобы служебные/пустые razdel не ломали определение родителя.
				if ($hasParsedTasks) {
					$lastElementWasSubtitle = false;
				}

				unset($links, $hasParsedTasks); // чистим память
			}

			unset($classAttr, $classList); // чистим память
		}

		unset(
			$currentChapter,
			$parentChapter,
			$lastSubtitleTitle,
			$lastElementWasSubtitle
		); // чистим память

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
	 * @param object $article Объект Simple PHP DOM
	 * 
	 * @return TaskListItemDTO[]
	 */
	private function parseNewFormat(object $article): array
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
				$currentChapter = Text::cleanupText($sublnk->plaintext);
				unset($sublnk);
			}

			unset($classAttr, $classList);
		}

		unset($slideMenu, $currentChapter);

		return $result;
	}

	/**
	 * Обработка блока submenu: парсим пары partName/partContent
	 * 
	 * @param object $li Объект Simple PHP DOM
	 * @param string|null $currentChapter Текущая глава
	 * @param array $result Список задач
	 */
	private function parseSubmenuBlock(object $li, ?string $currentChapter, array &$result): void
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
				$partText = Text::cleanupText($child->plaintext);
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
	 * 
	 * @param object $block Объект Simple PHP DOM
	 * @param string|null $currentChapter Текущая глава
	 * @param string|null $currentPartName Текущая подглава
	 * @param array $result Список задач
	 */
	private function parseLinksBlock(object $block, ?string $currentChapter, ?string $currentPartName, array &$result): void
	{
		$links = $block->find('a');

		foreach ($links as $a) {
			$href = trim((string)$a->getAttribute('href'));
			$title = Text::cleanupText($a->plaintext);

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
				url: Text::makeAbsoluteURL(self::DOMAIN, $href)
			);

			unset($href, $title, $chapter);
		}

		unset($links);
	}

	/**
	 * Проверка, что ссылка ведёт на задачу/ответ
	 * 
	 * @param string $href Ссылка
	 * @return bool
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
	 * 
	 * @param string|null $currentChapter Текущая глава
	 * @param string|null $currentPartName Текущая подглава
	 * 
	 * @return string|null
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

	/**
	 * Формирование вложенного названия главы
	 *
	 * Пример:
	 * parent: Задачи для подготовки к ЕГЭ.
	 * child: Задание 3.
	 *
	 * Результат:
	 * Задачи для подготовки к ЕГЭ. Задание 3.
	 *
	 * @param string|null $parentChapter Родительская глава
	 * @param string|null $childChapter Дочерняя глава
	 *
	 * @return string|null
	 */
	private function makeNestedChapterTitle(?string $parentChapter, ?string $childChapter): ?string
	{
		$parentChapter = $parentChapter !== null ? trim($parentChapter) : '';
		$childChapter = $childChapter !== null ? trim($childChapter) : '';

		if ($parentChapter !== '' && $childChapter !== '') {
			return $parentChapter . ' ' . $childChapter;
		}

		if ($childChapter !== '') {
			return $childChapter;
		}

		return $parentChapter !== '' ? $parentChapter : null;
	}
}
