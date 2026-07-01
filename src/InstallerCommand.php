<?php

declare(strict_types=1);

namespace SymfonyScaffold\Installer;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use SymfonyScaffold\Installer\Github\GithubClient;

/**
 * @author Victor Dittiere <victor.dittiere@camif.fr>
 */
#[AsCommand(name: 'symfony-scaffold', description: 'Scaffold a new Symfony docker project')]
class InstallerCommand extends Command
{
    public const string VERSION = '@package_version@';
    private const string NAME_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/';

    public function __construct(private readonly GithubClient $githubClient)
    {
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
            ->addOption('frankenphp-version', 'f', InputOption::VALUE_REQUIRED, 'FrankenPHP version to use (e.g. v1.12, v1.12.4, latest)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Symfony scaffold installer');

        try {
            $name = $this->resolveProjectName($io, $input);
            $frankenPhpVersion = $this->resolveFrankenPhpVersion($io, $input);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $projectDir = (getcwd() ?: '.').\DIRECTORY_SEPARATOR.$name;
        if (file_exists($projectDir)) {
            $io->error(sprintf('Directory %s already exists.', $projectDir));

            return Command::INVALID;
        }

        $io->section(sprintf('Creating project "%s" with FrankenPHP %s', $name, $frankenPhpVersion));

        return Command::SUCCESS;
    }

    /**
     * Resolve the project name command argument.
     */
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

    /**
     * Resolve the FrankenPHP version command option.
     *
     * @throws \Throwable
     */
    private function resolveFrankenPhpVersion(SymfonyStyle $io, InputInterface $input): string
    {
        $version = $input->getOption('frankenphp-version');

        // No version given: ask interactively or fetch latest
        if (null === $version) {
            if (!$input->isInteractive()) {
                $io->text('Fetching latest FrankenPHP release from GitHub...');
                $tag = $this->githubClient->getLatestTag();
                if (null === $tag) {
                    throw new \RuntimeException('Could not fetch latest FrankenPHP release from GitHub.');
                }
                $io->text(sprintf('Using FrankenPHP <info>%s</info> (latest).', $tag));

                return $tag;
            }

            $tags = $this->githubClient->getLatestTags(10);
            if ([] === $tags) {
                throw new \RuntimeException('Could not fetch FrankenPHP releases from GitHub.');
            }

            return (string) $io->askQuestion(new ChoiceQuestion('Select a FrankenPHP version', $tags, $tags[0]));
        }

        // "latest" keyword: fetch latest release
        if ('latest' === $version) {
            $io->text('Fetching latest FrankenPHP release from GitHub...');
            $tag = $this->githubClient->getLatestTag();
            if (null === $tag) {
                throw new \RuntimeException('Could not fetch latest FrankenPHP release from GitHub.');
            }
            $io->text(sprintf('Using FrankenPHP <info>%s</info> (latest).', $tag));

            return $tag;
        }

        // Resolve and check given version
        $tag = str_starts_with($version, 'v') ? $version : 'v'.$version;
        $shouldBeResolved = \count(explode('.', $version)) < 3;

        if ($shouldBeResolved) {
            $io->text(sprintf('Resolving FrankenPHP version <info>%s</info>...', $version));
            $resolvedTag = $this->githubClient->resolveTag($tag);
            if (null === $resolvedTag) {
                throw new \InvalidArgumentException(sprintf('No FrankenPHP release found matching version "%s".', $version));
            }
            $io->text(sprintf('Using FrankenPHP <info>%s</info>.', $resolvedTag));

            return $resolvedTag;
        }

        $io->text(sprintf('Checking FrankenPHP version <info>%s</info>...', $version));
        if (!$this->githubClient->checkTag($tag)) {
            throw new \InvalidArgumentException(sprintf('FrankenPHP tag "%s" does not exist on GitHub.', $version));
        }

        return $tag;
    }
}
