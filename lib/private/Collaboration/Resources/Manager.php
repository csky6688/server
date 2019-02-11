<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2018 Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OC\Collaboration\Resources;


use OCP\Collaboration\Resources\CollectionException;
use OCP\Collaboration\Resources\ICollection;
use OCP\Collaboration\Resources\IManager;
use OCP\Collaboration\Resources\IProvider;
use OCP\Collaboration\Resources\IResource;
use OCP\Collaboration\Resources\ResourceException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;

class Manager implements IManager {

	public const TABLE_COLLECTIONS = 'collres_collections';
	public const TABLE_RESOURCES = 'collres_resources';
	public const TABLE_ACCESS_CACHE = 'collres_accesscache';

	/** @var IDBConnection */
	protected $connection;

	/** @var IProvider[] */
	protected $providers = [];

	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

	/**
	 * @param int $id
	 * @return ICollection
	 * @throws CollectionException when the collection could not be found
	 * @since 16.0.0
	 */
	public function getCollection(int $id): ICollection {
		$query = $this->connection->getQueryBuilder();
		$query->select('*')
			->from(self::TABLE_COLLECTIONS)
			->where($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		if (!$row) {
			throw new CollectionException('Collection not found');
		}

		return new Collection($this, $this->connection, (int) $row['id'], (string) $row['name']);
	}

	/**
	 * @param int $id
	 * @param IUser|null $user
	 * @return ICollection
	 * @throws CollectionException when the collection could not be found
	 * @since 16.0.0
	 */
	public function getCollectionForUser(int $id, ?IUser $user): ICollection {
		$query = $this->connection->getQueryBuilder();
		$userId = $user instanceof IUser ? $user->getUID() : '';

		$query->select('*')
			->from(self::TABLE_COLLECTIONS)
			->leftJoin(
				'r', self::TABLE_ACCESS_CACHE, 'a',
				$query->expr()->andX(
					$query->expr()->eq('c.id', 'a.resource_id'),
					$query->expr()->eq('a.user_id', $query->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
				)
			)
			->where($query->expr()->eq('c.id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		if (!$row) {
			throw new CollectionException('Collection not found');
		}

		$access = $row['access'] === null ? null : (bool) $row['access'];
		if ($user instanceof IUser) {
			$access = [$user->getUID() => $access];
			return new Collection($this, $this->connection, (int) $row['id'], (string) $row['name'], $access, null);
		}

		return new Collection($this, $this->connection, (int) $row['id'], (string) $row['name'], [], $access);
	}

	/**
	 * @param IUser $user
	 * @param string $filter
	 * @param int $limit
	 * @param int $start
	 * @return ICollection[]
	 * @since 16.0.0
	 */
	public function searchCollections(IUser $user, string $filter, int $limit = 50, int $start = 0): array {
		$query = $this->connection->getQueryBuilder();
		$userId = $user instanceof IUser ? $user->getUID() : '';

		$query->select('c.*', 'a.access')
			->from(self::TABLE_COLLECTIONS)
			->leftJoin(
				'r', self::TABLE_ACCESS_CACHE, 'a',
				$query->expr()->andX(
					$query->expr()->eq('c.id', 'a.resource_id'),
					$query->expr()->eq('a.user_id', $query->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
				)
			)
			->where($query->expr()->iLike('c.name', $query->createNamedParameter($filter, IQueryBuilder::PARAM_STR)))
			->andWhere($query->expr()->neq('a.access', $query->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->setMaxResults($limit)
			->setFirstResult($start);
		$result = $query->execute();
		$collections = [];

		$foundResults = 0;
		while ($row = $result->fetch()) {
			$foundResults++;
			$access = $row['access'] === null ? null : (bool) $row['access'];
			$collection = new Collection($this, $this->connection, (int)$row['id'], (string)$row['name'], $user, $access);
			if ($collection->canAccess($user)) {
				$collections[] = $collection;
			}
		}
		$result->closeCursor();

		if (empty($collections) && $foundResults === $limit) {
			$this->searchCollections($user, $filter, $limit, $start + $limit);
		}

		return $collections;
	}

	/**
	 * @param string $name
	 * @return ICollection
	 * @since 16.0.0
	 */
	public function newCollection(string $name): ICollection {
		$query = $this->connection->getQueryBuilder();
		$query->insert(self::TABLE_COLLECTIONS)
			->values([
				'name' => $query->createNamedParameter($name),
			]);
		$query->execute();

		return new Collection($this, $this->connection, $query->getLastInsertId(), $name);
	}

	/**
	 * @param string $type
	 * @param string $id
	 * @return IResource
	 * @since 16.0.0
	 */
	public function createResource(string $type, string $id): IResource {
		return new Resource($this, $this->connection, $type, $id);
	}

	/**
	 * @param string $type
	 * @param string $id
	 * @param IUser|null $user
	 * @return IResource
	 * @throws ResourceException
	 * @since 16.0.0
	 */
	public function getResourceForUser(string $type, string $id, ?IUser $user): IResource {
		$query = $this->connection->getQueryBuilder();
		$userId = $user instanceof IUser ? $user->getUID() : '';

		$query->select('r.*', 'a.access')
			->from(self::TABLE_RESOURCES, 'r')
			->leftJoin(
				'r', self::TABLE_ACCESS_CACHE, 'a',
				$query->expr()->andX(
					$query->expr()->eq('r.id', 'a.resource_id'),
					$query->expr()->eq('a.user_id', $query->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
				)
			)
			->where($query->expr()->eq('r.resource_type', $query->createNamedParameter($type, IQueryBuilder::PARAM_STR)))
			->andWhere($query->expr()->eq('r.resource_id', $query->createNamedParameter($id, IQueryBuilder::PARAM_STR)));
		$result = $query->execute();
		$row = $result;
		$result->closeCursor();

		if (!$row) {
			throw new ResourceException('Resource not found');
		}

		$access = $row['access'] === null ? null : (bool) $row['access'];
		if ($user instanceof IUser) {
			return new Resource($this, $this->connection, $type, $id, $user, $access);
		}

		return new Resource($this, $this->connection, $type, $id, null, $access);
	}

	/**
	 * @param ICollection $collection
	 * @param IUser|null $user
	 * @return IResource[]
	 * @since 16.0.0
	 */
	public function getResourcesByCollectionForUser(ICollection $collection, ?IUser $user): array {
		$query = $this->connection->getQueryBuilder();
		$userId = $user instanceof IUser ? $user->getUID() : '';

		$query->select('r.*', 'a.access')
			->from(self::TABLE_RESOURCES, 'r')
			->leftJoin(
				'r', self::TABLE_ACCESS_CACHE, 'a',
				$query->expr()->andX(
					$query->expr()->eq('r.id', 'a.resource_id'),
					$query->expr()->eq('a.user_id', $query->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
				)
			)
			->where($query->expr()->eq('r.collection_id', $query->createNamedParameter($collection->getId(), IQueryBuilder::PARAM_INT)));

		$resources = [];
		$result = $query->execute();
		while ($row = $result->fetch()) {
			$access = $row['access'] === null ? null : (bool) $row['access'];
			$resources[] = new Resource($this, $this->connection, $row['resource_type'], $row['resource_id'], $user, $access);
		}
		$result->closeCursor();

		return $resources;
	}

	/**
	 * @return IProvider[]
	 * @since 16.0.0
	 */
	public function getProviders(): array {
		return $this->providers;
	}

	/**
	 * Get the display name of a resource
	 *
	 * @param IResource $resource
	 * @return string
	 * @since 16.0.0
	 */
	public function getName(IResource $resource): string {
		foreach ($this->getProviders() as $provider) {
			if ($provider->getType() === $resource->getType()) {
				try {
					return $provider->getName($resource);
				} catch (ResourceException $e) {
				}
			}
		}

		return '';
	}

	/**
	 *
	 * @param IResource $resource
	 * @return string
	 */
	public function getIconClass(IResource $resource): string {
		foreach ($this->getProviders() as $provider) {
			if ($provider->getType() === $resource->getType()) {
				try {
					return $provider->getIconClass($resource);
				} catch (ResourceException $e) {
				}
			}
		}

		return '';
	}

	/**
	 * Can a user/guest access the collection
	 *
	 * @param IResource $resource
	 * @param IUser|null $user
	 * @return bool
	 * @since 16.0.0
	 */
	public function canAccessResource(IResource $resource, ?IUser $user): bool {
		$access = false;
		foreach ($this->getProviders() as $provider) {
			if ($provider->getType() === $resource->getType()) {
				try {
					if ($provider->canAccess($resource, $user)) {
						$access = true;
						break;
					}
				} catch (ResourceException $e) {
				}
			}
		}

		$this->cacheAccessForResource($resource, $user, $access);
		return $access;
	}

	/**
	 * Can a user/guest access the collection
	 *
	 * @param ICollection $collection
	 * @param IUser|null $user
	 * @return bool
	 * @since 16.0.0
	 */
	public function canAccessCollection(ICollection $collection, ?IUser $user): bool {
		$access = false;
		foreach ($collection->getResources() as $resource) {
			if ($resource->canAccess($user)) {
				$access = true;
			}
		}

		$this->cacheAccessForCollection($collection, $user, $access);
		return $access;
	}

	public function cacheAccessForResource(IResource $resource, ?IUser $user, bool $access): void {
		$query = $this->connection->getQueryBuilder();
		$userId = $user instanceof IUser ? $user->getUID() : '';

		$query->insert(self::TABLE_ACCESS_CACHE)
			->values([
				'user_id' => $query->createNamedParameter($userId),
				'resource_id' => $query->createNamedParameter($resource->getId()),
				'access' => $query->createNamedParameter($access),
			]);
		$query->execute();
	}

	public function cacheAccessForCollection(ICollection $collection, ?IUser $user, bool $access): void {
		$query = $this->connection->getQueryBuilder();
		$userId = $user instanceof IUser ? $user->getUID() : '';

		$query->insert(self::TABLE_ACCESS_CACHE)
			->values([
				'user_id' => $query->createNamedParameter($userId),
				'collection_id' => $query->createNamedParameter($collection->getId()),
				'access' => $query->createNamedParameter($access),
			]);
		$query->execute();
	}

	public function invalidateAccessCacheForUser(?IUser $user): void {
		$query = $this->connection->getQueryBuilder();
		$userId = $user instanceof IUser ? $user->getUID() : '';

		$query->delete(self::TABLE_ACCESS_CACHE)
			->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)));
		$query->execute();
	}

	public function invalidateAccessCacheForResource(IResource $resource): void {
		$query = $this->connection->getQueryBuilder();

		$query->delete(self::TABLE_ACCESS_CACHE)
			->where($query->expr()->eq('resource_id', $query->createNamedParameter($resource->getId())));
		$query->execute();

		foreach ($resource->getCollections() as $collection) {
			$this->invalidateAccessCacheForCollection($collection);
		}
	}

	public function invalidateAccessCacheForCollection(ICollection $collection): void {
		$query = $this->connection->getQueryBuilder();

		$query->delete(self::TABLE_ACCESS_CACHE)
			->where($query->expr()->eq('collection_id', $query->createNamedParameter($collection->getId())));
		$query->execute();
	}

	public function invalidateAccessCacheForResourceByUser(IResource $resource, ?IUser $user): void {
		$query = $this->connection->getQueryBuilder();
		$userId = $user instanceof IUser ? $user->getUID() : '';

		$query->delete(self::TABLE_ACCESS_CACHE)
			->where($query->expr()->eq('resource_id', $query->createNamedParameter($resource->getId())))
			->andWhere($query->expr()->eq('user_id', $query->createNamedParameter($userId)));
		$query->execute();

		foreach ($resource->getCollections() as $collection) {
			$this->invalidateAccessCacheForCollectionByUser($collection, $user);
		}
	}

	protected function invalidateAccessCacheForCollectionByUser(ICollection $collection, ?IUser $user): void {
		$query = $this->connection->getQueryBuilder();
		$userId = $user instanceof IUser ? $user->getUID() : '';

		$query->delete(self::TABLE_ACCESS_CACHE)
			->where($query->expr()->eq('collection_id', $query->createNamedParameter($collection->getId())))
			->andWhere($query->expr()->eq('user_id', $query->createNamedParameter($userId)));
		$query->execute();
	}

	/**
	 * @param IProvider $provider
	 */
	public function registerResourceProvider(IProvider $provider): void {
		$this->providers[] = $provider;
	}

	/**
	 * Get the type of a resource
	 *
	 * @param IResource $resource
	 * @return string
	 * @since 16.0.0
	 */
	public function getType(): string {
		return '';
	}

	/**
	 * Get the link to a resource
	 *
	 * @param IResource $resource
	 * @return string
	 * @since 16.0.0
	 */
	public function getLink(IResource $resource): string {
		foreach ($this->getProviders() as $provider) {
			if ($provider->getType() === $resource->getType()) {
				try {
					return $provider->getLink($resource);
				} catch (ResourceException $e) {
				}
			}
		}

		return '';
	}
}