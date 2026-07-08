<?php

namespace Modules\Tenant\Support;

class Tenant
{
    protected ?int $id = null;

    public function id(): ?int
    {
        return $this->id;
    }

    public function set(?int $id): void
    {
        $this->id = $id;
    }
}
