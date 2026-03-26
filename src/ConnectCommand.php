<?php

namespace NgoTools\Cli;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConnectCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('connect')
            ->setDescription('Connect your app with an NGO.Tools instance')
            ->addArgument('url', InputArgument::OPTIONAL, 'The NGO.Tools instance URL');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('');
        $output->writeln('<comment>The connect command is not yet available.</comment>');
        $output->writeln('');
        $output->writeln('To connect your app with an NGO.Tools instance, use the');
        $output->writeln('Quick App wizard in the NGO.Tools admin panel instead.');
        $output->writeln('');

        return Command::SUCCESS;
    }
}
