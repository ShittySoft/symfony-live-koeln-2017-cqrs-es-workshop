<?php

declare(strict_types=1);

namespace Building\Domain\Aggregate;

use Building\Domain\DomainEvent\CheckInAnomalyDetected;
use Building\Domain\DomainEvent\NewBuildingWasRegistered;
use Building\Domain\DomainEvent\UserCheckedIn;
use Building\Domain\DomainEvent\UserCheckedOut;
use Building\Domain\Finder\IsUserBlacklistedInterface;
use Prooph\EventSourcing\AggregateRoot;
use Rhumsaa\Uuid\Uuid;

final class Building extends AggregateRoot
{
    /**
     * @var Uuid
     */
    private $uuid;

    /**
     * @var string
     */
    private $name;

    /**
     * @var null[] indexed by username
     */
    private $checkedInUsers = [];

    public static function new(string $name) : self
    {
        $self = new self();

        $self->recordThat(NewBuildingWasRegistered::occur(
            (string) Uuid::uuid4(),
            [
                'name' => $name
            ]
        ));

        return $self;
    }

    public function checkInUser(string $username, IsUserBlacklistedInterface $blacklisted)
    {
        if ($blacklisted($username)) {
            throw new \DomainException(sprintf('User "%s" is blacklisted, and cannot check in', $username));
        }

        $anomalyDetected = \array_key_exists($username, $this->checkedInUsers);

        $this->recordThat(UserCheckedIn::toBuilding($this->uuid, $username));

        if ($anomalyDetected) {
            $this->recordThat(CheckInAnomalyDetected::fromUser($this->uuid, $username));
        }
    }

    public function checkOutUser(string $username)
    {
        $anomalyDetected = ! \array_key_exists($username, $this->checkedInUsers);

        $this->recordThat(UserCheckedOut::toBuilding($this->uuid, $username));

        if ($anomalyDetected) {
            $this->recordThat(CheckInAnomalyDetected::fromUser($this->uuid, $username));
        }
    }

    public function whenNewBuildingWasRegistered(NewBuildingWasRegistered $event)
    {
        $this->uuid = $event->uuid();
        $this->name = $event->name();
    }

    public function whenUserCheckedIn(UserCheckedIn $event)
    {
        $this->checkedInUsers[$event->username()] = null;
    }

    public function whenUserCheckedOut(UserCheckedOut $event)
    {
        unset($this->checkedInUsers[$event->username()]);
    }

    public function whenCheckInAnomalyDetected(CheckInAnomalyDetected $event)
    {
        // empty, on purpose
    }

    /**
     * {@inheritDoc}
     */
    protected function aggregateId() : string
    {
        return (string) $this->uuid;
    }

    /**
     * {@inheritDoc}
     */
    public function id() : string
    {
        return $this->aggregateId();
    }
}
