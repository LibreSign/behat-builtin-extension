[![Behat](https://github.com/LibreSign/behat-builtin-extension/actions/workflows/behat.yml/badge.svg)](https://github.com/LibreSign/behat-builtin-extension/actions/workflows/behat.yml)

# Extension to use built-in PHP server on Behat tests

## Instalation

```bash
composer require libresign/behat-builtin-extension
vendor/bin/behat --init
```

## Configuration

Add the extension to your `behat.yml`:

```yaml
default:
  extensions:
    PhpBuiltin\Server:
      verbose: false
      rootDir: /var/www/html
      host: localhost
```

### Config values

| config  | default       | Environment    | Description                                                                                                                                                      |
| ------- | ------------- | -------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| verbose | false         | BEHAT_VERBOSE  | Enables/disables verbose mode                                                                                                                                    |
| rootDir | /var/www/html | BEHAT_ROOT_DIR | Specifies http root dir                                                                                                                                          |
| host    | localhost     | BEHAT_HOST     | Host domain or IP                                                                                                                                                |
| runAs   |               | BEHAT_RUN_AS   | The username to be used to run the built-in server                                                                                                               |
| workers | 0             | BEHAT_WORKERS  | The quantity of workers to use. More informations [here](https://www.php.net/manual/en/features.commandline.webserver.php) searching by `PHP_CLI_SERVER_WORKERS` |

You can also use `-v` option to enable verbose mode. Example
```bash
vendor/bin/behat -v
```
The verbose mode will show:
* The rootDir, host, runAs and workers used
* When the PHP built-in server starts: PID, host, port, worker count and log path
* PHP built-in server stdout/stderr captured for the suite
* Process exit status when the server terminates
* A clear message when teardown finds that the server process is already gone (instead of only `kill: No such process`)

## Usage example

```php
<?php

use Behat\Behat\Context\Context;
use PhpBuiltin\RunServerListener;

/**
 * Defines application features from the specific context.
 */
class FeatureContext implements Context
{
    private string $baseUrl;
    public function __construct()
    {
        $this->baseUrl = RunServerListener::getServerRoot();
    }

    public function sendRequest(string $verb, string $url, ?array $body = null, array $headers = []): void
    {
        $client = new Client();

        $fullUrl = $this->baseUrl . ltrim($url, '/');

        $options['headers'] = $headers;

        if (is_array($body)) {
            $options['form_params'] = $body;
        }

        try {
            $this->response = $client->{$verb}($fullUrl, $options);
        } catch (ClientException $e) {
            $this->response = $e->getResponse();
        } catch (ServerException $e) {
            $this->response = $e->getResponse();
        }
    }
}
```


## Diagnosing unexpected server failures

The extension checks the PHP built-in server before and after each Behat scenario.
If the server process or listener disappears, the run fails with a server infrastructure
error instead of allowing later scenarios to fail with unrelated connection errors.

When verbose mode is enabled (for example with `BEHAT_VERBOSE=1` or Behat `-v`),
the extension additionally:

- records the process group, listener state, memory state, core dump limit and core pattern;
- tracks the number of PHP worker processes observed after startup and reports worker loss;
- captures the PHP server exit status and stdout/stderr;
- enables core dumps on a best-effort basis with `ulimit -c unlimited`;
- preserves the diagnostic files after an unexpected server failure.

The extension deliberately does not restart a crashed server automatically. A request may
have changed application state before PHP terminated, and continuing the suite with a new
server could hide the original failure or make following scenarios unreliable.

Normal non-verbose runs do not perform worker-count supervision or emit the additional
diagnostic output.
