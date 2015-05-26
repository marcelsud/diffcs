<?php

namespace Melody\Diffcs;

use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local as Adapter;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Class Executor
 *
 * @author Marcelo Santos <marcelsud@gmail.com>
 */
class Executor
{
    /**
     * @var type
     */
    protected $owner;
    /**
     * @var type
     */
    protected $repository;
    /**
     * @var type
     */
    protected $accessToken;
    /**
     * @var \Github\Client
     */
    protected $client;
    /**
     * @var \League\Flysystem\Filesystem
     */
    protected $filesystem;
    /**
     * @var type
     */
    protected $progress;
    /**
     * @var string
     */
    protected $codeStandard;

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param string $owner
     * @param string $repository
     * @param string $codeStandard
     * @param bool $githubToken Optional.
     * @param bool $githubUser Optional.
     * @param bool $githubPass Optional.
     */
    public function __construct(
        $output,
        $owner,
        $repository,
        $codeStandard,
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
        $this->codeStandard = $codeStandard;
        $this->client = new \Github\Client();
        $this->filesystem = new Filesystem(new Adapter(sys_get_temp_dir()));
    }

    /**
     * @param string $pullRequestId
     * @return array
     */
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

    /**
     * @return void
     */
    public function authenticateWithToken()
    {
        $this->client->authenticate(
            $this->accessToken,
            null,
            \Github\Client::AUTH_URL_TOKEN
        );
    }

    /**
     * @return void
     */
    public function authenticateWithPassword()
    {
        $this->client->authenticate(
            $this->githubUser,
            $this->githubPass,
            \Github\Client::AUTH_HTTP_PASSWORD
        );
    }

    /**
     * @param array $files
     * @param string $commitId
     * @return array
     */
    public function downloadFiles($files, $commitId)
    {
        $downloadedFiles = [];
        
        foreach ($files as $file) {
            if (!preg_match('/.*\.php$/', $file['filename']) || $file['status'] === "removed") {
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

    /**
     * @param array $downloadedFiles
     * @return array
     */
    public function runCodeSniffer($downloadedFiles)
    {
        $progress = new ProgressBar($this->output, count($downloadedFiles));
        $progress->setProgressCharacter('|');
        $progress->start();
        
        $outputs = [];
        
        foreach ($downloadedFiles as $file) {
            if (!preg_match('/.*\.php$/', $file)) {
                continue;
            }

            $command = sprintf(
                "phpcs %s/%s --standard=%s",
                sys_get_temp_dir(),
                $file,
                $this->codeStandard
            );

            $output = shell_exec($command);

            if (!empty($output)) {
                $outputs[] = $output;
            }
            
            $progress->advance();
        }
        
        $progress->finish();

        return $outputs;
    }
}
