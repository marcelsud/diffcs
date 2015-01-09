<?php

namespace Melody\Diffcs;

use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local as Adapter;
use Symfony\Component\Console\Helper\ProgressBar;

class Executor
{
    protected $owner;
    protected $repository;
    protected $accessToken;
    protected $client;
    protected $filesystem;
    protected $progress;

    public function __construct(
        $output,
        $owner,
        $repository,
        $githubToken = false,
        $githubUser = false,
        $githubPass = false
    ) {
        $this->output = $output;
        $this->owner = $owner;
        $this->githubToken = $githubToken;
        $this->githubUser = $githubUser;
        $this->githubPass = $githubPass;
        $this->repository = $repository;
        $this->client = new \Github\Client();
        $this->filesystem = new Filesystem(new Adapter(sys_get_temp_dir()));
    }

    public function execute($pullRequestId)
    {
        if ($this->githubToken) {
            $this->authenticateWithToken();
        }

        if ($this->githubUser) {
            $this->authenticateWithPassword();
        }

        $pullRequest = $this->client->api('pull_request')->show(
            $this->owner,
            $this->repository,
            $pullRequestId
        );

        $files = $this->client->api('pull_request')->files(
            $this->owner,
            $this->repository,
            $pullRequestId
        );

        $downloadedFiles = $this->downloadFiles($files, $pullRequest["head"]["sha"]);

        return $this->runCodeSniffer($downloadedFiles);
    }

    public function authenticateWithToken()
    {
        $this->client->authenticate(
            $this->accessToken,
            null,
            \Github\Client::AUTH_URL_TOKEN
        );
    }

    public function authenticateWithPassword()
    {
        $this->client->authenticate(
            $this->githubUser,
            $this->githubPass,
            \Github\Client::AUTH_HTTP_PASSWORD
        );
    }

    public function downloadFiles($files, $commitId)
    {
        $progress = new ProgressBar($this->output, count($files));
        $progress->setProgressCharacter('|');
        $progress->start();
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
            $progress->advance();
        }

        $progress->finish();

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
