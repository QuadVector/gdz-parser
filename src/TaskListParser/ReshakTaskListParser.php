<?php

namespace QuadVector\GDZParser\TaskListParser;

use QuadVector\GDZParser\DTO\TaskListItemDTO;
use QuadVector\GDZParser\Exception\AccessDeniedException;
use QuadVector\GDZParser\Exception\PageNotFoundException;
use QuadVector\GDZParser\Exception\ParseException;
use QuadVector\GDZParser\Helper\CURL;
use QuadVector\GDZParser\Helper\Text;
use QuadVector\GDZParser\ValueObject\Proxy;
use voku\helper\HtmlDomParser;

class ReshakTaskListParser implements TaskListParserInterface
{
	const DOMAIN = 'reshak.ru';

	/**
	 * Получить список задач.
	 *
	 * @return TaskListItemDTO[]
	 */
	public function parse(
		string $url = '',
		?Proxy $proxy = null,
		?int $timeout = null
	): array {
		$url = Text::makeAbsoluteURL(
			self::DOMAIN,
			$url
		);

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

		$dom = HtmlDomParser::str_get_html($html);

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

		/*
         * Новый формат проверяем первым. На таких страницах задачи находятся
         * внутри #slidemenu и div.partContent, а не в обычных div.razdel.
         */
		$result = $this->parseNewFormat(
			$article
		);

		/*
         * Если нового меню нет — пробуем старый формат.
         */
		if (empty($result)) {
			$result = $this->parseOldFormat(
				$article
			);
		}

		/*
         * Защитный вариант для неизвестной верстки. Если распознать блоки
         * разделов не удалось, сохраняем все ссылки на задачи в razdel_1 в том
         * порядке, в котором они находятся в HTML.
         */
		if (empty($result)) {
			$result = $this->parseFallbackFormat(
				$article
			);
		}

		/*
         * Последняя страховка: наружу никогда не возвращаются задачи с
         * пустыми group_id/order_number_in_group. Одновременно заново
         * выстраиваем порядок внутри каждой группы по позиции в результате.
         */
		$result = $this->ensureTaskGrouping(
			$result
		);

		/*
		 * parse_url указывает на страницу книги, из которой был получен
		 * конкретный элемент списка задач.
		 */
		foreach ($result as $task) {
			$task->parse_url = $url;
		}

		unset($article, $dom);

		if (function_exists('gc_collect_cycles')) {
			gc_collect_cycles();
		}

		return $result;
	}

	/**
	 * Старый формат:
	 *
	 * subtitle
	 * razdel
	 * razdel
	 * subtitle
	 * razdel
	 */
	private function parseOldFormat(object $article): array
	{
		$result = [];

		$currentChapter = null;
		$chapterLevels = [
			1 => null,
			2 => null,
			3 => null,
		];
		$chapterCounters = [
			1 => 0,
			2 => 0,
			3 => 0,
		];

		/*
         * Глобальный номер razdel на странице.
         *
         * razdel_1
         * razdel_2
         * razdel_3
         * ...
         */
		$groupCounter = 0;

		foreach ($article->children() as $child) {
			$classAttr = (string)(
				$child->getAttribute('class') ?? ''
			);

			$classList = preg_split(
				'/\s+/',
				trim($classAttr)
			) ?: [];

			// =================================================
			// SUBTITLE
			// =================================================

			if (
				in_array(
					'subtitle',
					$classList,
					true
				)
			) {
				$subtitleTitle = Text::cleanupText(
					$child->plaintext
				);

				if ($subtitleTitle === '') {
					unset(
						$subtitleTitle,
						$classAttr,
						$classList
					);

					continue;
				}

				$subtitleLevel = $this->detectSubtitleLevel($child);
				$chapterCounters[$subtitleLevel]++;
				$chapterLevels[$subtitleLevel] = [
					'number' => $chapterCounters[$subtitleLevel],
					'title' => $subtitleTitle,
				];

				/*
				 * При смене родителя все более мелкие уровни и их локальные
				 * счётчики начинаются заново.
				 */
				for ($level = $subtitleLevel + 1; $level <= 3; $level++) {
					$chapterLevels[$level] = null;
					$chapterCounters[$level] = 0;
				}

				$currentChapter = $this->makeHierarchicalChapterTitle(
					$chapterLevels
				);

				unset(
					$subtitleTitle,
					$subtitleLevel,
					$level,
					$classAttr,
					$classList
				);

				continue;
			}

			// =================================================
			// RAZDEL
			// =================================================

			if (
				in_array(
					'razdel',
					$classList,
					true
				)
			) {
				/*
                 * Каждый отдельный div.razdel —
                 * новая группа.
                 */
				$groupCounter++;

				$groupName =
					'razdel_' . $groupCounter;

				/*
                 * Номер задачи внутри конкретного razdel.
                 */
				$groupOrderNumber = 0;

				$links = $child->find('a');

				$hasParsedTasks = false;

				foreach ($links as $a) {
					$href = trim(
						(string)$a->getAttribute('href')
					);

					$title = Text::cleanupText(
						$a->plaintext
					);

					if (
						$href === ''
						|| $title === ''
					) {
						unset(
							$href,
							$title
						);

						continue;
					}

					if (!$this->isTaskHref($href)) {
						unset(
							$href,
							$title
						);

						continue;
					}

					/*
                     * Увеличиваем номер только для
                     * реально добавленной задачи.
                     */
					$groupOrderNumber++;

					$result[] = new TaskListItemDTO(
						title: $title,

						url: Text::makeAbsoluteURL(
							self::DOMAIN,
							$href
						),

						chapter: $currentChapter,

						group_id: $groupName,

						order_number_in_group: $groupOrderNumber
					);

					$hasParsedTasks = true;

					unset(
						$href,
						$title
					);
				}

				if (!$hasParsedTasks) {
					/*
                     * Некоторые служебные div.razdel не содержат ни одной
                     * ссылки на задачу. Они не должны создавать пропуск в
                     * последовательности razdel_N.
                     */
					$groupCounter--;
				}

				unset(
					$links,
					$hasParsedTasks,
					$groupName,
					$groupOrderNumber
				);
			}

			unset(
				$classAttr,
				$classList
			);
		}

		unset(
			$currentChapter,
			$chapterLevels,
			$chapterCounters,
			$groupCounter
		);

		return $result;
	}

	/**
	 * Новый формат:
	 *
	 * #slidemenu
	 *   li
	 *      span.sublnk
	 *   li.submenu
	 */
	private function parseNewFormat(object $article): array
	{
		$result = [];

		$slideMenu = $article->findOneOrFalse(
			'#slidemenu'
		);

		if (!$slideMenu) {
			$container = $article->findOneOrFalse(
				'#extremum-slide-menu-index'
			);

			if ($container) {
				$slideMenu =
					$container->findOneOrFalse(
						'ul.reset-index'
					);
			}

			unset($container);
		}

		if (!$slideMenu) {
			return $result;
		}

		$currentChapter = null;

		/*
         * Счётчик razdel по всей странице.
         */
		$groupCounter = 0;

		foreach ($slideMenu->children() as $li) {
			$tag = strtolower(
				(string)$li->tag
			);

			if ($tag !== 'li') {
				continue;
			}

			$classAttr = (string)(
				$li->getAttribute('class') ?? ''
			);

			$classList = preg_split(
				'/\s+/',
				trim($classAttr)
			) ?: [];

			/*
             * submenu
             */
			if (
				in_array(
					'submenu',
					$classList,
					true
				)
			) {
				$this->parseSubmenuBlock(
					$li,
					$currentChapter,
					$result,
					$groupCounter
				);

				unset(
					$classAttr,
					$classList
				);

				continue;
			}

			/*
             * Название главы.
             */
			$sublnk = $li->findOneOrFalse(
				'span.sublnk'
			);

			if ($sublnk) {
				$currentChapter =
					Text::cleanupText(
						$sublnk->plaintext
					);

				unset($sublnk);
			}

			unset(
				$classAttr,
				$classList
			);
		}

		unset(
			$slideMenu,
			$currentChapter,
			$groupCounter
		);

		return $result;
	}

	/**
	 * Обработка submenu с поддержкой поломанной вложенности HTML.
	 */
	private function parseSubmenuBlock(
		object $li,
		?string $currentChapter,
		array &$result,
		int &$groupCounter
	): void {
		$sublnk1 = $li->findOneOrFalse(
			'div.sublnk1'
		);

		if (!$sublnk1) {
			$sublnk1 = $li;
		}

		$currentPartName = null;

		$this->parseSubmenuChildren(
			container: $sublnk1,
			currentChapter: $currentChapter,
			currentPartName: $currentPartName,
			result: $result,
			groupCounter: $groupCounter
		);

		unset(
			$sublnk1,
			$currentPartName
		);
	}

	/**
	 * Рекурсивный обход нового меню в фактическом DOM-порядке.
	 *
	 * На некоторых страницах Reshak из-за некорректно закрытого div следующие
	 * partName и partContent становятся вложенными в предыдущий блок. Обход
	 * только прямых детей в таком случае теряет целые модули задач.
	 */
	private function parseSubmenuChildren(
		object $container,
		?string $currentChapter,
		?string &$currentPartName,
		array &$result,
		int &$groupCounter
	): void {
		foreach ($container->children() as $child) {
			$classAttr = (string)(
				$child->getAttribute('class') ?? ''
			);
			$classList = preg_split(
				'/\s+/',
				trim($classAttr)
			) ?: [];

			if (in_array('partName', $classList, true)) {
				$hasNestedStructure =
					(bool)$child->findOneOrFalse('div.partName')
					|| (bool)$child->findOneOrFalse('div.partContent')
					|| (bool)$child->findOneOrFalse('div.razdel');

				/*
                 * У поломанного контейнера plaintext включает весь остаток
                 * меню. Вложенные настоящие partName будут обработаны ниже.
                 */
				if (!$hasNestedStructure) {
					$partText = Text::cleanupText(
						$child->plaintext
					);
					$currentPartName = rtrim(
						$partText,
						':'
					);

					unset($partText);
				} else {
					$this->parseSubmenuChildren(
						container: $child,
						currentChapter: $currentChapter,
						currentPartName: $currentPartName,
						result: $result,
						groupCounter: $groupCounter
					);
				}

				unset(
					$hasNestedStructure,
					$classAttr,
					$classList
				);

				continue;
			}

			$isTaskGroup =
				in_array('razdel', $classList, true)
				|| in_array('partContent', $classList, true);

			if ($isTaskGroup) {
				$nextGroupNumber = $groupCounter + 1;
				$groupName = 'razdel_' . $nextGroupNumber;
				$parsedTasks = $this->parseLinksBlock(
					block: $child,
					currentChapter: $currentChapter,
					currentPartName: $currentPartName,
					result: $result,
					groupName: $groupName
				);

				if ($parsedTasks > 0) {
					$groupCounter = $nextGroupNumber;
				}

				/*
                 * Текущий блок может содержать вложенные группы из-за
                 * поломанной разметки. Его собственные ссылки уже добавлены,
                 * вложенные структурные блоки обрабатываем отдельно.
                 */
				$this->parseSubmenuChildren(
					container: $child,
					currentChapter: $currentChapter,
					currentPartName: $currentPartName,
					result: $result,
					groupCounter: $groupCounter
				);

				unset(
					$parsedTasks,
					$nextGroupNumber,
					$groupName,
					$isTaskGroup,
					$classAttr,
					$classList
				);

				continue;
			}

			/* Обычный контейнер тоже может скрывать разделы глубже. */
			$this->parseSubmenuChildren(
				container: $child,
				currentChapter: $currentChapter,
				currentPartName: $currentPartName,
				result: $result,
				groupCounter: $groupCounter
			);

			unset(
				$isTaskGroup,
				$classAttr,
				$classList
			);
		}
	}

	/**
	 * Добавляет ссылки текущего структурного блока и возвращает их количество.
	 */
	private function parseLinksBlock(
		object $block,
		?string $currentChapter,
		?string $currentPartName,
		array &$result,
		string $groupName
	): int {
		$groupOrderNumber = 0;

		$this->parseOwnedLinks(
			container: $block,
			currentChapter: $currentChapter,
			currentPartName: $currentPartName,
			result: $result,
			groupName: $groupName,
			groupOrderNumber: $groupOrderNumber
		);

		return $groupOrderNumber;
	}

	/**
	 * Парсит ссылки, принадлежащие именно текущему структурному блоку.
	 * Вложенные partName/partContent/razdel обрабатываются отдельно, поэтому
	 * здесь они пропускаются и задачи не дублируются.
	 */
	private function parseOwnedLinks(
		object $container,
		?string $currentChapter,
		?string $currentPartName,
		array &$result,
		string $groupName,
		int &$groupOrderNumber
	): void {
		foreach ($container->children() as $child) {
			$tag = strtolower((string)$child->tag);

			if ($tag !== 'a') {
				$classAttr = (string)(
					$child->getAttribute('class') ?? ''
				);
				$classList = preg_split(
					'/\s+/',
					trim($classAttr)
				) ?: [];

				if (
					in_array('partName', $classList, true)
					|| in_array('partContent', $classList, true)
					|| in_array('razdel', $classList, true)
				) {
					unset($classAttr, $classList);

					continue;
				}

				$this->parseOwnedLinks(
					container: $child,
					currentChapter: $currentChapter,
					currentPartName: $currentPartName,
					result: $result,
					groupName: $groupName,
					groupOrderNumber: $groupOrderNumber
				);

				unset($classAttr, $classList);

				continue;
			}

			$href = trim(
				(string)$child->getAttribute('href')
			);
			$title = Text::cleanupText(
				$child->plaintext
			);

			if (
				$href === ''
				|| $title === ''
				|| !$this->isTaskHref($href)
			) {
				unset($href, $title);

				continue;
			}

			$groupOrderNumber++;
			$chapter = $this->makeChapterTitle(
				$currentChapter,
				$currentPartName
			);

			$result[] = new TaskListItemDTO(
				title: $title,

				url: Text::makeAbsoluteURL(
					self::DOMAIN,
					$href
				),

				chapter: $chapter,

				group_id: $groupName,

				order_number_in_group: $groupOrderNumber
			);

			unset($href, $title, $chapter);
		}
	}

	/**
	 * Резервный парсинг неизвестной верстки.
	 *
	 * Все найденные ссылки на задачи попадают в razdel_1. Их порядок полностью
	 * совпадает с порядком ссылок в исходном HTML и не зависит от текста title.
	 */
	private function parseFallbackFormat(object $article): array
	{
		$result = [];
		$groupOrderNumber = 0;
		$links = $article->find('a');

		foreach ($links as $a) {
			$href = trim(
				(string)$a->getAttribute('href')
			);

			$title = Text::cleanupText(
				$a->plaintext
			);

			if (
				$href === ''
				|| $title === ''
				|| !$this->isTaskHref($href)
			) {
				unset($href, $title);

				continue;
			}

			$groupOrderNumber++;

			$result[] = new TaskListItemDTO(
				title: $title,

				url: Text::makeAbsoluteURL(
					self::DOMAIN,
					$href
				),

				chapter: null,

				group_id: 'razdel_1',

				order_number_in_group: $groupOrderNumber
			);

			unset($href, $title);
		}

		unset($links, $groupOrderNumber);

		return $result;
	}

	/**
	 * Гарантирует корректную группу и последовательный порядок каждой задачи.
	 *
	 * Порядок пересчитывается по текущей позиции элементов, поэтому он не
	 * зависит от того, является title числом, буквой или произвольным словом.
	 *
	 * @param TaskListItemDTO[] $tasks
	 *
	 * @return TaskListItemDTO[]
	 */
	private function ensureTaskGrouping(array $tasks): array
	{
		$result = [];
		$orderByGroup = [];

		foreach ($tasks as $task) {
			$groupName = isset($task->group_id)
				? trim((string)$task->group_id)
				: '';

			if (!preg_match('/^razdel_[1-9]\d*$/', $groupName)) {
				$groupName = 'razdel_1';
			}

			$orderByGroup[$groupName] =
				($orderByGroup[$groupName] ?? 0) + 1;
			$orderNumber = $orderByGroup[$groupName];
			$currentOrder = filter_var(
				$task->order_number_in_group ?? null,
				FILTER_VALIDATE_INT,
				[
					'options' => [
						'min_range' => 1,
					],
				]
			);

			if (
				isset($task->group_id)
				&& trim((string)$task->group_id) === $groupName
				&& $currentOrder === $orderNumber
			) {
				$result[] = $task;

				continue;
			}

			$result[] = new TaskListItemDTO(
				title: (string)$task->title,

				url: (string)$task->url,

				chapter: isset($task->chapter)
					? (string)$task->chapter
					: null,

				group_id: $groupName,

				order_number_in_group: $orderNumber
			);
		}

		unset($orderByGroup);

		return $result;
	}

	/**
	 * Проверяет, можно ли безопасно пропустить уже сохраненный taskList.json.
	 *
	 * Если метод вернул false, страницу нужно распарсить заново и перезаписать
	 * файл. Проверяются не только null, но также формат razdel_N и непрерывный
	 * порядок 1..N внутри каждой группы.
	 */
	public static function isTaskListFileValid(string $filePath): bool
	{
		if (!is_file($filePath) || !is_readable($filePath)) {
			return false;
		}

		$content = file_get_contents($filePath);

		if (!is_string($content) || trim($content) === '') {
			return false;
		}

		try {
			$decoded = json_decode(
				$content,
				true,
				512,
				JSON_THROW_ON_ERROR
			);
		} catch (\JsonException) {
			return false;
		}

		if (!is_array($decoded)) {
			return false;
		}

		/* Поддерживаем как чистый массив, так и обертки tasks/data. */
		if (isset($decoded['tasks']) && is_array($decoded['tasks'])) {
			$tasks = $decoded['tasks'];
		} elseif (isset($decoded['data']) && is_array($decoded['data'])) {
			$tasks = $decoded['data'];
		} else {
			$tasks = $decoded;
		}

		if ($tasks === []) {
			return false;
		}

		$expectedOrderByGroup = [];

		foreach ($tasks as $task) {
			if (!is_array($task)) {
				return false;
			}

			$url = trim((string)($task['url'] ?? ''));
			$groupName = trim((string)($task['group_id'] ?? ''));
			$order = filter_var(
				$task['order_number_in_group'] ?? null,
				FILTER_VALIDATE_INT,
				[
					'options' => [
						'min_range' => 1,
					],
				]
			);

			if (
				$url === ''
				|| !preg_match('/^razdel_[1-9]\d*$/', $groupName)
				|| $order === false
			) {
				return false;
			}

			$expectedOrderByGroup[$groupName] =
				($expectedOrderByGroup[$groupName] ?? 0) + 1;

			if ($order !== $expectedOrderByGroup[$groupName]) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Проверка ссылки на задачу.
	 */
	private function isTaskHref(string $href): bool
	{
		$href = trim($href);

		if ($href === '') {
			return false;
		}

		if (
			str_starts_with(
				$href,
				'/otvet/'
			)
		) {
			return true;
		}

		if (preg_match('#^https?://#i', $href)) {
			$parts = parse_url($href);
			$host = strtolower((string)($parts['host'] ?? ''));
			$path = (string)($parts['path'] ?? '');

			$isReshakHost =
				$host === self::DOMAIN
				|| str_ends_with($host, '.' . self::DOMAIN);

			if (!$isReshakHost) {
				return false;
			}

			if (str_starts_with($path, '/otvet/')) {
				return true;
			}

			return (bool)preg_match(
				'~/images/.+\.(?:jpe?g|png|webp|gif)$~i',
				$path
			);
		}

		if (
			preg_match(
				'~^/[^?#]+/images/.+\.(?:jpe?g|png|webp|gif)(?:[?#].*)?$~i',
				$href
			)
		) {
			return true;
		}

		return false;
	}

	/**
	 * Формирование главы нового формата.
	 */
	private function makeChapterTitle(
		?string $currentChapter,
		?string $currentPartName
	): ?string {
		if (
			$currentPartName !== null
			&& $currentPartName !== ''
		) {
			return (
				$currentChapter !== null
				&& $currentChapter !== ''
			)
				? $currentChapter
				. ' — '
				. $currentPartName
				: $currentPartName;
		}

		return $currentChapter;
	}

	/**
	 * Определить уровень subtitle старого формата.
	 *
	 * align="center" или text-align:center — h1;
	 * обычный subtitle — h2;
	 * subtitle с font-size до 16px — h3.
	 */
	private function detectSubtitleLevel(object $subtitle): int
	{
		$align = strtolower(trim((string)(
			$subtitle->getAttribute('align') ?? ''
		)));
		$style = strtolower((string)(
			$subtitle->getAttribute('style') ?? ''
		));

		if (
			$align === 'center'
			|| preg_match('/(?:^|;)\s*text-align\s*:\s*center\b/i', $style)
		) {
			return 1;
		}

		if (
			preg_match(
				'/(?:^|;)\s*font-size\s*:\s*([0-9]+(?:\.[0-9]+)?)px\b/i',
				$style,
				$fontSizeMatch
			)
			&& (float)$fontSizeMatch[1] <= 16
		) {
			return 3;
		}

		return 2;
	}

	/**
	 * Собрать chapter из активных уровней и их порядковых номеров.
	 *
	 * {{1}}<h1>Часть 1</h1> || {{1}}<h2>Глава 1</h2>
	 * || {{1}}<h3>Дополнительные задачи (2022)</h3>
	 */
	private function makeHierarchicalChapterTitle(array $chapterLevels): ?string
	{
		$parts = [];

		foreach ([1, 2, 3] as $level) {
			$chapter = $chapterLevels[$level] ?? null;

			if (!is_array($chapter)) {
				continue;
			}

			$title = trim((string)($chapter['title'] ?? ''));
			$number = (int)($chapter['number'] ?? 0);

			if ($title === '' || $number < 1) {
				continue;
			}

			$parts[] = '{{' . $number . '}}'
				. '<h' . $level . '>'
				. $title
				. '</h' . $level . '>';
		}

		return $parts !== []
			? implode(' || ', $parts)
			: null;
	}
}
