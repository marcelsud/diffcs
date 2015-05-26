<?php

namespace Melody\Diffcs\Command;

use Melody\Diffcs\Executor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Class DiffcsCommand
 *
 * @author Marcelo Santos <marcelsud@gmail.com>
 */
class DiffcsCommand extends Command
{
    /**
     * @var string
     */
    const PULL_REQUEST_ARGUMENT = "pull-request";
    /**
     * @var string
     */
    const REPOSITORY_ARGUMENT = "repository";
    /**
     * @var string
     */
    const CODE_STANDARD_OPTION = "code-standard";
    /**
     * @var string
     */
    const GITHUB_TOKEN_OPTION = "github-token";
    /**
     * @var string
     */
    const GITHUB_USER_OPTION = "github-user";

    /**
     * @return void
     */
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
                self::CODE_STANDARD_OPTION,
                'cs',
                InputOption::VALUE_OPTIONAL,
                'The github token to access private repositories',
                'PSR2'
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

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $pullRequestId = $input->getArgument(self::PULL_REQUEST_ARGUMENT);

        list($owner, $repository) = explode("/", $input->getArgument(self::REPOSITORY_ARGUMENT));
        $githubToken = $input->getOption(self::GITHUB_TOKEN_OPTION);
        $githubUser = $input->getOption(self::GITHUB_USER_OPTION);
        $githubPass = false;

        $codeStandard = $input->getOption(self::CODE_STANDARD_OPTION);
        
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
            $output,
            $owner,
            $repository,
            $codeStandard,
            $githubToken,
            $githubUser,
            $githubPass
        );

        $output->writeln('<fg=white;bg=cyan;options=bold>                               </fg=white;bg=cyan;options=bold>');
        $output->writeln('<fg=white;bg=cyan;options=bold>  PHP DIFF CS (CODE STANDARD)  </fg=white;bg=cyan;options=bold>');
        $output->writeln('<fg=white;bg=cyan;options=bold>                               </fg=white;bg=cyan;options=bold>');
        
        $output->writeln('');
        $output->writeln(
            '<fg=cyan>Project: <options=bold>'
                .$input->getArgument(self::REPOSITORY_ARGUMENT)
            .'</options=bold></fg=cyan>'
        );
        $output->writeln(
            '<fg=cyan>Pull Request: <options=bold>#'.$pullRequestId.'</options=bold></fg=cyan>'
        );
        $output->writeln(
            '<fg=cyan>Code Standard: <options=bold>'.$codeStandard.'</options=bold></fg=cyan>'
        );
        $output->writeln('');
        
        try {
            $results = $executor->execute($pullRequestId);
        } catch (\Exception $e) {
            $output->writeln('<error>ERROR:</error> '.$e->getMessage());
            
            die();
        }

        if (count($results)) {
            foreach ($results as $result) {
                $output->writeln($result);
            }
        }

        $output->writeln(PHP_EOL);
    }
}
