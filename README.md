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

| config  | default       | Environment    | Description                   |
| ------- | ------------- | -------------- | ----------------------------- |
| verbose | false         | none           | Enables/disables verbose mode |
| rootDir | /var/www/html | BEHAT_HOST     | Specifies http root dir       |
| host    | localhost     | BEHAT_ROOT_DIR | Host domain or IP             |

You can also use `-v` option to enable verbose mode. Example
```bash
vendor/bin/behat -v
```
The verbose mode will show:
* The rootDir used
* The output of PHP Built-in server
