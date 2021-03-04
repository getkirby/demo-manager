# Kirby Demo Manager

The Kirby Demo Manager powers the Kirby demo at <https://trykirby.com>.

## Installation and usage

1. Create a template repo. The contents of that repository will be set up for each demo user. You can customize the demo build with a `.hooks.php` file at the root of your template repo. Our own template repo is the [Kirby Demokit](https://github.com/getkirby/demokit).
2. Clone the `demo-manager` repository to a directory that can be accessed by your webserver.
3. Install the Composer dependencies with `composer install`.
4. Create an empty `data` directory inside the `demo-manager` and create a `config.php` file within it. You can find all configuration options in the [source code](src/Demo/Config.php). The bare minimum are the following options:

```php
<?php

return [
    'indexResponse'  => 'https://example.com/try',
    'statusResponse' => 'https://example.com/try/{{ type }}:{{ status }}',
    'templateUrl'    => 'https://github.com/ghost/example-repo/archive/main.zip#example-repo-main',
    'webhookOrigins' => ['ghost/example-repo#refs/heads/main'],
    'webhookSecret'  => '...',
];
```

5. Point your webserver at the `public` directory inside this repository.
6. Set up the following cronjobs:
   - `bin/demo_cleanup` (recommendation: every 10 minutes)
   - `bin/demo_prepare` (recommendation: every minute, only in production)
   - `bin/demo_stats --csv >> /your/path/to/stats.csv` (optional at a custom interval)
7. If your template repo is hosted on GitHub, you can automate the template build by creating a `push` webhook to the URL `https://example.com/build` with the secret you have configured in the configuration above. Otherwise you can manually build the template with the `bin/demo_build` command.

## License

<http://www.opensource.org/licenses/mit-license.php>

## Author

Lukas Bestle <https://getkirby.com>