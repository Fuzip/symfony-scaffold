<?php

declare(strict_types=1);

namespace Symfinit\Installer;

use Symfinit\Installer\Github\GithubClient;
use Symfinit\Installer\Runner\ProjectRunner;
use Symfinit\Installer\Symfony\SymfonyVersionResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @author Victor Dittiere <victor.dittiere@camif.fr>
 */
#[AsCommand(name: 'symfinit', description: 'Scaffold a new Symfony docker project')]
class InstallerCommand extends Command
{
    public const string VERSION = '@package_version@';
    private const string NAME_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/';
    private const string SYMFONY_DOCKER_REPOSITORY = 'dunglas/symfony-docker';

    public function __construct(
        private readonly GithubClient $githubClient = new GithubClient(),
        private readonly SymfonyVersionResolver $symfonyVersionResolver = new SymfonyVersionResolver(),
        private readonly ProjectRunner $projectStarter = new ProjectRunner(),
    ) {
        parent::__construct();
    }

    public static function version(): string
    {
        return str_starts_with(self::VERSION, '@') ? 'dev' : self::VERSION;
    }

    public static function validateName(string $name): string
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException('Project name must start with a letter or digit and contain only letters, digits, hyphens, dots, or underscores.');
        }

        return $name;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'The name of the project')
            ->addOption('symfony-version', null, InputOption::VALUE_REQUIRED, 'The Symfony version to use (e.g. "8" or "8.4")')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Symfinit installer');

        try {
            $name = $this->resolveProjectName($io, $input);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $symfonyVersionOption = $input->getOption('symfony-version');

        try {
            $resolved = \is_string($symfonyVersionOption) && '' !== $symfonyVersionOption
                ? $this->symfonyVersionResolver->resolve($symfonyVersionOption)
                : $this->symfonyVersionResolver->resolveLatestLts();
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        if (!$resolved->isLts) {
            $io->warning(sprintf('Symfony %s is not an LTS version.', $resolved->version));
        }

        $symfonyVersion = $resolved->version;

        $projectDir = (getcwd() ?: '.').\DIRECTORY_SEPARATOR.$name;
        if (file_exists($projectDir)) {
            $io->error(sprintf('Directory %s already exists.', $projectDir));

            return Command::INVALID;
        }

        try {
            $this->githubClient->clone(self::SYMFONY_DOCKER_REPOSITORY, $projectDir);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->text(sprintf('Project cloned into <info>%s</info>.', $projectDir));

        try {
            $this->projectStarter->start($projectDir, $symfonyVersion, static function (string $type, string $buffer) use ($output): void {
                $output->write($buffer);
            });
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Project %s is ready.', $name));

        return Command::SUCCESS;
    }

    private function resolveProjectName(SymfonyStyle $io, InputInterface $input): string
    {
        $name = $input->getArgument('name');
        if (\is_string($name) && '' !== $name) {
            return self::validateName($name);
        }

        $question = new Question('Project name', 'my-app');
        $question->setValidator(static fn ($v): string => self::validateName((string) $v));
        $question->setMaxAttempts(3);

        return (string) $io->askQuestion($question);
    }
}
