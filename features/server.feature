Feature: test server
  Scenario: Check if server is up
    Given server is up
    And the host of server is "localhost"
    Then stop server

  Scenario: Start server
    When start server
    Then server is up

  Scenario: Zombies was killed
    Given start server
    And server is up
    When kill all instances
    Then server is down