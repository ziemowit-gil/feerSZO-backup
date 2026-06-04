<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Overrides\PsrDiscovery\Discovery;

use Psr\Log\LoggerInterface;
use Composer\InstalledVersions as Composer;
use Composer\Semver\VersionParser as Version;
use PsrDiscovery\Collections\CandidatesCollection;
use PsrDiscovery\Entities\CandidateEntity;
use PsrDiscovery\Exceptions\SupportPackageNotFoundException;
use PsrDiscovery\Implementations\Psr3\Logs;
use Throwable;

/**
 * PsrDiscovery\Discover has a bug. See https://github.com/psr-discovery/discovery/pull/7
 *
 * We cannot update this package to ^1.2.0 because it is not compatible with PHP 8.1.
 * This class is a temporary workaround.
 */
final class Discover
{
    /**
     * @var string
     */
    private const PSR_LOG = LoggerInterface::class;

    /**
     * @var CandidatesCollection[]
     */
    private static array $extendedCandidates = [];

    /**
     * @return array<CandidateEntity>
     */
    public static function logs(): array
    {
        $implementationsPackage = Logs::class;

        if ( ! class_exists($implementationsPackage)) {
            throw new SupportPackageNotFoundException('PSR-3 Logger', 'psr-discovery/log-implementations');
        }

        self::$extendedCandidates[self::PSR_LOG] ??= $implementationsPackage::allCandidates();

        return self::discoveries(self::PSR_LOG);
    }

    /**
     * Discover all available interface implementations from a list of well-known classes.
     *
     * @param string $interface The interface to discover.
     *
     * @return CandidateEntity[] The discovered implementations, or null if none could be found
     *
     * @psalm-suppress MixedInferredReturnType,MixedReturnStatement,MixedMethodCall
     */
    private static function discoveries(string $interface): array
    {
        if ( ! isset(self::$extendedCandidates[$interface])) {
            return [];
        }

        $discovered = [];

        // Try to find a candidate that satisfies the version constraints.
        foreach (self::$extendedCandidates[$interface]->all() as $candidateEntity) {
            try {
                /** @var CandidateEntity $candidateEntity */
                if (Composer::satisfies(new Version(), $candidateEntity->getPackage(), $candidateEntity->getVersion())) {
                    $discovered[] = $candidateEntity;
                }
            } catch (Throwable) {
            }
        }

        return $discovered;
    }
}
