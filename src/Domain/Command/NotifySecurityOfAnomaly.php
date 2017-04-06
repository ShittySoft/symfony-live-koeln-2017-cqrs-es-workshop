<?php

declare(strict_types=1);

namespace Building\Domain\Command;

use Prooph\Common\Messaging\Command;
use Rhumsaa\Uuid\Uuid;

final class NotifySecurityOfAnomaly extends Command
{
    /**
     * @var string
     */
    private $username;

    /**
     * @var Uuid
     */
    private $buildingId;

    private function __construct(Uuid $buildingId, string $username)
    {
        $this->init();

        $this->buildingId = $buildingId;
        $this->username   = $username;
    }

    public static function fromBuildingAndUsername(Uuid $buildingId, string $username) : self
    {
        return new self($buildingId, $username);
    }

    public function buildingId() : Uuid
    {
        return $this->buildingId;
    }

    public function username() : string
    {
        return $this->username;
    }

    /**
     * {@inheritDoc}
     */
    public function payload() : array
    {
        return [
            'buildingId' => $this->buildingId->toString(),
            'username' => $this->username,
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function setPayload(array $payload)
    {
        $this->buildingId = Uuid::fromString($payload['buildingId']);
        $this->username = (string) $payload['username'];
    }
}
