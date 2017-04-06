Feature: Users can check-in and check-out

  Scenario: If a user checks in twice without checking out, an anomaly is detected
    Given there is a building
    And a user has checked in
    When the user checks in
    Then a checkin anomaly should have been detected
