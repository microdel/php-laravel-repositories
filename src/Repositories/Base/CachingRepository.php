<?php

namespace Saritasa\Repositories\Base;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Saritasa\DingoApi\Paging\CursorRequest;
use Saritasa\DingoApi\Paging\CursorResult;
use Saritasa\DingoApi\Paging\PagingInfo;
use Saritasa\Exceptions\RepositoryException;

class CachingRepository implements IRepository
{
    /**
     * @var IRepository
     */
    private $repo;
    /**
     * @var string
     */
    private $prefix;
    /**
     * @var int
     */
    private $cacheTimeout;

    /* @var string */
    private $modelClass;

    public function __construct(IRepository $repository, string $prefix, int $cacheTimeout = 10)
    {
        $this->repo = $repository;
        $this->prefix = $prefix;
        $this->cacheTimeout = $cacheTimeout;
        $this->modelClass = $repository->getModelClass();
    }

    private function cached(string $key, Closure $dataGetter)
    {
        if (Cache::has($key)) {
            return Cache::get($key);
        }
        $result = $dataGetter();
        Cache::put($key, $result, $this->cacheTimeout);
        return $result;
    }

    public function getModelValidationRules(Model $model = null): array
    {
        return $this->repo->getModelValidationRules($model);
    }

    public function findOrFail($id): Model
    {
        $result = $this->cachedFind($id);
        if (!$result) {
            throw new RepositoryException($this, "$this->modelClass with ID=$id was not found");
        }
        return $result;
    }

    public function findOrNew($id): Model
    {
        $result = $this->cachedFind($id);
        return $result ?: new $this->modelClass;
    }

    public function findWhere(array $fieldValues)//: Model
    {
        $key = $this->prefix.":find:".md5(serialize($fieldValues));
        return $this->cached($key, function () use ($fieldValues) {
            return $this->repo->findWhere($fieldValues);
        });
    }

    public function create(Model $model): Model
    {
        return $this->repo->save($model);
    }

    public function save(Model $model): Model
    {
        $result = $this->repo->save($model);
        $this->invalidate($model);
        return $result;
    }

    public function delete(Model $model)
    {
        $this->repo->delete($model);
        $this->invalidate($model);
    }

    public function get(): Collection
    {
        return $this->cached("$this->prefix:all", function () {
            return $this->repo->get();
        });
    }

    public function getWhere(array $fieldValues): Collection
    {
        $key = $this->prefix.":get:".md5(serialize($fieldValues));
        return $this->cached($key, function () use ($fieldValues) {
            return $this->repo->getWhere($fieldValues);
        });
    }

    public function getPage(PagingInfo $paging, array $fieldValues = null): LengthAwarePaginator
    {
        $key = $this->prefix.":page:".md5(serialize($paging->toArray()).serialize($fieldValues));
        return $this->cached($key, function () use ($paging, $fieldValues) {
            return $this->repo->getPage($paging, $fieldValues);
        });
    }

    public function getCursorPage(CursorRequest $cursor, array $fieldValues = null): CursorResult
    {
        $key = $this->prefix.":page:".md5(serialize($cursor->toArray()).serialize($fieldValues));
        return $this->cached($key, function () use ($cursor, $fieldValues) {
            return $this->repo->getCursorPage($cursor, $fieldValues);
        });
    }

    private function find($id) // : Model
    {
        $result = $this->repo->findOrNew($id);
        if ($result->exists) {
            return $result;
        }
        return null;
    }

    private function cachedFind($id) //: Model
    {
        return $this->cached("$this->prefix:$id", function () use ($id) {
            return $this->find($id);
        });
    }

    private function invalidate(Model $model)
    {
        $key = $this->prefix.":".$model->getKey();
        if (Cache::has($key)) {
            Cache::forget($key);
        }
        Cache::forget("$this->prefix.:all");
    }

    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    public function __call($name, $arguments)
    {
        if (0 === strpos($name, 'get')) {
            $argHash = md5(serialize($arguments));
            $key = "$this->prefix:$name:$argHash";
            return $this->cached($key, function () use ($name, $arguments) {
                return call_user_func_array([$this->repo, $name], $arguments);
            });
        }
        throw new RepositoryException($this, "Caching repository proxies only get* methods");
    }

    public function __get($name)
    {
        return $this->cached("$this->prefix:$name", function () use ($name) {
            return $this->repo->$name;
        });
    }
}
