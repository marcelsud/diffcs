<?php

namespace League\Flysystem\Adapter;

use Aws\Common\Exception\MultipartUploadException;
use Aws\S3\Model\MultipartUpload\AbstractTransfer;
use Aws\S3\Model\MultipartUpload\UploadBuilder;
use Aws\S3\S3Client;
use Aws\S3\Enum\Group;
use Aws\S3\Enum\Permission;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Util;

class AwsS3 extends AbstractAdapter
{
    /**
     * @var  array  $resultMap
     */
    protected static $resultMap = array(
        'Body'          => 'raw_contents',
        'ContentLength' => 'size',
        'ContentType'   => 'mimetype',
        'Size'          => 'size',
    );

    /**
     * @var  array  $metaOptions
     */
    protected static $metaOptions = array(
        'CacheControl',
        'Expires',
        'StorageClass',
        'ServerSideEncryption',
        'Metadata',
        'ACL',
        'ContentType'
    );

    /**
     * @var  string  $bucket  bucket name
     */
    protected $bucket;

    /**
     * @var  S3Client  $client  S3 Client
     */
    protected $client;

    /**
     * @var  array  $options  default options[
     *                            Multipart=1024 Mb - After what size should multipart be used
     *                            MinPartSize=32 Mb - Minimum size of parts for each part
     *                            Concurrency=3 - If multipart is used, how many concurrent connections should be used
     *                            ]
     */
    protected $options = array(
        'Multipart' => 1024,
        'MinPartSize' => 32,
        'Concurrency' => 3,
    );

    /**
     * @var  UploadBuilder $uploadBuilder Used to upload object using a multipart transfer
     */
    protected $uploadBuilder;

    /**
     * Constructor
     *
     * @param  S3Client      $client
     * @param  string        $bucket
     * @param  string        $prefix
     * @param  array         $options
     * @param  UploadBuilder $uploadBuilder
     */
    public function __construct(
        S3Client $client,
        $bucket,
        $prefix = null,
        array $options = array(),
        UploadBuilder $uploadBuilder = null
    ) {
        $this->client  = $client;
        $this->bucket  = $bucket;
        $this->setPathPrefix($prefix);
        $this->options = array_merge($this->options, $options);
        $this->setUploadBuilder($uploadBuilder);
    }

    /**
     * Get the S3Client bucket
     *
     * @return  string
     */
    public function getBucket()
    {
        return $this->bucket;
    }

    /**
     * Get the S3Client instance
     *
     * @return  S3Client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Check whether a file exists
     *
     * @param   string  $path
     * @return  bool    weather an object result
     */
    public function has($path)
    {
        $location = $this->applyPathPrefix($path);

        return $this->client->doesObjectExist($this->bucket, $location);
    }

    /**
     * Write a file
     *
     * @param   string $path
     * @param   string $contents
     * @param   mixed  $config
     *
     * @return  array   file metadata
     */
    public function write($path, $contents, $config = null)
    {
        $options = $this->getOptions(
            $path,
            array(
                'Body'          => $contents,
                'ContentType'   => Util::guessMimeType($path, $contents),
                'ContentLength' => Util::contentSize($contents),
            ),
            $config = Util::ensureConfig($config)
        );

        return $this->writeObject($options);
    }

    /**
     * Write using a stream
     *
     * @param   string   $path
     * @param   resource $resource
     * @param   mixed    $config ['visibility'='private', 'mimetype'='', 'Metadata'=[]]
     *
     * @return  array     file metadata
     */
    public function writeStream($path, $resource, $config = null)
    {
        $config  = Util::ensureConfig($config);
        $options = array('Body' => $resource);
        $options['ContentLength'] = Util::getStreamSize($resource);
        $options = $this->getOptions($path, $options, $config);

        return $this->writeObject($options);
    }

    /**
     * Write an object to S3
     *
     * @param   array  $options
     * @return  array   file metadata
     */
    protected function writeObject(array $options)
    {
        $multipartLimit = $this->mbToBytes($options['Multipart']);

        // If we don't know the stream size, we have to assume we need to upload using multipart, otherwise it might fail.
        if ($options['ContentLength'] > $multipartLimit) {
            $result = $this->putObjectMultipart($options);
        } else {
            $result = $this->client->putObject($options);
        }

        if ($result === false) {
            return false;
        }

        if ( ! is_string($options['Body'])) {
            unset($options['Body']);
        }

        return $this->normalizeObject($options);
    }

    /**
     * Update a file
     *
     * @param   string  $path
     * @param   string  $contents
     * @param   mixed   $config   Config object or visibility setting
     * @return  array   file metadata
     */
    public function update($path, $contents, $config = null)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * Update a file using a stream
     *
     * @param   string    $path
     * @param   resource  $resource
     * @param   mixed     $config   Config object or visibility setting
     * @return  array     file metadata
     */
    public function updateStream($path, $resource, $config = null)
    {
        return $this->writeStream($path, $resource, $config);
    }

    /**
     * Read a file
     *
     * @param   string  $path
     * @return  array   file metadata
     */
    public function read($path)
    {
        $result = $this->readObject($path);
        $result['contents'] = (string) $result['raw_contents'];
        unset($result['raw_contents']);

        return $result;
    }

    /**
     * Get a read-stream for a file
     *
     * @param   string  $path
     * @return  array   file metadata
     */
    public function readStream($path)
    {
        $result = $this->readObject($path);
        $result['stream'] = $result['raw_contents']->getStream();
        // Ensure the EntityBody object destruction doesn't close the stream
        $result['raw_contents']->detachStream();
        unset($result['raw_contents']);

        return $result;
    }

    /**
     * Get an object from S3
     *
     * @param   string  $path
     * @return  array   file metadata
     */
    protected function readObject($path)
    {
        $options = $this->getOptions($path);
        $result = $this->client->getObject($options);

        return $this->normalizeObject($result->getAll(), $path);
    }

    /**
     * Rename a file
     *
     * @param   string  $path
     * @param   string  $newpath
     * @return  array   file metadata
     */
    public function rename($path, $newpath)
    {
        $options = $this->getOptions($newpath, array(
            'Bucket' => $this->bucket,
            'CopySource' => $this->bucket.'/'.$this->applyPathPrefix($path),
            'ACL' => $this->getObjectACL($path),
        ));

        $result = $this->client->copyObject($options)->getAll();
        $result = $this->normalizeObject($result, $newpath);
        $this->delete($path);

        return $result;
    }

    /**
     * Copy a file
     *
     * @param   string  $path
     * @param   string  $newpath
     * @return  array   file metadata
     */
    public function copy($path, $newpath)
    {
        $options = $this->getOptions($newpath, array(
            'Bucket' => $this->bucket,
            'CopySource' => $this->bucket.'/'.$this->applyPathPrefix($path),
            'ACL' => $this->getObjectACL($path),
        ));

        $result = $this->client->copyObject($options)->getAll();

        return $this->normalizeObject($result, $newpath);
    }

    /**
     * Delete a file
     *
     * @param   string   $path
     * @return  boolean  delete result
     */
    public function delete($path)
    {
        $options = $this->getOptions($path);

        return $this->client->deleteObject($options);
    }

    /**
     * Delete a directory (recursive)
     *
     * @param   string   $path
     * @return  boolean  delete result
     */
    public function deleteDir($path)
    {
        $prefix = rtrim($this->applyPathPrefix($path), '/') . '/';

        return $this->client->deleteMatchingObjects($this->bucket, $prefix);
    }

    /**
     * Create a directory
     *
     * @param   string        $path directory name
     * @param   array|Config  $options
     *
     * @return  bool
     */
    public function createDir($path, $options = null)
    {
        $result = $this->write(rtrim($path, '/') . '/', '', $options);

        if ( ! $result) {
            return false;
        }

        return array('path' => $path, 'type' => 'dir');
    }

    /**
     * Get metadata for a file
     *
     * @param   string  $path
     * @return  array   file metadata
     */
    public function getMetadata($path)
    {
        $options = $this->getOptions($path);
        $result = $this->client->headObject($options);

        return $this->normalizeObject($result->getAll(), $path);
    }

    /**
     * Get the mimetype of a file
     *
     * @param   string  $path
     * @return  array   file metadata
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the file of a file
     *
     * @param   string  $path
     * @return  array   file metadata
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the timestamp of a file
     *
     * @param   string  $path
     * @return  array   file metadata
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the visibility of a file
     *
     * @param   string  $path
     * @return  array   file metadata
     */
    public function getVisibility($path)
    {
        $options = $this->getOptions($path);
        $result = $this->client->getObjectAcl($options)->getAll();
        $visibility = AdapterInterface::VISIBILITY_PRIVATE;

        foreach ($result['Grants'] as $grant) {
            if (isset($grant['Grantee']['URI']) && $grant['Grantee']['URI'] === Group::ALL_USERS && $grant['Permission'] === Permission::READ) {
                $visibility = AdapterInterface::VISIBILITY_PUBLIC;
                break;
            }
        }

        return compact('visibility');
    }

    /**
     * Get the ACL based on the visibility
     *
     * @param $path
     * @return string
     */
    protected function getObjectACL($path)
    {
        $metadata = $this->getVisibility($path);

        return $metadata['visibility'] === AdapterInterface::VISIBILITY_PUBLIC ? 'public-read' : 'private';
    }

    /**
     * Get mimetype of a file
     *
     * @param   string  $path
     * @param   string  $visibility
     * @return  array   file metadata
     */
    public function setVisibility($path, $visibility)
    {
        $options = $this->getOptions($path, array(
            'ACL' => $visibility === AdapterInterface::VISIBILITY_PUBLIC ? 'public-read' : 'private',
        ));

        $this->client->putObjectAcl($options);

        return compact('visibility');
    }

    /**
     * List contents of a directory
     *
     * @param   string  $dirname
     * @param   bool    $recursive
     * @return  array   directory contents
     */
    public function listContents($dirname = '', $recursive = false)
    {
        $objectsIterator = $this->client->getIterator('listObjects', array(
            'Bucket' => $this->bucket,
            'Prefix' => $this->applyPathPrefix($dirname),
        ));

        $contents = iterator_to_array($objectsIterator);
        $result = array_map(array($this, 'normalizeObject'), $contents);

        return Util::emulateDirectories($result);
    }

    /**
     * Normalize a result from AWS
     *
     * @param   array  $object
     * @param   string  $path
     * @return  array   file metadata
     */
    protected function normalizeObject(array $object, $path = null)
    {
        $result = array('path' => $path ?: $this->removePathPrefix($object['Key']));
        $result['dirname'] = Util::dirname($result['path']);

        if (isset($object['LastModified'])) {
            $result['timestamp'] = strtotime($object['LastModified']);
        }

        if (substr($result['path'], -1) === '/') {
            $result['type'] = 'dir';
            $result['path'] = rtrim($result['path'], '/');

            return $result;
        }

        $result = array_merge($result, Util::map($object, static::$resultMap), array('type' => 'file'));

        return $result;
    }

    /**
     * Get options for a AWS call
     *
     * @param   string $path
     * @param   array  $options
     * @param   Config $config
     *
     * @return  array   AWS options
     */
    protected function getOptions($path, array $options = array(), Config $config = null)
    {
        $options = array_merge($this->options, $options);
        $options['Key']    = $this->applyPathPrefix($path);
        $options['Bucket'] = $this->bucket;

        if ($config) {
            $options = array_merge($options, $this->getOptionsFromConfig($config));
        }

        return $options;
    }

    /**
     * Retrieve options from a Config instance
     *
     * @param   Config  $config
     * @return  array
     */
    protected function getOptionsFromConfig(Config $config)
    {
        $options = array();

        foreach (static::$metaOptions as $option) {
            if ( ! $config->has($option)) continue;
            $options[$option] = $config->get($option);
        }

        if ($visibility = $config->get('visibility')) {
            // For local reference
            $options['visibility'] = $visibility;
            // For external reference
            $options['ACL'] = $visibility === AdapterInterface::VISIBILITY_PUBLIC ? 'public-read' : 'private';
        }

        if ($mimetype = $config->get('mimetype')) {
            // For local reference
            $options['mimetype'] = $mimetype;
            // For external reference
            $options['ContentType'] = $mimetype;
        }

        return $options;
    }

    /**
     * Sends an object to a bucket using a multipart transfer, possibly also using concurrency
     *
     * @param   array $options Can have: [Body, Bucket, Key, MinPartSize, Concurrency, ContentType, ACL, Metadata]
     *
     * @return  bool
     */
    protected function putObjectMultipart(array $options)
    {
        // Prepare the upload parameters.
        /** @var UploadBuilder $uploadBuilder */
        $uploadBuilder = $this->getUploadBuilder();

        $uploadBuilder->setBucket($options['Bucket'])
            // This options are always set in the $options array, so we don't need to check for them
            ->setKey($options['Key'])
            ->setMinPartSize($options['MinPartSize'])
            ->setConcurrency($options['Concurrency'])
            ->setSource($options['Body']) // these 2 methods must be the last to be called because they return
            ->setClient($this->client); // AbstractUploadBuilder, which makes IDE and CI complain.

        foreach (static::$metaOptions as $option) {
            if ( ! array_key_exists($option, $options)) continue;
            $uploadBuilder->setOption($option, $options[$option]);
        }

        $uploader = $uploadBuilder->build();

        return $this->upload($uploader);
    }

    /**
     * Perform the upload. Abort the upload if something goes wrong.
     *
     * @param   AbstractTransfer $uploader
     *
     * @return  bool
     */
    protected function upload(AbstractTransfer $uploader)
    {
        try {
            $uploader->upload();
        } catch (MultipartUploadException $e) {
            $uploader->abort();

            return false;
        }

        return true;
    }

    /**
     * Convert megabytes to bytes
     *
     * @param   int $megabytes
     *
     * @return  int
     */
    protected function mbToBytes($megabytes)
    {
        return $megabytes * 1024 * 1024;
    }

    /**
     * Set the S3 UploadBuilder
     *
     * @param   UploadBuilder $uploadBuilder
     * @return  self
     */
    public function setUploadBuilder(UploadBuilder $uploadBuilder = null)
    {
        $this->uploadBuilder = $uploadBuilder;

        return $this;
    }

    /**
     * Get the S3 UploadBuilder
     *
     * @return UploadBuilder
     */
    public function getUploadBuilder()
    {
        if ( ! $this->uploadBuilder) {
            $this->uploadBuilder = UploadBuilder::newInstance();
        }

        return $this->uploadBuilder;
    }
}
