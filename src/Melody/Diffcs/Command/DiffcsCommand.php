<?php

namespace Melody\Diffcs\Command;

use Melody\Diffcs\Executor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class DiffcsCommand extends Command
{
    const PULL_REQUEST_ARGUMENT = "pull-request";
    const REPOSITORY_ARGUMENT = "repository";
    const GITHUB_TOKEN_OPTION = "github-token";
    const GITHUB_USER_OPTION = "github-user";

    protected function configure()
    {
        $this
            ->setName('diffcs')
            ->setDescription('Used to run phpcs on pull requests')
            ->addArgument(
                self::REPOSITORY_ARGUMENT,
                InputArgument::REQUIRED,
                'The repository name'
            )
            ->addArgument(
                self::PULL_REQUEST_ARGUMENT,
                InputArgument::REQUIRED,
                'The pull request id'
            )
            ->addOption(
                self::GITHUB_TOKEN_OPTION,
                null,
                InputOption::VALUE_REQUIRED,
                'The github token to access private repositories'
            )
            ->addOption(
                self::GITHUB_USER_OPTION,
                null,
                InputOption::VALUE_REQUIRED,
                'The github username to access private repositories'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $pullRequestId = $input->getArgument(self::PULL_REQUEST_ARGUMENT);

        list($owner, $repository) = explode("/", $input->getArgument(self::REPOSITORY_ARGUMENT));
        $githubToken = $input->getOption(self::GITHUB_TOKEN_OPTION);
        $githubUser = $input->getOption(self::GITHUB_USER_OPTION);
        $githubPass = false;

        if (!empty($githubUser)) {
            $helper = $this->getHelper('question');

            $question = new Question('Password:');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $question->setValidator(function ($value) {
                if (trim($value) == '') {
                    throw new \Exception('The password can not be empty');
                }

                return $value;
            });

            $githubPass = $helper->ask($input, $output, $question);
        }

        $executor = new Executor(
            $owner,
            $repository,
            $githubToken,
            $githubUser,
            $githubPass
        );

        try {
            $results = $executor->execute($pullRequestId);
        } catch (\Exception $e) {
            die("ERROR: " . $e->getMessage() . PHP_EOL);
        }

        if (count($results)) {
            foreach ($results as $result) {
                $output->writeln($result);
            }
        }
    }
}
