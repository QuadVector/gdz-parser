<?php

namespace Mihairu\GDZParser\Helper;

final class Text
{
	/**
	 * Очистить текст от лишних символов
	 * @param string $text исходный текст
	 * @return string
	 */
	public static function CleanupText(string $text): string
	{
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace('/\x{00A0}/u', ' ', $text);
		$text = preg_replace('/\s+/u', ' ', $text);

		return trim($text);
	}
}
