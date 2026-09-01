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
         * Сначала пробуем старый формат.
         */
		$result = $this->parseOldFormat(
			$article
		);

		/*
         * Если ничего нет — новый формат.
         */
		if (empty($result)) {
			$result = $this->parseNewFormat(
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
	 * Обработка submenu.
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

		foreach ($sublnk1->children() as $child) {
			$childClassAttr = (string)(
				$child->getAttribute('class') ?? ''
			);

			$childClassList = preg_split(
				'/\s+/',
				trim($childClassAttr)
			) ?: [];

			/*
             * Название подраздела.
             */
			if (
				in_array(
					'partName',
					$childClassList,
					true
				)
			) {
				$partText = Text::cleanupText(
					$child->plaintext
				);

				$currentPartName = rtrim(
					$partText,
					':'
				);

				unset(
					$partText,
					$childClassAttr,
					$childClassList
				);

				continue;
			}

			/*
             * Если новый формат тоже содержит настоящий
             * div.razdel — создаём для него группу.
             */
			if (
				in_array(
					'razdel',
					$childClassList,
					true
				)
			) {
				$groupCounter++;

				$groupName =
					'razdel_' . $groupCounter;

				$this->parseLinksBlock(
					block: $child,
					currentChapter: $currentChapter,
					currentPartName: $currentPartName,
					result: $result,
					groupName: $groupName
				);

				unset(
					$groupName,
					$childClassAttr,
					$childClassList
				);

				continue;
			}

			/*
             * partContent сам по себе razdel не является,
             * поэтому группу ему искусственно не присваиваем.
             */
			if (
				in_array(
					'partContent',
					$childClassList,
					true
				)
			) {
				$this->parseLinksBlock(
					block: $child,
					currentChapter: $currentChapter,
					currentPartName: $currentPartName,
					result: $result,
					groupName: null
				);
			}

			unset(
				$childClassAttr,
				$childClassList
			);
		}

		unset(
			$sublnk1,
			$currentPartName
		);
	}

	/**
	 * Парсинг ссылок.
	 */
	private function parseLinksBlock(
		object $block,
		?string $currentChapter,
		?string $currentPartName,
		array &$result,
		?string $groupName = null
	): void {
		$links = $block->find('a');

		/*
         * Нумерация начинается заново для каждой группы.
         */
		$groupOrderNumber = 0;

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

			$chapter = $this->makeChapterTitle(
				$currentChapter,
				$currentPartName
			);

			if ($groupName !== null) {
				$groupOrderNumber++;
			}

			$result[] = new TaskListItemDTO(
				title: $title,

				url: Text::makeAbsoluteURL(
					self::DOMAIN,
					$href
				),

				chapter: $chapter,

				group_id: $groupName,

				order_number_in_group: $groupName !== null
					? $groupOrderNumber
					: null
			);

			unset(
				$href,
				$title,
				$chapter
			);
		}

		unset(
			$links,
			$groupOrderNumber
		);
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

		if (
			preg_match(
				'#^https?://#i',
				$href
			)
		) {
			return true;
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
