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

  @diagnostic-crash
  Scenario: Unexpected PHP crash is detected by the extension
    Given server is up
    When kill server unexpectedly with "SEGV"

  @diagnostic-crash
  Scenario: Scenarios after an unexpected crash are not executed
    Then diagnostic marker after crash is executed
