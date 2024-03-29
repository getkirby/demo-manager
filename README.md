# Kirby Demo Manager

The Kirby Demo Manager powers the Kirby demo at <https://trykirby.com>.

## Installation and administration

1. Create a template repo. The contents of that repository will be set up for each demo user. You can customize the demo build with a `.hooks.php` file at the root of your template repo. Our own template repo is the [Kirby Demokit](https://github.com/getkirby/demokit).
2. Clone the `demo-manager` repository to a directory that can be accessed by your web server.
3. Install the Composer dependencies with `composer install`.
4. Create an empty `data` directory inside the `demo-manager` and create a `config.php` file within it. You can find all configuration options in the [source code](src/Demo/Config.php). The bare minimum are the following options:

```php
<?php

return [
    'indexResponse'  => 'https://example.com/try',
    'statusResponse' => 'https://example.com/try/{{ type }}:{{ status }}',
    'templateUrl'    => 'https://github.com/ghost/example-repo/archive/{{ buildId }}.zip#example-repo-{{ buildId }}'
];
```

5. Point your web server at the Demo Manager's `public` directory and ensure that the web server takes each subfolder into account as well by respecting the instances' `index.php` files. For Apache setups this is a given due to `.htaccess`, other servers may need custom config (see our [example nginx config](etc/nginx.conf)).
6. Set up the following cronjobs:
   - `bin/demo_cleanup` (recommendation: every 10 minutes)
   - `bin/demo_prepare` (recommendation: every minute, only in production)
   - `bin/demo_stats --csv >> /your/path/to/stats.csv` (optional, at a custom interval; alternatively you can request the `/stats` URL manually or with an external tool)
7. If your template repo is hosted on GitHub, you can automate the template build by creating a `push` webhook to the URL `https://example.com/build` with the `webhookSecret` you have configured in the configuration above. Otherwise you can manually build the template with the `bin/demo_build` command.

## Integration with a frontend

The Kirby Demo Manager does *not* ship with any user interface or site frontend.

### Create new instances

To create a new demo instance, make the user send a `POST` request to the root of your installation. On the [Kirby website](https://getkirby.com/try) we use a simple `<form action="https://trykirby.com" method="POST">` for this.

### Index and status responses

The configured **index response** (URL or closure) is used to respond to requests to the root of your demo installation.

The configured **status response** (URL or closure) is used for all kinds of status information. The following combinations of type and status are currently in use:

- `error:referrer` (if the creation request came from an invalid referrer)
- `error:overload` (if too many active demo instances exist)
- `error:rate-limit` (if the IP address of the visitor has created too many instances)
- `error:not-found` (for requests to instances that don't exist or have been deleted)
- `error:unexpected` (for fatal errors in the Demo Manager code)

Additional status responses can be created from instances of your demo template. E.g. our Demokit sends a `status:deleted` status response/redirect when the user has manually deleted their instance.

## How it works

- The template ZIP is fetched and unpacked. Afterwards it is pre-processed using its optional included build script. The resulting template directory is stored as `data/template` and copied to every demo instance.
- All demo instances are kept in a SQLite database in `data/instances.sqlite`. This allows to keep an overview of all active and prepared instances and the connected IP address hash and expiry time for each active instance.
- "Preparing" an instance means to copy it to the `public` folder ahead of its actual creation by a user. This increases creation performance as a prepared instance can quickly be activated by simply updating the database entry.
- A `data/.lock` file is used to prevent simultaneous template builds and instance creations.
- The actual demo instances are fully independent from the Demo Manager and are expected to be served directly by the web server as subfolder installations (however they can of course [load the code of the Demo Manager](https://github.com/getkirby/demokit/blob/main/index.php) to [access data](https://github.com/getkirby/demokit/blob/main/site/plugins/demo/index.php) such as their connected IP address hash and expiry time). Requests to deleted instances need to fall back to the Demo Manager's `index.php` at the root of the `public` folder.

## License

<http://www.opensource.org/licenses/mit-license.php>

## Author

Lukas Bestle <https://getkirby.com>
