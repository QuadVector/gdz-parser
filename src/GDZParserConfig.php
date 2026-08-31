<?php

namespace QuadVector\GDZParser;

use InvalidArgumentException;
use QuadVector\GDZParser\BookParser\BookParserInterface;
use QuadVector\GDZParser\TaskListParser\TaskListParserInterface;
use QuadVector\GDZParser\TaskParser\TaskParserInterface;

final class GDZParserConfig
{
	private const AVAILABLE_MODES = [
		'books',
		'tasks',
		'all',
	];

	public string $mode;

	public bool $parseImages;

	public function __construct(
		public BookParserInterface $bookParser,
		public TaskListParserInterface $taskListParser,
		public TaskParserInterface $taskParser,
		public array $startURLs = [],
		public array $proxy = [],
		public int $attempts = 5,
		public int $timeout = 5,
		public string $parseOutputFolder = 'output',
		public bool $showLogs = false,
		string $mode = 'all',
		bool $parseImages = true
	) {
		$mode = strtolower(
			trim($mode)
		);

		if (
			!in_array(
				$mode,
				self::AVAILABLE_MODES,
				true
			)
		) {
			throw new InvalidArgumentException(
				"Unsupported parser mode '{$mode}'. "
					. "Available modes: "
					. implode(
						', ',
						self::AVAILABLE_MODES
					)
			);
		}

		$this->mode = $mode;
		$this->parseImages = $parseImages;
	}

	/**
	 * Нужно ли заходить глубже предметов.
	 */
	public function shouldParseBooks(): bool
	{
		return in_array(
			$this->mode,
			[
				'books',
				'tasks',
				'all',
			],
			true
		);
	}

	/**
	 * Нужно ли получать списки задач книг.
	 */
	public function shouldParseTaskLists(): bool
	{
		return in_array(
			$this->mode,
			[
				'tasks',
				'all',
			],
			true
		);
	}

	/**
	 * Нужно ли открывать каждую задачу
	 * и получать её содержимое.
	 */
	public function shouldParseTaskContents(): bool
	{
		return $this->mode === 'all';
	}
}
