<?php

namespace Melody\Diffcs\Command;

use Melody\Diffcs\Executor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DiffcsCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('demo:greet')
            ->setDescription('Greet someone')
            ->addArgument(
                'pr',
                InputArgument::REQUIRED,
                'The pull request id'
            )
            ->addArgument(
                'repo',
                InputArgument::REQUIRED,
                'The repository name'
            )
            ->addOption(
                'token',
                null,
                InputOption::VALUE_NONE,
                'The access token'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $pullRequestId = $input->getArgument('pr');

        list($owner, $repository) = explode("/", $input->getArgument("repo"));
        $accessToken = $input->getOption('token');

        $diffcs = new Diffcs($owner, $repository, $accessToken);

        try {
            $results = $diffcs->execute($pullRequestId);
        } catch (\Exception $e) {
            die("ERROR: " . $e->getMessage() . PHP_EOL);
        }


        if (count($results) > 0) {
            foreach ($results as $result) {
                $output->writeln($result);
            }
        }
    }
}

