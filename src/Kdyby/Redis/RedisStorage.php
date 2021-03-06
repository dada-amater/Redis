<?php

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.md that was distributed with this source code.
 */

namespace Kdyby\Redis;
use Kdyby;
use Nette;
use Nette\Caching\Cache;
use Nette\Caching\Storages\IJournal;
use Nette\Utils\Json;



/**
 * Redis Storage.
 *
 * @author Filip Procházka <filip@prochazka.su>
 */
class RedisStorage extends Nette\Object implements Nette\Caching\IStorage
{

	/** @internal cache structure */
	const NS_META = 'meta',
		NS_NETTE = 'Nette.Storage';

	/** @internal cache meta structure: array of */
	const META_TIME = 'time', // timestamp
		META_SERIALIZED = 'serialized', // is content serialized?
		META_EXPIRE = 'expire', // expiration timestamp
		META_DELTA = 'delta', // relative (sliding) expiration
		META_ITEMS = 'di', // array of dependent items (file => timestamp)
		META_CALLBACKS = 'callbacks'; // array of callbacks (function, args)

	/** additional cache structure */
	const KEY = 'key';

	/**
	 * @var RedisClient
	 */
	private $client;

	/**
	 * @var \Nette\Caching\Storages\IJournal
	 */
	private $journal;



	/**
	 * @param RedisClient $client
	 * @param \Nette\Caching\Storages\IJournal $journal
	 */
	public function __construct(RedisClient $client, IJournal $journal = NULL)
	{
		$this->client = $client;
		$this->journal = $journal;
	}



	/**
	 * Read from cache.
	 *
	 * @param string $key
	 *
	 * @return mixed|NULL
	 */
	public function read($key)
	{
		if (($meta = $this->readMeta($key)) && $this->verify($meta)) {
			$data = $this->client->get($this->formatEntryKey($key));

		} else {
			return NULL;
		}

		if (empty($meta[self::META_SERIALIZED])) {
			return $data;

		} else {
			return @unserialize($data); // intentionally @
		}
	}



	/**
	 * Verifies dependencies.
	 *
	 * @param  array
	 *
	 * @return bool
	 */
	protected function verify($meta)
	{
		do {
			if (!empty($meta[self::META_DELTA])) {
				$this->client->expire($this->formatEntryKey($meta[self::KEY]), $meta[self::META_DELTA]);
				$this->client->expire($this->formatMetaKey($meta[self::KEY]), $meta[self::META_DELTA]);

			} elseif (!empty($meta[self::META_EXPIRE]) && $meta[self::META_EXPIRE] < time()) {
				break;
			}

			if (!empty($meta[self::META_CALLBACKS]) && !Cache::checkCallbacks($meta[self::META_CALLBACKS])) {
				break;
			}

			if (!empty($meta[self::META_ITEMS])) {
				foreach ($meta[self::META_ITEMS] as $itemKey => $time) {
					$m = $this->readMeta($itemKey);
					if ($m[self::META_TIME] !== $time || ($m && !$this->verify($m))) {
						break 2;
					}
				}
			}

			return TRUE;
		} while (FALSE);

		$this->remove($meta[self::KEY]); // meta[handle] & meta[file] was added by readMetaAndLock()
		return FALSE;
	}



	/**
	 * @param string $key
	 * @return void
	 */
	public function lock($key)
	{
		$this->client->lock($this->formatEntryKey($key));
	}



	/**
	 * @internal
	 * @param string $key
	 */
	public function unlock($key)
	{
		$this->client->unlock($this->formatEntryKey($key));
	}



	/**
	 * Writes item into the cache.
	 *
	 * @param string $key
	 * @param mixed $data
	 * @param array $dp
	 *
	 * @throws \Nette\InvalidStateException
	 * @return void
	 */
	public function write($key, $data, array $dp)
	{
		$meta = array(
			self::META_TIME => microtime(),
		);

		if (isset($dp[Cache::EXPIRATION])) {
			if (empty($dp[Cache::SLIDING])) {
				$meta[self::META_EXPIRE] = $dp[Cache::EXPIRATION] + time(); // absolute time

			} else {
				$meta[self::META_DELTA] = (int)$dp[Cache::EXPIRATION]; // sliding time
			}
		}

		if (isset($dp[Cache::ITEMS])) {
			foreach ((array)$dp[Cache::ITEMS] as $itemName) {
				$m = $this->readMeta($itemName);
				$meta[self::META_ITEMS][$itemName] = $m[self::META_TIME]; // may be NULL
				unset($m);
			}
		}

		if (isset($dp[Cache::CALLBACKS])) {
			$meta[self::META_CALLBACKS] = $dp[Cache::CALLBACKS];
		}

		if (isset($dp[Cache::TAGS]) || isset($dp[Cache::PRIORITY])) {
			if (!$this->journal) {
				throw new Nette\InvalidStateException('CacheJournal has not been provided.');
			}
			$this->journal->write($key, $dp);
		}

		if (!is_string($data) || $data === NULL) {
			$data = serialize($data);
			$meta[self::META_SERIALIZED] = TRUE;
		}

		$meta = Json::encode($meta);

		try {
			$this->client->multi();
			if (isset($dp[Cache::EXPIRATION])) {
				$this->client->setEX($this->formatMetaKey($key), $dp[Cache::EXPIRATION], $meta);
				$this->client->setEX($this->formatEntryKey($key), $dp[Cache::EXPIRATION], $data);

			} else {
				$this->client->mSet(array(
					$this->formatMetaKey($key) => $meta,
					$this->formatEntryKey($key) => $data,
				));
			}
			$this->unlock($key);
			$this->client->exec();

		} catch (RedisClientException $e) {
			$this->remove($key);
			throw new Nette\InvalidStateException($e->getMessage(), $e->getCode(), $e);
		}
	}



	/**
	 * Removes item from the cache.
	 *
	 * @param string $key
	 */
	public function remove($key)
	{
		$this->client->del(
			$this->formatEntryKey($key),
			$this->formatMetaKey($key)
		);
	}



	/**
	 * Removes items from the cache by conditions & garbage collector.
	 *
	 * @param array $conds
	 *
	 * @return void
	 */
	public function clean(array $conds)
	{
		// cleaning using file iterator
		if (!empty($conds[Cache::ALL])) {
			call_user_func_array(array($this->client, 'del'), $this->client->keys(self::NS_NETTE . ':*'));

			if ($this->journal) {
				$this->journal->clean($conds);
			}
			return;
		}

		// cleaning using journal
		if ($this->journal) {
			foreach ($this->journal->clean($conds) as $key) {
				$this->remove($key);
			}
		}
	}



	/**
	 * @param string $key
	 * @return string
	 */
	protected function formatMetaKey($key)
	{
		return self::NS_NETTE . ':' . str_replace(Cache::NAMESPACE_SEPARATOR, ':', $key) . ':' . self::NS_META;
	}



	/**
	 * @param string $key
	 * @return string
	 */
	protected function formatEntryKey($key)
	{
		return self::NS_NETTE . ':' . str_replace(Cache::NAMESPACE_SEPARATOR, ':', $key);
	}



	/**
	 * @param string $key
	 *
	 * @return array
	 */
	protected function readMeta($key)
	{
		if ($meta = $this->client->get($this->formatMetaKey($key))) {
			try {
				$meta = Json::decode($meta, JSON::FORCE_ARRAY);
			} catch (Nette\Utils\JsonException $e) {
				$meta = array();
				Nette\Diagnostics\Debugger::log($e, 'redis-error');
			}
			$meta[self::KEY] = $key;
			return $meta;
		}

		return NULL;
	}

}
