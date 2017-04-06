<?php

namespace BuildingTest\Acceptance\Building;

use Assert\Assertion;
use Behat\Behat\Context\Context;
use Building\Domain\Aggregate\Building;
use Building\Domain\DomainEvent\CheckInAnomalyDetected;
use Building\Domain\DomainEvent\UserCheckedIn;
use Building\Domain\Finder\IsUserBlacklistedInterface;
use Rhumsaa\Uuid\Uuid;

final class CheckInCheckOutContext implements Context
{
    private $givens = [];
    /**
     * @var Building
     */
    private $building;

    /**
     * @var string
     */
    private $username;

    public function __construct()
    {
        $this->username = uniqid('randomUser', true);
    }

    /**
     * @Given there is a building
     */
    public function there_is_a_building()
    {
        $this->building = Building::new('Komed');

        $popReflection = new \ReflectionMethod($this->building, 'popRecordedEvents');

        $popReflection->setAccessible(true);
        $popReflection->invoke($this->building);
    }

    /**
     * @Given a user has checked in
     */
    public function a_user_has_checked_in()
    {
        $this->building->whenUserCheckedIn(UserCheckedIn::toBuilding(
            Uuid::fromString($this->building->id()),
            $this->username
        ));
    }

    /**
     * @When the user checks in
     */
    public function the_user_checks_in()
    {
        $this->building->checkInUser(
            $this->username,
            new class implements IsUserBlacklistedInterface
            {
                public function __invoke(string $username) : bool
                {
                    return false;
                }
            }
        );
    }

    /**
     * @Then a checkin anomaly should have been detected
     */
    public function a_checkin_anomaly_should_have_been_detected()
    {
        $popReflection = new \ReflectionMethod($this->building, 'popRecordedEvents');

        $popReflection->setAccessible(true);

        $events = $popReflection->invoke($this->building);

        Assertion::isInstanceOf($events[0], UserCheckedIn::class);
        Assertion::isInstanceOf($events[1], CheckInAnomalyDetected::class);
    }
}