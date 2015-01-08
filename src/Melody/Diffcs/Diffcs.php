<?php

namespace Melody\Diffcs;

use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local as Adapter;

class Diffcs
{
    protected $owner;
    protected $repository;
    protected $accessToken;
    protected $client;
    protected $filesystem;

    public function __construct($owner, $repository, $accessToken = false)
    {
        $this->owner = $owner;
        $this->accessToken = $accessToken;
        $this->repository = $repository;
        $this->client = new \Github\Client();
        $this->filesystem = new Filesystem(new Adapter(sys_get_temp_dir()));
    }

    public function execute($pullRequestId)
    {
        if ($this->accessToken) {
            $this->authenticate();
        }

        $pullRequest = $this->client->api('pull_request')->show($this->owner, $this->repository, $pullRequestId);
        $files = $this->client->api('pull_request')->files($this->owner, $this->repository, $pullRequestId);

        $downloadedFiles = $this->downloadFiles($files, $pullRequest["head"]["sha"]);

        return $this->runCodeSniffer($downloadedFiles);
    }

    public function authenticate()
    {
        $this->client->authenticate(
            $this->accessToken,
            null,
            \Github\Client::AUTH_URL_TOKEN
        );
    }

    public function downloadFiles($files, $commitId)
    {
        $downloadedFiles = [];

        foreach ($files as $file) {
            if (!preg_match('/src\/.*\.php$/', $file['filename'])) {
                continue;
            }

            $fileContent = $this->client->api('repo')->contents()->download(
                $this->owner,
                $this->repository,
                $file['filename'],
                $commitId
            );

            $file = sys_get_temp_dir() . "/" . $file['filename'];
            $this->filesystem->put($file, $fileContent);

            $downloadedFiles[] = $file;
        }

        return $downloadedFiles;
    }

    public function runCodeSniffer($downloadedFiles)
    {
        $outputs = [];

        foreach ($downloadedFiles as $file) {
            if (!preg_match('/src\/.*\.php$/', $file)) {
                continue;
            }

            $command = sprintf(
                "phpcs %s/%s --standard=PSR2",
                sys_get_temp_dir(),
                $file
            );

            $output = shell_exec($command);

            if (!empty($output)) {
                $outputs[] = $output;
            }
        }

        return $outputs;
    }
}
