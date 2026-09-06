<?php

namespace QuadVector\GDZParser\BookParser;

use QuadVector\GDZParser\DTO\BookDTO;
use QuadVector\GDZParser\Exception\AccessDeniedException;
use QuadVector\GDZParser\Exception\PageNotFoundException;
use QuadVector\GDZParser\Exception\ParseException;
use QuadVector\GDZParser\Helper\CURL;
use QuadVector\GDZParser\Helper\Text;
use QuadVector\GDZParser\ValueObject\Proxy;
use voku\helper\HtmlDomParser;

class ReshakBookParser implements BookParserInterface
{
	const DOMAIN = 'reshak.ru';

	/**
	 * @return BookDTO[]
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

		if (trim($html) === 'Access Denied') {
			throw new AccessDeniedException(
				"Access denied for {$url}"
			);
		}

		$dom = HtmlDomParser::str_get_html(
			$html
		);

		unset($html);

		if (!$dom) {
			throw new ParseException(
				"Can't parse {$url}."
			);
		}

		$domBooks =
			$dom->findMultiOrFalse(
				'.list_gdz .main_gdz-div'
			);

		if (!$domBooks) {
			unset($dom);

			throw new ParseException(
				"Can't find books on {$url}"
			);
		}

		/*
		 * Карта предметов текущей страницы.
		 *
		 * Например:
		 *
		 * math => Математика
		 * russian => Русский
		 * english => Английский
		 */
		$subjects =
			$this->extractSubjects(
				$dom
			);

		/*
		 * Класс одинаковый для всех книг
		 * текущей страницы.
		 */
		$grade =
			$this->extractGrade(
				$url,
				$dom
			);

		$result = [];

		foreach ($domBooks as $bookNode) {
			$linkNode =
				$bookNode->find(
					'a',
					0
				);

			$titleNode =
				$bookNode->find(
					'.subjectName',
					0
				);

			$dopTitleNode =
				$bookNode->find(
					'.dopName',
					0
				);

			$authorNode =
				$bookNode->find(
					'.author',
					0
				);

			if (
				!$linkNode
				|| !$titleNode
				|| !$authorNode
			) {
				unset(
					$linkNode,
					$titleNode,
					$dopTitleNode,
					$authorNode
				);

				continue;
			}

			$title =
				Text::cleanupText(
					$titleNode->plaintext
				);

			if ($dopTitleNode) {
				$dopTitle =
					Text::cleanupText(
						$dopTitleNode->plaintext
					);

				if ($dopTitle !== '') {
					$title .=
						' '
						. $dopTitle;
				}

				unset($dopTitle);
			}

			$href =
				Text::cleanupText(
					(string)$linkNode->href
				);

			if (
				$title === ''
				|| $href === ''
			) {
				unset(
					$linkNode,
					$titleNode,
					$dopTitleNode,
					$authorNode,
					$title,
					$href
				);

				continue;
			}

			$bookUrl =
				Text::makeAbsoluteURL(
					self::DOMAIN,
					$href
				);

			/*
			 * У каждой книги на странице есть:
			 *
			 * <article
			 *     class="main_gdz-div"
			 *     data-subject="math"
			 * >
			 *
			 * По нему определяем конкретный предмет.
			 */
			$subject =
				$this->extractBookSubject(
					$bookNode,
					$subjects,
					$bookUrl
				);

			/*
			 * Если класс почему-то не удалось получить
			 * со страницы списка, пробуем взять его
			 * непосредственно из URL книги.
			 */
			$bookGrade =
				$grade !== ''
				? $grade
				: $this->extractGradeFromBookUrl(
					$bookUrl
				);

			$result[] = new BookDTO(
				title: $title,

				author: Text::cleanupText(
					$authorNode->plaintext
				),

				grade: $bookGrade,

				subject: $subject,

				url: $bookUrl,

				book_id: Text::generateBookId(
					$bookUrl
				),

				parse_url: $url
			);

			unset(
				$linkNode,
				$titleNode,
				$dopTitleNode,
				$authorNode,
				$title,
				$href,
				$bookUrl,
				$subject,
				$bookGrade
			);
		}

		unset(
			$subjects,
			$grade,
			$domBooks,
			$dom
		);

		if (
			function_exists(
				'gc_collect_cycles'
			)
		) {
			gc_collect_cycles();
		}

		return $result;
	}

	/**
	 * Получить карту предметов со страницы.
	 *
	 * Например:
	 *
	 * math => Математика
	 * russian => Русский
	 * english => Английский
	 */
	private function extractSubjects(
		object $dom
	): array {
		$result = [];

		$subjectNodes =
			$dom->findMultiOrFalse(
				'.subject-tabs-item[data-subject]'
			);

		if (!$subjectNodes) {
			return $result;
		}

		foreach ($subjectNodes as $subjectNode) {
			$subjectId =
				trim(
					(string)$subjectNode->getAttribute(
						'data-subject'
					)
				);

			$subjectName =
				Text::cleanupText(
					$subjectNode->plaintext
				);

			if (
				$subjectId === ''
				|| $subjectId === 'all'
				|| $subjectName === ''
			) {
				unset(
					$subjectId,
					$subjectName
				);

				continue;
			}

			$result[$subjectId] =
				$subjectName;

			unset(
				$subjectId,
				$subjectName
			);
		}

		unset($subjectNodes);

		return $result;
	}

	/**
	 * Определить предмет конкретной книги.
	 */
	private function extractBookSubject(
		object $bookNode,
		array $subjects,
		string $bookUrl
	): string {
		$subjectId =
			trim(
				(string)$bookNode->getAttribute(
					'data-subject'
				)
			);

		if (
			$subjectId !== ''
			&& isset(
				$subjects[$subjectId]
			)
		) {
			return $subjects[$subjectId];
		}

		/*
		 * Если по data-subject предмет определить
		 * не удалось, используем URL книги.
		 */
		return $this->extractSubjectFromBookUrl(
			$bookUrl
		);
	}

	/**
	 * Получить класс страницы.
	 */
	private function extractGrade(
		string $parseUrl,
		object $dom
	): string {
		/*
		 * Самый надежный вариант для страниц:
		 *
		 * /tag/4klass.html
		 * /tag/4klass_math.html
		 * /tag/10klass_alg.html
		 */
		if (
			preg_match(
				'~/tag/(\d{1,2})klass(?:[_./]|$)~i',
				$parseUrl,
				$matches
			)
		) {
			return $matches[1];
		}

		/*
		 * Дополнительная страховка через H1:
		 *
		 * ГДЗ Математика 4 класс
		 */
		$titleNode =
			$dom->findOneOrFalse(
				'.titleh1'
			);

		if ($titleNode) {
			$pageTitle =
				Text::cleanupText(
					$titleNode->plaintext
				);

			if (
				preg_match(
					'/(\d{1,2})\s*класс/ui',
					$pageTitle,
					$matches
				)
			) {
				return $matches[1];
			}
		}

		return '';
	}

	/**
	 * Получить класс из URL конкретной книги.
	 */
	private function extractGradeFromBookUrl(
		string $bookUrl
	): string {
		$path =
			(string)(
				parse_url(
					$bookUrl,
					PHP_URL_PATH
				)
				?? ''
			);

		/*
		 * /reshebniki/matematika/4/moro/
		 */
		if (
			preg_match(
				'~/reshebniki/[^/]+/(\d{1,2})/~i',
				$path,
				$matches
			)
		) {
			return $matches[1];
		}

		/*
		 * Старые короткие URL:
		 *
		 * /rainbow4/index.html
		 * /spotlight4/index.html
		 * /forward10/index.html
		 */
		if (
			preg_match(
				'~/[^/]*?(\d{1,2})/index\.html$~i',
				$path,
				$matches
			)
		) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * Резервное определение предмета
	 * непосредственно по URL книги.
	 */
	private function extractSubjectFromBookUrl(
		string $bookUrl
	): string {
		$path =
			mb_strtolower(
				(string)(
					parse_url(
						$bookUrl,
						PHP_URL_PATH
					)
					?? ''
				)
			);

		if ($path === '') {
			return '';
		}

		/*
		 * Современные URL:
		 *
		 * /reshebniki/matematika/4/...
		 * /reshebniki/russkijazik/4/...
		 */
		if (
			preg_match(
				'~/reshebniki/([^/]+)/~i',
				$path,
				$matches
			)
		) {
			$subjectSlug =
				mb_strtolower(
					$matches[1]
				);

			$subjects = [
				'matematika' =>
					'Математика',

				'russkijazik' =>
					'Русский',

				'russkiyazik' =>
					'Русский',

				'okruzhaushiy_mir' =>
					'Окружающий мир',

				'okruzhayushchiy_mir' =>
					'Окружающий мир',

				'chtenie' =>
					'Литературное чтение',

				'literatura' =>
					'Литература',

				'algebra' =>
					'Алгебра',

				'geometriya' =>
					'Геометрия',

				'geometria' =>
					'Геометрия',

				'fizika' =>
					'Физика',

				'himiya' =>
					'Химия',

				'khimiya' =>
					'Химия',

				'biologiya' =>
					'Биология',

				'biologia' =>
					'Биология',

				'geografiya' =>
					'География',

				'geografia' =>
					'География',

				'istoriya' =>
					'История',

				'istoria' =>
					'История',

				'informatika' =>
					'Информатика',

				'obshestvo' =>
					'Общество',

				'obschestvo' =>
					'Общество',

				'anglijskij' =>
					'Английский',

				'angliyskiy' =>
					'Английский',
			];

			if (
				isset(
					$subjects[$subjectSlug]
				)
			) {
				return $subjects[$subjectSlug];
			}
		}

		/*
		 * Старые короткие ссылки Reshak.
		 *
		 * Они используются в том числе
		 * у английского языка:
		 *
		 * /rainbow4/
		 * /spotlight4/
		 * /forward4/
		 * /vereshagina4/
		 * /kuzovlev4/
		 * /starlight4/
		 * /enjoy4/
		 */
		$englishPrefixes = [
			'/rainbow',
			'/spotlight',
			'/forward',
			'/vereshagina',
			'/kuzovlev',
			'/starlight',
			'/enjoy',
		];

		foreach (
			$englishPrefixes
			as $prefix
		) {
			if (
				str_starts_with(
					$path,
					$prefix
				)
			) {
				return 'Английский';
			}
		}

		return '';
	}
}