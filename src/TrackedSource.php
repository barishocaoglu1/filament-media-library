<?php

namespace BarisDev\MediaLibrary;

class TrackedSource
{
    protected ?string $edit = null;

    protected ?string $routeKey = null;

    /** @var array<string, string> */
    protected array $keys = [];

    /** @var array<string, mixed>|null */
    protected ?array $owner = null;

    public function __construct(
        protected string $table,
        protected string $column,
        protected string $type,
        protected string $label,
        protected ?string $title = null,
    ) {}

    /** Tıklanınca açılacak Filament Resource (ve slug kullanıyorsa URL anahtarı). */
    public function editedBy(string $resource, ?string $routeKey = null): static
    {
        $this->edit = $resource;
        $this->routeKey = $routeKey;

        return $this;
    }

    /** type = json iken aranacak anahtarlar: ['image' => 'id', 'file' => 'path'] */
    public function keys(array $keys): static
    {
        $this->keys = $keys;

        return $this;
    }

    /** Düzenleme sayfası başka kayda aitse (örn. alt kayıt -> ana kayıt). */
    public function ownedBy(string $column, string $table, ?string $routeKey = null): static
    {
        $this->owner = array_filter(['column' => $column, 'table' => $table, 'route_key' => $routeKey]);

        return $this;
    }

    public function toArray(): array
    {
        return array_filter([
            'table' => $this->table,
            'column' => $this->column,
            'type' => $this->type,
            'label' => $this->label,
            'title' => $this->title,
            'edit' => $this->edit,
            'route_key' => $this->routeKey,
            'keys' => $this->keys ?: null,
            'owner' => $this->owner,
        ], fn ($v) => $v !== null);
    }
}
