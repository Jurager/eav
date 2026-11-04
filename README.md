# Jurager/Eav
[![Latest Stable Version](https://poser.pugx.org/jurager/eav/v/stable)](https://packagist.org/packages/jurager/eav)
[![Total Downloads](https://poser.pugx.org/jurager/eav/downloads)](https://packagist.org/packages/jurager/eav)
[![PHP Version Require](https://poser.pugx.org/jurager/eav/require/php)](https://packagist.org/packages/jurager/eav)
[![License](https://poser.pugx.org/jurager/eav/license)](https://packagist.org/packages/jurager/eav)
[![Ko-fi](https://img.shields.io/badge/Ko--fi-support-FF5E5B?logo=ko-fi&logoColor=white)](https://ko-fi.com/jurager01)

Add typed, localized attributes to Eloquent models, with support for inheritance and select fields.

```php
$product->eav()->set('color', 'blue')->save();

$product->eav()->value('color'); // 'blue'
```

## Requirements

`PHP >= 8.4` and `Laravel 13.x`

## Installation

```bash
composer require jurager/eav
```

See [installation and setup](docs/getting-started.md). The full [documentation](docs/_index.md) is also available [online](https://docs.gerassimov.me/eav/).

## Testing

```bash
vendor/bin/phpunit
```

The suite runs on SQLite in memory. To run it on PostgreSQL, which has its own column types and indexes, point it at an empty database:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=eav_test DB_USERNAME=postgres vendor/bin/phpunit
```

## License

Open source, licensed under the [MIT license](LICENSE.md).
