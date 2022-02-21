![Test Status](https://github.com/libresign/behat-builtin-extension/workflows/behat/badge.svg?branch=main)

# Extension to use built-in PHP server on Behat tests

## Instalation

```bash
composer require libresign/behat-builtin-extension
```

## Configuration

Add the extension to your `behat.yml`:

```yaml
default:
  extensions:
      LibreSign\BehatBuiltinExtension\ServiceContainer\CallExtension:
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
