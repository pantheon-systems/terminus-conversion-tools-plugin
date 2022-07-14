<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\TerminusConversionTools\Utils\Git;
use Pantheon\TerminusConversionTools\Utils\Composer;
use Pantheon\TerminusConversionTools\Utils\Files;
use Pantheon\Terminus\Friends\LocalCopiesTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\ComposerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Class ConvertToComposerSiteCommand.
 */
class ConvertCreateProjectCommand extends TerminusCommand implements SiteAwareInterface
{
    use LocalCopiesTrait;
    use ConversionCommandsTrait;
    use ComposerAwareTrait;

    private const TARGET_UPSTREAM_GIT_REMOTE_URL = 'https://github.com/pantheon-upstreams/drupal-composer-managed.git';
    private const WEB_ROOT = 'web';
    private const EMPTY_UPSTREAM_ID = '4c7176de-e079-eed1-154d-44d5a9945b65';
    private const WEBROOT_FOLDERS_TO_FIX = [
        'docroot',
        'html',
    ];

    /**
     * Creates a Pantheon site from a distro.
     *
     * @command conversion:create-project
     *
     * @option composer-options Extra composer options.
     * @option label Site label.
     * @option org Organization name to create this site in.
     * @option region Region to create this site in.
     *
     * @param string $package
     *   The composer package name (vendor/package[:version])
     * @param string $siteId
     *   The name of a site to be created.
     * @param array $options
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function createProject(
        string $package,
        string $siteId,
        array $options = [
            'composer-options' => null,
            'label' => null,
            'org' => null,
            'region' => null,
        ]
    ): void {
        $filesystem = new Filesystem();
        $localCopiesPath = $this->getLocalCopiesDir();
        $path = $this->initialize($package, $siteId, $options, $filesystem, $localCopiesPath);

        $targetUpstreamRepoPath = Files::buildPath($localCopiesPath, sprintf('%s-%s', basename(self::TARGET_UPSTREAM_GIT_REMOTE_URL), bin2hex(random_bytes(2))));
        Git::clone($targetUpstreamRepoPath, self::TARGET_UPSTREAM_GIT_REMOTE_URL);

        // Mirror upstream-configuration folder.
        $filesystem->mirror(
            Files::buildPath($targetUpstreamRepoPath, 'upstream-configuration'),
            Files::buildPath($path, 'upstream-configuration')
        );

        // Copy pantheon.upstream.yml file.
        $filesystem->copy(
            Files::buildPath($targetUpstreamRepoPath, 'pantheon.upstream.yml'),
            Files::buildPath($path, 'pantheon.upstream.yml')
        );

        $pantheonYmlContent = Yaml::parseFile(Files::buildPath($path, 'pantheon.upstream.yml'));
        preg_match('/(\d+\.\d+)/', phpversion(), $matches);
        if (!$matches[1]) {
            throw new TerminusException('An error ocurred getting current php version.');
        }
        $phpVersion = $matches[1];
        if ($phpVersion !== $pantheonYmlContent['php_version']) {
            $pantheonYmlContent['php_version'] = (float) $phpVersion;
            $pantheonYmlFile = fopen(Files::buildPath($path, 'pantheon.upstream.yml'), 'wa+');
            fwrite($pantheonYmlFile, Yaml::dump($pantheonYmlContent, 2, 2));
            fclose($pantheonYmlFile);
        }

        // Create config folder and empty file in it.
        $filesystem->mkdir(Files::buildPath($path, 'config'));
        $filesystem->touch(Files::buildPath($path, 'config', '.gitkeep'));

        $this->getGit()->commit('Add files and folders from target upstream repository.');

        $this->matchComposerFromUpstream($path, $targetUpstreamRepoPath, $filesystem);

        $this->log()->notice('Adding paths to .gitignore file...');
        $this->setLocalSitePath($path);
        $pathsToIgnore = $this->getPathsToIgnore();
        if (count($pathsToIgnore) > 0) {
            $this->addGitignorePaths($pathsToIgnore);
            $this->deletePaths($pathsToIgnore);
        } else {
            $this->log()->notice('No paths detected to add to .gitignore file.');
        }

        /** @var \Pantheon\Terminus\Models\Environment $devEnv */
        $devEnv = $this->site->getEnvironments()->get('dev');
        $devEnv->changeConnectionMode('git');
        $connectionInfo = $devEnv->connectionInfo();
        $gitUrl = $connectionInfo['git_url'];

        $this->getGit()->addRemote($gitUrl, 'origin');
        $this->addGitHostToKnownHosts($connectionInfo['git_host'], $connectionInfo['git_port']);
        $this->getGit()->push('master', '-f');

        $dashboardUrl = $devEnv->dashboardUrl();
        $this->log()->notice(sprintf('Your new project is ready at %s', $dashboardUrl));

        $this->log()->notice('Done!');
    }

    /**
     * Match composer requires and configurations from target upstream repo.
     *
     * @param string $path
     *   Path to run everything in.
     * @param string $targetUpstreamRepoPath
     *   Path to target upstream repo.
     * @param \Symfony\Component\Filesystem\Filesystem $filesystem
     *   Filesystem object.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function matchComposerFromUpstream(string $path, string $targetUpstreamRepoPath, Filesystem $filesystem): void
    {
        // Composer require and configurations.
        $this->getComposer()->config('repositories.upstream-configuration', 'path', 'upstream-configuration');
        $this->getComposer()->require('pantheon-systems/drupal-integrations');

        if (!$filesystem->exists(Files::buildPath($path, 'vendor', 'drush', 'drush'))) {
            $this->getComposer()->require('drush/drush', '^12|^11|^10', '-W');
        }

        $targetUpstreamComposer = new Composer($targetUpstreamRepoPath);
        $targetUpstreamComposerJson = $targetUpstreamComposer->getComposerJsonData();

        // Edit composer.json file.
        $composerJson = $this->getComposer()->getComposerJsonData();
        if (!isset($composerJson['extra']['drupal-scaffold']['allowed-packages'])) {
            $composerJson['extra']['drupal-scaffold']['allowed-packages'] = [];
        }
        $allowedPackagesDiff = array_diff($targetUpstreamComposerJson['extra']['drupal-scaffold']['allowed-packages'], $composerJson['extra']['drupal-scaffold']['allowed-packages']);
        if (count($allowedPackagesDiff) > 0) {
            $composerJson['extra']['drupal-scaffold']['allowed-packages'] = array_merge($composerJson['extra']['drupal-scaffold']['allowed-packages'], $allowedPackagesDiff);
        }

        foreach ($targetUpstreamComposerJson['scripts'] as $scriptName => $contents) {
            if (!isset($composerJson['scripts'][$scriptName])) {
                $composerJson['scripts'][$scriptName] = [];
            }
            $diff = array_diff($contents, $composerJson['scripts'][$scriptName]);
            if (count($diff) > 0) {
                $composerJson['scripts'][$scriptName] = array_merge($composerJson['scripts'][$scriptName], $diff);
            }
        }

        $scriptsDescriptionsDiff = array_diff($targetUpstreamComposerJson['scripts-descriptions'], $composerJson['scripts-descriptions'] ?? []);
        if (count($scriptsDescriptionsDiff) > 0) {
            $composerJson['scripts-descriptions'] = array_merge($composerJson['scripts-descriptions'] ?? [], $scriptsDescriptionsDiff);
        }

        if (!isset($composerJson['autoload']['classmap'])) {
            $composerJson['autoload']['classmap'] = [];
        }
        $classmapDiff = array_diff($targetUpstreamComposerJson['autoload']['classmap'], $composerJson['autoload']['classmap']);
        if (count($classmapDiff) > 0) {
            $composerJson['autoload']['classmap'] = array_merge($composerJson['autoload']['classmap'], $classmapDiff);
        }

        $composerJson['extra']['installer-paths']['web/private/scripts/quicksilver/{$name}/'] = ['type:quicksilver-script'];

        $this->getComposer()->writeComposerJsonData($composerJson);

        // Require upstream-configuration.
        $this->getComposer()->require('pantheon-upstreams/upstream-configuration', 'dev-main', '--no-update');
        $this->getComposer()->update('pantheon-upstreams/upstream-configuration');

        $settingsPhpContents = file_get_contents(Files::buildPath($path, 'web/sites/default/settings.php'));
        if (strpos($settingsPhpContents, 'settings.pantheon.php') === false) {
            $settingsPhpContents .= "\ninclude __DIR__ . '/settings.pantheon.php';";
            file_put_contents(Files::buildPath($path, 'web/sites/default/settings.php'), $settingsPhpContents);
        }

        $this->getGit()->commit('Require some composer packages and configure them.');
    }

    /**
     * Initialize everything that is needed for this command.
     *
     * @param string $package
     *   Template package (and optionally constraints) to use.
     * @param string $siteId
     *   Site id to create.
     * @param array $options
     *   Additional options.
     * @param \Symfony\Component\Filesystem\Filesystem $filesystem
     *   Filesystem object.
     * @param string $localCopiesPath
     *   Path to local copies' folder.
     *
     * @return string
     *   The path to the new project.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     */
    private function initialize(string $package, string $siteId, array $options, Filesystem $filesystem, string $localCopiesPath): string
    {
        $label = $options['label'] ?? $siteId;

        $this->createSite($siteId, $label, self::EMPTY_UPSTREAM_ID, $options);
        $this->setSite($siteId);

        $siteDirName = sprintf('%s_terminus_conversion_plugin', $siteId);

        $path = Files::buildPath($localCopiesPath, $siteDirName);
        $extraComposerOptions = $options['composer-options'] ?? '';
        Composer::createProject($package, $path, '--no-interaction', $extraComposerOptions);
        $this->setComposer($path);
        if (!$this->getComposer()->hasVendorFolder()) {
            $this->getComposer()->install();
        }

        Git::init($path, '-b', 'master');
        $this->setGit($path);
        $this->getGit()->commit(sprintf('Create new Pantheon site using "conversion:create-project" command and package %s.', $package));

        if (!$filesystem->exists(Files::buildPath($path, self::WEB_ROOT))) {
            $webrootFixed = false;
            foreach (self::WEBROOT_FOLDERS_TO_FIX as $folder) {
                if ($filesystem->exists(Files::buildPath($path, $folder))) {
                    $filesystem->symlink(
                        $folder,
                        Files::buildPath($path, self::WEB_ROOT)
                    );
                    $webrootFixed = true;
                    $this->getGit()->commit('Add symlink to webroot.');
                    break;
                }
            }
            if (!$webrootFixed) {
                $this->log()->warning(
                    'Could not find a web root folder in the distro. ' .
                    'Please manually create a symlink to the web root folder.'
                );
            }
        }
        return $path;
    }

    /**
     * Create new Pantheon site.
     *
     * @param $siteId
     * @param $label
     * @param $upstreamId
     * @param null[] $options
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     */
    protected function createSite($siteId, $label, $upstreamId, $options = ['org' => null, 'region' => null,])
    {
        // This function's code is mostly copied from Terminus site:create command.

        if ($this->sites()->nameIsTaken($siteId)) {
            throw new TerminusException('The site name {siteId} is already taken.', compact('siteId'));
        }

        $workflowOptions = [
            'label' => $label,
            'site_name' => $siteId,
        ];
        // If the user specified a region, then include it in the workflow
        // options. We'll allow the API to decide whether the region is valid.
        $region = $options['region'] ?? $this->config->get('command_site_options_region');
        if ($region) {
            $workflowOptions['preferred_zone'] = $region;
        }

        $user = $this->session()->getUser();

        // Locate upstream.
        $upstream = $user->getUpstreams()->get($upstreamId);

        // Locate organization.
        if (!is_null($orgId = $options['org'])) {
            /** @var \Pantheon\Terminus\Models\OrganizationUserMembership $orgUserMembership */
            $orgUserMembership = $user->getOrganizationMemberships()->get($orgId);
            $org = $orgUserMembership->getOrganization();
            $workflowOptions['organization_id'] = $org->id;
        }

        // Create the site.
        $this->log()->notice('Creating a new site...');
        $workflow = $this->sites()->create($workflowOptions);
        $this->processWorkflow($workflow);

        // Deploy the upstream.
        if ($site = $this->getSite($workflow->get('waiting_for_task')->site_id)) {
            $this->log()->notice('Deploying CMS...');
            $this->processWorkflow($site->deployProduct($upstream->id));
            $this->log()->notice('Deployed CMS');
        }
    }

    /**
     * Adds site's Git host to known_hosts file.
     */
    protected function addGitHostToKnownHosts($host, $port): void
    {
        $addGitHostToKnownHostsCommand = sprintf(
            'ssh-keyscan -p %d %s 2>/dev/null >> ~/.ssh/known_hosts',
            $port,
            $host
        );
        exec($addGitHostToKnownHostsCommand);
    }
}
