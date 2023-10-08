<?php declare(strict_types=1);

namespace h4kuna\Exchange\Driver;

use DateTime;
use DateTimeZone;
use Generator;
use h4kuna\Exchange;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Download currency from server.
 * @phpstan-type Source object|string
 * @template T of Exchange\Currency\Property
 */
abstract class Driver
{

	private \DateTimeImmutable $date;

	protected string $timeZone = 'UTC';

	protected string $refresh = 'midnight';

	/**
	 * @var iterable<Source>
	 */
	private iterable $list;


	public function __construct(
		private ClientInterface $client,
		private RequestFactoryInterface $requestFactory,
	)
	{
	}


	/**
	 * @throws ClientExceptionInterface
	 */
	public function initRequest(?\DateTimeInterface $date): void
	{
		$content = $this->client->sendRequest($this->createRequest($date));
		$this->list = $this->createList($content);
	}


	public function getDate(): \DateTimeImmutable
	{
		return $this->date;
	}


	/**
	 * @param array<string, int> $allowedCurrencies
	 * @return Generator<T>
	 */
	public function properties(array $allowedCurrencies): Generator
	{
		foreach ($this->list as $data) {
			$property = $this->createProperty($data);

			if ($property->rate === 0.0 || ($allowedCurrencies !== [] && isset($allowedCurrencies[$property->code]) === false)) {
				continue;
			}

			yield $property;
		}

		return [];
	}


	public function getRefresh(): DateTime
	{
		return new DateTime($this->refresh, new DateTimeZone($this->timeZone));
	}


	protected function setDate(string $format, string $value): void
	{
		$date = \DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone($this->timeZone));
		if ($date === false) {
			throw new Exchange\Exceptions\InvalidStateException(sprintf('Can not create DateTime object from source "%s" with format "%s".', $value, $format));
		}
		$this->date = $date;
	}


	/**
	 * @return iterable<Source>
	 */
	abstract protected function createList(ResponseInterface $response): iterable;


	/**
	 * @param Source $row
	 * @return T
	 */
	abstract protected function createProperty($row);


	abstract protected function prepareUrl(?\DateTimeInterface $date): string;


	private function createRequest(?\DateTimeInterface $date): RequestInterface
	{
		if ($date !== null && $date->getTimezone()->getName() !== $this->timeZone) {
			$date = new DateTime('@' . $date->getTimestamp(), new DateTimeZone('UTC'));
			$date->setTimezone(new DateTimeZone($this->timeZone));
		}

		$request = $this->requestFactory->createRequest('GET', $this->prepareUrl($date));
		$request->withHeader('X-Powered-By', 'h4kuna/exchange');

		return $request;
	}

}
