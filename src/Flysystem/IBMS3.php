<?php

namespace Drupal\flysystem_ibmcloud_s3\Flysystem;

use Aws\AwsClientInterface;
use Aws\S3\S3Client;
use Drupal\Component\Utility\UrlHelper;
use Drupal\flysystem_s3\AwsCacheAdapter;
use Drupal\flysystem_s3\Flysystem\Adapter\S3Adapter;
use Drupal\flysystem_s3\Flysystem\S3;
use League\Flysystem\Config;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Drupal plugin for the "IBM Cloud S3" Flysystem adapter.
 *
 * @Adapter(id = "ibmcloud_s3")
 */
class IBMS3 extends S3 {

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The config.
   *
   * @var \League\Flysystem\Config
   */
  protected $config;

  /**
   * Constructs an IBM Cloud S3 object.
   *
   * @param \Aws\AwsClientInterface $client
   *   The AWS client.
   * @param \League\Flysystem\Config $config
   *   The configuration.
   */
  public function __construct(AwsClientInterface $client, Config $config, RequestStack $requestStack) {
    $this->client = $client;
    $this->config = $config;
    $this->bucket = $config->get('bucket', '');
    $this->prefix = $config->get('prefix', '');
    $this->options = $config->get('options', []);
    $this->requestStack = $requestStack;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $configuration = static::mergeConfiguration($container, $configuration);
    $client_config = static::mergeClientConfiguration($container, $configuration);

    $client = S3Client::factory($client_config);

    unset($configuration['key'], $configuration['secret']);

    return new static(
      $client,
      new Config($configuration),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function mergeClientConfiguration(ContainerInterface $container, array $configuration) {
    $client_config = parent::mergeClientConfiguration($container, $configuration);
    $client_config['credentials.cache'] = new AwsCacheAdapter(
      $container->get('cache.default'),
      'flysystem_ibmcloud_s3:'
    );
    $client_config['use_path_style_endpoint'] = TRUE;
    return $client_config;
  }

  /**
   * Merges default Flysystem configuration.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container to pull out services used in the plugin.
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   *
   * @return array
   *   The Flysystem configuration.
   */
  public static function mergeConfiguration(ContainerInterface $container, array $configuration) {
    return $configuration += [
      'region' => 'us-standard',
      'endpoint' => 'https://s3-api.us-geo.objectstorage.service.networklayer.com',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getAdapter() {
    return new S3Adapter($this->client, $this->bucket, $this->prefix, $this->options);
  }

  /**
   * {@inheritdoc}
   */
  public function getExternalUrl($uri) {
    $target = $this->getTarget($uri);

    if (strpos($target, 'styles/') === 0 && !file_exists($uri)) {
      $this->generateImageStyle($target);
    }

    return $this->getUrlPrefix($this->config, $uri) . '/' . UrlHelper::encodePath($target);
  }

  /**
   * Calculates the URL prefix.
   *
   * @param \League\Flysystem\Config $config
   *   The configuration.
   * @param string $uri
   *   The requested URI.
   *
   * @return string
   *   The URL prefix in the form protocol://cname[/bucket][/prefix].
   */
  protected function getUrlPrefix(Config $config, $uri) {
    if (!$config->get('use_host', TRUE)) {
      $request = $this->requestStack->getCurrentRequest();
      return $request->getSchemeAndHttpHost() . '/_flysystem/' . $this->getScheme($uri);
    }
    else {
      $host = $config->get('host', 'https://s3-api.us-geo.objectstorage.softlayer.net');
      $prefix = (string) $config->get('prefix', '');
      $prefix = $prefix === '' ? '' : '/' . UrlHelper::encodePath($prefix);
      $bucket = (string) $config->get('bucket', '');
      $bucket = $bucket === '' ? '' : '/' . UrlHelper::encodePath($bucket);
      return $host . $bucket . $prefix;
    }
  }

}
