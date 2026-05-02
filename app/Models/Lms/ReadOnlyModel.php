<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Exceptions\LmsWriteException;
use Illuminate\Database\Eloquent\Model;

abstract class ReadOnlyModel extends Model
{
    protected $connection = 'lms';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            throw new LmsWriteException(static::class, 'insert');
        });

        static::saving(function (self $model): void {
            throw new LmsWriteException(static::class, 'save');
        });

        static::updating(function (self $model): void {
            throw new LmsWriteException(static::class, 'update');
        });

        static::deleting(function (self $model): void {
            throw new LmsWriteException(static::class, 'delete');
        });
    }

    public function save(array $options = []): bool
    {
        throw new LmsWriteException(static::class, 'save');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LmsWriteException(static::class, 'update');
    }

    public function delete(): ?bool
    {
        throw new LmsWriteException(static::class, 'delete');
    }

    public function forceDelete(): ?bool
    {
        throw new LmsWriteException(static::class, 'delete');
    }

    public function insert(array $values): bool
    {
        throw new LmsWriteException(static::class, 'insert');
    }
}
