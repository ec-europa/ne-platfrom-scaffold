<?php

/**
 * @file
 * Contains \NextEuropa\PlatformScaffold\Handler.
 */

namespace NextEuropa\PlatformScaffold;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Composer\Util\RemoteFilesystem;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * Class Handler
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class Handler
{
    const PLATFORM_ARTIFACT_FILENAME = 'artifact.tar.gz';
    const MODULES_DIRECTORY = '/sites/all/modules';
    const THEMES_DIRECTORY = '/sites/all/themes';

    /**
     * The build path.
     *
     * @var string
     */
    protected $buildPath;

    /**
     * Composer object.
     *
     * @var Composer
     */
    protected $composer;

    /**
     * IO object.
     *
     * @var IOInterface
     */
    protected $io;

    /**
     * The Executor object.
     *
     * @var ProcessExecutor
     */
    protected $executor;

    /**
     * An array with options from the composer.json extra.
     *
     * @var array
     */
    protected $options;

    /**
     * Handler constructor.
     *
     * @param Composer    $composer
     * @param IOInterface $io
     */
    public function __construct(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->executor = new ProcessExecutor($this->io);
    }

    /**
     * Scaffold NextEuropa Platform.
     */
    public function scaffoldPlatform()
    {
        $this->io->write('<info>Scaffolding NextEuropa platform.</info>');
        // Collecting data from the composer package.
        $this->options = $this->getOptions();

        $this->buildPath = dirname($this->composer->getConfig()->get('vendor-dir')).'/'.$this->options['directories']['build'];

        // Processing the platform artifact.
        $this->downloadPlatformArtifact();
        $this->extractPlatformArtifact();
        $this->removeArtifactFiles();

        // Processing local and remote patches.
        $this->applyLocalPatches();
        $this->downloadAndApplyPatches();
        $this->removeDownloadedPatches();

        $this->io->write('<info>Scaffolding process completed.</info>');
    }

    /**
     * Download NextEuropa Platform artifact.
     */
    public function downloadPlatformArtifact()
    {
        if (file_exists(self::PLATFORM_ARTIFACT_FILENAME)) {
            unlink(self::PLATFORM_ARTIFACT_FILENAME);
        }

        $remoteFs = new RemoteFilesystem($this->io);
        $url = $this->getUri();
        $remoteFs->copy($url, $url, self::PLATFORM_ARTIFACT_FILENAME);
    }

    /**
     * Extract downloaded NextEuropa Platform artifact.
     */
    protected function extractPlatformArtifact()
    {
        $fs = new Filesystem();

        if (is_dir($this->options['directories']['build'])) {
            $fs->removeDirectory($this->options['directories']['build']);
        }

        mkdir($this->options['directories']['build'], 0777, true);

        $command = sprintf('tar -xzvf %s --directory %s', self::PLATFORM_ARTIFACT_FILENAME, $this->options['directories']['build']);
        $this->executeCommand($command);
    }

    /**
     * Removes artifacts files.
     */
    protected function removeArtifactFiles()
    {
        unlink(self::PLATFORM_ARTIFACT_FILENAME);
    }

    /**
     * Removes downloaded patches.
     */
    protected function removeDownloadedPatches()
    {
        array_map(function ($file) {
            unlink($file);
        }, glob($this->buildPath.'/*.patch'));
    }

    /**
     * Apply local patches from the plugin 'patches' folder.
     */
    protected function applyLocalPatches()
    {
        $finder = new Finder();
        $fs = new SymfonyFilesystem();
        $finder->files()->in(__DIR__.'/../patches');
        $this->io->write('<comment>  Applying local patches');

        /** @var \SplFileInfo $file */
        foreach ($finder as $file) {
            $fileName = $file->getFilename();
            // Copying the patch to the build directory.
            $patchPath = $file->getRealPath();
            $fs->copy($patchPath, $this->buildPath.'/'.$fileName);

            // Applying the patch.
            $this->io->write("    Applying local patch: $fileName");
            $this->applyPatch($fileName);
        }

        $this->io->write('</comment>');
    }

    /**
     * Downloads and apply patches defined in the composer.json.
     */
    protected function downloadAndApplyPatches()
    {
        if (isset($this->options['patches'])) {
            $this->io->write('<comment>  Downloading and applying patches');
            // Set up a downloader.
            $downloader = new RemoteFilesystem($this->io, $this->composer->getConfig());

            // Iterating through the patches array elements.
            foreach ($this->options['patches'] as $desc => $patchUrl) {
                // Extracting the patch filename.
                $path = parse_url($patchUrl, PHP_URL_PATH);
                $pathFragments = explode('/', $path);
                $patchFileName = end($pathFragments);

                // Downloading the patch.
                $this->io->write("    Downloading patch: $desc");
                $patchPath = $this->options['directories']['build'].'/'.$patchFileName;
                $hostname = parse_url($patchUrl, PHP_URL_HOST);
                $downloader->copy($hostname, $patchUrl, $patchPath, false);

                // Applying the patch.
                $this->io->write('    Applying...');
                $this->applyPatch($patchFileName);
            }

            $this->io->write('</comment>');

            return;
        }
        $this->io->write('<info>There are no remote patches to apply.</info>');
    }

    /**
     * Retrieve data from optional "extra" configuration.
     *
     * @return array
     */
    protected function getOptions()
    {
        $extra = $this->composer->getPackage()->getExtra();

        return $extra['ne-platform-scaffold'];
    }

    /**
     * Apply given patch.
     *
     * @param string $patchFilename
     *   The patch filename.
     */
    protected function applyPatch($patchFilename)
    {
        $checked = $this->executeCommand(
            'git -C %s apply --check -v %s',
            $this->options['directories']['build'],
            $patchFilename
        );

        if (!$checked) {
            $this->io->write('<error>Error while applying a patch: '.$patchFilename.'</error>');
            $this->io->write('<comment>'.$this->executor->getErrorOutput().'</comment>');
        }

        if ($checked) {
            // Apply the first successful style.
            $patched = $this->executeCommand(
                'git -C %s apply %s',
                $this->options['directories']['build'],
                $patchFilename
            );
        }
    }

    /**
     * Get URI artifact.
     *
     * @return string
     *   An URI of the NextEuropa Platform artifact.
     */
    protected function getUri()
    {
        $map = [
        '{version}' => $this->options['version'],
        ];

        return str_replace(array_keys($map), array_values($map), $this->options['artifact']['url']);
    }

    /**
     * Execute an escaped shell command.
     *
     * @param string $cmd
     *
     * @return bool
     */
    protected function executeCommand($cmd)
    {
        // Shell-escape all arguments except the command.
        $args = func_get_args();
        foreach ($args as $index => $arg) {
            if (0 !== $index) {
                $args[$index] = escapeshellarg($arg);
            }
        }

        // And replace the arguments.
        $command = call_user_func_array('sprintf', $args);
        $output = '';
        if ($this->io->isVerbose()) {
            $this->io->write('<comment>'.$command.'</comment>');
            $io = $this->io;
            $output = function ($type, $data) use ($io) {
                if (Process::ERR == $type) {
                    $io->write('<error>'.$data.'</error>');
                } else {
                    $io->write('<comment>'.$data.'</comment>');
                }
            };
        }

        return (0 == $this->executor->execute($command, $output));
    }
}
