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

		/*
         * Родительская глава.
         *
         * Например:
         *
         * Часть 1
         * Глава 1
         *
         * => Часть 1 Глава 1
         */
		$parentChapter = null;

		/*
         * Последний subtitle.
         */
		$lastSubtitleTitle = null;

		/*
         * Предыдущий значимый элемент был subtitle.
         */
		$lastElementWasSubtitle = false;

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

				/*
                 * Два subtitle подряд:
                 *
                 * Часть 1
                 * Глава 1
                 *
                 * Первый становится родителем.
                 */
				if (
					$lastElementWasSubtitle
					&& $lastSubtitleTitle !== null
					&& $lastSubtitleTitle !== ''
				) {
					$parentChapter = $lastSubtitleTitle;

					$currentChapter =
						$this->makeNestedChapterTitle(
							$parentChapter,
							$subtitleTitle
						);
				}

				/*
                 * Родитель уже существует.
                 */ elseif (
					$parentChapter !== null
					&& $parentChapter !== ''
				) {
					$currentChapter =
						$this->makeNestedChapterTitle(
							$parentChapter,
							$subtitleTitle
						);
				}

				/*
                 * Обычная глава.
                 */ else {
					$currentChapter = $subtitleTitle;
				}

				$lastSubtitleTitle = $subtitleTitle;
				$lastElementWasSubtitle = true;

				unset(
					$subtitleTitle,
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

				/*
                 * Сбрасываем состояние двух subtitle подряд
                 * только если в razdel действительно были задачи.
                 */
				if ($hasParsedTasks) {
					$lastElementWasSubtitle = false;
				} else {
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
			$parentChapter,
			$lastSubtitleTitle,
			$lastElementWasSubtitle,
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
	 * Формирование вложенной главы.
	 *
	 * Часть 1 + Глава 1
	 * =>
	 * Часть 1 Глава 1
	 */
	private function makeNestedChapterTitle(
		?string $parentChapter,
		?string $childChapter
	): ?string {
		$parentChapter =
			$parentChapter !== null
			? trim($parentChapter)
			: '';

		$childChapter =
			$childChapter !== null
			? trim($childChapter)
			: '';

		if (
			$parentChapter !== ''
			&& $childChapter !== ''
		) {
			return $parentChapter
				. ' '
				. $childChapter;
		}

		if ($childChapter !== '') {
			return $childChapter;
		}

		return $parentChapter !== ''
			? $parentChapter
			: null;
	}
}
