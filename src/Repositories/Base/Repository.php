<?php

namespace Saritasa\Repositories\Base;

use DB;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Saritasa\DingoApi\Paging\CursorQueryBuilder;
use Saritasa\DingoApi\Paging\CursorRequest;
use Saritasa\DingoApi\Paging\CursorResult;
use Saritasa\DingoApi\Paging\PagingInfo;
use Saritasa\Exceptions\RepositoryException;

/**
 * Superclass for any repository.
 * Contains logic of receipt the entity list, with filters (search) and sort.
 */
class Repository implements IRepository
{
    /**
     * FQN model name of the repository. Must be determined in the inheritors.
     *
     * Note: PHP 7 allows to use "SomeModel::class" just as property initial value. Use it.
     *
     * @var string
     */
    protected $modelClass;

    /**
     * List of fields, allowed to use in the search
     *
     * Should be determine in the inheritors. Determines the result of the list request of entities.
     *
     * @var array
     */
    protected $searchableFields = [];

    /** @var Model */
    private $model;

    /**
     * Repository constructor.
     *
     * @throws RepositoryException
     */
    public function __construct()
    {
        if (!$this->modelClass) {
            throw new RepositoryException($this, 'Mandatory property $modelClass not defined');
        }
        try {
            $this->model = new $this->modelClass;
        } catch (\Exception $e) {
            throw new RepositoryException($this, "Error creating instance of model $this->modelClass", 500, $e);
        }
        if (!is_a($this->model, Model::class, true)) {
            throw new RepositoryException($this, "$this->modelClass must extend " . Model::class);
        }
    }

    public function __get($key)
    {
        $result = null;
        switch ($key) {
            case 'model':
                $result = new $this->modelClass;
                break;
            case 'searchableFields':
                $result = $this->searchableFields;
                break;
            case 'modelValidationRules':
                $result = $this->getModelValidationRules();
                break;
            default:
                throw new RepositoryException($this, "Unknown property ! $key requested");
        }
        return $result;
    }

    /**
     * Returns the class of model.
     *
     * @return string
     */
    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    /**
     * Get visible fields from model
     *
     * @return array
     */
    public function getVisibleFields()
    {
        return $this->model->getVisible();
    }

    public function getModelValidationRules(Model $model = null): array
    {
        $model = $model ?: new $this->modelClass;
        if (method_exists($model, 'getValidationRules')) {
            return $model->getValidationRules();
        }
        if (isset($this->validationRules)) {
            return $this->validationRules;
        }
        return [];
    }

    /**
     * Find a model by its primary key or throw an exception.
     *
     * @param mixed $id
     * @return Model
     */
    public function findOrFail($id): Model
    {
        return $this->query()->findOrFail($id);
    }

    /**
     * Find a model by its primary key or return fresh model instance.
     *
     * @param $id
     * @return Model
     */
    public function findOrNew($id): Model
    {
        return $this->query()->findOrNew($id);
    }

    /**
     * Find a model by specified rules or return null if model not found.
     *
     * @param array $fieldValues
     * @return Model|null
     */
    public function findWhere(array $fieldValues): ?Model
    {
        return $this->query()->where($fieldValues)->first();
    }

    /**
     * @deprecated Use save() instead to create the model.
     */
    public function create(Model $model): Model
    {
        if (!$model->save()) {
            throw new RepositoryException($this, "Cannot create $this->modelClass record");
        }
        return $model;
    }

    /**
     * Saves the model.
     *
     * @param Model $model The model for saving
     * @return Model
     * @throws RepositoryException
     */
    public function save(Model $model): Model
    {
        if (!$model->save()) {
            if ($model->exists) {
                throw new RepositoryException($this, "Cannot update $this->modelClass record");
            } else {
                throw new RepositoryException($this, "Cannot create $this->modelClass record");
            }
        }

        return $model;
    }

    /**
     * Saves models.
     *
     * @param Model[] $models Models for saving
     * @return array
     */
    public function saveMany(array $models): array
    {
        $this->transaction(function () use ($models) {
            foreach ($models as $model) {
                $model->saveOrFail();
            }
        });

        return $models;
    }

    /**
     * Deletes the model.
     *
     * @param Model $model The model for deleting
     * @throws RepositoryException
     */
    public function delete(Model $model)
    {
        if (!$model->delete()) {
            throw new RepositoryException($this, "Cannot delete $this->modelClass record");
        }
    }

    /**
     * Deletes models.
     *
     * @param array $models Models for deleting
     */
    public function deleteMany(array $models)
    {
        $this->transaction(function () use ($models) {
            foreach ($models as $model) {
                $this->delete($model);
            }
        });
    }

    /**
     * Returns all models.
     *
     * @return Collection
     */
    public function get(): Collection
    {
        return $this->query()->get();
    }

    /**
     * Returns models filtered by specified rules.
     *
     * @param array $fieldValues Rules for filtration
     * @return Collection
     */
    public function getWhere(array $fieldValues): Collection
    {
        return $this->query()->where($fieldValues)->get();
    }

    /**
     * Returns the models split into pages.
     *
     * @param PagingInfo $paging Paging data
     * @param array|null $fieldValues Rules for filtration
     * @return LengthAwarePaginator
     */
    public function getPage(PagingInfo $paging, array $fieldValues = null): LengthAwarePaginator
    {
        $query = $this->query()->where($fieldValues);
        return $query->paginate($paging->pageSize, ['*'], 'page', $paging->page);
    }

    /**
     * @param CursorRequest $cursor Requested cursor parameters
     * @param array|null $fieldValues Rules for filtration
     * @return CursorResult
     */
    public function getCursorPage(CursorRequest $cursor, array $fieldValues = null): CursorResult
    {
        return $this->toCursorResult($cursor, $this->query()->where($fieldValues));
    }

    /**
     * Wrap the query to support cursor pagination with custom sort.
     *
     * @deprecated Now it's default implementation of toCursorResult.
     *
     * @param CursorRequest $cursor Requested cursor parameters
     * @param Builder|QueryBuilder $query
     * @return CursorResult
     */
    protected function toCursorResultWithCustomSort(CursorRequest $cursor, $query)
    {
        return $this->toCursorResult($cursor, $query);
    }

    /**
     * @param CursorRequest $cursor Requested cursor parameters
     * @param Builder|QueryBuilder $query
     * @return CursorResult
     */
    protected function toCursorResult(CursorRequest $cursor, $query): CursorResult
    {
        return (new CursorQueryBuilder($cursor, $query))->getCursor();
    }

    /**
     * Returns the query builder.
     *
     * @return Builder
     */
    protected function query(): Builder
    {
        return $this->model->query();
    }

    /**
     * To run a set of operations within a database transaction.
     *
     * @param callable $callback
     */
    public function transaction(callable $callback)
    {
        return DB::transaction($callback);
    }
}
