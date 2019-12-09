<?php

declare(strict_types=1);

namespace Platformsh\LaravelBridge;

use Platformsh\ConfigReader\Config;

mapPlatformShEnvironment();

/**
 * Map Platform.Sh environment variables to the values Laravel expects.
 *
 * This is wrapped up into a function to avoid executing code in the global
 * namespace.
 */
function mapPlatformShEnvironment() : void
{
    $config = new Config();

    if (!$config->inRuntime()) {
        return;
    }

    // Laravel needs an accurate base URL.
    mapAppUrl($config);

    // Set the application secret if it's not already set.
    $secret = getenv('APP_KEY');
    if (!$secret && $config->projectEntropy) {
        $secret = "base64:" . base64_encode(substr(base64_decode($config->projectEntropy), 0, 32));
    }
    // This value must always be defined, even if it's set to false/empty.
    setEnvVar('APP_KEY', $secret);

    // Force secure cookies on by default, since Platform.sh is SSL-all-the-things.
    // It can be overridden explicitly.
    $secure_cookie = getenv('SESSION_SECURE_COOKIE') ?: 1;
    setEnvVar('SESSION_SECURE_COOKIE', $secure_cookie);

    // Map services as feasible.
    mapPlatformShDatabase('database', $config);
    mapPlatformShRedisCache('rediscache', $config);
    mapPlatformShRedisSession('redissession', $config);
    mapPlatformShMail($config);

    // @TODO Should we support a redisqueue service as well?

}

/**
 * Sets an environment variable in all the myriad places PHP can store it.
 *
 * @param string $name
 *   The name of the variable to set.
 * @param mixed $value
 *   The value to set.  Null to unset it.
 */
function setEnvVar(string $name, $value = null) : void
{
    if (!putenv("$name=$value")) {
        throw new \RuntimeException('Failed to create environment variable: ' . $name);
    }
    $order = ini_get('variables_order');
    if (stripos($order, 'e') !== false) {
        $_ENV[$name] = $value;
    }
    if (stripos($order, 's') !== false) {
        if (strpos($name, 'HTTP_') !== false) {
            throw new \RuntimeException('Refusing to add ambiguous environment variable ' . $name . ' to $_SERVER');
        }
        $_SERVER[$name] = $value;
    }
}

function mapAppUrl(Config $config) : void
{
    // If the APP_URL is already set, leave it be.
    if (getenv('APP_URL')) {
        return;
    }

    // If not on Platform.sh, say in a local dev environment, simply
    // do nothing.  Users need to set the host pattern themselves
    // in a .env file.
    if (!$config->inRuntime()) {
        return;
    }

    foreach ($config->routes() as $url => $route) {
        $host = parse_url($url, PHP_URL_HOST);
        // This conditional translates to "if it's the route for this app".
        // Note: wildcard routes are not currently supported by this code.
        if ($host !== FALSE && $route['type'] == 'upstream' && $route['upstream'] == $config->applicationName) {
            setEnvVar('APP_URL', $url);
            return;
        }
    }
}

function mapPlatformShDatabase(string $relationshipName, Config $config) : void
{
    if (!$config->hasRelationship($relationshipName)) {
        return;
    }

    $credentials = $config->credentials($relationshipName);

    setEnvVar('DB_CONNECTION', $credentials['scheme']);
    setEnvVar('DB_HOST', $credentials['host']);
    setEnvVar('DB_PORT', $credentials['port']);
    setEnvVar('DB_DATABASE', $credentials['path']);
    setEnvVar('DB_USERNAME', $credentials['username']);
    setEnvVar('DB_PASSWORD', $credentials['password']);
}

function mapPlatformShRedisCache(string $relationshipName, Config $config) : void
{
    if (!$config->hasRelationship($relationshipName)) {
        return;
    }

    $credentials = $config->credentials($relationshipName);

    setEnvVar('CACHE_DRIVER', 'redis');
    setEnvVar('REDIS_CLIENT', 'phpredis');
    setEnvVar('REDIS_HOST', $credentials['host']);
    setEnvVar('REDIS_PORT', $credentials['port']);
}

function mapPlatformShRedisSession(string $relationshipName, Config $config) : void
{
    if (!$config->hasRelationship($relationshipName)) {
        return;
    }

    $credentials = $config->credentials($relationshipName);

    setEnvVar('SESSION_DRIVER', 'redis');
    setEnvVar('REDIS_CLIENT', 'phpredis');
    setEnvVar('REDIS_HOST', $credentials['host']);
    setEnvVar('REDIS_PORT', $credentials['port']);
}

function mapPlatformShMail(Config $config) : void
{
    if (!isset($config->smtpHost)) {
        return;
    }

    setEnvVar('MAIL_DRIVER', 'smtp');
    setEnvVar('MAIL_HOST', $config->smtpHost);
    setEnvVar('MAIL_PORT', '25');
    setEnvVar('MAIL_ENCRYPTION', '0');
}
