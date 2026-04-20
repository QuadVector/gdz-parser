<?php

namespace Mihairu\GDZParser;

use Mihairu\GDZParser\BookParser\BookParserInterface;
use Mihairu\GDZParser\Network\Proxy;
use InvalidArgumentException;

final class GDZParserConfig
{
	/**
	 * @param Proxy[] $Proxies
	 */
	public function __construct(
		public readonly BookParserInterface $BookParser,
		public readonly array $Proxies = []
	) {
		foreach ($this->Proxies as $proxy) {
			if (!$proxy instanceof Proxy) {
				throw new InvalidArgumentException('Все элементы Proxies должны быть экземплярами Proxy');
			}
		}
	}
}
