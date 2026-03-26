<?php

namespace NgoTools\Cli;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class ConnectCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('connect')
            ->setDescription('Connect your app with an NGO.Tools instance')
            ->addArgument('token', InputArgument::REQUIRED, 'The connect token from NGO.Tools (starts with ngt_)')
            ->addOption('no-tunnel', null, InputOption::VALUE_NONE, 'Skip tunnel setup and just register');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connectToken = $input->getArgument('token');

        // Parse composite token
        $parsed = $this->parseConnectToken($connectToken);

        if (! $parsed) {
            $output->writeln('<error>Invalid connect token. Token must start with ngt_</error>');

            return Command::FAILURE;
        }

        $apiUrl = $parsed['url'];
        $token = $parsed['token'];

        $output->writeln('');
        $output->writeln('<info>Connecting to NGO.Tools...</info>');
        $output->writeln("  Instance: <comment>{$apiUrl}</comment>");
        $output->writeln('');

        // Check we're in a project directory with a manifest
        $manifestPath = getcwd() . '/.well-known/ngotools.json';

        if (! file_exists($manifestPath)) {
            $output->writeln('<error>No .well-known/ngotools.json found in current directory.</error>');
            $output->writeln('  Make sure you are in your app\'s project root.');
            $output->writeln('  Create a new app first with: <comment>ngotools new myapp</comment>');

            return Command::FAILURE;
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);

        if (! is_array($manifest) || ! isset($manifest['slug'])) {
            $output->writeln('<error>Invalid manifest file.</error>');

            return Command::FAILURE;
        }

        $output->writeln("  App: <comment>{$manifest['slug']}</comment>");

        // Determine tunnel URL
        $tunnelUrl = null;

        if (! $input->getOption('no-tunnel')) {
            $tunnelUrl = $this->startTunnel($output);

            if (! $tunnelUrl) {
                return Command::FAILURE;
            }
        }

        // Bootstrap with NGO.Tools
        $output->writeln('<comment>Registering with NGO.Tools...</comment>');

        $payload = json_encode(array_filter([
            'bootstrap_token' => $token,
            'tunnel_url' => $tunnelUrl ?? 'https://placeholder.localhost',
            'manifest' => $manifest,
        ]));

        $curlCmd = [
            'curl', '-sf', '-X', 'POST',
            "{$apiUrl}/api/tools-dev/bootstrap",
            '-H', 'Content-Type: application/json',
            '-d', $payload,
        ];

        $process = new Process($curlCmd);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            $output->writeln('<error>Failed to register with NGO.Tools.</error>');
            $output->writeln('  ' . trim($process->getErrorOutput() ?: $process->getOutput()));

            return Command::FAILURE;
        }

        $response = json_decode($process->getOutput(), true);

        if (! is_array($response) || empty($response['dev_token'])) {
            $output->writeln('<error>Unexpected response from NGO.Tools.</error>');
            $output->writeln('  ' . $process->getOutput());

            return Command::FAILURE;
        }

        // Write credentials to .env
        $envPath = getcwd() . '/.env';
        $this->writeEnvValue($envPath, 'NGOTOOLS_API_URL', $apiUrl);
        $this->writeEnvValue($envPath, 'NGOTOOLS_DEV_TOKEN', $response['dev_token']);

        if (! empty($response['webhook_secret'])) {
            $this->writeEnvValue($envPath, 'NGOTOOLS_WEBHOOK_SECRET', $response['webhook_secret']);
        }

        // Done
        $output->writeln('');
        $output->writeln('<info>Connected!</info>');
        $output->writeln('');
        $output->writeln("  Instance:       <comment>{$apiUrl}</comment>");
        $output->writeln("  App:            <comment>{$response['tool_slug']}</comment>");

        if ($tunnelUrl) {
            $output->writeln("  Tunnel:         <comment>{$tunnelUrl}</comment>");
        }

        $output->writeln('');
        $output->writeln('  Credentials saved to <comment>.env</comment>');
        $output->writeln('');

        return Command::SUCCESS;
    }

    protected function startTunnel(OutputInterface $output): ?string
    {
        if (! (new ExecutableFinder)->find('cloudflared')) {
            $output->writeln('<error>cloudflared is not installed.</error>');
            $output->writeln('  Install it: <comment>brew install cloudflared</comment>');

            return null;
        }

        // Detect local port from .env or config
        $port = $this->detectPort();

        $output->writeln("<comment>Starting tunnel to localhost:{$port}...</comment>");

        $origin = "http://localhost:{$port}";
        $process = new Process([
            'cloudflared', 'tunnel', '--config', '/dev/null',
            '--url', $origin, '--http-host-header', 'localhost',
        ]);
        $process->setTimeout(null);
        $process->start();

        // Wait for tunnel URL
        $tunnelUrl = null;
        $attempts = 0;

        while ($attempts < 30 && $tunnelUrl === null) {
            $buffer = $process->getIncrementalErrorOutput() . $process->getIncrementalOutput();

            if (preg_match('/https:\/\/[a-z0-9-]+\.trycloudflare\.com/', $buffer, $matches)) {
                $tunnelUrl = $matches[0];
            }

            $attempts++;
            usleep(500_000);
        }

        if (! $tunnelUrl) {
            $output->writeln('<error>Could not establish tunnel (timeout after 15s).</error>');
            $process->stop();

            return null;
        }

        $output->writeln("  Tunnel: <info>{$tunnelUrl}</info>");

        return $tunnelUrl;
    }

    protected function detectPort(): int
    {
        $envPath = getcwd() . '/.env';

        if (file_exists($envPath)) {
            $content = file_get_contents($envPath);

            if (preg_match('/^NGOTOOLS_PORT=(\d+)/m', $content, $matches)) {
                return (int) $matches[1];
            }

            if (preg_match('/^PORT=(\d+)/m', $content, $matches)) {
                return (int) $matches[1];
            }
        }

        // Check for Node.js config
        $packageJson = getcwd() . '/package.json';
        if (file_exists($packageJson)) {
            return 3000;
        }

        return 8001;
    }

    /**
     * Parse a composite connect token: ngt_{base64url(url)}_{token}
     *
     * @return array{url: string, token: string}|null
     */
    protected function parseConnectToken(string $connectToken): ?array
    {
        if (! str_starts_with($connectToken, 'ngt_')) {
            return null;
        }

        $withoutPrefix = substr($connectToken, 4);
        $lastUnderscore = strrpos($withoutPrefix, '_');

        if ($lastUnderscore === false) {
            return null;
        }

        $encodedUrl = substr($withoutPrefix, 0, $lastUnderscore);
        $token = substr($withoutPrefix, $lastUnderscore + 1);

        $url = base64_decode(strtr($encodedUrl, '-_', '+/'));

        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return ['url' => $url, 'token' => $token];
    }

    protected function writeEnvValue(string $envPath, string $key, string $value): void
    {
        if (! file_exists($envPath)) {
            file_put_contents($envPath, '');
        }

        $content = file_get_contents($envPath);

        if (str_contains($content, "{$key}=")) {
            $content = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $content);
        } else {
            $content = rtrim($content, "\n") . "\n{$key}={$value}\n";
        }

        file_put_contents($envPath, $content);
    }
}
