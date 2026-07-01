<?php

declare(strict_types=1);

namespace SymfonyScaffold\Installer\Github;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @author Victor Dittiere <victor.dittiere@camif.fr>
 */
class GithubClient
{
    private const string BASE_URL = 'https://api.github.com';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $repo,
    ) {
    }

    /**
     * Return the latest tag of a public GitHub repository.
     *
     * @throws \Throwable
     */
    public function getLatestTag(): ?string
    {
        $response = $this->httpClient->request(
            'GET',
            sprintf('%s/repos/%s/releases/latest', self::BASE_URL, $this->repo)
        );
        $tag = $response->toArray()['tag_name'] ?? null;

        if (!\is_string($tag) || '' === $tag) {
            return null;
        }

        return $tag;
    }

    /**
     * Return the $limit most recent release tags of a public GitHub repository.
     *
     * @return array<string>
     *
     * @throws \Throwable
     */
    public function getLatestTags(int $limit): array
    {
        $response = $this->httpClient->request(
            'GET',
            sprintf('%s/repos/%s/releases', self::BASE_URL, $this->repo),
            ['query' => ['per_page' => $limit, 'page' => 1]]
        );

        /** @var array<array{tag_name?: string}> $releases */
        $releases = $response->toArray();
        $tags = [];

        foreach ($releases as $release) {
            $tag = $release['tag_name'] ?? '';
            if ('' !== $tag) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * Resolve a partial semver prefix (e.g. "v1", "v1.12") to the latest matching tag.
     *
     * Paginates releases (newest first) and returns the first tag starting with "{prefix}.".
     *
     * @throws \Throwable
     */
    public function resolveTag(string $prefix): ?string
    {
        $searchPrefix = $prefix.'.';
        $page = 1;

        while (true) {
            $response = $this->httpClient->request(
                'GET',
                sprintf('%s/repos/%s/releases', self::BASE_URL, $this->repo),
                ['query' => ['per_page' => 50, 'page' => $page]]
            );

            /** @var array<array{tag_name?: string}> $releases */
            $releases = $response->toArray();

            if ([] === $releases) {
                return null;
            }

            foreach ($releases as $release) {
                $tag = $release['tag_name'] ?? '';
                if (str_starts_with($tag, $searchPrefix)) {
                    return $tag;
                }
            }

            if (\count($releases) < 50) {
                return null;
            }

            ++$page;
        }
    }

    /**
     * Check if an exact tag exists for a public GitHub repository.
     *
     * @throws \Throwable
     */
    public function checkTag(string $tag): bool
    {
        try {
            $response = $this->httpClient->request(
                'GET',
                sprintf('%s/repos/%s/releases/tags/%s', self::BASE_URL, $this->repo, $tag)
            );

            return 200 === $response->getStatusCode();
        } catch (TransportExceptionInterface) {
            return false;
        }
    }
}
