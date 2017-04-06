<?php

declare(strict_types=1);

namespace Building\Domain\Finder;

interface IsUserBlacklistedInterface
{
    public function __invoke(string $username) : bool;
}
