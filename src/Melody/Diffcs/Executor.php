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
    const PHPCS_PHAR_URL = 'https://squizlabs.github.io/PHP_CodeSniffer/phpcs.phar';

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
        
        $this->runCodeSniffer($downloadedFiles);
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

            $this->filesystem->put(sys_get_temp_dir() . '/' . $file['filename'], $fileContent);

            $downloadedFiles[] = $file['filename'];
        }

        return $downloadedFiles;
    }

    /**
     * @param array $downloadedFiles
     *
     * @return void
     */
    public function runCodeSniffer($downloadedFiles)
    {
        $phpcsBinPath = self::getPhpCsBinPath();

        $filesToCheck = array_filter($downloadedFiles, function($file) {
            return preg_match('/.*\.php$/', $file);
        });

        $command = sprintf(
            "cd %s && %s %s --standard=%s --basepath=.",
            sys_get_temp_dir(),
            $phpcsBinPath,
            join(' ', $filesToCheck),
            $this->codeStandard
        );

        exec($command, $output, $exitCode);

        printf(join(PHP_EOL, $output));
        exit($exitCode);
    }

    private static function getPhpCsBinPath()
    {
        $phpcsBinPath = trim(shell_exec('which phpcs'));

        if (!$phpcsBinPath) {
            $phpcsBinPath = sys_get_temp_dir() . '/.diffcs/phpcs';
        }

        if (!file_exists($phpcsBinPath)) {
            shell_exec(sprintf("mkdir -p %s/.diffcs", sys_get_temp_dir()));
            copy(self::PHPCS_PHAR_URL, $phpcsBinPath);
            shell_exec('chmod +x ' . $phpcsBinPath);
        }

        return $phpcsBinPath;
    }
}
