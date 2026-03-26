<?php

namespace NgoTools\Cli;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class NewCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('new')
            ->setDescription('Create a new NGO.Tools app')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the app')
            ->addOption('laravel', null, InputOption::VALUE_NONE, 'Scaffold a Laravel app')
            ->addOption('node', null, InputOption::VALUE_NONE, 'Scaffold a Node.js/Fastify app')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing directory');
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        if (! $input->getOption('laravel') && ! $input->getOption('node')) {
            $framework = select(
                label: 'Which framework do you want to use?',
                options: [
                    'laravel' => 'Laravel (PHP)',
                    'node' => 'Node.js (Fastify)',
                ],
                default: 'laravel',
            );

            $input->setOption($framework, true);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $slug = $this->slugify($name);
        $directory = getcwd() . '/' . $slug;

        if (is_dir($directory) && ! $input->getOption('force')) {
            $output->writeln("<error>Directory \"{$slug}\" already exists. Use --force to overwrite.</error>");

            return Command::FAILURE;
        }

        if ($input->getOption('node')) {
            return $this->scaffoldNode($input, $output, $name, $slug, $directory);
        }

        return $this->scaffoldLaravel($input, $output, $name, $slug, $directory);
    }

    protected function scaffoldLaravel(InputInterface $input, OutputInterface $output, string $name, string $slug, string $directory): int
    {
        $output->writeln('');
        $output->writeln('<info>Creating Laravel NGO.Tools app...</info>');
        $output->writeln('');

        // Check prerequisites
        if (! $this->hasCommand('composer')) {
            $output->writeln('<error>Composer is not installed. Visit https://getcomposer.org</error>');

            return Command::FAILURE;
        }

        if (! $this->hasPhpVersion('8.2')) {
            $output->writeln('<error>PHP >= 8.2 is required.</error>');

            return Command::FAILURE;
        }

        // Create Laravel project
        $output->writeln('<comment>Creating Laravel project...</comment>');

        $result = $this->runProcess(
            ['composer', 'create-project', 'laravel/laravel', $directory, '--quiet', '--no-interaction'],
            getcwd(),
            $output,
        );

        if ($result !== Command::SUCCESS) {
            $output->writeln('<error>Failed to create Laravel project.</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Laravel project created.</info>');

        // Install starter package
        $output->writeln('<comment>Installing NGO.Tools starter package...</comment>');

        $result = $this->runProcess(
            ['composer', 'require', 'ngo-tools/sdk-laravel-starter', '--quiet', '--no-interaction'],
            $directory,
            $output,
        );

        if ($result !== Command::SUCCESS) {
            $output->writeln('<error>Failed to install starter package.</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Starter package installed.</info>');

        // Run ngotools:install
        $output->writeln('');

        $result = $this->runProcess(
            ['php', 'artisan', 'ngotools:install', '--name=' . $name],
            $directory,
            $output,
            tty: true,
        );

        if ($result !== Command::SUCCESS) {
            $output->writeln('<error>Setup failed.</error>');

            return Command::FAILURE;
        }

        // Done
        $output->writeln('');
        $output->writeln('<info>Your NGO.Tools app is ready!</info>');
        $output->writeln('');
        $output->writeln("  <comment>cd {$slug}</comment>");
        $output->writeln('  <comment>php artisan ngotools:dev</comment>');
        $output->writeln('');

        return Command::SUCCESS;
    }

    protected function scaffoldNode(InputInterface $input, OutputInterface $output, string $name, string $slug, string $directory): int
    {
        $output->writeln('');
        $output->writeln('<info>Creating Node.js NGO.Tools app...</info>');
        $output->writeln('');

        // Check prerequisites
        if (! $this->hasCommand('node')) {
            $output->writeln('<error>Node.js is not installed. Visit https://nodejs.org</error>');

            return Command::FAILURE;
        }

        if (! $this->hasCommand('npm')) {
            $output->writeln('<error>npm is not installed.</error>');

            return Command::FAILURE;
        }

        $author = text(
            label: 'Author name',
            default: 'My Organization',
        );

        $port = text(
            label: 'Development port',
            default: '3000',
        );

        // Copy template
        $output->writeln('<comment>Scaffolding project...</comment>');

        $templateDir = dirname(__DIR__) . '/templates/node';

        if (! is_dir($templateDir)) {
            $output->writeln('<error>Node.js template not found. Package may be incomplete.</error>');

            return Command::FAILURE;
        }

        if ($input->getOption('force') && is_dir($directory)) {
            $this->removeDirectory($directory);
        }

        $this->copyDirectory($templateDir, $directory);

        // Replace placeholders
        $replacements = [
            '{{SLUG}}' => $slug,
            '{{NAME}}' => $name,
            '{{AUTHOR}}' => $author,
            '{{PORT}}' => $port,
        ];

        $this->replaceInFile($directory . '/package.json', $replacements);
        $this->replaceInFile($directory . '/.env.example', $replacements);
        $this->replaceInFile($directory . '/.env', $replacements);
        $this->replaceInFile($directory . '/src/server.js', $replacements);
        $this->replaceInFile($directory . '/.well-known/ngotools.json', $replacements);

        // Done
        $output->writeln('');
        $output->writeln('<info>Your NGO.Tools app is ready!</info>');
        $output->writeln('');
        $output->writeln("  <comment>cd {$slug}</comment>");
        $output->writeln('  <comment>npm install</comment>');
        $output->writeln('  <comment>npm run dev</comment>');
        $output->writeln('');
        $output->writeln("  Your app will run on <info>http://localhost:{$port}</info>");
        $output->writeln('');

        return Command::SUCCESS;
    }

    protected function slugify(string $name): string
    {
        $slug = mb_strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }

    protected function hasCommand(string $command): bool
    {
        return (new ExecutableFinder)->find($command) !== null;
    }

    protected function hasPhpVersion(string $minVersion): bool
    {
        return version_compare(PHP_VERSION, $minVersion, '>=');
    }

    protected function runProcess(array $command, string $cwd, OutputInterface $output, bool $tty = false): int
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(300);

        if ($tty && Process::isTtySupported()) {
            $process->setTty(true);
        }

        $process->run(function ($type, $buffer) use ($output, $tty) {
            if (! $tty) {
                $output->write($buffer);
            }
        });

        return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }

    protected function copyDirectory(string $source, string $destination): void
    {
        if (! is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $target = $destination . '/' . $iterator->getSubPathname();

            if ($item->isDir()) {
                if (! is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    protected function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }

    protected function replaceInFile(string $filePath, array $replacements): void
    {
        if (! file_exists($filePath)) {
            return;
        }

        $content = file_get_contents($filePath);

        foreach ($replacements as $search => $replace) {
            $content = str_replace($search, $replace, $content);
        }

        file_put_contents($filePath, $content);
    }
}
