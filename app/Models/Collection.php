<?php

namespace App\Models;

use App\Dto\CollectionTemplateDto;
use Database\Factories\CollectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A product collection. Two levels at most: a collection with a parent cannot
 * have children, and a parent cannot have a parent.
 *
 * In files that already import Illuminate\Support\Collection, import this
 * model as ProductCollection.
 *
 * @property int $id
 * @property ?int $parent_id
 * @property string $name
 * @property string $slug
 * @property ?string $description
 * @property ?string $icon
 * @property ?string $image_path
 * @property bool $enabled
 * @property int $weight
 * @property CollectionTemplateDto $template
 * @property int $user_id
 * @property Carbon $created_at
 * @property ?Collection $parent
 * @property \Illuminate\Database\Eloquent\Collection<int, Collection> $children
 * @property \Illuminate\Database\Eloquent\Collection<int, Product> $products
 */
class Collection extends Model
{
    /** @use HasFactory<CollectionFactory> */
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'icon',
        'image_path',
        'enabled',
        'weight',
        'template',
        'user_id',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'weight' => 'integer',
    ];

    public static function booted(): void
    {
        static::saving(function (Collection $collection): void {
            if (blank($collection->slug) || $collection->isDirty('name')) {
                $collection->slug = Str::slug($collection->name);
            }

            $collection->assertDepth();
        });
    }

    /**
     * A parent cannot have a parent, and a collection with children cannot get one.
     *
     * @throws InvalidArgumentException
     */
    public function assertDepth(): void
    {
        if ($this->parent_id === null) {
            return;
        }

        if ($this->exists && (int) $this->parent_id === (int) $this->getKey()) {
            throw new InvalidArgumentException(__('A collection cannot be its own parent.'));
        }

        $parent = static::query()->find($this->parent_id);

        if ($parent?->parent_id !== null) {
            throw new InvalidArgumentException(__('Collections have at most two levels: the parent already has a parent.'));
        }

        if ($this->exists && $this->children()->exists()) {
            throw new InvalidArgumentException(__('A collection with subcollections cannot get a parent.'));
        }
    }

    protected function template(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): CollectionTemplateDto => CollectionTemplateDto::fromArray(
                $value !== null ? (array) json_decode($value, true) : null
            ),
            set: fn (CollectionTemplateDto|array|null $value): ?string => $value === null
                ? null
                : json_encode(($value instanceof CollectionTemplateDto ? $value : CollectionTemplateDto::fromArray($value))->toArray()),
        );
    }

    /**
     * @return BelongsTo<Collection, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Collection::class, 'parent_id');
    }

    /**
     * @return HasMany<Collection, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Collection::class, 'parent_id')->orderBy('weight')->orderBy('name');
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'collection_product');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Enabled, and (top level, or the parent is enabled too).
     *
     * @param  Builder<Collection>  $query
     * @return Builder<Collection>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('collections.enabled', true)
            ->where(fn (Builder $query) => $query
                ->whereNull('collections.parent_id')
                ->orWhereHas('parent', fn (Builder $parent) => $parent->where('enabled', true)));
    }

    /**
     * @param  Builder<Collection>  $query
     * @return Builder<Collection>
     */
    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * @param  Builder<Collection>  $query
     * @return Builder<Collection>
     */
    public function scopeCurrentUser(Builder $query): Builder
    {
        return $query->where('collections.user_id', auth()->id());
    }

    public function isActive(): bool
    {
        return $this->enabled && ($this->parent === null || $this->parent->enabled);
    }

    /**
     * "Parent › Child" for lists.
     */
    public function fullName(): string
    {
        return $this->parent ? $this->parent->name.' › '.$this->name : $this->name;
    }
}
