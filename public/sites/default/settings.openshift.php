<?php

$databases['default']['default'] = [
  'database' => getenv('DRUPAL_DB_NAME'),
  'username' => getenv('DRUPAL_DB_USER'),
  'password' => getenv('DRUPAL_DB_PASS'),
  'prefix' => '',
  'host' => getenv('DRUPAL_DB_HOST'),
  'port' => getenv('DRUPAL_DB_PORT') ?: 3306,
  'namespace' => 'Drupal\Core\Database\Driver\mysql',
  'driver' => 'mysql',
];

$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT') ?: '000';

if ($ssl_ca_path = getenv('AZURE_SQL_SSL_CA_PATH')) {
  $databases['default']['default']['pdo'] = [
    \PDO::MYSQL_ATTR_SSL_CA => $ssl_ca_path,
    \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => FALSE,
  ];

  // Azure specific filesystem fixes.
  $settings['php_storage']['twig']['directory'] = '/tmp';
  $settings['php_storage']['twig']['secret'] = $settings['hash_salt'];
  $settings['file_chmod_directory'] = 16895;
  $settings['file_chmod_file'] = 16895;

  $config['system.performance']['cache']['page']['max_age'] = 86400;
}

if ($es_username = getenv('ELASTICSEARCH_USER')) {
  // Unfortunately there apparently aren't sensible ways of fetching this...
  $regexp = '%([a-z]+\.hel\.fi)%';
  preg_match($regexp, getenv('DRUPAL_REVERSE_PROXY_ADDRESS'), $hostname_parts);
  $config['elasticsearch_connector.cluster.search']['url'] = 'https://elasticsearch-hki-kanslia-tyollisyysptv-' . getenv('REDIS_PREFIX') . '.apps.' . $hostname_parts[1] . ':443';
  $config['elasticsearch_connector.cluster.search']['options']['use_authentication'] = TRUE;
  $config['elasticsearch_connector.cluster.search']['options']['username'] = $es_username;
  if ($password = getenv($es_username)) {
    $config['elasticsearch_connector.cluster.search']['options']['password'] = $password;
  }
}

if (isset($_ENV['REDIS_HOST'])) {
  $settings['redis.connection']['interface'] = 'PhpRedis'; // Can be "Predis".
  $settings['redis.connection']['host']      = [$_ENV['REDIS_HOST']];
  $settings['redis.connection']['instance']  = $_ENV['REDIS_INSTANCE'];
  $settings['redis.connection']['password'] = $_ENV['REDIS_PASSWORD'];
  $settings['cache']['default'] = 'cache.backend.redis';
  $settings['cache_prefix'] = $_ENV['REDIS_PREFIX'] . '_';
}
